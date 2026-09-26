<?php
/**
 * Bookings — a custom table (`{prefix}mclb_bookings`), InnoDB. Transactional,
 * high-volume, range-queried and FK'd to WooCommerce orders — none of which a
 * CPT serves well. Holds live in this same table as `status='held'` rows.
 *
 * HOLDS (two-layer design):
 *   1. Lazy expiry is the source of truth — the availability query counts a
 *      hold only while `status='held' AND hold_expires_at > NOW()`, so a lapsed
 *      hold frees its slot the instant it expires, not only after cron runs.
 *      (See active_for_lane() below — that WHERE clause is the contract.)
 *   2. A 15-min WP-Cron sweep is housekeeping only: it flips lapsed holds to
 *      'cancelled' so the table doesn't accumulate dead rows. This is the
 *      `cc-booking-holds` pattern brought IN-plugin (no external dependency).
 *
 * Concurrency (double-sell prevention) uses a SELECT ... FOR UPDATE overlap
 * check inside a transaction — schema-ready here (InnoDB + lane_range index);
 * the transaction itself is wired in Phase 4 when add-to-cart writes holds.
 *
 * All datetimes are LOCAL WALL-CLOCK (wp_timezone), consistent with hours and
 * closures. Phase 2/4 math uses DateTimeImmutable, never strtotime().
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Bookings {

    const STATUS_HELD      = 'held';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';

    const CRON_HOOK     = 'mclb_sweep_expired_holds';
    const CRON_SCHEDULE = 'mclb_fifteen_minutes';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'sweep_expired_holds']);
    }

    /** Fully-qualified table name. */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mclb_bookings';
    }

    /** dbDelta-formatted CREATE TABLE. */
    public static function schema($charset_collate) {
        $table = self::table();
        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lane_id bigint(20) unsigned NOT NULL,
  lane_name varchar(191) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned DEFAULT NULL,
  order_item_id bigint(20) unsigned DEFAULT NULL,
  session_token varchar(64) DEFAULT NULL,
  starts_at datetime NOT NULL,
  ends_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'held',
  hold_expires_at datetime DEFAULT NULL,
  customer_id bigint(20) unsigned DEFAULT NULL,
  customer_email varchar(191) DEFAULT NULL,
  customer_name varchar(191) DEFAULT NULL,
  price decimal(10,2) DEFAULT NULL,
  coach_requested tinyint(1) NOT NULL DEFAULT 0,
  coach_request_note text,
  assigned_coach_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lane_range (lane_id,starts_at,ends_at),
  KEY status (status),
  KEY order_id (order_id),
  KEY hold_expires_at (hold_expires_at)
) ENGINE=InnoDB {$charset_collate};";
    }

    /**
     * Rows that occupy a lane right now: confirmed bookings + still-live holds.
     * THE availability contract — the lazy-expiry WHERE clause lives here so
     * Phase 2 and Phase 4 both read holds the same way.
     *
     * @return array
     */
    public static function active_for_lane($lane_id) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . "
                  WHERE lane_id = %d
                    AND (status = %s OR (status = %s AND hold_expires_at > %s))",
                (int) $lane_id,
                self::STATUS_CONFIRMED,
                self::STATUS_HELD,
                current_time('mysql')
            )
        );
    }

    /**
     * Create a hold row. Phase 4 calls this from add-to-cart, inside the
     * FOR-UPDATE transaction; the gateway just writes the row.
     *
     * @return int|false new row id
     */
    public static function insert_hold(array $data) {
        global $wpdb;
        $now  = current_time('mysql');
        $mins = (int) MCLB_Settings::get('hold_minutes');
        $mins = ($mins >= 1) ? $mins : 15;

        // Add the hold window on the site's own wall-clock via DateTimeImmutable —
        // no strtotime(), consistent with how Phase 2/4 handle every datetime here.
        $expires = (new DateTimeImmutable($now, wp_timezone()))
            ->modify('+' . $mins . ' minutes')
            ->format('Y-m-d H:i:s');

        $row = [
            'lane_id'         => absint($data['lane_id'] ?? 0),
            'lane_name'       => sanitize_text_field($data['lane_name'] ?? ''),
            'session_token'   => isset($data['session_token']) ? sanitize_text_field($data['session_token']) : null,
            'starts_at'       => $data['starts_at'],
            'ends_at'         => $data['ends_at'],
            'status'          => self::STATUS_HELD,
            'hold_expires_at' => $expires,
            'customer_id'     => isset($data['customer_id']) ? absint($data['customer_id']) : null,
            'customer_email'  => isset($data['customer_email']) ? sanitize_email($data['customer_email']) : null,
            'customer_name'   => isset($data['customer_name']) ? sanitize_text_field($data['customer_name']) : null,
            'price'           => isset($data['price']) ? (float) $data['price'] : null,
            'coach_requested' => empty($data['coach_requested']) ? 0 : 1,
            'coach_request_note' => isset($data['coach_request_note']) ? sanitize_textarea_field($data['coach_request_note']) : null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $ok = $wpdb->insert(self::table(), $row);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /** Mark a booking (or all rows for a WC order) confirmed once paid. */
    public static function confirm_by_order($order_id) {
        global $wpdb;
        return $wpdb->update(
            self::table(),
            ['status' => self::STATUS_CONFIRMED, 'hold_expires_at' => null, 'updated_at' => current_time('mysql')],
            ['order_id' => (int) $order_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public static function cancel($id) {
        global $wpdb;
        return $wpdb->update(
            self::table(),
            ['status' => self::STATUS_CANCELLED, 'updated_at' => current_time('mysql')],
            ['id' => (int) $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    // ── Cron: housekeeping sweep (not the source of truth — see class docblock) ──

    public static function cron_schedule($schedules) {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 Minutes (moBooking)', 'mclb-lane-booking'),
            ];
        }
        return $schedules;
    }

    public static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function clear_cron() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Flip lapsed holds to cancelled so the table doesn't accrue dead rows. */
    public static function sweep_expired_holds() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table() . '
                    SET status = %s, updated_at = %s
                  WHERE status = %s AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s',
                self::STATUS_CANCELLED,
                current_time('mysql'),
                self::STATUS_HELD,
                current_time('mysql')
            )
        );
    }

    // ── Coach integration (generic; no hard CPT dependency) ─────────────────────

    /**
     * id => label options for the admin coach-assignment picker (Phase 5).
     * Empty by default; a site integrates its own source via the filter — CCWA
     * maps this to its `coach` CPT. Only meaningful when coach requests are on.
     *
     * @return array<int,string>
     */
    public static function coach_options() {
        return (array) apply_filters('mclb_coach_options', []);
    }
}
