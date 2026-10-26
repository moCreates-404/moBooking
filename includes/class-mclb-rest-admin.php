<?php
/**
 * Admin REST controllers (Phase 7c) — mclb/v1/admin/*. Thin: every write calls an
 * existing gateway (insert_confirmed_locked, assign_coach_locked, remove_coach,
 * mark/reverse_counter_paid, claim_cancel, MCLB_Refunds, MCLB_Closures) so there
 * is no duplicated business logic. The server is the sole authority on price and
 * availability; the client's price preview is advisory.
 *
 * Auth: permission_callback requires the capability; core's cookie-nonce check
 * (rest_cookie_invalid_nonce → 403) runs before it, so an expired nonce surfaces
 * to the client for refresh-and-retry. Logged-out → 401, customer → 403.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_REST_Admin {

    const NS = 'mclb/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    public static function register() {
        $manage = [__CLASS__, 'can_manage'];

        register_rest_route(self::NS, '/admin/day', [
            'methods'             => 'GET',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'day'],
            'args'                => [
                'date'           => ['required' => true],
                'show_cancelled' => ['required' => false],
            ],
        ]);
        register_rest_route(self::NS, '/admin/coaches', [
            'methods'             => 'GET',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'coaches'],
        ]);
        register_rest_route(self::NS, '/admin/booking', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'create_booking'],
        ]);
        register_rest_route(self::NS, '/admin/series-preview', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'series_preview'],
        ]);
        register_rest_route(self::NS, '/admin/series', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'create_series'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/cancel-series', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'cancel_series'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/coach', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_coach'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/counter', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_counter'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_cancel'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/details', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_details'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/price', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_price'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/coach-fee', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_coach_fee'],
        ]);
        register_rest_route(self::NS, '/admin/closure/(?P<id>\d+)/release', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'closure_release'],
        ]);
        register_rest_route(self::NS, '/admin/closure-exception/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'closure_exception_undo'],
        ]);
        // Prepaid passes (v1.5.0) — staff (mclb_manage_bookings) can issue, look up,
        // void/reissue, and draw a pass onto a booking. Adjust is admin-only (wp-admin).
        register_rest_route(self::NS, '/admin/pass/issue', [
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'pass_issue'],
        ]);
        register_rest_route(self::NS, '/admin/pass/lookup', [
            'methods' => 'GET', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'pass_lookup'],
        ]);
        register_rest_route(self::NS, '/admin/pass/(?P<id>\d+)/void', [
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'pass_void'],
        ]);
        register_rest_route(self::NS, '/admin/pass/(?P<id>\d+)/reissue', [
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'pass_reissue'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/pass', [
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'booking_pass'],
        ]);
        // Coach roster week view + "send day sheets to all" (v1.5.1).
        register_rest_route(self::NS, '/admin/week', [
            'methods' => 'GET', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'week'],
        ]);
        register_rest_route(self::NS, '/admin/daysheets/all', [
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => [__CLASS__, 'daysheets_all'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/move', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_move'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/move-preview', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_move_preview'],
        ]);
        register_rest_route(self::NS, '/admin/booking/(?P<id>\d+)/resend-confirmation', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'booking_resend_confirmation'],
        ]);
        register_rest_route(self::NS, '/admin/daysheet', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'send_daysheet'],
        ]);
        register_rest_route(self::NS, '/admin/blockout', [
            'methods'             => 'POST',
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'create_blockout'],
        ]);
        register_rest_route(self::NS, '/admin/blockout/(?P<id>\d+)', [
            'methods'             => ['POST', 'DELETE'],
            'permission_callback' => $manage,
            'callback'            => [__CLASS__, 'blockout'],
        ]);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public static function can_manage() {
        if (!is_user_logged_in()) {
            return new WP_Error('mclb_unauth', __('Please log in.', 'mclb-lane-booking'), ['status' => 401]);
        }
        if (!current_user_can(MCLB_Caps::MANAGE)) {
            return new WP_Error('mclb_forbidden', __('You don’t have access to the booking manager.', 'mclb-lane-booking'), ['status' => 403]);
        }
        return true;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Required staff initials, trimmed/sanitised/≤20, or null when missing. */
    private static function actor($request) {
        $a = sanitize_text_field((string) $request->get_param('actor'));
        $a = trim($a);
        return $a === '' ? null : mb_substr($a, 0, 20);
    }

    private static function need_initials() {
        return new WP_Error('mclb_need_initials', __('Staff initials are required for this action.', 'mclb-lane-booking'), ['status' => 400]);
    }

    /** Timestamped audit line in the agreed format: "[Y-m-d H:i] XX: action". */
    private static function log($booking_id, $actor, $action) {
        MCLB_Bookings::append_admin_note($booking_id, sprintf('[%s] %s: %s', current_time('Y-m-d H:i'), $actor, $action));
    }

    /** Minutes-from-midnight for a datetime on $date, clamped to [0,1440]. */
    private static function min_of_day($datetime, DateTimeImmutable $midnight, DateTimeZone $tz) {
        $m = (int) floor(((new DateTimeImmutable((string) $datetime, $tz))->getTimestamp() - $midnight->getTimestamp()) / 60);
        return max(0, min(1440, $m));
    }

    private static function hm_to_min($hm) {
        return preg_match('/^(\d{1,2}):(\d{2})/', (string) $hm, $m) ? ((int) $m[1] * 60 + (int) $m[2]) : null;
    }

    private static function money($n) {
        return MCLB_Coaches::money((float) $n);
    }

    // ── GET /admin/day ──────────────────────────────────────────────────────────

    public static function day($request) {
        $date = self::sanitize_date((string) $request->get_param('date'));
        if ($date === '') {
            return new WP_Error('mclb_bad_date', __('Invalid date.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $tz        = wp_timezone();
        $midnight  = new DateTimeImmutable($date . ' 00:00:00', $tz);
        $weekday   = (int) $midnight->format('N');
        $increment = (int) MCLB_Settings::get('booking_increment') ?: 60;

        // Lanes grouped by type, each with the date's open window + resolved rate.
        $lanes_posts = MCLB_Lane::all_bookable();
        $types       = [];
        $type_order  = [];
        $min_open    = null;
        $max_close   = null;
        foreach ($lanes_posts as $post) {
            $id    = (int) $post->ID;
            $type  = MCLB_Lane::get_type($id) ?: '';
            $hours = MCLB_Lane::get_hours($id);
            $h     = isset($hours[$weekday]) && is_array($hours[$weekday]) ? $hours[$weekday] : null;
            $open  = ($h && empty($h['closed'])) ? self::hm_to_min($h['open']) : null;
            $close = ($h && empty($h['closed'])) ? self::hm_to_min($h['close']) : null;
            if ($open !== null && $close !== null && $close > $open) {
                $min_open  = ($min_open === null) ? $open : min($min_open, $open);
                $max_close = ($max_close === null) ? $close : max($max_close, $close);
            }
            if (!isset($types[$type])) {
                $types[$type] = [];
                $type_order[] = $type;
            }
            $types[$type][] = [
                'id'             => $id,
                'name'           => get_the_title($id),
                'price_per_hour' => (float) MCLB_Lane::get_price($id),
                'open_min'       => $open,
                'close_min'      => $close,
            ];
        }
        $grouped = [];
        foreach ($type_order as $t) {
            $grouped[] = ['type' => $t, 'lanes' => $types[$t]];
        }

        // Bookings → spanning blocks. Always include cancelled rows so the
        // "to collect" strip can show refund-at-counter items; the client hides the
        // ghosted cancelled BLOCKS unless "show cancelled" is on.
        $bookings = [];
        foreach (MCLB_Bookings::for_day_admin($date, true) as $b) {
            $bookings[] = self::block_payload($b, $midnight, $tz);
        }
        // Series position (N of M) for any booking in a series — one extra query.
        $series_ids = array_values(array_filter(array_map(function ($r) { return (int) $r['series_id']; }, $bookings)));
        if (!empty($series_ids)) {
            $pos = MCLB_Bookings::series_positions($series_ids);
            foreach ($bookings as &$row) {
                if ($row['series_id'] && isset($pos[$row['id']])) {
                    $row['series_n'] = $pos[$row['id']][0];
                    $row['series_m'] = $pos[$row['id']][1];
                }
            }
            unset($row);
        }

        // Blockouts (one-off + recurring resolved for this date).
        $blockouts = MCLB_Closures::for_day_detail($date);

        // Coaches (all bookable, with rate) for chips + the filter dropdown, plus
        // each coach's day-sheet status for this date (sent / changed-since-sent).
        $coaches = [];
        $seen    = [];
        foreach (MCLB_Coaches::options() as $cid => $name) {
            $st        = MCLB_Daysheets::status((int) $cid, $date);
            $coaches[] = [
                'id'            => (int) $cid,
                'name'          => $name,
                'rate'          => MCLB_Coaches::rate((int) $cid),
                'sheet_sent_at' => $st['sent_at'],
                'sheet_changed' => $st['changed'],
            ];
            $seen[(int) $cid] = true;
        }
        // Roster fix (a): a coach unticked as bookable / removed from the CPT who
        // still holds a session must still get a row, so no session disappears.
        foreach ($bookings as $bk) {
            $cid = (int) $bk['coach_id'];
            if ($cid && empty($seen[$cid]) && !$bk['cancelled'] && !$bk['held']) {
                $st        = MCLB_Daysheets::status($cid, $date);
                $coaches[] = [
                    'id'            => $cid,
                    'name'          => MCLB_Coaches::label($cid), // fallback name for unlisted/removed
                    'rate'          => MCLB_Coaches::rate($cid),
                    'sheet_sent_at' => $st['sent_at'],
                    'sheet_changed' => $st['changed'],
                    'unlisted'      => true,
                ];
                $seen[$cid] = true;
            }
        }

        return rest_ensure_response([
            'date'       => $date,
            'date_label' => MCLB_Settings::friendly_date($midnight->getTimestamp()),
            'now'        => current_time('mysql'),
            'increment' => $increment,
            'names_mode' => MCLB_Settings::get('daysheet_names') ?: 'full', // roster PRINT uses this (screen shows full)
            'grid'      => ['open_min' => $min_open, 'close_min' => $max_close],
            'types'     => $grouped,
            'bookings'  => $bookings,
            'blockouts' => $blockouts,
            'released'  => MCLB_Closures::released_for_day($date), // v1.4.1 release markers
            'coaches'   => $coaches,
            'canRefund' => current_user_can(MCLB_Caps::REFUND),
        ]);
    }

    /**
     * Build one booking's calendar-block payload (shared by the day + week views so
     * the lane grid and the coach roster render identical blocks). Minutes-of-day
     * are relative to $midnight (the day the block belongs to).
     */
    private static function block_payload($b, DateTimeImmutable $midnight, DateTimeZone $tz) {
        $held      = ($b->status === MCLB_Bookings::STATUS_HELD);
        $cancelled = ($b->status === MCLB_Bookings::STATUS_CANCELLED);
        $paid      = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');
        return [
            'id'          => (int) $b->id,
            'lane_id'     => (int) $b->lane_id,
            'lane_name'   => (string) $b->lane_name,
            'starts_at'   => $b->starts_at,
            'ends_at'     => $b->ends_at,
            'start_min'   => self::min_of_day($b->starts_at, $midnight, $tz),
            'end_min'     => self::min_of_day($b->ends_at, $midnight, $tz),
            'status'      => $b->status,
            'held'        => $held,
            'cancelled'   => $cancelled,
            'source'      => (string) $b->source,
            'created_label' => $b->created_at ? (MCLB_Settings::friendly_date_short((new DateTimeImmutable($b->created_at, $tz))->getTimestamp()) . ', ' . wp_date(get_option('time_format') ?: 'g:i a', (new DateTimeImmutable($b->created_at, $tz))->getTimestamp())) : '',
            'created_by'    => (string) ($b->created_by !== null && $b->created_by !== '' ? $b->created_by : MCLB_Bookings::creation_initials($b->admin_note)),
            'coupons'       => ($b->coupons !== null && $b->coupons !== '') ? json_decode($b->coupons, true) : null,
            'customer'    => trim((string) $b->customer_name),
            'email'       => (string) $b->customer_email,
            'phone'       => $b->customer_phone ? MCLB_Phone::format_au($b->customer_phone) : '',
            'phone_raw'   => (string) $b->customer_phone,
            'coach_id'    => $b->assigned_coach_id ? (int) $b->assigned_coach_id : 0,
            'coach_label' => $b->assigned_coach_id ? MCLB_Coaches::label((int) $b->assigned_coach_id) : '',
            'coach_start_min' => ($b->assigned_coach_id && $b->coach_starts_at) ? self::min_of_day($b->coach_starts_at, $midnight, $tz) : null,
            'coach_end_min'   => ($b->assigned_coach_id && $b->coach_ends_at) ? self::min_of_day($b->coach_ends_at, $midnight, $tz) : null,
            'coach_starts_at' => $b->coach_starts_at ?: '',
            'coach_ends_at'   => $b->coach_ends_at ?: '',
            'coach_partial'   => (bool) ($b->assigned_coach_id && $b->coach_starts_at),
            'price'       => $b->price !== null ? (float) $b->price : null,
            'price_original' => $b->price_original !== null ? (float) $b->price_original : null,
            'coach_fee'   => $b->coach_fee !== null ? (float) $b->coach_fee : null,
            'coach_fee_original' => $b->coach_fee_original !== null ? (float) $b->coach_fee_original : null,
            'pass_hours'   => $b->pass_hours !== null ? (float) $b->pass_hours : null,
            'pass_code'    => ($b->pass_id && class_exists('MCLB_Passes') && ($pp = MCLB_Passes::get((int) $b->pass_id))) ? $pp->code : '',
            'pass_balance' => ($b->pass_id && class_exists('MCLB_Passes')) ? MCLB_Passes::balance((int) $b->pass_id) : null,
            'counter_due' => $b->counter_due !== null ? (float) $b->counter_due : null,
            'counter_paid'=> $paid,
            'order_id'    => $b->order_id ? (int) $b->order_id : 0,
            'note'        => (string) $b->admin_note,
            'staff_note'  => (string) $b->staff_note,
            'badges'      => $held ? [] : self::badges($b, $paid, $cancelled),
            'refundable'  => (!$held && !$cancelled && (string) $b->source === 'online' && $b->order_id),
            'series_id'   => $b->series_id ? (int) $b->series_id : 0,
        ];
    }

    /**
     * Week roster (v1.5.1): Mon–Sun from the given start date, ONE range query for
     * confirmed coach-assigned bookings, grouped per day → coach. Returns per-day
     * blocks + per-coach day totals (hours + fees) + the week totals, plus the
     * names of coaches with no sessions that day (for the collapsed line). Sheet
     * status is computed from the in-memory rows (no per-day/coach queries).
     */
    public static function week($request) {
        global $wpdb;
        $tz    = wp_timezone();
        $start = self::sanitize_date((string) $request->get_param('start'));
        if ($start === '') { $start = wp_date('Y-m-d'); }
        $d0     = new DateTimeImmutable($start . ' 00:00:00', $tz);
        $monday = $d0->modify('-' . ((int) $d0->format('N') - 1) . ' days'); // ISO Monday
        $wStart = $monday->format('Y-m-d H:i:s');
        $wEnd   = $monday->modify('+7 days')->format('Y-m-d H:i:s');

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . MCLB_Bookings::table() . ' WHERE status = %s AND assigned_coach_id IS NOT NULL AND starts_at < %s AND ends_at > %s ORDER BY starts_at, id',
            MCLB_Bookings::STATUS_CONFIRMED, $wEnd, $wStart
        ));
        $series_ids = array_values(array_filter(array_map(function ($r) { return (int) $r->series_id; }, $rows)));
        $pos        = $series_ids ? MCLB_Bookings::series_positions($series_ids) : [];

        // Group rows by day → coach.
        $byDay = [];
        foreach ($rows as $r) { $byDay[substr((string) $r->starts_at, 0, 10)][(int) $r->assigned_coach_id][] = $r; }

        $bookable = MCLB_Coaches::options(); // id => name (currently bookable)
        $win      = self::roster_week_window($monday);
        $weekTot  = []; // id => ['name','hours','fees','has_rate']

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day      = $monday->modify('+' . $i . ' days');
            $date     = $day->format('Y-m-d');
            $midnight = new DateTimeImmutable($date . ' 00:00:00', $tz);
            $dayCoach = isset($byDay[$date]) ? $byDay[$date] : [];

            $blocks = [];
            foreach ($dayCoach as $cid => $crows) {
                foreach ($crows as $r) {
                    $blk = self::block_payload($r, $midnight, $tz);
                    if ($blk['series_id'] && isset($pos[$blk['id']])) { $blk['series_n'] = $pos[$blk['id']][0]; $blk['series_m'] = $pos[$blk['id']][1]; }
                    $blocks[] = $blk;
                }
            }

            // Coach rows: bookable ∪ any coach with sessions today (fix a).
            $ids = array_values(array_unique(array_merge(array_map('intval', array_keys($bookable)), array_map('intval', array_keys($dayCoach)))));
            $coaches = [];
            $none    = [];
            foreach ($ids as $cid) {
                $name = isset($bookable[$cid]) ? $bookable[$cid] : MCLB_Coaches::label($cid);
                $rate = MCLB_Coaches::rate($cid);
                $crows = isset($dayCoach[$cid]) ? $dayCoach[$cid] : [];
                if (empty($crows)) { $none[] = $name; continue; }
                $hours = 0.0; $fees = 0.0;
                foreach ($crows as $r) {
                    list($cs, $ce) = MCLB_Bookings::coach_window($r->starts_at, $r->ends_at, $r->coach_starts_at, $r->coach_ends_at);
                    $hours += MCLB_Bookings::hours_between($cs, $ce);
                    if ($r->coach_fee !== null) { $fees += (float) $r->coach_fee; }
                }
                $st = MCLB_Daysheets::status_rows($cid, $date, $crows);
                $coaches[] = [
                    'id' => $cid, 'name' => $name, 'rate' => $rate, 'has_rate' => ($rate !== null),
                    'hours' => round($hours, 2), 'fees' => round($fees, 2),
                    'sheet_sent_at' => $st['sent_at'], 'sheet_changed' => $st['changed'],
                    'unlisted' => !isset($bookable[$cid]),
                ];
                if (!isset($weekTot[$cid])) { $weekTot[$cid] = ['id' => $cid, 'name' => $name, 'hours' => 0.0, 'fees' => 0.0, 'has_rate' => ($rate !== null)]; }
                $weekTot[$cid]['hours'] += $hours;
                $weekTot[$cid]['fees']  += $fees;
            }
            $days[] = [
                'date'     => $date,
                'label'    => wp_date('l, j F Y', $midnight->getTimestamp()),
                'bookings' => $blocks,
                'coaches'  => $coaches,
                'none'     => $none,
            ];
        }
        foreach ($weekTot as &$wt) { $wt['hours'] = round($wt['hours'], 2); $wt['fees'] = round($wt['fees'], 2); }
        unset($wt);

        return rest_ensure_response([
            'start'       => $monday->format('Y-m-d'),
            'start_label' => MCLB_Settings::friendly_date_short($monday->getTimestamp()),
            'now'         => current_time('mysql'),
            'increment'   => (int) MCLB_Settings::get('booking_increment') ?: 60,
            'names_mode'  => MCLB_Settings::get('daysheet_names') ?: 'full',
            'grid'        => ['open_min' => $win[0], 'close_min' => $win[1]],
            'days'        => $days,
            'week_totals' => array_values($weekTot),
            'canRefund'   => current_user_can(MCLB_Caps::REFUND),
        ]);
    }

    /** Union of opening windows across the week's weekdays (minutes), for the roster scale. */
    private static function roster_week_window(DateTimeImmutable $monday) {
        $hours = (array) MCLB_Settings::get('hours');
        $open = null; $close = null;
        for ($i = 0; $i < 7; $i++) {
            $wd  = (int) $monday->modify('+' . $i . ' days')->format('N');
            $row = isset($hours[$wd]) && is_array($hours[$wd]) ? $hours[$wd] : null;
            if (!$row || !empty($row['closed'])) { continue; }
            $o = self::hhmm_to_min($row['open'] ?? ''); $c = self::hhmm_to_min($row['close'] ?? '');
            if ($o === null || $c === null || $c <= $o) { continue; }
            $open  = ($open === null) ? $o : min($open, $o);
            $close = ($close === null) ? $c : max($close, $c);
        }
        if ($open === null) { return [540, 1020]; } // 9:00–17:00 fallback
        return [$open, $close];
    }

    private static function hhmm_to_min($t) {
        return preg_match('/^(\d{1,2}):(\d{2})/', (string) $t, $m) ? ((int) $m[1] * 60 + (int) $m[2]) : null;
    }

    /**
     * "Send day sheets to all" (v1.5.1): sends each coach-with-sessions their sheet
     * for $date, guarded by a short per-date transient against double-sends, and
     * reports sent / skipped (no sessions) / no-email (sessions but no contact).
     */
    public static function daysheets_all($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $date = self::sanitize_date((string) $request->get_param('date'));
        if ($date === '') { return new WP_Error('mclb_invalid', __('Pick a valid date.', 'mclb-lane-booking'), ['status' => 400]); }

        $lock = 'mclb_sendall_' . $date;
        if (get_transient($lock)) {
            return rest_ensure_response(['ok' => false, 'busy' => true, 'message' => __('Day sheets for that date are already being sent — please wait a moment.', 'mclb-lane-booking')]);
        }
        set_transient($lock, 1, 30); // blocks a second click for 30s

        $withSessions = MCLB_Daysheets::coaches_with_sessions($date); // id => rows
        $bookable     = MCLB_Coaches::options();
        $reroute      = (MCLB_Mail::test_recipient() !== '');
        $sent = 0; $failed = 0; $no_email = [];
        foreach ($withSessions as $cid => $rows) {
            $name = isset($bookable[$cid]) ? $bookable[$cid] : MCLB_Coaches::label((int) $cid);
            if (!$reroute && MCLB_Coaches::contact((int) $cid) === '') { $no_email[] = $name; continue; }
            $r = MCLB_Daysheets::send_for_coach((int) $cid, $date, 'manual-all:' . $actor);
            if (!empty($r['ok'])) { $sent++; } else { $failed++; }
        }
        $skipped = max(0, count($bookable) - count($withSessions)); // coaches with no sessions
        return rest_ensure_response(['ok' => true, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'no_email' => $no_email]);
    }

    /** Payment badges for a booking block. */
    private static function badges($b, $paid, $cancelled) {
        $out = [];
        if ((string) $b->source === 'online' && $b->status === MCLB_Bookings::STATUS_CONFIRMED) {
            $out[] = ['kind' => 'paid_online', 'label' => __('Paid online', 'mclb-lane-booking')];
        }
        if ($b->counter_due !== null && (float) $b->counter_due > 0) {
            if ($cancelled && $paid) {
                /* translators: %s: amount. */
                $out[] = ['kind' => 'refund', 'label' => sprintf(__('Refund at counter %s', 'mclb-lane-booking'), self::money($b->counter_due))];
            } elseif ($paid) {
                $out[] = ['kind' => 'counter_paid', 'label' => __('Counter paid', 'mclb-lane-booking')];
            } else {
                /* translators: %s: amount. */
                $out[] = ['kind' => 'due', 'label' => sprintf(__('Due %s', 'mclb-lane-booking'), self::money($b->counter_due))];
            }
        }
        return $out;
    }

    // ── GET /admin/coaches (free for a slot) ──────────────────────────────────

    public static function coaches($request) {
        $date  = self::sanitize_date((string) $request->get_param('date'));
        $start = (string) $request->get_param('start');
        $end   = (string) $request->get_param('end');
        $exclude = (int) $request->get_param('exclude');
        if ($date === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
            return new WP_Error('mclb_bad_range', __('Invalid range.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $starts_at = "$date $start:00";
        $ends_at   = "$date $end:00";
        // Partial coach time (v1.4.1): free-list checks the chosen coach window.
        $cstart = (string) $request->get_param('coach_start');
        $cend   = (string) $request->get_param('coach_end');
        $ccs = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cstart) ? "$date $cstart:00" : null;
        $cce = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cend) ? "$date $cend:00" : null;
        $out = [];
        foreach (MCLB_Coaches::available_options($starts_at, $ends_at, $exclude, $ccs, $cce) as $cid => $name) {
            $rate = MCLB_Coaches::rate((int) $cid);
            if ($rate === null) {
                continue; // can't be booked without a rate
            }
            $out[] = ['id' => (int) $cid, 'name' => $name, 'rate' => (float) $rate];
        }
        return rest_ensure_response(['coaches' => $out]);
    }

    /**
     * Parse + validate a coach sub-range from coach_start/coach_end params (HH:MM)
     * against the booking (v1.4.1). Returns [cs,ce] datetimes, [null,null] for the
     * whole booking, or a WP_Error. Server-side guard — the UI's clamp is advisory.
     */
    private static function coach_range_param($request, $date, $starts_at, $ends_at) {
        $cs = (string) $request->get_param('coach_start');
        $ce = (string) $request->get_param('coach_end');
        if ($cs === '' && $ce === '') { return [null, null]; }
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!preg_match($re, $cs) || !preg_match($re, $ce)) {
            return new WP_Error('mclb_coach_time', __('Please check the coach start and end times.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $csd = "$date $cs:00";
        $ced = "$date $ce:00";
        if (!MCLB_Bookings::validate_coach_time($starts_at, $ends_at, $csd, $ced)) {
            return new WP_Error('mclb_coach_time', __('Coach time must sit within the booking and on the booking increment.', 'mclb-lane-booking'), ['status' => 400]);
        }
        return [$csd, $ced];
    }

    // ── POST /admin/booking (manual create) ───────────────────────────────────

    public static function create_booking($request) {
        if (!MCLB_License::can_book()) {
            return new WP_Error('mclb_demo', MCLB_License::demo_message(), ['status' => 403]);
        }
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $lane_id  = (int) $request->get_param('lane_id');
        $date     = self::sanitize_date((string) $request->get_param('date'));
        $start    = (string) $request->get_param('start');
        $end      = (string) $request->get_param('end');
        $override = filter_var($request->get_param('override'), FILTER_VALIDATE_BOOLEAN);
        $coach_id = (int) $request->get_param('coach_id');
        $name     = sanitize_text_field((string) $request->get_param('name'));
        $email    = sanitize_email((string) $request->get_param('email'));
        $phone    = MCLB_Phone::clean((string) $request->get_param('phone'));
        $note     = sanitize_textarea_field((string) $request->get_param('note')); // → staff_note

        // Phone is required on a manual booking (loose validation: 8+ digits).
        if (!MCLB_Phone::valid($phone)) {
            return new WP_Error('mclb_bad_phone', __('A valid phone number is required.', 'mclb-lane-booking'), ['status' => 400]);
        }

        $lane = get_post($lane_id);
        $re   = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!$lane || $lane->post_type !== 'mclb_lane' || $date === '' || !preg_match($re, $start) || !preg_match($re, $end)) {
            return new WP_Error('mclb_invalid', __('Please check the lane, date and times.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $starts_at = "$date $start:00";
        $ends_at   = "$date $end:00";
        if ($ends_at <= $starts_at) {
            return new WP_Error('mclb_invalid', __('The end time must be after the start.', 'mclb-lane-booking'), ['status' => 400]);
        }
        // Override gate covers hours/closures/past only; a booking clash is left to
        // the FOR-UPDATE insert below so it surfaces cleanly as "slot taken".
        if (!$override && !MCLB_Availability::is_range_bookable_time($lane_id, $starts_at, $ends_at)) {
            return new WP_Error('mclb_unavailable', __('That time is outside opening hours or during a blockout. Use override to book it anyway.', 'mclb-lane-booking'), ['status' => 409]);
        }

        $lane_price = MCLB_Bookings::lane_price($lane_id, $starts_at, $ends_at); // ONE pricing seam

        // Optional lane-price override at creation (reason required when it changes
        // the price). coach_fee is never overridden; counter_due = price + fee is
        // recomputed inside insert_confirmed_locked from the price we pass.
        $price          = $lane_price;
        $price_original = null;
        $reason         = sanitize_text_field((string) $request->get_param('price_reason'));
        $override_in    = $request->get_param('override_price');
        if ($override_in !== null && $override_in !== '') {
            if (!is_numeric($override_in) || (float) $override_in < 0) {
                return new WP_Error('mclb_bad_price', __('Enter a valid price (0 or more).', 'mclb-lane-booking'), ['status' => 400]);
            }
            $op = round((float) $override_in, 2);
            if (abs($op - $lane_price) > 0.001) {
                if ($reason === '') {
                    return new WP_Error('mclb_need_reason', __('A reason is required to override the price.', 'mclb-lane-booking'), ['status' => 400]);
                }
                $price          = $op;
                $price_original = $lane_price;
            }
        }

        // Partial coach time (v1.4.1): a coach sub-range within the booking.
        $cr = self::coach_range_param($request, $date, $starts_at, $ends_at);
        if (is_wp_error($cr)) { return $cr; }

        $res = MCLB_Bookings::insert_confirmed_locked([
            'lane_id'         => $lane_id,
            'lane_name'       => get_the_title($lane_id),
            'starts_at'       => $starts_at,
            'ends_at'         => $ends_at,
            'price'           => $price,
            'price_original'  => $price_original,
            'customer_name'   => $name,
            'customer_email'  => $email,
            'customer_phone'  => $phone,
            'staff_note'      => $note,
            'coach_id'        => $coach_id,
            'coach_starts_at' => $coach_id > 0 ? $cr[0] : null,
            'coach_ends_at'   => $coach_id > 0 ? $cr[1] : null,
            'created_by'      => $actor, // staff initials (v1.4.2)
        ]);
        if (empty($res['ok'])) {
            return self::write_error($res);
        }
        $id = (int) $res['id'];
        $coach_hours = ($coach_id > 0 && $cr[0] !== null && $cr[1] !== null)
            ? ' ' . self::time_label($cr[0]) . '–' . self::time_label($cr[1]) : '';
        $summary = $coach_id > 0
            ? sprintf(__('created booking with coach %s', 'mclb-lane-booking'), MCLB_Coaches::label($coach_id)) . $coach_hours
            : __('created booking', 'mclb-lane-booking');
        self::log($id, $actor, $summary);
        // Book-over (v1.4.1): a manual booking placed over a blockout records the
        // reason in History only — never in staff_note (per the approved spec).
        $book_over = sanitize_text_field((string) $request->get_param('book_over_reason'));
        if ($book_over !== '') {
            /* translators: %s: staff reason for booking over a blockout. */
            self::log($id, $actor, sprintf(__('booked over blockout: %s', 'mclb-lane-booking'), $book_over));
        }
        if ($price_original !== null) {
            /* translators: 1: original price, 2: new price, 3: reason. */
            self::log($id, $actor, sprintf(__('price %1$s → %2$s (%3$s)', 'mclb-lane-booking'), self::money($price_original), self::money($price), $reason));
        }
        // Prepaid pass (v1.5.0): draw whole lane hours onto this new manual booking.
        $pass_code = MCLB_Pass_Code::normalise((string) $request->get_param('pass_code'));
        if ($pass_code !== '' && class_exists('MCLB_Passes')) {
            $pd = self::apply_pass_draw($id, $pass_code, $actor);
            if (!is_wp_error($pd)) {
                self::log($id, $actor, sprintf(__('drew %1$sh from pass %2$s', 'mclb-lane-booking'), rtrim(rtrim(number_format((float) $pd['hours'], 2), '0'), '.'), $pd['code']));
            }
        }
        // Customer confirmation (if enabled + an email is on file) + optional staff alert (8a-2).
        $emailed = false;
        if (class_exists('MCLB_Emails')) {
            $r = MCLB_Emails::send_manual_confirmation($id);
            $emailed = !empty($r['ok']);
            MCLB_Emails::alert_manual_created($id);
        }
        return rest_ensure_response(['ok' => true, 'id' => $id, 'emailed' => $emailed, 'has_email' => $email !== '']);
    }

    // ── Recurring series (Phase 1.4.0) ──────────────────────────────────────────

    /** Parse + validate the series params. Returns the shared data + the slot list, or a WP_Error. */
    private static function parse_series($request) {
        $lane_id = (int) $request->get_param('lane_id');
        $date    = self::sanitize_date((string) $request->get_param('date'));
        $start   = (string) $request->get_param('start');
        $end     = (string) $request->get_param('end');
        $repeat  = ($request->get_param('repeat') === 'fortnightly') ? 'fortnightly' : 'weekly';
        $mode    = ($request->get_param('mode') === 'until') ? 'until' : 'count';
        $re      = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $lane    = get_post($lane_id);
        if (!$lane || $lane->post_type !== 'mclb_lane' || $date === '' || !preg_match($re, $start) || !preg_match($re, $end) || $end <= $start) {
            return new WP_Error('mclb_invalid', __('Please check the lane, date and times.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $interval = ($repeat === 'fortnightly') ? 14 : 7;
        $tz  = wp_timezone();
        $cur = new DateTimeImmutable("$date $start:00", $tz);

        $slots = [];
        if ($mode === 'until') {
            $until = self::sanitize_date((string) $request->get_param('until'));
            if ($until === '' || $until < $date) {
                return new WP_Error('mclb_invalid', __('Choose an “until” date on or after the first date.', 'mclb-lane-booking'), ['status' => 400]);
            }
            while ($cur->format('Y-m-d') <= $until && count($slots) < 52) {
                $d = $cur->format('Y-m-d');
                $slots[] = ['starts_at' => "$d $start:00", 'ends_at' => "$d $end:00"];
                $cur = $cur->modify("+{$interval} days");
            }
        } else {
            $count = max(1, min(52, (int) $request->get_param('count')));
            for ($i = 0; $i < $count; $i++) {
                $d = $cur->format('Y-m-d');
                $slots[] = ['starts_at' => "$d $start:00", 'ends_at' => "$d $end:00"];
                $cur = $cur->modify("+{$interval} days");
            }
        }
        if (empty($slots)) {
            return new WP_Error('mclb_invalid', __('That repeat produced no dates.', 'mclb-lane-booking'), ['status' => 400]);
        }

        // Partial coach time (v1.4.1): one coach sub-range per series, applied to
        // every occurrence by time-of-day. Validate once against the first slot.
        $coach_id = (int) $request->get_param('coach_id');
        $cstart   = (string) $request->get_param('coach_start');
        $cend     = (string) $request->get_param('coach_end');
        $coach_time = ($coach_id > 0 && preg_match($re, $cstart) && preg_match($re, $cend));
        if ($coach_time && !MCLB_Bookings::validate_coach_time($slots[0]['starts_at'], $slots[0]['ends_at'], substr($slots[0]['starts_at'], 0, 10) . " $cstart:00", substr($slots[0]['starts_at'], 0, 10) . " $cend:00")) {
            return new WP_Error('mclb_coach_time', __('Coach time must sit within the session and on the booking increment.', 'mclb-lane-booking'), ['status' => 400]);
        }
        if ($coach_time) {
            foreach ($slots as &$slot) {
                $d = substr($slot['starts_at'], 0, 10);
                $slot['coach_starts_at'] = "$d $cstart:00";
                $slot['coach_ends_at']   = "$d $cend:00";
            }
            unset($slot);
        }

        $price = MCLB_Bookings::lane_price($lane_id, "$date $start:00", "$date $end:00"); // ONE pricing seam
        return [
            'lane_id'    => $lane_id,
            'lane_name'  => get_the_title($lane_id),
            'start'      => $start,
            'end'        => $end,
            'slots'      => $slots,
            'coach_id'   => $coach_id,
            'coach_time' => $coach_time,
            'override'   => filter_var($request->get_param('override'), FILTER_VALIDATE_BOOLEAN),
            'price'      => $price,
        ];
    }

    public static function series_preview($request) {
        $p = self::parse_series($request);
        if (is_wp_error($p)) { return $p; }
        $tz = wp_timezone();
        $tf = get_option('time_format') ?: 'g:i a';
        $dates = [];
        $available = 0;
        foreach ($p['slots'] as $slot) {
            // fix (d): the per-occurrence clash check runs on the COACH range when
            // partial, through the shared predicate inside slot_available().
            $a = MCLB_Bookings::slot_available(
                $p['lane_id'], $p['coach_id'], $slot['starts_at'], $slot['ends_at'], $p['override'],
                isset($slot['coach_starts_at']) ? $slot['coach_starts_at'] : null,
                isset($slot['coach_ends_at'])   ? $slot['coach_ends_at']   : null
            );
            if (!empty($a['available'])) { $available++; }
            $ts = (new DateTimeImmutable($slot['starts_at'], $tz))->getTimestamp();
            $dates[] = [
                'date'      => substr($slot['starts_at'], 0, 10),
                'label'     => wp_date('D j M Y', $ts),
                'available' => !empty($a['available']),
                'reason'    => $a['reason'],
            ];
        }
        // Per-session due = lane_price + coach fee (same as the per-booking
        // "Lane + Coach = Due" line); coach fee from the shared MCLB_Coaches::fee().
        $s0 = $p['slots'][0];
        $coach_fee = 0.0;
        if ($p['coach_id'] > 0) {
            $rate = MCLB_Coaches::rate($p['coach_id']);
            if ($rate !== null) {
                // Fee from the coach's effective window (partial coach time, v1.4.1).
                list($cs, $ce) = MCLB_Bookings::coach_window(
                    $s0['starts_at'], $s0['ends_at'],
                    isset($s0['coach_starts_at']) ? $s0['coach_starts_at'] : null,
                    isset($s0['coach_ends_at'])   ? $s0['coach_ends_at']   : null
                );
                $coach_fee = MCLB_Coaches::fee((float) $rate, $cs, $ce);
            }
        }
        $per_due = round($p['price'] + $coach_fee, 2);

        // Prepaid pass (fix e): flag each AVAILABLE date "covered by pass" or
        // "pass empty: due at counter" against a running balance, in order.
        $pass_code = MCLB_Pass_Code::normalise((string) $request->get_param('pass_code'));
        $pass_info = null;
        if ($pass_code !== '' && class_exists('MCLB_Passes')) {
            $pass = MCLB_Pass_Code::looks_valid($pass_code) ? MCLB_Passes::get_by_code($pass_code) : null;
            if ($pass && MCLB_Passes::is_usable($pass)) {
                $left = (float) floor(MCLB_Passes::balance((int) $pass->id));
                $dur  = (int) floor(MCLB_Bookings::hours_between($s0['starts_at'], $s0['ends_at']));
                foreach ($dates as &$d) {
                    if (empty($d['available'])) { continue; }
                    if ($left >= $dur && $dur > 0) { $d['pass'] = 'covered'; $left -= $dur; }
                    else { $d['pass'] = 'empty'; }
                }
                unset($d);
                $pass_info = ['code' => $pass->code, 'balance' => MCLB_Passes::balance((int) $pass->id)];
            } else {
                $pass_info = ['error' => true];
            }
        }
        return rest_ensure_response([
            'ok'        => true,
            'dates'     => $dates,
            'count'     => count($dates),
            'available' => $available,
            'per_lane'  => $p['price'],
            'per_coach' => $coach_fee,
            'per_due'   => $per_due,
            'per_price' => $per_due, // back-compat: the amount collected per session
            'pass'      => $pass_info,
            'total'     => round($per_due * $available, 2),
            'time'      => wp_date($tf, (new DateTimeImmutable($s0['starts_at'], $tz))->getTimestamp()) . '–' . wp_date($tf, (new DateTimeImmutable($s0['ends_at'], $tz))->getTimestamp()),
        ]);
    }

    public static function create_series($request) {
        if (!MCLB_License::can_book()) {
            return new WP_Error('mclb_demo', MCLB_License::demo_message(), ['status' => 403]);
        }
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }

        $p = self::parse_series($request);
        if (is_wp_error($p)) { return $p; }

        $name  = sanitize_text_field((string) $request->get_param('name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $phone = MCLB_Phone::clean((string) $request->get_param('phone'));
        $note  = sanitize_textarea_field((string) $request->get_param('note'));
        if (!MCLB_Phone::valid($phone)) {
            return new WP_Error('mclb_bad_phone', __('A valid phone number is required.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $paid_upfront = filter_var($request->get_param('paid_upfront'), FILTER_VALIDATE_BOOLEAN);

        $base = [
            'lane_id'        => $p['lane_id'],
            'lane_name'      => $p['lane_name'],
            'price'          => $p['price'],
            'customer_name'  => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'staff_note'     => $note,
            'coach_id'       => $p['coach_id'],
            'created_by'     => $actor, // staff initials (v1.4.2)
        ];
        $res       = MCLB_Bookings::create_series($base, $p['slots'], $p['override']);
        $created   = $res['created'];
        $skipped   = $res['skipped'];
        $series_id = $res['series_id'];
        $m         = count($created);

        // Prepaid pass (fix e): draw whole lane hours per occurrence in order until
        // the balance runs out; later occurrences then fall to the counter. One lock
        // for the whole series.
        $pass_code = MCLB_Pass_Code::normalise((string) $request->get_param('pass_code'));
        if ($pass_code !== '' && class_exists('MCLB_Passes')) {
            $pass = MCLB_Passes::get_by_code($pass_code);
            if ($pass && MCLB_Passes::is_usable($pass) && MCLB_Passes::lock($pass->code)) {
                MCLB_Passes::begin_holder_batch(); // ONE holder summary for the whole draw
                try {
                    foreach ($created as $bid) {
                        $bb   = MCLB_Bookings::get($bid);
                        $want = (int) floor(MCLB_Bookings::hours_between($bb->starts_at, $bb->ends_at));
                        $r    = MCLB_Passes::redeem_booking((int) $pass->id, (int) $bid, $want, $actor, true);
                        if (empty($r['ok'])) { break; } // pass empty → the rest stay at counter
                        MCLB_Bookings::apply_pass_price((int) $bid);
                        self::log($bid, $actor, sprintf(__('drew %1$sh from pass %2$s', 'mclb-lane-booking'), rtrim(rtrim(number_format((float) $r['hours'], 2), '0'), '.'), $pass->code));
                    }
                } finally {
                    MCLB_Passes::flush_holder_batch();
                    MCLB_Passes::unlock($pass->code);
                }
            }
        }

        // Audit each occurrence + (optionally) record the whole-series prepayment.
        $total = 0.0;
        foreach ($created as $i => $bid) {
            $b = MCLB_Bookings::get($bid);
            $total += ($b && $b->counter_due !== null) ? (float) $b->counter_due : 0.0;
            /* translators: 1: n, 2: m. */
            self::log($bid, $actor, sprintf(__('created (series %1$d of %2$d)', 'mclb-lane-booking'), $i + 1, $m));
        }
        if ($paid_upfront && $m > 0) {
            foreach ($created as $bid) {
                MCLB_Bookings::mark_counter_paid($bid);
                /* translators: 1: total amount, 2: number of sessions, 3: initials. */
                self::log($bid, $actor, sprintf(__('Paid upfront %1$s for %2$d sessions, %3$s', 'mclb-lane-booking'), self::money($total), $m, $actor));
            }
        }

        // ONE summary email to the customer + ONE staff alert (per-occurrence
        // confirmations/alerts are intentionally not sent during a series).
        $emailed = false;
        if (class_exists('MCLB_Emails') && $series_id) {
            $r = MCLB_Emails::send_series_confirmation($series_id, $skipped);
            $emailed = !empty($r['ok']);
            MCLB_Emails::alert_series_created($series_id, $skipped);
        }

        return rest_ensure_response([
            'ok'        => true,
            'series_id' => $series_id,
            'created'   => $m,
            'skipped'   => array_map(function ($s) {
                $ts = (new DateTimeImmutable($s['date'] . ' 00:00:00', wp_timezone()))->getTimestamp();
                return ['date' => $s['date'], 'label' => wp_date('D j M Y', $ts), 'reason' => $s['reason']];
            }, $skipped),
            'total'     => round($total, 2),
            'paid_upfront' => $paid_upfront,
            'emailed'   => $emailed,
            'has_email' => $email !== '',
        ]);
    }

    public static function cancel_series($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $id = (int) $request['id'];
        $b  = MCLB_Bookings::get($id);
        if (!$b || empty($b->series_id)) {
            return new WP_Error('mclb_invalid', __('That booking is not part of a series.', 'mclb-lane-booking'), ['status' => 404]);
        }
        $mode    = ($request->get_param('mode') === 'whole') ? 'whole' : 'following';
        $confirm = filter_var($request->get_param('confirm_paid'), FILTER_VALIDATE_BOOLEAN);
        $dry     = filter_var($request->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
        // Future-only: "whole" from now, "following" from this occurrence's start.
        $from    = ($mode === 'whole') ? current_time('mysql') : (string) $b->starts_at;
        $targets = MCLB_Bookings::series_future((int) $b->series_id, $from);
        $tz      = wp_timezone();
        $now     = current_time('mysql');

        // Paid OR past occurrences are sensitive (no auto-refund; staff handle the
        // money / a session that already happened) and are named in the one confirm.
        $sensitive = array_filter($targets, function ($r) use ($now) {
            $paid = $r->counter_paid_at !== null && $r->counter_paid_at !== '0000-00-00 00:00:00';
            $past = (string) $r->starts_at < $now;
            return $paid || $past;
        });
        $sensitive_dates = array_values(array_map(function ($r) use ($tz, $now) {
            $paid = $r->counter_paid_at !== null && $r->counter_paid_at !== '0000-00-00 00:00:00';
            $past = (string) $r->starts_at < $now;
            $tag  = $paid && $past ? ' (paid, past)' : ($paid ? ' (paid)' : ' (past)');
            return wp_date('D j M Y', (new DateTimeImmutable($r->starts_at, $tz))->getTimestamp()) . $tag;
        }, $sensitive));

        // Dry run: the UI asks first so ONE confirm can state exactly what will go.
        if ($dry) {
            $flabel = $targets ? wp_date('D j M Y', (new DateTimeImmutable($targets[0]->starts_at, $tz))->getTimestamp()) : '';
            $llabel = $targets ? wp_date('D j M Y', (new DateTimeImmutable($targets[count($targets) - 1]->starts_at, $tz))->getTimestamp()) : '';
            return rest_ensure_response([
                'ok'              => true,
                'dry_run'         => true,
                'count'           => count($targets),
                'first'           => $flabel,
                'last'            => $llabel,
                'sensitive_count' => count($sensitive),
                'sensitive_dates' => $sensitive_dates,
            ]);
        }

        // Safety net if ever called without the dry-run confirm: still gate paid/past.
        if (!empty($sensitive) && !$confirm) {
            return rest_ensure_response([
                'ok'            => false,
                'needs_confirm' => true,
                'paid_count'    => count($sensitive),
                'paid_dates'    => $sensitive_dates,
                'total'         => count($targets),
            ]);
        }

        $notify = ($request->get_param('notify') === null) ? true : filter_var($request->get_param('notify'), FILTER_VALIDATE_BOOLEAN);

        // Series cancels never auto-refund (staff settle the money); each occurrence
        // fires the single seam (batch — no per-booking email), then ONE customer
        // email + ONE staff alert list every cancelled date with its outcome.
        $cancelled = 0;
        $items     = []; // [ ['booking'=>row, 'outcome'=>...] ] for the combined emails
        if (class_exists('MCLB_Passes')) { MCLB_Passes::begin_holder_batch(); } // ONE holder summary for returned hours
        foreach ($targets as $r) {
            if (MCLB_Bookings::claim_cancel((int) $r->id)) {
                $cancelled++;
                $label = ($mode === 'whole') ? __('cancelled (whole series)', 'mclb-lane-booking') : __('cancelled (this and following)', 'mclb-lane-booking');
                self::log((int) $r->id, $actor, $label);
                $paid_counter = ($r->counter_paid_at !== null && $r->counter_paid_at !== '0000-00-00 00:00:00' && $r->counter_due !== null);
                if (MCLB_Cancel::is_online_paid($r)) {
                    $outcome = ['type' => 'paid_not_refunded', 'amount' => (float) $r->price, 'actor' => $actor, 'manual_flag' => true];
                } elseif ($paid_counter) {
                    $outcome = ['type' => 'counter_refund', 'amount' => (float) $r->counter_due, 'actor' => $actor, 'manual_flag' => true];
                } else {
                    $outcome = ['type' => 'cancelled', 'amount' => 0.0, 'actor' => $actor, 'manual_flag' => false];
                }
                MCLB_Cancel::finish($r, $outcome, ['batch' => true]); // seam only
                $items[] = ['booking' => $r, 'outcome' => $outcome];
            }
        }
        if (class_exists('MCLB_Passes')) { MCLB_Passes::flush_holder_batch(); }
        $has_email = sanitize_email((string) $b->customer_email) !== '';
        if (!empty($items) && class_exists('MCLB_Emails')) {
            if ((int) MCLB_Settings::get('alert_cancel') === 1) {
                MCLB_Emails::alert_series_cancel($b, $items, $actor);
            }
            if ($notify && (int) MCLB_Settings::get('email_customer_on_cancel') === 1 && $has_email) {
                MCLB_Emails::send_series_cancellation($b, $items);
            }
        }
        return rest_ensure_response(['ok' => true, 'cancelled' => $cancelled, 'emailed' => ($notify && $has_email), 'has_email' => $has_email]);
    }

    // ── POST /admin/booking/{id}/details (edit customer fields) ───────────────

    public static function booking_details($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id = (int) $request['id'];
        $b  = MCLB_Bookings::get($id);
        if (!$b) {
            return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]);
        }

        $fields = [];
        $audit  = [];
        $dash   = '—';

        if ($request->get_param('name') !== null) {
            $new = sanitize_text_field((string) $request->get_param('name'));
            $old = (string) $b->customer_name;
            if ($new !== $old) {
                $fields['customer_name'] = $new;
                /* translators: 1: old, 2: new. */
                $audit[] = sprintf(__('name %1$s → %2$s', 'mclb-lane-booking'), $old !== '' ? $old : $dash, $new !== '' ? $new : $dash);
            }
        }
        if ($request->get_param('email') !== null) {
            $new = sanitize_email((string) $request->get_param('email'));
            $old = (string) $b->customer_email;
            if ($new !== $old) {
                $fields['customer_email'] = $new;
                /* translators: 1: old, 2: new. */
                $audit[] = sprintf(__('email %1$s → %2$s', 'mclb-lane-booking'), $old !== '' ? $old : $dash, $new !== '' ? $new : $dash);
            }
        }
        if ($request->get_param('phone') !== null) {
            $new = MCLB_Phone::clean((string) $request->get_param('phone'));
            if ($new !== '' && !MCLB_Phone::valid($new)) {
                return new WP_Error('mclb_bad_phone', __('Enter a valid phone number.', 'mclb-lane-booking'), ['status' => 400]);
            }
            $old = (string) $b->customer_phone;
            if ($new !== $old) {
                $fields['customer_phone'] = $new;
                /* translators: 1: old, 2: new. */
                $audit[] = sprintf(__('phone %1$s → %2$s', 'mclb-lane-booking'), $old !== '' ? MCLB_Phone::format_au($old) : $dash, $new !== '' ? MCLB_Phone::format_au($new) : $dash);
            }
        }
        if ($request->get_param('staff_note') !== null) {
            $new = sanitize_textarea_field((string) $request->get_param('staff_note'));
            $old = (string) $b->staff_note;
            if ($new !== $old) {
                $fields['staff_note'] = $new;
                $audit[] = __('updated note', 'mclb-lane-booking');
            }
        }

        if (empty($fields)) {
            return rest_ensure_response(['ok' => true, 'unchanged' => true]);
        }
        MCLB_Bookings::update_customer($id, $fields);
        foreach ($audit as $line) {
            self::log($id, $actor, $line);
        }
        return rest_ensure_response(['ok' => true]);
    }

    // ── POST /admin/booking/{id}/price (lane-price override) ──────────────────

    public static function booking_price($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id = (int) $request['id'];
        $b  = MCLB_Bookings::get($id);
        if (!$b) {
            return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]);
        }
        $paid = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');
        if ((string) $b->source === 'online' || $paid) {
            return new WP_Error('mclb_no_override', __('Only an unpaid manual booking can have its price overridden.', 'mclb-lane-booking'), ['status' => 409]);
        }
        $new = $request->get_param('price');
        if ($new === null || $new === '' || !is_numeric($new) || (float) $new < 0) {
            return new WP_Error('mclb_bad_price', __('Enter a valid price (0 or more).', 'mclb-lane-booking'), ['status' => 400]);
        }
        $reason = sanitize_text_field((string) $request->get_param('reason'));
        if ($reason === '') {
            return new WP_Error('mclb_need_reason', __('A reason is required to override the price.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $res = MCLB_Bookings::override_lane_price($id, (float) $new);
        if (empty($res['ok'])) {
            return new WP_Error('mclb_write', __('Could not update the price.', 'mclb-lane-booking'), ['status' => 500]);
        }
        /* translators: 1: old price, 2: new price, 3: reason. */
        self::log($id, $actor, sprintf(__('price %1$s → %2$s (%3$s)', 'mclb-lane-booking'), self::money($res['old']), self::money($res['new']), $reason));
        return rest_ensure_response(['ok' => true]);
    }

    // ── POST /admin/booking/{id}/move(-preview) ───────────────────────────────

    /** Parse + validate the shared move params. Returns [lane_id,starts_at,ends_at,override] or a WP_Error. */
    private static function move_params($request) {
        $lane_id = (int) $request->get_param('lane_id');
        $date    = self::sanitize_date((string) $request->get_param('date'));
        $start   = (string) $request->get_param('start');
        $end     = (string) $request->get_param('end');
        $re      = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $lane    = get_post($lane_id);
        if (!$lane || $lane->post_type !== 'mclb_lane' || $date === '' || !preg_match($re, $start) || !preg_match($re, $end)) {
            return new WP_Error('mclb_invalid', __('Please check the lane, date and times.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $starts_at = "$date $start:00";
        $ends_at   = "$date $end:00";
        if ($ends_at <= $starts_at) {
            return new WP_Error('mclb_invalid', __('The end time must be after the start.', 'mclb-lane-booking'), ['status' => 400]);
        }
        return [
            'lane_id'   => $lane_id,
            'starts_at' => $starts_at,
            'ends_at'   => $ends_at,
            'override'  => filter_var($request->get_param('override'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Read-only preview: availability + price delta before a move is committed. */
    public static function booking_move_preview($request) {
        $id = (int) $request['id'];
        if (!MCLB_Bookings::get($id)) {
            return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]);
        }
        $p = self::move_params($request);
        if (is_wp_error($p)) { return $p; }
        $pre = MCLB_Bookings::preview_move($id, $p['lane_id'], $p['starts_at'], $p['ends_at'], $p['override']);

        $conflict_label = '';
        if (!empty($pre['conflict_id'])) {
            $c = MCLB_Bookings::get((int) $pre['conflict_id']);
            if ($c) { $conflict_label = trim((string) $c->customer_name) ?: ('#' . (int) $pre['conflict_id']); }
        }
        return rest_ensure_response([
            'available'      => !empty($pre['available']),
            'reason'         => $pre['reason'] ?? '',
            'conflict_label' => $conflict_label,
            'mode'           => $pre['mode'],
            'delta'          => $pre['delta'],
            'new_lane_price' => $pre['new_lane_price'],
            'old_price'      => $pre['old_price'],
            'coach_fee_new'  => $pre['coach_fee_new'],
            'online'         => $pre['online'],
            'counter_paid'   => $pre['counter_paid'],
        ]);
    }

    public static function booking_move($request) {
        if (!MCLB_License::can_book()) {
            return new WP_Error('mclb_demo', MCLB_License::demo_message(), ['status' => 403]);
        }
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id = (int) $request['id'];
        $b  = MCLB_Bookings::get($id);
        if (!$b) {
            return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]);
        }
        if ($b->status !== MCLB_Bookings::STATUS_CONFIRMED) {
            // Held (checkout in progress), cancelled or refunded — not movable.
            return new WP_Error('mclb_not_movable', __('Only a confirmed booking can be moved.', 'mclb-lane-booking'), ['status' => 409]);
        }
        $p = self::move_params($request);
        if (is_wp_error($p)) { return $p; }

        // Override covers hours / closure / past only; a real clash is left to the
        // FOR-UPDATE move so it surfaces cleanly as "slot taken".
        if (!$p['override'] && !MCLB_Availability::is_range_bookable_time($p['lane_id'], $p['starts_at'], $p['ends_at'])) {
            return new WP_Error('mclb_unavailable', __('That time is outside opening hours or during a blockout. Use override to move it there anyway.', 'mclb-lane-booking'), ['status' => 409]);
        }

        // Price-down on an online-paid booking: the staff member picks refund vs no
        // refund; "no refund" needs a reason (logged).
        $refund_choice = ($request->get_param('refund_choice') === 'none') ? 'none' : 'refund';
        $refund_reason = sanitize_text_field((string) $request->get_param('refund_reason'));

        $old_label = self::move_label($b->lane_name, $b->starts_at, $b->ends_at);

        $res = MCLB_Bookings::move_locked($id, $p['lane_id'], $p['starts_at'], $p['ends_at']);
        if (empty($res['ok'])) {
            if (!empty($res['conflict']) && !empty($res['conflict_id'])) {
                $c  = MCLB_Bookings::get((int) $res['conflict_id']);
                $nm = $c ? (trim((string) $c->customer_name) ?: ('#' . (int) $res['conflict_id'])) : '';
                $reason = $res['reason'] ?? 'lane';
                $msg = ($reason === 'coach')
                    ? sprintf(__('That coach is already booked for an overlapping time (%s).', 'mclb-lane-booking'), $nm)
                    : sprintf(__('That slot was just taken (%s). Refresh and try another.', 'mclb-lane-booking'), $nm);
                return new WP_Error('mclb_move_conflict', $msg, ['status' => 409]);
            }
            return self::write_error($res);
        }

        $new    = $res['new'];
        $money  = $res['money'];
        $online = !empty($res['online']);
        $new_label = self::move_label($new['lane_name'], $new['starts_at'], $new['ends_at']);

        // Primary audit line in the agreed format.
        /* translators: 1: from, 2: to. */
        self::log($id, $actor, sprintf(__('moved %1$s → %2$s', 'mclb-lane-booking'), $old_label, $new_label));

        // Partial coach time (v1.4.1): a coach sub-range that ran past the new
        // (shorter) session was clamped to fit. Record it so staff see why the
        // coach hours shrank, and surface it in the response for a UI toast.
        $coach_clamped = !empty($res['coach_clamped']);
        if ($coach_clamped && $new['coach_starts_at'] !== null && $new['coach_ends_at'] !== null) {
            /* translators: 1: coach start time, 2: coach end time. */
            self::log($id, $actor, sprintf(__('coach time clamped to the new session (%1$s–%2$s)', 'mclb-lane-booking'), self::time_label($new['coach_starts_at']), self::time_label($new['coach_ends_at'])));
        }

        // Money follow-through (the move transaction already set counter_due; the
        // card refund + price drop for an online move-down happen here, post-commit).
        if ($online) {
            if ($money['mode'] === 'refund_or_none') {
                $amount = round($money['old_price'] - $money['new_lane_price'], 2);
                if ($refund_choice === 'refund') {
                    $r = MCLB_Refunds::refund_delta(MCLB_Bookings::get($id), $amount, __('Booking moved to a lower-priced slot', 'mclb-lane-booking'));
                    if (!empty($r['ok'])) {
                        MCLB_Bookings::set_price($id, $money['new_lane_price']); // net card amount, so a later cancel reconciles
                        /* translators: 1: amount, 2: old price, 3: new price. */
                        self::log($id, $actor, sprintf(__('refunded %1$s to card (lane %2$s → %3$s)', 'mclb-lane-booking'), self::money($r['amount']), self::money($money['old_price']), self::money($money['new_lane_price'])));
                    } else {
                        self::log($id, $actor, __('move to lower-priced slot — automatic refund FAILED, refund manually', 'mclb-lane-booking'));
                    }
                } else {
                    if ($refund_reason === '') {
                        $refund_reason = __('no reason given', 'mclb-lane-booking');
                    }
                    /* translators: 1: amount, 2: reason. */
                    self::log($id, $actor, sprintf(__('moved to lower-priced slot, no refund of %1$s (%2$s)', 'mclb-lane-booking'), self::money($amount), $refund_reason));
                }
            } elseif ($money['mode'] === 'counter_up') {
                /* translators: %s: amount to collect. */
                self::log($id, $actor, sprintf(__('lane price up — %s to collect at counter', 'mclb-lane-booking'), self::money($money['delta'])));
            }
            // Keep the WC order line + customer-facing meta in step (best-effort).
            MCLB_Order::update_item_for_move(MCLB_Bookings::get($id));
        } elseif ($money['mode'] === 'manual_recompute' && abs($money['delta']) > 0.001) {
            /* translators: 1: old, 2: new. */
            self::log($id, $actor, sprintf(__('lane price %1$s → %2$s', 'mclb-lane-booking'), self::money($money['old_price']), self::money($money['new_lane_price'])));
        }

        if ($money['mode'] === 'locked_paid' && abs($money['delta']) > 0.001) {
            self::log($id, $actor, __('moved after counter payment taken — price difference may need manual adjustment', 'mclb-lane-booking'));
        }

        // Customer "your booking has moved" email (v1.3.2) + optional staff alert.
        // notify defaults ON; staff can untick it on the Move panel (e.g. the
        // customer is at the counter). MCLB_Emails skips a no-change move.
        $notify = ($request->get_param('notify') === null) ? true : filter_var($request->get_param('notify'), FILTER_VALIDATE_BOOLEAN);
        do_action('mclb_booking_moved', $id, $res['old'], $res['new'], ['notify' => $notify, 'actor' => $actor]);
        if (class_exists('MCLB_Emails')) {
            MCLB_Emails::alert_move($id, $res['old'], $res['new']);
        }

        $tz  = wp_timezone();
        $sdt = new DateTimeImmutable($new['starts_at'], $tz);
        $edt = new DateTimeImmutable($new['ends_at'], $tz);
        $tf  = get_option('time_format') ?: 'g:i a';
        return rest_ensure_response([
            'ok'         => true,
            'id'         => $id,
            'date'       => substr($new['starts_at'], 0, 10),
            'moved_to'   => MCLB_Settings::friendly_date($sdt->getTimestamp()) . ', ' . wp_date($tf, $sdt->getTimestamp()) . ' – ' . wp_date($tf, $edt->getTimestamp()),
            'coach_clamped' => $coach_clamped,
        ]);
    }

    /** "Lane · Mon 15 Oct 4:00 pm – 5:00 pm" for the move audit line. */
    private static function move_label($lane_name, $starts_at, $ends_at) {
        $tz = wp_timezone();
        $s  = new DateTimeImmutable((string) $starts_at, $tz);
        $e  = new DateTimeImmutable((string) $ends_at, $tz);
        $tf = get_option('time_format') ?: 'g:i a';
        return sprintf('%s %s %s–%s', (string) $lane_name, wp_date('D j M', $s->getTimestamp()), wp_date($tf, $s->getTimestamp()), wp_date($tf, $e->getTimestamp()));
    }

    // ── POST /admin/booking/{id}/resend-confirmation ──────────────────────────

    public static function booking_resend_confirmation($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id = (int) $request['id'];
        $b  = MCLB_Bookings::get($id);
        if (!$b) {
            return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]);
        }
        if ((string) $b->source !== 'manual') {
            return new WP_Error('mclb_not_manual', __('Only a manual booking sends this confirmation — online bookings use the WooCommerce order email.', 'mclb-lane-booking'), ['status' => 409]);
        }
        if (sanitize_email((string) $b->customer_email) === '') {
            return new WP_Error('mclb_no_email', __('No email address is on file for this booking.', 'mclb-lane-booking'), ['status' => 409]);
        }
        $r = class_exists('MCLB_Emails') ? MCLB_Emails::send_manual_confirmation($id, true) : ['ok' => false];
        if (empty($r['ok'])) {
            return new WP_Error('mclb_send_failed', __('Could not send the confirmation — please try again.', 'mclb-lane-booking'), ['status' => 500]);
        }
        self::log($id, $actor, __('resent booking confirmation', 'mclb-lane-booking'));
        return rest_ensure_response(['ok' => true]);
    }

    // ── POST /admin/booking/{id}/coach ────────────────────────────────────────

    public static function booking_coach($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id       = (int) $request['id'];
        $coach_id = (int) $request->get_param('coach_id');
        $note     = sanitize_text_field((string) $request->get_param('note'));
        $b        = MCLB_Bookings::get($id);
        if (!$b) { return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]); }

        // Coach time-of-day (HH:MM) for the coach sub-range; validated per booking.
        $ct_start = (string) $request->get_param('coach_start');
        $ct_end   = (string) $request->get_param('coach_end');
        $re       = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $partial  = ($coach_id > 0 && preg_match($re, $ct_start) && preg_match($re, $ct_end));
        // Series scope: apply to just this occurrence, or this + all later ones.
        $scope    = ($request->get_param('scope') === 'following' && $b->series_id) ? 'following' : 'this';

        $targets = ($scope === 'following')
            ? MCLB_Bookings::series_future((int) $b->series_id, (string) $b->starts_at)
            : [$b];

        $done = 0; $override_cleared = false; $last_err = null;
        foreach ($targets as $t) {
            $d  = substr((string) $t->starts_at, 0, 10);
            $cs = $partial ? "$d $ct_start:00" : null;
            $ce = $partial ? "$d $ct_end:00" : null;
            if ($partial && !MCLB_Bookings::validate_coach_time($t->starts_at, $t->ends_at, $cs, $ce)) {
                $last_err = new WP_Error('mclb_coach_time', __('Coach time must sit within the booking and on the booking increment.', 'mclb-lane-booking'), ['status' => 400]);
                continue;
            }
            $res = MCLB_Bookings::assign_coach_locked((int) $t->id, $coach_id, $note, $cs, $ce);
            if (empty($res['ok'])) { $last_err = self::write_error($res); continue; }
            if (!empty($res['override_cleared'])) { $override_cleared = true; }
            $time = $partial ? ' ' . self::time_label($cs) . '–' . self::time_label($ce) : '';
            $summary = $coach_id > 0
                ? sprintf(__('assigned coach %s', 'mclb-lane-booking'), MCLB_Coaches::label($coach_id)) . $time
                : __('removed coach', 'mclb-lane-booking');
            if ($note !== '') { $summary .= ' ' . sprintf(__('(note: %s)', 'mclb-lane-booking'), $note); }
            if ($override_cleared) { $summary .= ' ' . __('— coach-fee override cleared', 'mclb-lane-booking'); }
            self::log((int) $t->id, $actor, $summary);
            $done++;
        }
        if ($done === 0) {
            return $last_err ?: new WP_Error('mclb_coach', __('Could not update the coach.', 'mclb-lane-booking'), ['status' => 409]);
        }
        return rest_ensure_response(['ok' => true, 'updated' => $done, 'override_cleared' => $override_cleared]);
    }

    /** "4:00 pm" from a datetime, for audit lines. */
    private static function time_label($dt) {
        $tf = get_option('time_format') ?: 'g:i a';
        return wp_date($tf, (new DateTimeImmutable((string) $dt, wp_timezone()))->getTimestamp());
    }

    // ── POST /admin/booking/{id}/coach-fee (coach-fee override) ───────────────

    public static function booking_coach_fee($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $id = (int) $request['id'];
        $b  = MCLB_Bookings::get($id);
        if (!$b) { return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]); }
        if ((int) $b->assigned_coach_id <= 0) { return new WP_Error('mclb_no_coach', __('This booking has no coach.', 'mclb-lane-booking'), ['status' => 409]); }
        $paid = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');
        if ($paid) { return new WP_Error('mclb_locked', __('The counter payment has been taken — the coach fee can’t be changed.', 'mclb-lane-booking'), ['status' => 409]); }
        $new = $request->get_param('fee');
        if ($new === null || $new === '' || !is_numeric($new) || (float) $new < 0) {
            return new WP_Error('mclb_bad_fee', __('Enter a valid coach fee (0 or more).', 'mclb-lane-booking'), ['status' => 400]);
        }
        $reason = sanitize_text_field((string) $request->get_param('reason'));
        if ($reason === '') { return new WP_Error('mclb_need_reason', __('A reason is required to override the coach fee.', 'mclb-lane-booking'), ['status' => 400]); }
        $res = MCLB_Bookings::override_coach_fee($id, (float) $new);
        if (empty($res['ok'])) { return new WP_Error('mclb_write', __('Could not update the coach fee.', 'mclb-lane-booking'), ['status' => 500]); }
        /* translators: 1: old fee, 2: new fee, 3: reason. */
        self::log($id, $actor, sprintf(__('coach fee %1$s → %2$s (%3$s)', 'mclb-lane-booking'), self::money($res['old']), self::money($res['new']), $reason));
        return rest_ensure_response(['ok' => true]);
    }

    // ── POST /admin/closure/{id}/release ──────────────────────────────────────
    //
    // Free up a blockout for a single date without touching the recurring rule:
    //   • whole day  — no release_from → the blockout is lifted for that date
    //   • ended early — release_from "HH:MM" → the blockout ends at that time
    // Either writes ONE row to the exceptions table; the availability engine and
    // the admin calendar read it back (see MCLB_Closures::exceptions_for_day /
    // apply_exceptions). "Release the whole blockout" is the existing delete, not
    // this. The exception stores the actor's initials — that IS the audit trail,
    // surfaced on the calendar "released" marker (released_for_day()).

    public static function closure_release($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $id = (int) $request['id'];
        $c  = MCLB_Closures::get($id);
        if (!$c) { return new WP_Error('mclb_invalid', __('Blockout not found.', 'mclb-lane-booking'), ['status' => 404]); }

        $date = (string) $request->get_param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('mclb_bad_date', __('A valid date is required.', 'mclb-lane-booking'), ['status' => 400]);
        }
        // The blockout must actually fall on that date, or there is nothing to free.
        $w = MCLB_Availability::closure_window($c, $date);
        if (!$w) {
            return new WP_Error('mclb_no_closure_here', __('That blockout does not apply on that date.', 'mclb-lane-booking'), ['status' => 409]);
        }

        // lane_id 0 = whole blockout for that date; a specific lane releases one
        // column of a site-wide blockout. A per-lane release on a lane-specific
        // blockout only makes sense for that same lane.
        $lane_id = (int) $request->get_param('lane_id');
        if ($lane_id && (int) $c->lane_id && $lane_id !== (int) $c->lane_id) {
            return new WP_Error('mclb_bad_lane', __('That lane is not covered by this blockout.', 'mclb-lane-booking'), ['status' => 400]);
        }

        // "Ended early": release from a time inside the blockout window.
        $release_from = trim((string) $request->get_param('release_from'));
        if ($release_from !== '') {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $release_from)) {
                return new WP_Error('mclb_bad_time', __('Enter a valid end time (HH:MM).', 'mclb-lane-booking'), ['status' => 400]);
            }
            $tz   = wp_timezone();
            $cut  = (new DateTimeImmutable("$date $release_from:00", $tz))->getTimestamp();
            $ws   = (new DateTimeImmutable($w['starts_at'], $tz))->getTimestamp();
            $we   = (new DateTimeImmutable($w['ends_at'], $tz))->getTimestamp();
            if ($cut <= $ws || $cut >= $we) {
                return new WP_Error('mclb_time_range', __('The end time must fall inside the blockout.', 'mclb-lane-booking'), ['status' => 400]);
            }
        }

        $ex_id = MCLB_Closures::add_exception($id, $date, $lane_id, ($release_from !== '' ? $release_from : null), $actor);
        if (!$ex_id) {
            return new WP_Error('mclb_write', __('Could not release the blockout.', 'mclb-lane-booking'), ['status' => 500]);
        }
        return rest_ensure_response([
            'ok'        => true,
            'id'        => $ex_id,
            'date'      => $date,
            'whole_day' => ($release_from === ''),
            'released'  => MCLB_Closures::released_for_day($date),
        ]);
    }

    // ── DELETE /admin/closure-exception/{id} (undo a release) ─────────────────
    //
    // Re-close a released window — but REFUSE if a booking has since been taken
    // inside it (a book-over), so undo can never silently double-book. The
    // re-closed window is the blockout's window on that date, trimmed to the
    // released portion (whole-day vs "ended early").

    public static function closure_exception_undo($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $id = (int) $request['id'];
        $ex = MCLB_Closures::get_exception($id);
        if (!$ex) { return new WP_Error('mclb_invalid', __('That release was already undone.', 'mclb-lane-booking'), ['status' => 404]); }

        $date = (string) $ex->exception_date;
        $c    = MCLB_Closures::get((int) $ex->closure_id);
        $w    = $c ? MCLB_Availability::closure_window($c, $date) : null;

        // If the blockout no longer applies here (deleted or rule changed), there
        // is nothing to restore — just drop the stale exception.
        if ($w) {
            $tz    = wp_timezone();
            $winS  = ($ex->release_from !== null && $ex->release_from !== '')
                ? (new DateTimeImmutable("$date " . substr((string) $ex->release_from, 0, 8), $tz))
                : (new DateTimeImmutable($w['starts_at'], $tz));
            $winE  = new DateTimeImmutable($w['ends_at'], $tz);
            // Clamp the released window start into the blockout window.
            $wsDt = new DateTimeImmutable($w['starts_at'], $tz);
            if ($winS < $wsDt) { $winS = $wsDt; }

            if ($winS < $winE) {
                // Which lanes does re-closing cover? A per-lane release restores one
                // lane; otherwise the blockout's own scope (0 = every lane).
                $check_lane = (int) $ex->lane_id ?: (int) $c->lane_id; // 0 = all lanes
                foreach (MCLB_Bookings::for_day_admin($date) as $b) {
                    if ((string) $b->status !== MCLB_Bookings::STATUS_CONFIRMED) { continue; }
                    if ($check_lane && (int) $b->lane_id !== $check_lane) { continue; }
                    $bs = new DateTimeImmutable((string) $b->starts_at, $tz);
                    $be = new DateTimeImmutable((string) $b->ends_at, $tz);
                    if ($bs < $winE && $be > $winS) {
                        /* translators: %d: booking id booked inside the released window. */
                        return new WP_Error('mclb_booked_over', sprintf(__('Can’t restore: #%d is booked in this window.', 'mclb-lane-booking'), (int) $b->id), ['status' => 409]);
                    }
                }
            }
        }

        if (!MCLB_Closures::delete_exception($id)) {
            return new WP_Error('mclb_write', __('Could not undo the release.', 'mclb-lane-booking'), ['status' => 500]);
        }
        return rest_ensure_response(['ok' => true, 'date' => $date, 'released' => MCLB_Closures::released_for_day($date)]);
    }

    // ── POST /admin/booking/{id}/counter ──────────────────────────────────────

    public static function booking_counter($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id = (int) $request['id'];
        $op = (string) $request->get_param('op');

        if ($op === 'reverse') {
            $res = MCLB_Bookings::reverse_counter_paid($id, $actor);
            if (empty($res['ok'])) {
                return self::write_error($res);
            }
            self::log($id, $actor, __('reversed counter payment', 'mclb-lane-booking'));
            return rest_ensure_response(['ok' => true]);
        }

        $before = MCLB_Bookings::get($id);
        $res    = MCLB_Bookings::mark_counter_paid($id);
        if (empty($res['ok'])) {
            return self::write_error($res);
        }
        $amt = ($before && $before->counter_due !== null) ? self::money($before->counter_due) : '';
        self::log($id, $actor, trim(__('marked counter paid', 'mclb-lane-booking') . ' ' . $amt));
        return rest_ensure_response(['ok' => true]);
    }

    // ── POST /admin/booking/{id}/cancel ───────────────────────────────────────

    public static function booking_cancel($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id     = (int) $request['id'];
        $mode   = ($request->get_param('mode') === 'refund') ? 'refund' : 'only';
        // Staff "Notify customer" box (default on), mirroring Move.
        $notify = ($request->get_param('notify') === null) ? true : filter_var($request->get_param('notify'), FILTER_VALIDATE_BOOLEAN);

        $b = MCLB_Bookings::get($id);
        if (!$b) {
            return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]);
        }
        $has_email = sanitize_email((string) $b->customer_email) !== '';

        if ($mode === 'refund') {
            if (!current_user_can(MCLB_Caps::REFUND)) {
                return new WP_Error('mclb_forbidden', __('You can’t issue refunds.', 'mclb-lane-booking'), ['status' => 403]);
            }
            if ((string) $b->source !== 'online' || !$b->order_id) {
                return new WP_Error('mclb_no_refund', __('This booking has no online payment to refund. Use “Cancel only”.', 'mclb-lane-booking'), ['status' => 400]);
            }
            // cancel_and_refund fires the single seam + the WEB-12 emails.
            $res = MCLB_Refunds::cancel_and_refund($id, __('Admin cancel + refund', 'mclb-lane-booking'), ['actor' => $actor, 'notify' => $notify]);
            if (empty($res['ok'])) {
                return new WP_Error('mclb_nochange', __('That booking was not in a cancellable state.', 'mclb-lane-booking'), ['status' => 409]);
            }
            self::log($id, $actor, !empty($res['refunded']) ? __('cancelled + refunded', 'mclb-lane-booking') : __('cancelled — automatic refund FAILED, refund manually', 'mclb-lane-booking'));
            return rest_ensure_response(['ok' => true, 'refunded' => !empty($res['refunded']), 'emailed' => ($notify && $has_email), 'has_email' => $has_email]);
        }

        // Cancel only (no refund). Classify the money outcome so the email is
        // accurate (addition a: an online-paid booking must not just say "cancelled").
        if (!MCLB_Bookings::claim_cancel($id)) {
            return new WP_Error('mclb_nochange', __('That booking was not in a cancellable state.', 'mclb-lane-booking'), ['status' => 409]);
        }
        $paid_counter = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00' && $b->counter_due !== null);
        if (MCLB_Cancel::is_online_paid($b)) {
            $outcome = ['type' => 'paid_not_refunded', 'amount' => (float) $b->price, 'actor' => $actor, 'manual_flag' => true];
        } elseif ($paid_counter) {
            $outcome = ['type' => 'counter_refund', 'amount' => (float) $b->counter_due, 'actor' => $actor, 'manual_flag' => true];
        } else {
            $outcome = ['type' => 'cancelled', 'amount' => 0.0, 'actor' => $actor, 'manual_flag' => false];
        }
        MCLB_Cancel::finish($b, $outcome, ['notify' => $notify]);
        self::log($id, $actor, __('cancelled', 'mclb-lane-booking'));
        return rest_ensure_response(['ok' => true, 'emailed' => ($notify && $has_email), 'has_email' => $has_email]);
    }

    // ── Prepaid passes (v1.5.0) ──────────────────────────────────────────────

    /** Draw a pass onto an already-confirmed MANUAL booking (whole lane hours). */
    public static function apply_pass_draw($booking_id, $code, $actor) {
        $b = MCLB_Bookings::get((int) $booking_id);
        if (!$b) { return new WP_Error('mclb_invalid', __('Booking not found.', 'mclb-lane-booking'), ['status' => 404]); }
        if ((string) $b->source !== 'manual') {
            return new WP_Error('mclb_pass_manual', __('A pass can only be drawn onto a counter (manual) booking.', 'mclb-lane-booking'), ['status' => 409]);
        }
        $pass = MCLB_Passes::get_by_code($code);
        if (!$pass || !MCLB_Passes::is_usable($pass)) {
            return new WP_Error('mclb_pass_invalid', __('That code is invalid or expired.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $want = (int) floor(MCLB_Bookings::hours_between($b->starts_at, $b->ends_at));
        if (!MCLB_Passes::lock($pass->code)) {
            return new WP_Error('mclb_pass_busy', __('That pass is busy — try again.', 'mclb-lane-booking'), ['status' => 409]);
        }
        try {
            $r = MCLB_Passes::redeem_booking((int) $pass->id, (int) $booking_id, $want, $actor, true);
        } finally {
            MCLB_Passes::unlock($pass->code);
        }
        if (empty($r['ok'])) {
            return new WP_Error('mclb_pass_empty', __('That pass has no hours left.', 'mclb-lane-booking'), ['status' => 409]);
        }
        MCLB_Bookings::apply_pass_price((int) $booking_id);
        return ['ok' => true, 'hours' => $r['hours'], 'code' => $pass->code, 'balance' => MCLB_Passes::balance((int) $pass->id)];
    }

    public static function booking_pass($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $id = (int) $request['id'];
        $op = ($request->get_param('op') === 'return') ? 'return' : 'draw';
        if ($op === 'return') {
            $r = MCLB_Passes::return_booking($id, sprintf(__('returned by %s', 'mclb-lane-booking'), $actor));
            if (!$r) { return new WP_Error('mclb_pass_none', __('No pass hours on this booking.', 'mclb-lane-booking'), ['status' => 409]); }
            MCLB_Bookings::apply_pass_price($id);
            self::log($id, $actor, sprintf(__('returned %1$sh to pass %2$s', 'mclb-lane-booking'), rtrim(rtrim(number_format((float) $r['hours'], 2), '0'), '.'), $r['code']));
            return rest_ensure_response(['ok' => true, 'balance' => $r['balance'], 'code' => $r['code']]);
        }
        $code = MCLB_Pass_Code::normalise((string) $request->get_param('code'));
        $res  = self::apply_pass_draw($id, $code, $actor);
        if (is_wp_error($res)) { return $res; }
        self::log($id, $actor, sprintf(__('drew %1$sh from pass %2$s', 'mclb-lane-booking'), rtrim(rtrim(number_format((float) $res['hours'], 2), '0'), '.'), $res['code']));
        return rest_ensure_response($res);
    }

    public static function pass_issue($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $pos = sanitize_text_field((string) $request->get_param('pos_ref'));
        if ($pos === '') { return new WP_Error('mclb_pass_pos', __('A POS receipt / reference is required (payment is taken on Square first).', 'mclb-lane-booking'), ['status' => 400]); }
        $months  = ($request->get_param('expiry_months') !== null && $request->get_param('expiry_months') !== '') ? absint($request->get_param('expiry_months')) : MCLB_Passes::default_expiry_months();
        $expires = $months > 0 ? (new DateTimeImmutable('now', wp_timezone()))->modify('+' . $months . ' months')->format('Y-m-d H:i:s') : null;
        $res = MCLB_Passes::issue([
            'holder_name'  => sanitize_text_field((string) $request->get_param('holder_name')),
            'holder_email' => sanitize_email((string) $request->get_param('holder_email')),
            'holder_phone' => sanitize_text_field((string) $request->get_param('holder_phone')),
            'hours'        => (float) $request->get_param('hours'),
            'expires_at'   => $expires,
            'note'         => sanitize_textarea_field((string) $request->get_param('note')),
            'pos_ref'      => $pos,
            'created_by'   => $actor,
        ]);
        if (is_wp_error($res)) { return $res; }
        return rest_ensure_response([
            'ok' => true, 'code' => $res->code, 'balance' => MCLB_Passes::balance((int) $res->id),
            'expires_at' => $res->expires_at, 'id' => (int) $res->id,
        ]);
    }

    public static function pass_lookup($request) {
        if (self::actor($request) === null) { return self::need_initials(); }
        $code = MCLB_Pass_Code::normalise((string) $request->get_param('code'));
        $pass = MCLB_Pass_Code::looks_valid($code) ? MCLB_Passes::get_by_code($code) : null;
        if (!$pass) { return new WP_Error('mclb_pass_invalid', __('No pass found for that code.', 'mclb-lane-booking'), ['status' => 404]); }
        return rest_ensure_response([
            'ok' => true, 'id' => (int) $pass->id, 'code' => $pass->code, 'holder' => (string) $pass->holder_name,
            'balance' => MCLB_Passes::balance((int) $pass->id), 'status' => (string) $pass->status,
            'expires_at' => $pass->expires_at, 'usable' => MCLB_Passes::is_usable($pass),
        ]);
    }

    public static function pass_void($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $res = MCLB_Passes::void((int) $request['id'], $actor, __('voided at counter', 'mclb-lane-booking'));
        if (is_wp_error($res)) { return $res; }
        return rest_ensure_response(['ok' => true]);
    }

    public static function pass_reissue($request) {
        $actor = self::actor($request);
        if ($actor === null) { return self::need_initials(); }
        $res = MCLB_Passes::reissue((int) $request['id'], $actor);
        if (is_wp_error($res)) { return $res; }
        return rest_ensure_response(['ok' => true, 'code' => $res->code, 'balance' => MCLB_Passes::balance((int) $res->id), 'id' => (int) $res->id]);
    }

    // ── POST /admin/daysheet (manual send) ───────────────────────────────────

    public static function send_daysheet($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $coach_id = (int) $request->get_param('coach_id');
        $date     = self::sanitize_date((string) $request->get_param('date'));
        if (!$coach_id || $date === '') {
            return new WP_Error('mclb_invalid', __('Pick a coach and a valid date.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $res = MCLB_Daysheets::send_for_coach($coach_id, $date, 'manual:' . $actor);
        return rest_ensure_response(['ok' => !empty($res['ok']), 'result' => $res['result'] ?? '']);
    }

    // ── Blockouts ──────────────────────────────────────────────────────────────

    public static function create_blockout($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $lane_id    = (int) $request->get_param('lane_id'); // 0 = site-wide
        $date       = self::sanitize_date((string) $request->get_param('date'));
        $start      = (string) $request->get_param('start');
        $end        = (string) $request->get_param('end');
        $event_type = sanitize_title((string) $request->get_param('event_type'));
        $note       = sanitize_text_field((string) $request->get_param('note'));
        $re         = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if ($date === '' || !preg_match($re, $start) || !preg_match($re, $end) || $end <= $start) {
            return new WP_Error('mclb_invalid', __('Please check the date and times.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $id = MCLB_Closures::insert([
            'lane_id'    => $lane_id,
            'kind'       => 'oneoff',
            'event_type' => $event_type,
            'label'      => $note,
            'starts_at'  => "$date $start",
            'ends_at'    => "$date $end",
        ]);
        return $id
            ? rest_ensure_response(['ok' => true, 'id' => (int) $id])
            : new WP_Error('mclb_db', __('Could not save the blockout.', 'mclb-lane-booking'), ['status' => 500]);
    }

    public static function blockout($request) {
        $actor = self::actor($request);
        if ($actor === null) {
            return self::need_initials();
        }
        $id = (int) $request['id'];
        $c  = MCLB_Closures::get($id);
        if (!$c) {
            return new WP_Error('mclb_not_found', __('Blockout not found.', 'mclb-lane-booking'), ['status' => 404]);
        }
        if ($c->kind !== 'oneoff') {
            return new WP_Error('mclb_recurring_readonly', __('Recurring blockouts are edited on the Closures screen.', 'mclb-lane-booking'), ['status' => 403]);
        }
        if ($request->get_method() === 'DELETE') {
            MCLB_Closures::delete($id);
            return rest_ensure_response(['ok' => true, 'deleted' => true]);
        }
        MCLB_Closures::update($id, [
            'lane_id'    => (int) $c->lane_id,
            'kind'       => 'oneoff',
            'event_type' => sanitize_title((string) $request->get_param('event_type')),
            'label'      => sanitize_text_field((string) $request->get_param('note')),
            'starts_at'  => $c->starts_at,
            'ends_at'    => $c->ends_at,
        ]);
        return rest_ensure_response(['ok' => true]);
    }

    // ── Shared write-error → WP_Error mapping ─────────────────────────────────

    private static function write_error($res) {
        if (!empty($res['lock_error'])) {
            return new WP_Error('mclb_busy', __('The booking system is busy — please try again.', 'mclb-lane-booking'), ['status' => 503]);
        }
        if (!empty($res['conflict'])) {
            $reason = $res['reason'] ?? 'lane';
            return ($reason === 'coach')
                ? new WP_Error('mclb_coach_conflict', __('That coach is already booked for an overlapping time.', 'mclb-lane-booking'), ['status' => 409])
                : new WP_Error('mclb_slot_taken', __('That slot was just taken. Refresh and try another.', 'mclb-lane-booking'), ['status' => 409]);
        }
        $map = [
            'coach_no_rate'      => __('That coach has no hourly rate set.', 'mclb-lane-booking'),
            'coach_not_bookable' => __('That coach isn’t available for bookings.', 'mclb-lane-booking'),
            'locked_need_note'   => __('Counter payment already taken — a note is required to change the coach.', 'mclb-lane-booking'),
            'nothing_due'        => __('Nothing is due on this booking.', 'mclb-lane-booking'),
            'already_paid'       => __('The counter payment was already recorded.', 'mclb-lane-booking'),
            'not_paid'           => __('No counter payment to reverse.', 'mclb-lane-booking'),
            'not_confirmed'      => __('That booking is not confirmed.', 'mclb-lane-booking'),
            'not_found'          => __('Booking not found.', 'mclb-lane-booking'),
            'need_note'          => __('A note is required.', 'mclb-lane-booking'),
        ];
        $err = $res['error'] ?? 'error';
        $msg = $map[$err] ?? __('Could not complete that action.', 'mclb-lane-booking');
        return new WP_Error('mclb_' . $err, $msg, ['status' => ($err === 'not_found' ? 404 : 409)]);
    }

    private static function sanitize_date($date) {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }
        [$y, $mo, $d] = array_map('intval', explode('-', $date));
        return checkdate($mo, $d, $y) ? $date : '';
    }
}
