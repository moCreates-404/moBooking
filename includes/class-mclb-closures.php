<?php
/**
 * Blockouts — a custom table (`{prefix}mclb_closures`), not a CPT, because these
 * are range-queried by the availability engine (Phase 2) and gain nothing from
 * the post editor. One table serves both one-off and recurring blockouts via a
 * `kind` column plus nullable date/weekday/time columns.
 *
 * All datetimes/times are LOCAL WALL-CLOCK in the site's timezone (wp_timezone),
 * matching opening-hours and bookings storage. Availability math (Phase 2) must
 * resolve them with DateTimeImmutable + wp_timezone(), never strtotime().
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Closures {

    /** Fully-qualified table name. */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mclb_closures';
    }

    /** dbDelta-formatted CREATE TABLE. `lane_id = 0` means site-wide. */
    public static function schema($charset_collate) {
        $table = self::table();
        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lane_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL DEFAULT 'oneoff',
  starts_at datetime DEFAULT NULL,
  ends_at datetime DEFAULT NULL,
  weekday tinyint(1) DEFAULT NULL,
  start_time time DEFAULT NULL,
  end_time time DEFAULT NULL,
  active_from date DEFAULT NULL,
  active_until date DEFAULT NULL,
  event_type varchar(50) DEFAULT NULL,
  label varchar(191) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lane_id (lane_id),
  KEY weekday (weekday),
  KEY starts_at (starts_at)
) ENGINE=InnoDB {$charset_collate};";
    }

    /**
     * Insert a closure. $data uses the column names above; only the columns
     * relevant to $data['kind'] need be set. Returns the new row id or false.
     */
    public static function insert(array $data) {
        global $wpdb;
        $row = self::prepare($data);
        $row['created_at'] = current_time('mysql');
        $ok = $wpdb->insert(self::table(), $row);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function update($id, array $data) {
        global $wpdb;
        $row = self::prepare($data);
        return $wpdb->update(self::table(), $row, ['id' => (int) $id]) !== false;
    }

    public static function delete($id) {
        global $wpdb;
        // Cascade: a closure's per-date release exceptions go with it (v1.4.1).
        $wpdb->delete(self::exceptions_table(), ['closure_id' => (int) $id], ['%d']);
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']);
    }

    // ── Per-date release exceptions (Phase 1.4.1) ───────────────────────────────

    public static function exceptions_table() {
        global $wpdb;
        return $wpdb->prefix . 'mclb_closure_exceptions';
    }

    /** dbDelta-formatted CREATE TABLE for the release-exceptions. */
    public static function exceptions_schema($charset_collate) {
        $t = self::exceptions_table();
        return "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  closure_id bigint(20) unsigned NOT NULL,
  exception_date date NOT NULL,
  lane_id bigint(20) unsigned NOT NULL DEFAULT 0,
  release_from time DEFAULT NULL,
  actor varchar(20) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY closure_date (closure_id,exception_date),
  KEY exception_date (exception_date)
) ENGINE=InnoDB {$charset_collate};";
    }

    /** Add a release exception. $release_from = null (whole day) or 'HH:MM[:SS]'. */
    public static function add_exception($closure_id, $exception_date, $lane_id, $release_from, $actor) {
        global $wpdb;
        $rf = ($release_from === null || $release_from === '') ? null : substr((string) $release_from, 0, 8);
        $ok = $wpdb->insert(self::exceptions_table(), [
            'closure_id'     => (int) $closure_id,
            'exception_date' => (string) $exception_date,
            'lane_id'        => (int) $lane_id,
            'release_from'   => $rf,
            'actor'          => mb_substr((string) $actor, 0, 20),
            'created_at'     => current_time('mysql'),
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function get_exception($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::exceptions_table() . ' WHERE id = %d', (int) $id));
    }

    public static function delete_exception($id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::exceptions_table(), ['id' => (int) $id], ['%d']);
    }

    /** All release exceptions on $date (for the availability engine + calendar markers). */
    public static function exceptions_for_day($date) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::exceptions_table() . ' WHERE exception_date = %s', (string) $date));
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    /** All closures, newest first — for the admin list. */
    public static function all() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC');
    }

    /**
     * Blockouts that fall on $date, resolved for the admin Manage calendar
     * (Phase 7c). Matching is delegated to MCLB_Availability::closure_window() so
     * it is identical to the public availability engine. lane_id 0 = site-wide
     * (render across every lane column). One-off blockouts are editable in the
     * calendar panel; recurring are read-only there (edit on the Closures screen).
     *
     * @return array<int,array>
     */
    public static function for_day_detail($date) {
        $out  = [];
        $exes = self::exceptions_for_day($date);
        $tz   = wp_timezone();
        $midnight = new DateTimeImmutable($date . ' 00:00:00', $tz);
        foreach (self::all() as $c) {
            $w = MCLB_Availability::closure_window($c, $date);
            if (!$w) {
                continue;
            }
            // Apply release exceptions so the admin calendar shows the effective
            // (shortened/removed) blockout. An exception with lane_id 0 applies to
            // the whole closure; one with lane_id = the closure's own lane applies
            // to this lane-specific closure. (A per-lane release of a SITE-WIDE
            // closure can't be shown as one entry, so it surfaces as a marker only.)
            $start_min = (int) $w['start_min'];
            $end_min   = (int) $w['end_min'];
            $dropped   = false;
            foreach ($exes as $ex) {
                if ((int) $ex->closure_id !== (int) $c->id) { continue; }
                if ((int) $ex->lane_id !== 0 && (int) $ex->lane_id !== (int) $c->lane_id) { continue; }
                if ($ex->release_from === null || $ex->release_from === '') { $dropped = true; break; }
                $cut = self::time_to_min((string) $ex->release_from);
                if ($cut !== null && $cut < $end_min) { $end_min = max($start_min, $cut); }
            }
            if ($dropped || $end_min <= $start_min) { continue; }
            $out[] = [
                'id'         => (int) $c->id,
                'lane_id'    => (int) $c->lane_id,
                'kind'       => $c->kind,
                'event_type' => (string) $c->event_type,
                'note'       => (string) $c->label,
                'colour'     => MCLB_Event_Types::color($c->event_type),
                'type_label' => MCLB_Event_Types::admin_label($c->event_type),
                'editable'   => ($c->kind === 'oneoff'),
                'starts_at'  => $midnight->modify('+' . $start_min . ' minutes')->format('Y-m-d H:i:s'),
                'ends_at'    => $midnight->modify('+' . $end_min . ' minutes')->format('Y-m-d H:i:s'),
                'start_min'  => $start_min,
                'end_min'    => $end_min,
            ];
        }
        return $out;
    }

    /** HH:MM[:SS] → minutes-from-midnight, or null. */
    private static function time_to_min($t) {
        return preg_match('/^(\d{1,2}):(\d{2})/', (string) $t, $m) ? ((int) $m[1] * 60 + (int) $m[2]) : null;
    }

    /** Release markers for the calendar (+ undo), one per exception on $date. */
    public static function released_for_day($date) {
        $out = [];
        foreach (self::exceptions_for_day($date) as $ex) {
            $c = self::get((int) $ex->closure_id);
            $out[] = [
                'id'         => (int) $ex->id,
                'closure_id' => (int) $ex->closure_id,
                'lane_id'    => (int) $ex->lane_id,
                'from'       => $ex->release_from ? substr((string) $ex->release_from, 0, 5) : '',
                'actor'      => (string) $ex->actor,
                'type_label' => $c ? MCLB_Event_Types::admin_label($c->event_type) : __('Blockout', 'mclb-lane-booking'),
            ];
        }
        return $out;
    }

    /**
     * Closures that could affect a given lane (its own + site-wide) — the query
     * the availability engine (Phase 2) will build on.
     *
     * @param int $lane_id
     * @return array
     */
    public static function for_lane($lane_id) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE lane_id = 0 OR lane_id = %d',
                (int) $lane_id
            )
        );
    }

    /**
     * Batch sibling of for_lane() for the grid — one query covering many lanes
     * plus the site-wide (lane_id = 0) rows. Rows carry lane_id so the caller
     * groups them (and fans site-wide rows out to every lane) in PHP.
     *
     * @param int[] $lane_ids
     * @return array
     */
    public static function for_lanes(array $lane_ids) {
        global $wpdb;
        $lane_ids = array_values(array_filter(array_map('intval', $lane_ids)));
        if (empty($lane_ids)) {
            return $wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE lane_id = 0');
        }
        $in = implode(',', array_fill(0, count($lane_ids), '%d'));
        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE lane_id = 0 OR lane_id IN ($in)", $lane_ids)
        );
    }

    /**
     * Normalise/sanitise an input row down to just the columns that matter for
     * its kind, so a one-off never carries stray recurring fields and vice versa.
     */
    private static function prepare(array $data) {
        $kind = (isset($data['kind']) && $data['kind'] === 'recurring') ? 'recurring' : 'oneoff';
        $row  = [
            'lane_id'    => isset($data['lane_id']) ? absint($data['lane_id']) : 0,
            'kind'       => $kind,
            'event_type' => (isset($data['event_type']) && $data['event_type'] !== '') ? sanitize_title((string) $data['event_type']) : null,
            'label'      => isset($data['label']) ? sanitize_text_field($data['label']) : null,
            // Reset both shapes; the relevant branch fills its own below.
            'starts_at'    => null,
            'ends_at'      => null,
            'weekday'      => null,
            'start_time'   => null,
            'end_time'     => null,
            'active_from'  => null,
            'active_until' => null,
        ];

        if ($kind === 'oneoff') {
            $row['starts_at'] = self::datetime_or_null($data['starts_at'] ?? null);
            $row['ends_at']   = self::datetime_or_null($data['ends_at'] ?? null);
        } else {
            $wd = isset($data['weekday']) ? absint($data['weekday']) : 0;
            $row['weekday']      = ($wd >= 1 && $wd <= 7) ? $wd : null;
            $row['start_time']   = self::time_or_null($data['start_time'] ?? null);
            $row['end_time']     = self::time_or_null($data['end_time'] ?? null);
            $row['active_from']  = self::date_or_null($data['active_from'] ?? null);
            $row['active_until'] = self::date_or_null($data['active_until'] ?? null);
        }

        return $row;
    }

    private static function datetime_or_null($v) {
        $v = trim((string) $v);
        // Accept "Y-m-d H:i" or "Y-m-d H:i:s"; normalise to full seconds.
        if (preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            return $v . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $v)) {
            return $v;
        }
        return null;
    }

    private static function date_or_null($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private static function time_or_null($v) {
        $v = trim((string) $v);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            return $v . ':00';
        }
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $v) ? $v : null;
    }
}
