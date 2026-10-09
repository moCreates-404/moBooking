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
            $held      = ($b->status === MCLB_Bookings::STATUS_HELD);
            $cancelled = ($b->status === MCLB_Bookings::STATUS_CANCELLED);
            $paid      = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00');
            $bookings[] = [
                'id'          => (int) $b->id,
                'lane_id'     => (int) $b->lane_id,
                'starts_at'   => $b->starts_at,
                'ends_at'     => $b->ends_at,
                'start_min'   => self::min_of_day($b->starts_at, $midnight, $tz),
                'end_min'     => self::min_of_day($b->ends_at, $midnight, $tz),
                'status'      => $b->status,
                'held'        => $held,
                'cancelled'   => $cancelled,
                'source'      => (string) $b->source,
                'customer'    => trim((string) $b->customer_name),
                'email'       => (string) $b->customer_email,
                'coach_id'    => $b->assigned_coach_id ? (int) $b->assigned_coach_id : 0,
                'coach_label' => $b->assigned_coach_id ? MCLB_Coaches::label((int) $b->assigned_coach_id) : '',
                'price'       => $b->price !== null ? (float) $b->price : null,
                'coach_fee'   => $b->coach_fee !== null ? (float) $b->coach_fee : null,
                'counter_due' => $b->counter_due !== null ? (float) $b->counter_due : null,
                'counter_paid'=> $paid,
                'order_id'    => $b->order_id ? (int) $b->order_id : 0,
                'note'        => (string) $b->admin_note,
                'badges'      => $held ? [] : self::badges($b, $paid, $cancelled),
                'refundable'  => (!$held && !$cancelled && (string) $b->source === 'online' && $b->order_id),
            ];
        }

        // Blockouts (one-off + recurring resolved for this date).
        $blockouts = MCLB_Closures::for_day_detail($date);

        // Coaches (all bookable, with rate) for chips + the filter dropdown, plus
        // each coach's day-sheet status for this date (sent / changed-since-sent).
        $coaches = [];
        foreach (MCLB_Coaches::options() as $cid => $name) {
            $rate    = MCLB_Coaches::rate((int) $cid);
            $st      = MCLB_Daysheets::status((int) $cid, $date);
            $coaches[] = [
                'id'            => (int) $cid,
                'name'          => $name,
                'rate'          => $rate,
                'sheet_sent_at' => $st['sent_at'],
                'sheet_changed' => $st['changed'],
            ];
        }

        return rest_ensure_response([
            'date'      => $date,
            'now'       => current_time('mysql'),
            'increment' => $increment,
            'grid'      => ['open_min' => $min_open, 'close_min' => $max_close],
            'types'     => $grouped,
            'bookings'  => $bookings,
            'blockouts' => $blockouts,
            'coaches'   => $coaches,
            'canRefund' => current_user_can(MCLB_Caps::REFUND),
        ]);
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
        $out = [];
        foreach (MCLB_Coaches::available_options($starts_at, $ends_at, $exclude) as $cid => $name) {
            $rate = MCLB_Coaches::rate((int) $cid);
            if ($rate === null) {
                continue; // can't be booked without a rate
            }
            $out[] = ['id' => (int) $cid, 'name' => $name, 'rate' => (float) $rate];
        }
        return rest_ensure_response(['coaches' => $out]);
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
        $note     = sanitize_textarea_field((string) $request->get_param('note'));

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

        $tz       = wp_timezone();
        $duration = ((new DateTimeImmutable($ends_at, $tz))->getTimestamp() - (new DateTimeImmutable($starts_at, $tz))->getTimestamp()) / 3600;
        $price    = round((float) MCLB_Lane::get_price($lane_id) * $duration, 2);

        $res = MCLB_Bookings::insert_confirmed_locked([
            'lane_id'        => $lane_id,
            'lane_name'      => get_the_title($lane_id),
            'starts_at'      => $starts_at,
            'ends_at'        => $ends_at,
            'price'          => $price,
            'customer_name'  => $name,
            'customer_email' => $email,
            'admin_note'     => $note,
            'coach_id'       => $coach_id,
        ]);
        if (empty($res['ok'])) {
            return self::write_error($res);
        }
        $summary = $coach_id > 0
            ? sprintf(__('created booking with coach %s', 'mclb-lane-booking'), MCLB_Coaches::label($coach_id))
            : __('created booking', 'mclb-lane-booking');
        self::log((int) $res['id'], $actor, $summary);
        return rest_ensure_response(['ok' => true, 'id' => (int) $res['id']]);
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

        // Pass the note through unchanged — the gateway enforces that a counter-paid
        // change carries one. A single audit line is written here, in the standard
        // format, including the note.
        $res = MCLB_Bookings::assign_coach_locked($id, $coach_id, $note);
        if (empty($res['ok'])) {
            return self::write_error($res);
        }
        $summary = $coach_id > 0
            ? sprintf(__('assigned coach %s', 'mclb-lane-booking'), MCLB_Coaches::label($coach_id))
            : __('removed coach', 'mclb-lane-booking');
        if ($note !== '') {
            /* translators: %s: staff note. */
            $summary .= ' ' . sprintf(__('(note: %s)', 'mclb-lane-booking'), $note);
        }
        self::log($id, $actor, $summary);
        return rest_ensure_response(['ok' => true]);
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
        $id   = (int) $request['id'];
        $mode = ($request->get_param('mode') === 'refund') ? 'refund' : 'only';

        if ($mode === 'refund') {
            if (!current_user_can(MCLB_Caps::REFUND)) {
                return new WP_Error('mclb_forbidden', __('You can’t issue refunds.', 'mclb-lane-booking'), ['status' => 403]);
            }
            $b = MCLB_Bookings::get($id);
            if (!$b || (string) $b->source !== 'online' || !$b->order_id) {
                return new WP_Error('mclb_no_refund', __('This booking has no online payment to refund. Use “Cancel only”.', 'mclb-lane-booking'), ['status' => 400]);
            }
            $res = MCLB_Refunds::cancel_and_refund($id, __('Admin cancel + refund', 'mclb-lane-booking'));
            if (empty($res['ok'])) {
                return new WP_Error('mclb_nochange', __('That booking was not in a cancellable state.', 'mclb-lane-booking'), ['status' => 409]);
            }
            self::log($id, $actor, __('cancelled + refunded', 'mclb-lane-booking'));
            return rest_ensure_response(['ok' => true, 'refunded' => !empty($res['refunded'])]);
        }

        if (!MCLB_Bookings::claim_cancel($id)) {
            return new WP_Error('mclb_nochange', __('That booking was not in a cancellable state.', 'mclb-lane-booking'), ['status' => 409]);
        }
        self::log($id, $actor, __('cancelled', 'mclb-lane-booking'));
        return rest_ensure_response(['ok' => true]);
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
