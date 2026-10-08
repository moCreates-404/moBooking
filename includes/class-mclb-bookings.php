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

    /** How many times to retry a transient InnoDB lock failure before giving up. */
    const MAX_LOCK_RETRIES = 3;

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'sweep_expired_holds']);
        // Self-heal: re-arm the sweep if it's missing (e.g. an install whose
        // activation hook never scheduled it, or the event was cleared). The
        // guard inside schedule_cron() keeps this idempotent.
        add_action('init', [__CLASS__, 'schedule_cron']);
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
  admin_note text,
  source varchar(10) NOT NULL DEFAULT 'online',
  coach_rate decimal(10,2) DEFAULT NULL,
  coach_fee decimal(10,2) DEFAULT NULL,
  counter_due decimal(10,2) DEFAULT NULL,
  counter_paid_at datetime DEFAULT NULL,
  counter_paid_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lane_range (lane_id,starts_at,ends_at),
  KEY coach_range (assigned_coach_id,starts_at,ends_at),
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
    public static function active_for_lane($lane_id, $day_start = null, $day_end = null) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table() . '
                  WHERE lane_id = %d
                    AND (status = %s OR (status = %s AND hold_expires_at > %s))';
        $params = [(int) $lane_id, self::STATUS_CONFIRMED, self::STATUS_HELD, current_time('mysql')];

        // Optional day-range scope: overlaps [day_start, day_end).
        if ($day_start && $day_end) {
            $sql     .= ' AND starts_at < %s AND ends_at > %s';
            $params[] = $day_end;
            $params[] = $day_start;
        }

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Batch sibling of active_for_lane() for the grid — one query for many lanes,
     * always day-scoped so it pulls only that day's rows, not full history.
     * Rows carry lane_id so the caller groups them in PHP.
     *
     * @param int[]  $lane_ids
     * @return array
     */
    public static function active_for_lanes(array $lane_ids, $day_start, $day_end) {
        global $wpdb;
        $lane_ids = array_values(array_filter(array_map('intval', $lane_ids)));
        if (empty($lane_ids)) {
            return [];
        }
        $in     = implode(',', array_fill(0, count($lane_ids), '%d'));
        $sql    = 'SELECT * FROM ' . self::table() . "
                  WHERE lane_id IN ($in)
                    AND (status = %s OR (status = %s AND hold_expires_at > %s))
                    AND starts_at < %s AND ends_at > %s";
        $params = array_merge(
            $lane_ids,
            [self::STATUS_CONFIRMED, self::STATUS_HELD, current_time('mysql'), $day_end, $day_start]
        );
        return $wpdb->get_results($wpdb->prepare($sql, $params));
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

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    /** Filtered query for the admin booking view. */
    public static function query(array $args = []) {
        global $wpdb;
        $where  = ['1=1'];
        $params = [];
        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['lane_id'])) {
            $where[]  = 'lane_id = %d';
            $params[] = (int) $args['lane_id'];
        }
        if (!empty($args['date_from'])) {
            $where[]  = 'starts_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[]  = 'starts_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(customer_name LIKE %s OR customer_email LIKE %s OR lane_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 200;
        $sql   = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY starts_at DESC LIMIT ' . $limit;
        return $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
    }

    // NOTE: coach assignment goes exclusively through assign_coach_locked() /
    // remove_coach() (Phase 7b) so every write of assigned_coach_id is serialised
    // by the per-coach named lock. The old unlocked set_coach() helper was removed
    // to keep that invariant — there is no other writer of assigned_coach_id.

    /**
     * Rows occupying a lane on $date for the admin Manage calendar (Phase 7c):
     * confirmed, plus LIVE (unexpired) holds so staff can see a checkout in
     * progress, and cancelled only when asked (rendered ghosted). Expired holds
     * are excluded — they no longer occupy the slot.
     *
     * @return array
     */
    public static function for_day_admin($date, $include_cancelled = false) {
        global $wpdb;
        $now     = current_time('mysql');
        $clauses = [
            $wpdb->prepare('status = %s', self::STATUS_CONFIRMED),
            $wpdb->prepare('(status = %s AND hold_expires_at > %s)', self::STATUS_HELD, $now),
        ];
        if ($include_cancelled) {
            $clauses[] = $wpdb->prepare('status = %s', self::STATUS_CANCELLED);
        }
        $status_sql = '(' . implode(' OR ', $clauses) . ')';
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . "
               WHERE starts_at < %s AND ends_at > %s AND {$status_sql}
               ORDER BY lane_id, starts_at",
            $date . ' 23:59:59',
            $date . ' 00:00:00'
        ));
    }

    /** Append one timestamped audit line to a booking's admin_note (Phase 7c). */
    public static function append_admin_note($id, $line) {
        global $wpdb;
        $b = self::get((int) $id);
        if (!$b) {
            return false;
        }
        $existing = trim((string) $b->admin_note);
        $new      = ($existing === '') ? $line : $existing . "\n" . $line;
        return $wpdb->update(self::table(), ['admin_note' => $new, 'updated_at' => current_time('mysql')], ['id' => (int) $id]);
    }

    /** A logged-in customer's bookings (for the My Account list), newest first. */
    public static function for_user($user_id, array $statuses = ['confirmed', 'cancelled']) {
        global $wpdb;
        $user_id = (int) $user_id;
        if (!$user_id || empty($statuses)) {
            return [];
        }
        $in     = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge([$user_id], $statuses);
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . " WHERE customer_id = %d AND status IN ($in) ORDER BY starts_at DESC",
                $params
            )
        );
    }

    /**
     * Write holds for a whole selection atomically, preventing double-sell.
     *
     * One transaction covers the batch: for each selection a `SELECT … FOR UPDATE`
     * range scan on the lane_range index takes InnoDB next-key/gap locks over the
     * requested window, so a concurrent add can't slip a second hold into the same
     * gap. If ANY selection conflicts, the whole batch rolls back (all-or-nothing,
     * per Phase 3's one-order resolution) and the conflicting index is returned.
     *
     * @param array $selections rows shaped for insert_hold().
     * @return array{ok:bool,ids?:int[],conflict?:int}
     */
    public static function insert_holds_locked(array $selections) {
        // Retry only the engine-level lock failures (deadlock / lock-wait timeout),
        // which InnoDB may raise transiently under contention; a real slot conflict
        // is deterministic and returns immediately. Small randomised backoff.
        for ($attempt = 1; $attempt <= self::MAX_LOCK_RETRIES; $attempt++) {
            $r = self::attempt_holds($selections);
            if ($r['ok'] || empty($r['lock_error'])) {
                return $r; // committed, or a genuine conflict — done either way
            }
            usleep(mt_rand(40, 120) * 1000);
        }
        // Persistent lock contention: fail safe (no double-sell) and tell the caller.
        return ['ok' => false, 'lock_error' => true];
    }

    /** One transactional attempt. Distinguishes a real conflict from a lock error. */
    private static function attempt_holds(array $selections) {
        global $wpdb;
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');
        $ids = [];
        foreach ($selections as $i => $sel) {
            $conflict = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . self::table() . '
                       WHERE lane_id = %d
                         AND (status = %s OR (status = %s AND hold_expires_at > %s))
                         AND starts_at < %s AND ends_at > %s
                       LIMIT 1 FOR UPDATE',
                    (int) $sel['lane_id'],
                    self::STATUS_CONFIRMED,
                    self::STATUS_HELD,
                    $now,
                    $sel['ends_at'],
                    $sel['starts_at']
                )
            );
            // A deadlock on the SELECT returns null with an error set — must NOT be
            // mistaken for "no conflict". Check the error before trusting the value.
            if (self::is_lock_error($wpdb->last_error)) {
                $wpdb->query('ROLLBACK');
                return ['ok' => false, 'lock_error' => true];
            }
            if ($conflict) {
                $wpdb->query('ROLLBACK');
                return ['ok' => false, 'conflict' => $i];
            }
            $id = self::insert_hold($sel);
            if (!$id) {
                if (self::is_lock_error($wpdb->last_error)) {
                    $wpdb->query('ROLLBACK');
                    return ['ok' => false, 'lock_error' => true];
                }
                $wpdb->query('ROLLBACK');
                return ['ok' => false, 'conflict' => $i];
            }
            $ids[] = $id;
        }
        $wpdb->query('COMMIT');
        return ['ok' => true, 'ids' => $ids];
    }

    /** True for InnoDB deadlock (1213) / lock-wait timeout (1205) messages. */
    private static function is_lock_error($err) {
        if (!$err) {
            return false;
        }
        $e = strtolower($err);
        return strpos($e, 'deadlock') !== false || strpos($e, 'lock wait timeout') !== false;
    }

    /**
     * Insert a CONFIRMED booking directly (admin manual entry — no cart/hold/
     * payment), through the same FOR-UPDATE overlap guard + lock-retry as holds
     * so staff can't double-book a lane either. Returns the new id or a conflict.
     *
     * @return array{ok:bool,id?:int,conflict?:bool,lock_error?:bool}
     */
    public static function insert_confirmed_locked(array $data) {
        // Manual admin entry — stamp the source explicitly (column default 'online').
        $data['source'] = 'manual';

        // Optional coach: validate + snapshot the rate ONCE (so it stays stable
        // across lock retries) and fold the fee into counter_due. A manual booking
        // collects the lane price at the counter too → counter_due = price + fee.
        $coach_id = isset($data['coach_id']) ? (int) $data['coach_id'] : 0;
        $price    = isset($data['price']) ? (float) $data['price'] : 0.0;
        if ($coach_id > 0) {
            if (!MCLB_Coaches::is_bookable($coach_id)) {
                return ['ok' => false, 'error' => 'coach_not_bookable'];
            }
            $rate = MCLB_Coaches::rate($coach_id);
            if ($rate === null) {
                return ['ok' => false, 'error' => 'coach_no_rate'];
            }
            $data['assigned_coach_id'] = $coach_id;
            $data['coach_rate']        = (float) $rate;
            $data['coach_fee']         = MCLB_Coaches::fee($rate, $data['starts_at'], $data['ends_at']);
            $data['counter_due']       = round($price + $data['coach_fee'], 2);
        } else {
            $data['counter_due'] = round($price, 2);
        }

        $lock_coach = ($coach_id > 0);
        for ($attempt = 1; $attempt <= self::MAX_LOCK_RETRIES; $attempt++) {
            if ($lock_coach && !self::acquire_coach_lock($coach_id)) {
                usleep(mt_rand(40, 120) * 1000);
                continue; // coach busy with another write — retry
            }
            try {
                $r = self::attempt_confirmed($data);
            } finally {
                if ($lock_coach) {
                    self::release_coach_lock($coach_id);
                }
            }
            if ($r['ok'] || empty($r['lock_error'])) {
                return $r;
            }
            usleep(mt_rand(40, 120) * 1000);
        }
        return ['ok' => false, 'lock_error' => true];
    }

    private static function attempt_confirmed(array $data) {
        global $wpdb;
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');
        $conflict = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . self::table() . '
                   WHERE lane_id = %d
                     AND (status = %s OR (status = %s AND hold_expires_at > %s))
                     AND starts_at < %s AND ends_at > %s
                   LIMIT 1 FOR UPDATE',
                (int) $data['lane_id'],
                self::STATUS_CONFIRMED,
                self::STATUS_HELD,
                $now,
                $data['ends_at'],
                $data['starts_at']
            )
        );
        if (self::is_lock_error($wpdb->last_error)) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'lock_error' => true];
        }
        if ($conflict) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'conflict' => true, 'reason' => 'lane'];
        }
        $coach_id = isset($data['assigned_coach_id']) ? (int) $data['assigned_coach_id'] : 0;

        // Coach double-book guard — hard block, first in first served. A plain
        // read: the caller holds this coach's named lock (see insert_confirmed_locked)
        // so no other writer can assign the coach concurrently.
        if ($coach_id > 0) {
            $busy = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::table() . '
                   WHERE assigned_coach_id = %d
                     AND (status = %s OR (status = %s AND hold_expires_at > %s))
                     AND starts_at < %s AND ends_at > %s
                   LIMIT 1',
                $coach_id, self::STATUS_CONFIRMED, self::STATUS_HELD, $now, $data['ends_at'], $data['starts_at']
            ));
            if ($busy) {
                $wpdb->query('ROLLBACK');
                return ['ok' => false, 'conflict' => true, 'reason' => 'coach'];
            }
        }

        $row = [
            'lane_id'           => absint($data['lane_id']),
            'lane_name'         => sanitize_text_field($data['lane_name'] ?? ''),
            'starts_at'         => $data['starts_at'],
            'ends_at'           => $data['ends_at'],
            'status'            => self::STATUS_CONFIRMED,
            'hold_expires_at'   => null,
            'customer_id'       => isset($data['customer_id']) ? absint($data['customer_id']) : null,
            'customer_email'    => isset($data['customer_email']) ? sanitize_email($data['customer_email']) : null,
            'customer_name'     => isset($data['customer_name']) ? sanitize_text_field($data['customer_name']) : null,
            'price'             => isset($data['price']) ? (float) $data['price'] : null,
            'assigned_coach_id' => $coach_id ?: null,
            'coach_rate'        => isset($data['coach_rate']) ? (float) $data['coach_rate'] : null,
            'coach_fee'         => isset($data['coach_fee']) ? (float) $data['coach_fee'] : null,
            'counter_due'       => isset($data['counter_due']) ? (float) $data['counter_due'] : null,
            'source'            => isset($data['source']) ? sanitize_text_field($data['source']) : 'manual',
            'admin_note'        => isset($data['admin_note']) ? sanitize_textarea_field($data['admin_note']) : null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $ok = $wpdb->insert(self::table(), $row);
        if (!$ok) {
            if (self::is_lock_error($wpdb->last_error)) {
                $wpdb->query('ROLLBACK');
                return ['ok' => false, 'lock_error' => true];
            }
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'conflict' => true, 'reason' => 'lane'];
        }
        $id = (int) $wpdb->insert_id;
        $wpdb->query('COMMIT');
        return ['ok' => true, 'id' => $id];
    }

    /** Stamp the WC order + line item onto a held row (status stays 'held'). */
    public static function attach_order($hold_id, $order_id, $order_item_id) {
        global $wpdb;
        return $wpdb->update(
            self::table(),
            ['order_id' => (int) $order_id, 'order_item_id' => (int) $order_item_id, 'updated_at' => current_time('mysql')],
            ['id' => (int) $hold_id],
            ['%d', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * Atomically claim a confirmed booking for cancellation: flip it to
     * 'cancelled' only if it is currently 'confirmed', in a single UPDATE.
     * Returns true only to the ONE caller that won the flip — the idempotency
     * guard shared by the account self-cancel flow and the admin cancel/refund
     * action, so neither can double-release or double-refund the same booking.
     */
    public static function claim_cancel($id) {
        global $wpdb;
        $rows = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
                self::STATUS_CANCELLED,
                current_time('mysql'),
                (int) $id,
                self::STATUS_CONFIRMED
            )
        );
        if ((int) $rows === 1) {
            // Only the one caller that won the flip reconciles the counter balance.
            self::handle_counter_on_cancel((int) $id);
            return true;
        }
        return false;
    }

    /**
     * On cancellation, reconcile any coach/counter balance: an UNPAID counter is
     * voided (counter_due → NULL); a PAID counter can't be un-taken automatically,
     * so it's flagged for manual handling in admin_note and (if there's an order)
     * an order note. Runs once, for the caller that won claim_cancel().
     */
    private static function handle_counter_on_cancel($id) {
        global $wpdb;
        $b = self::get((int) $id);
        if (!$b) {
            return;
        }
        $now  = current_time('mysql');
        $paid = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');

        if (!$paid) {
            if ($b->counter_due !== null) {
                $wpdb->update(self::table(), ['counter_due' => null, 'updated_at' => $now], ['id' => (int) $id]);
            }
            return;
        }

        /* translators: %s: money amount. */
        $msg        = sprintf(__('Cancelled after a counter payment of %s was taken — handle manually.', 'mclb-lane-booking'), MCLB_Coaches::money((float) $b->counter_due));
        $line       = "[{$now}] {$msg}";
        $admin_note = trim((string) $b->admin_note);
        $admin_note = $admin_note === '' ? $line : $admin_note . "\n" . $line;
        $wpdb->update(self::table(), ['admin_note' => $admin_note, 'updated_at' => $now], ['id' => (int) $id]);

        if ($b->order_id && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $b->order_id);
            if ($order) {
                $order->add_order_note('moBooking: ' . $msg);
            }
        }
    }

    // ── Coach assignment (hard double-book block) + counter payments (Phase 7b) ──

    /**
     * Assign (or change) a coach on a confirmed booking under a FOR-UPDATE lock,
     * mirroring the hold/insert guard: one transaction locks the target booking
     * row then the coach's overlapping rows, so two concurrent assigns of the same
     * coach to overlapping slots can't both win. Hard block — no override.
     *
     * While the counter is unpaid the fee + counter_due recompute; once counter-
     * paid the money is locked, so a change needs an admin note and is logged
     * rather than silently re-priced. coach_id 0 removes the coach.
     *
     * @return array{ok:bool,error?:string,conflict?:bool,reason?:string,coach_fee?:float,counter_due?:float,locked?:bool}
     */
    // Named application lock per coach — serialises every assignment attempt for a
    // coach across processes, which is what actually guarantees the hard block. A
    // FOR-UPDATE read on assigned_coach_id can't: while the value doesn't exist yet
    // (NULL → id) concurrent transactions take *shared* gap locks and can both pass
    // the overlap check (verified failing under a parallel race). The named lock is
    // not an InnoDB row lock, so it can't deadlock with the lane lock either.
    private static function coach_lock_name($coach_id) {
        // Hashed to a fixed length well under MySQL's 64-char lock-name limit, so a
        // long DB name can never truncate the coach id (which would collide locks).
        // Namespaced by DB name so parallel sites on one server never share a lock.
        return 'mclb_coach_' . substr(md5(DB_NAME . '|' . (int) $coach_id), 0, 32);
    }
    // Short timeout: the lock is only ever held for a sub-second transaction, so a
    // wait this long means real contention — fail fast as "busy" and let the retry
    // loop (or the user) try again rather than hanging the request.
    private static function acquire_coach_lock($coach_id, $timeout = 3) {
        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::coach_lock_name($coach_id), $timeout));
        return (string) $got === '1';
    }
    private static function release_coach_lock($coach_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::coach_lock_name($coach_id)));
    }

    public static function assign_coach_locked($booking_id, $coach_id, $note = '') {
        $booking_id = (int) $booking_id;
        $coach_id   = (int) $coach_id;

        if ($coach_id <= 0) {
            return self::remove_coach($booking_id, $note);
        }
        if (!MCLB_Coaches::is_bookable($coach_id)) {
            return ['ok' => false, 'error' => 'coach_not_bookable'];
        }
        $rate = MCLB_Coaches::rate($coach_id);
        if ($rate === null) {
            return ['ok' => false, 'error' => 'coach_no_rate'];
        }

        for ($attempt = 1; $attempt <= self::MAX_LOCK_RETRIES; $attempt++) {
            if (!self::acquire_coach_lock($coach_id)) {
                usleep(mt_rand(40, 120) * 1000);
                continue; // coach busy with another assignment — retry
            }
            try {
                $r = self::attempt_assign_coach($booking_id, $coach_id, (float) $rate, (string) $note);
            } finally {
                self::release_coach_lock($coach_id);
            }
            if ($r['ok'] || empty($r['lock_error'])) {
                return $r;
            }
            usleep(mt_rand(40, 120) * 1000);
        }
        return ['ok' => false, 'lock_error' => true];
    }

    private static function attempt_assign_coach($booking_id, $coach_id, $rate, $note) {
        global $wpdb;
        $t   = self::table();
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');

        // Lock + re-read the target booking (guards against a concurrent cancel).
        $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d FOR UPDATE", $booking_id));
        if (self::is_lock_error($wpdb->last_error)) { $wpdb->query('ROLLBACK'); return ['ok' => false, 'lock_error' => true]; }
        if (!$b) { $wpdb->query('ROLLBACK'); return ['ok' => false, 'error' => 'not_found']; }
        if ($b->status !== self::STATUS_CONFIRMED) { $wpdb->query('ROLLBACK'); return ['ok' => false, 'error' => 'not_confirmed']; }

        // Coach double-book guard. Plain read — the caller holds this coach's named
        // lock, so no other writer can assign the coach concurrently.
        $busy = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t
               WHERE assigned_coach_id = %d
                 AND (status = %s OR (status = %s AND hold_expires_at > %s))
                 AND starts_at < %s AND ends_at > %s
                 AND id <> %d
               LIMIT 1",
            $coach_id, self::STATUS_CONFIRMED, self::STATUS_HELD, $now, $b->ends_at, $b->starts_at, $booking_id
        ));
        if ($busy) { $wpdb->query('ROLLBACK'); return ['ok' => false, 'conflict' => true, 'reason' => 'coach']; }

        $paid = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');
        if ($paid) {
            // Financials locked: a swap requires a note (enforced here); the audit
            // line itself is written once by the caller in the standard format.
            if (trim((string) $note) === '') { $wpdb->query('ROLLBACK'); return ['ok' => false, 'error' => 'locked_need_note']; }
            $upd = $wpdb->update($t, ['assigned_coach_id' => $coach_id, 'updated_at' => $now], ['id' => $booking_id]);
            if ($upd === false) {
                $lock = self::is_lock_error($wpdb->last_error);
                $wpdb->query('ROLLBACK');
                return $lock ? ['ok' => false, 'lock_error' => true] : ['ok' => false, 'error' => 'db'];
            }
            $wpdb->query('COMMIT');
            return ['ok' => true, 'locked' => true, 'coach_fee' => (float) $b->coach_fee, 'counter_due' => (float) $b->counter_due];
        }

        // Unpaid → snapshot the rate, (re)compute fee + counter_due.
        $fee         = MCLB_Coaches::fee($rate, $b->starts_at, $b->ends_at);
        $price       = (float) $b->price;
        $counter_due = ($b->source === 'manual') ? round($price + $fee, 2) : round($fee, 2);
        $upd = $wpdb->update($t, [
            'assigned_coach_id' => $coach_id,
            'coach_rate'        => $rate,
            'coach_fee'         => $fee,
            'counter_due'       => $counter_due,
            'updated_at'        => $now,
        ], ['id' => $booking_id]);
        if ($upd === false) {
            $lock = self::is_lock_error($wpdb->last_error);
            $wpdb->query('ROLLBACK');
            return $lock ? ['ok' => false, 'lock_error' => true] : ['ok' => false, 'error' => 'db'];
        }
        $wpdb->query('COMMIT');
        return ['ok' => true, 'coach_fee' => $fee, 'counter_due' => $counter_due];
    }

    /** Remove the coach. Unpaid → recompute counter_due; paid → needs a note, logged. */
    public static function remove_coach($booking_id, $note = '') {
        global $wpdb;
        $t   = self::table();
        $now = current_time('mysql');
        $b   = self::get((int) $booking_id);
        if (!$b) { return ['ok' => false, 'error' => 'not_found']; }
        if ($b->status !== self::STATUS_CONFIRMED) { return ['ok' => false, 'error' => 'not_confirmed']; }

        $paid = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');
        if ($paid) {
            // Requires a note (enforced); the audit line is written once by the caller.
            if (trim((string) $note) === '') { return ['ok' => false, 'error' => 'locked_need_note']; }
            $wpdb->update($t, ['assigned_coach_id' => null, 'updated_at' => $now], ['id' => (int) $booking_id]);
            return ['ok' => true, 'locked' => true];
        }

        $price       = (float) $b->price;
        $counter_due = ($b->source === 'manual') ? round($price, 2) : null;
        $wpdb->update($t, [
            'assigned_coach_id' => null,
            'coach_rate'        => null,
            'coach_fee'         => null,
            'counter_due'       => $counter_due,
            'updated_at'        => $now,
        ], ['id' => (int) $booking_id]);
        return ['ok' => true];
    }

    /** Record (never charge) a counter payment. */
    public static function mark_counter_paid($booking_id, $staff_id = 0) {
        global $wpdb;
        $b = self::get((int) $booking_id);
        if (!$b) { return ['ok' => false, 'error' => 'not_found']; }
        if ($b->counter_due === null) { return ['ok' => false, 'error' => 'nothing_due']; }
        if ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00') { return ['ok' => false, 'error' => 'already_paid']; }
        $wpdb->update(self::table(), [
            'counter_paid_at' => current_time('mysql'),
            'counter_paid_by' => $staff_id ?: get_current_user_id(),
            'updated_at'      => current_time('mysql'),
        ], ['id' => (int) $booking_id]);
        return ['ok' => true];
    }

    /** Reverse a recorded counter payment — requires an admin note, logged. */
    public static function reverse_counter_paid($booking_id, $note) {
        global $wpdb;
        if (trim((string) $note) === '') { return ['ok' => false, 'error' => 'need_note']; }
        $b = self::get((int) $booking_id);
        if (!$b) { return ['ok' => false, 'error' => 'not_found']; }
        if ($b->counter_paid_at === null || $b->counter_paid_at === '0000-00-00 00:00:00') { return ['ok' => false, 'error' => 'not_paid']; }
        // Requires a note (enforced); the audit line is written once by the caller.
        $wpdb->update(self::table(), [
            'counter_paid_at' => null,
            'counter_paid_by' => null,
            'updated_at'      => current_time('mysql'),
        ], ['id' => (int) $booking_id]);
        return ['ok' => true];
    }

    /** Release every held/confirmed row for an order (cancel/refund). */
    public static function cancel_by_order($order_id) {
        global $wpdb;
        return $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s
                  WHERE order_id = %d AND status IN (%s, %s)',
                self::STATUS_CANCELLED,
                current_time('mysql'),
                (int) $order_id,
                self::STATUS_HELD,
                self::STATUS_CONFIRMED
            )
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
