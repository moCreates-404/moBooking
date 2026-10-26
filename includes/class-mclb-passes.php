<?php
/**
 * Prepaid lane-hour passes (Phase 1.5.0, WEB-02). Hours-based bearer passes.
 *
 * The balance is ALWAYS derived from the append-only ledger (SUM of signed
 * `hours`), never a mutable column:
 *   issue  +N   adjust ±X   reserve −h (soft hold on a hold_id)
 *   release +h (reverses a reserve)      redeem −h (+ a paired release that
 *   reverses the reserve, so at confirm the net stays −h but the hours are now a
 *   permanent redemption tied to a booking)   return +h (cancellation)
 *   void −remaining   expire −remaining
 *
 * Covers LANE hours only (coach fees stay counter-due). Whole hours only.
 * Concurrency: every mutating op runs under a per-code GET_LOCK (same pattern as
 * the coach lock) and reads the balance from the ledger inside the lock, so two
 * redemptions can't overdraw. Pricing plugs into the ONE seam via the
 * mclb_lane_price filter; cancellation hooks ONLY mclb_booking_cancelled.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Passes {

    public static function table()        { global $wpdb; return $wpdb->prefix . 'mclb_passes'; }
    public static function ledger_table() { global $wpdb; return $wpdb->prefix . 'mclb_pass_ledger'; }

    public static function init() {
        // ONE pricing seam: zero pass-covered hours for the contextual hold/booking.
        add_filter('mclb_lane_price', [__CLASS__, 'filter_lane_price'], 10, 5);
        // Cancellation: return redeemed hours (hook ONLY this seam).
        add_action('mclb_booking_cancelled', [__CLASS__, 'on_booking_cancelled'], 10, 2);
        // Release reservations whose holds have lapsed, on the existing sweep cron.
        add_action(MCLB_Bookings::CRON_HOOK, [__CLASS__, 'reconcile'], 20);
        // Daily expiry pass.
        add_action(MCLB_Bookings::CRON_HOOK, [__CLASS__, 'expire_due'], 30);
    }

    // ── Schema ─────────────────────────────────────────────────────────────────

    public static function schema($charset_collate) {
        $t = self::table();
        return "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(20) NOT NULL,
  holder_name varchar(191) DEFAULT NULL,
  holder_email varchar(191) DEFAULT NULL,
  holder_phone varchar(32) DEFAULT NULL,
  hours_total decimal(6,2) NOT NULL DEFAULT 0,
  expires_at datetime DEFAULT NULL,
  status varchar(10) NOT NULL DEFAULT 'active',
  note text,
  pos_ref varchar(100) DEFAULT NULL,
  reissued_from bigint(20) unsigned DEFAULT NULL,
  superseded_by bigint(20) unsigned DEFAULT NULL,
  created_by varchar(20) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY holder_email (holder_email),
  KEY status (status)
) ENGINE=InnoDB {$charset_collate};";
    }

    public static function ledger_schema($charset_collate) {
        $t = self::ledger_table();
        return "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pass_id bigint(20) unsigned NOT NULL,
  type varchar(10) NOT NULL,
  hours decimal(6,2) NOT NULL,
  booking_id bigint(20) unsigned DEFAULT NULL,
  hold_id bigint(20) unsigned DEFAULT NULL,
  actor varchar(40) DEFAULT NULL,
  reason text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY pass_id (pass_id),
  KEY booking_id (booking_id),
  KEY hold_id (hold_id)
) ENGINE=InnoDB {$charset_collate};";
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    public static function get_by_code($code) {
        global $wpdb;
        $code = MCLB_Pass_Code::normalise($code);
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE code = %s', $code));
    }

    /** Derived available balance (ledger sum), rounded to 2dp. */
    public static function balance($pass_id) {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(hours),0) FROM ' . self::ledger_table() . ' WHERE pass_id = %d', (int) $pass_id));
        return round((float) $v, 2);
    }

    /** True while the pass can still be drawn from (active + unexpired + balance>0). */
    public static function is_usable($pass) {
        if (!$pass || (string) $pass->status !== 'active') { return false; }
        if ($pass->expires_at !== null && strtotime((string) $pass->expires_at) < time()) { return false; }
        return self::balance((int) $pass->id) > 0;
    }

    /** Live reserved (not yet redeemed/released) whole-hours against one hold. */
    public static function reserved_hours_for_hold($hold_id) {
        global $wpdb;
        // reserve −h, release +h → net negative means a live reserve of |net|.
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(hours),0) FROM ' . self::ledger_table() . " WHERE hold_id = %d AND type IN ('reserve','release')",
            (int) $hold_id
        ));
        return max(0.0, round(-1 * (float) $v, 2));
    }

    /** Still-redeemed (not returned) hours on a confirmed booking. */
    public static function redeemed_hours_for_booking($booking_id) {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(hours),0) FROM ' . self::ledger_table() . " WHERE booking_id = %d AND type IN ('redeem','return')",
            (int) $booking_id
        ));
        return max(0.0, round(-1 * (float) $v, 2));
    }

    /** The pass a hold's reservation belongs to (or 0). */
    public static function pass_for_hold($hold_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT pass_id FROM ' . self::ledger_table() . " WHERE hold_id = %d AND type = 'reserve' ORDER BY id DESC LIMIT 1", (int) $hold_id));
    }

    public static function pass_for_booking($booking_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT pass_id FROM ' . self::ledger_table() . " WHERE booking_id = %d AND type = 'redeem' ORDER BY id DESC LIMIT 1", (int) $booking_id));
    }

    /** Hold ids this pass currently holds a live reservation against. */
    public static function reserved_holds($pass_id) {
        global $wpdb;
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT hold_id FROM ' . self::ledger_table() . " WHERE pass_id = %d AND hold_id IS NOT NULL AND type IN ('reserve','release') GROUP BY hold_id HAVING SUM(hours) < 0",
            (int) $pass_id
        )));
    }

    /** Redeem reserves for every confirmed booking on an order (online confirm). */
    public static function redeem_for_order($order_id) {
        foreach (MCLB_Bookings::for_order((int) $order_id) as $b) {
            if ((string) $b->status !== MCLB_Bookings::STATUS_CONFIRMED) { continue; }
            if (self::reserved_hours_for_hold((int) $b->id) <= 0) { continue; }
            $pid  = self::pass_for_hold((int) $b->id);
            $pass = $pid ? self::get($pid) : null;
            if ($pass && self::lock($pass->code)) {
                try { self::redeem_hold((int) $b->id, (int) $b->id, 'online'); }
                finally { self::unlock($pass->code); }
            } else {
                self::redeem_hold((int) $b->id, (int) $b->id, 'online');
            }
        }
    }

    public static function ledger_for_pass($pass_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::ledger_table() . ' WHERE pass_id = %d ORDER BY id', (int) $pass_id));
    }

    // ── Locking (per code, same pattern as the coach lock) ─────────────────────

    private static function lock_name($code) { return 'mclb_pass_' . md5((string) $code); }
    public static function lock($code, $timeout = 5) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', self::lock_name($code), (int) $timeout)) === '1';
    }
    public static function unlock($code) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($code)));
    }

    // ── Ledger write ───────────────────────────────────────────────────────────

    private static function add_row($pass_id, $type, $hours, $args = []) {
        global $wpdb;
        $wpdb->insert(self::ledger_table(), [
            'pass_id'    => (int) $pass_id,
            'type'       => (string) $type,
            'hours'      => round((float) $hours, 2),
            'booking_id' => isset($args['booking_id']) ? (int) $args['booking_id'] : null,
            'hold_id'    => isset($args['hold_id']) ? (int) $args['hold_id'] : null,
            'actor'      => isset($args['actor']) ? mb_substr((string) $args['actor'], 0, 40) : null,
            'reason'     => isset($args['reason']) ? (string) $args['reason'] : null,
            'created_at' => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    // ── Issue / adjust / void / reissue / expire ───────────────────────────────

    /**
     * Issue a new pass. $data: holder_name/email/phone, hours, expires_at (or
     * null), note, pos_ref, created_by. Returns the pass row (with ->code) or
     * WP_Error. Staff-only is enforced by callers. Designed so a WC "pass product"
     * could call this later.
     */
    public static function issue(array $data) {
        global $wpdb;
        $hours = round((float) ($data['hours'] ?? 0), 2);
        if ($hours <= 0) { return new WP_Error('mclb_pass_hours', __('Enter the number of hours.', 'mclb-lane-booking')); }
        // Unique code (retry on the astronomically-unlikely collision).
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $try = MCLB_Pass_Code::generate();
            if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE code = %s', $try))) { $code = $try; break; }
        }
        if ($code === '') { return new WP_Error('mclb_pass_code', __('Could not generate a unique code — try again.', 'mclb-lane-booking')); }

        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'code'          => $code,
            'holder_name'   => sanitize_text_field((string) ($data['holder_name'] ?? '')),
            'holder_email'  => sanitize_email((string) ($data['holder_email'] ?? '')),
            'holder_phone'  => isset($data['holder_phone']) ? MCLB_Phone::clean((string) $data['holder_phone']) : '',
            'hours_total'   => $hours,
            'expires_at'    => !empty($data['expires_at']) ? (string) $data['expires_at'] : null,
            'status'        => 'active',
            'note'          => sanitize_textarea_field((string) ($data['note'] ?? '')),
            'pos_ref'       => sanitize_text_field((string) ($data['pos_ref'] ?? '')),
            'reissued_from' => !empty($data['reissued_from']) ? (int) $data['reissued_from'] : null,
            'created_by'    => mb_substr(sanitize_text_field((string) ($data['created_by'] ?? '')), 0, 20),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $pass_id = (int) $wpdb->insert_id;
        self::add_row($pass_id, 'issue', $hours, ['actor' => $data['created_by'] ?? '', 'reason' => $data['pos_ref'] ?? '']);
        return self::get($pass_id);
    }

    /** Admin-only ± adjustment with a reason. */
    public static function adjust($pass_id, $delta, $reason, $actor) {
        $pass = self::get($pass_id);
        if (!$pass) { return new WP_Error('mclb_pass_nf', __('Pass not found.', 'mclb-lane-booking')); }
        $delta = round((float) $delta, 2);
        if ($delta === 0.0) { return new WP_Error('mclb_pass_zero', __('Enter a non-zero adjustment.', 'mclb-lane-booking')); }
        if (trim((string) $reason) === '') { return new WP_Error('mclb_pass_reason', __('A reason is required.', 'mclb-lane-booking')); }
        if ($delta < 0 && self::balance((int) $pass_id) + $delta < 0) {
            return new WP_Error('mclb_pass_over', __('That would take the balance below zero.', 'mclb-lane-booking'));
        }
        self::add_row((int) $pass_id, 'adjust', $delta, ['actor' => $actor, 'reason' => $reason]);
        return true;
    }

    /** Void a pass: zero its balance + mark void. */
    public static function void($pass_id, $actor, $reason = '') {
        $pass = self::get($pass_id);
        if (!$pass || (string) $pass->status !== 'active') { return new WP_Error('mclb_pass_nf', __('Pass not found or not active.', 'mclb-lane-booking')); }
        $bal = self::balance((int) $pass_id);
        if ($bal > 0) { self::add_row((int) $pass_id, 'void', -1 * $bal, ['actor' => $actor, 'reason' => $reason]); }
        self::set_status((int) $pass_id, 'void');
        return true;
    }

    /** Void + reissue: new pass carries the voided balance. Returns the new pass. */
    public static function reissue($pass_id, $actor) {
        $old = self::get($pass_id);
        if (!$old || (string) $old->status !== 'active') { return new WP_Error('mclb_pass_nf', __('Pass not found or not active.', 'mclb-lane-booking')); }
        $bal = self::balance((int) $pass_id);
        $new = self::issue([
            'holder_name' => $old->holder_name, 'holder_email' => $old->holder_email, 'holder_phone' => $old->holder_phone,
            'hours' => $bal > 0 ? $bal : 0.01, 'expires_at' => $old->expires_at, 'note' => $old->note,
            'pos_ref' => $old->pos_ref, 'created_by' => $actor, 'reissued_from' => (int) $pass_id,
        ]);
        if (is_wp_error($new)) { return $new; }
        // Zero + void the old one (no carry left).
        self::void((int) $pass_id, $actor, sprintf(__('reissued as %s', 'mclb-lane-booking'), $new->code));
        self::set_superseded((int) $pass_id, (int) $new->id);
        return $new;
    }

    /** Cron: expire active passes past their date, zeroing any remaining balance. */
    public static function expire_due() {
        global $wpdb;
        $now  = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . self::table() . " WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s", $now));
        foreach ($rows as $r) {
            $bal = self::balance((int) $r->id);
            if ($bal > 0) { self::add_row((int) $r->id, 'expire', -1 * $bal, ['reason' => 'auto-expire']); }
            self::set_status((int) $r->id, 'expired');
        }
    }

    private static function set_status($pass_id, $status) {
        global $wpdb;
        $wpdb->update(self::table(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => (int) $pass_id]);
    }
    private static function set_superseded($pass_id, $new_id) {
        global $wpdb;
        $wpdb->update(self::table(), ['superseded_by' => (int) $new_id, 'updated_at' => current_time('mysql')], ['id' => (int) $pass_id]);
    }

    // ── Reserve / redeem / release / return ────────────────────────────────────

    /**
     * Reserve whole hours for a hold under the per-code lock. Expiry is judged at
     * reserve/booking time (fix f): a pass unexpired now is usable even if the
     * session is later. Returns ['ok'=>bool,'hours'=>float,'error'?]. The caller
     * must already hold (or this takes) the lock; pass $locked=true if held.
     */
    public static function reserve($pass_id, $hold_id, $hours, $actor = '', $locked = false) {
        $pass = self::get($pass_id);
        if (!$pass) { return ['ok' => false, 'error' => 'not_found']; }
        $code = $pass->code;
        if (!$locked && !self::lock($code)) { return ['ok' => false, 'error' => 'busy']; }
        try {
            if (!self::is_usable($pass)) { return ['ok' => false, 'error' => 'unusable']; }
            $want = (float) floor((float) $hours);
            $avail = (float) floor(self::balance((int) $pass_id));
            $take  = min($want, $avail);
            if ($take <= 0) { return ['ok' => false, 'error' => 'empty', 'hours' => 0.0]; }
            self::add_row((int) $pass_id, 'reserve', -1 * $take, ['hold_id' => (int) $hold_id, 'actor' => $actor]);
            return ['ok' => true, 'hours' => $take];
        } finally {
            if (!$locked) { self::unlock($code); }
        }
    }

    /** Release a hold's live reservation back to the balance. */
    public static function release_hold($hold_id, $reason = '') {
        $h = self::reserved_hours_for_hold($hold_id);
        if ($h <= 0) { return false; }
        $pid = self::pass_for_hold($hold_id);
        if (!$pid) { return false; }
        self::add_row($pid, 'release', $h, ['hold_id' => (int) $hold_id, 'reason' => $reason]);
        return true;
    }

    /**
     * Redeem a hold's live reservation permanently against the confirmed booking:
     * release the reserve (+h) + write redeem (−h, booking_id). Net unchanged, but
     * the hours are now a permanent redemption. Stamps the booking snapshot.
     */
    public static function redeem_hold($hold_id, $booking_id, $actor = '') {
        $h = self::reserved_hours_for_hold($hold_id);
        if ($h <= 0) { return 0.0; }
        $pid = self::pass_for_hold($hold_id);
        if (!$pid) { return 0.0; }
        self::add_row($pid, 'release', $h, ['hold_id' => (int) $hold_id, 'reason' => 'reserve→redeem']);
        self::add_row($pid, 'redeem', -1 * $h, ['hold_id' => (int) $hold_id, 'booking_id' => (int) $booking_id, 'actor' => $actor]);
        self::stamp_booking((int) $booking_id, $pid, $h);
        // Lower the booking's stored price to the net card amount so a later refund
        // only ever refunds what the card paid (pass hours return, never refund).
        MCLB_Bookings::apply_pass_price((int) $booking_id);
        self::maybe_holder_email($pid, $h, 'used', (int) $booking_id);
        return $h;
    }

    /** Draw hours directly onto an already-confirmed booking (staff path). */
    public static function redeem_booking($pass_id, $booking_id, $hours, $actor = '', $locked = false) {
        $pass = self::get($pass_id);
        if (!$pass) { return ['ok' => false, 'error' => 'not_found']; }
        $code = $pass->code;
        if (!$locked && !self::lock($code)) { return ['ok' => false, 'error' => 'busy']; }
        try {
            if (!self::is_usable($pass)) { return ['ok' => false, 'error' => 'unusable']; }
            $want  = (float) floor((float) $hours);
            $avail = (float) floor(self::balance((int) $pass_id));
            $take  = min($want, $avail);
            if ($take <= 0) { return ['ok' => false, 'error' => 'empty', 'hours' => 0.0]; }
            self::add_row((int) $pass_id, 'redeem', -1 * $take, ['booking_id' => (int) $booking_id, 'actor' => $actor]);
            self::stamp_booking((int) $booking_id, (int) $pass_id, $take);
            self::maybe_holder_email((int) $pass_id, $take, 'used', (int) $booking_id);
            return ['ok' => true, 'hours' => $take];
        } finally {
            if (!$locked) { self::unlock($code); }
        }
    }

    /** Return a confirmed booking's redeemed hours to the pass (cancellation). */
    public static function return_booking($booking_id, $reason = '') {
        $h = self::redeemed_hours_for_booking($booking_id);
        if ($h <= 0) { return null; }
        $pid = self::pass_for_booking($booking_id);
        if (!$pid) { return null; }
        self::add_row($pid, 'return', $h, ['booking_id' => (int) $booking_id, 'reason' => $reason]);
        self::stamp_booking((int) $booking_id, null, null);
        self::maybe_holder_email($pid, $h, 'returned', (int) $booking_id);
        $pass = self::get($pid);
        return ['pass_id' => $pid, 'code' => $pass ? $pass->code : '', 'hours' => $h, 'balance' => self::balance($pid), 'expires_at' => $pass ? $pass->expires_at : null];
    }

    /**
     * A moved booking keeps its pass hours when the duration is unchanged; a
     * shorter session returns the excess, a longer one draws the difference if the
     * pass still has it (else the remainder is priced normally). (v1.5.0)
     */
    public static function adjust_for_move($booking_id, $starts, $ends) {
        $covered = self::redeemed_hours_for_booking((int) $booking_id);
        if ($covered <= 0) { return; }
        $pid  = self::pass_for_booking((int) $booking_id);
        $pass = $pid ? self::get($pid) : null;
        if (!$pass) { return; }
        $new_dur = (float) floor(MCLB_Bookings::hours_between($starts, $ends));
        if ($new_dur == $covered) { return; }
        if (!self::lock($pass->code)) { return; }
        try {
            if ($new_dur < $covered) {
                $back = $covered - $new_dur;
                self::add_row($pid, 'return', $back, ['booking_id' => (int) $booking_id, 'reason' => 'move: shorter session']);
                self::stamp_booking((int) $booking_id, $pid, $new_dur);
            } else {
                $need  = $new_dur - $covered;
                $avail = self::is_usable($pass) ? (float) floor(self::balance($pid)) : 0.0;
                $draw  = min($need, $avail);
                if ($draw > 0) {
                    self::add_row($pid, 'redeem', -1 * $draw, ['booking_id' => (int) $booking_id, 'reason' => 'move: longer session']);
                    self::stamp_booking((int) $booking_id, $pid, $covered + $draw);
                }
            }
        } finally {
            self::unlock($pass->code);
        }
    }

    // ── Cancellation + sweep hooks ─────────────────────────────────────────────

    /** Fired on mclb_booking_cancelled: return any redeemed hours. */
    public static function on_booking_cancelled($booking, $outcome) {
        if (empty($booking->id)) { return; }
        $r = self::return_booking((int) $booking->id, __('booking cancelled', 'mclb-lane-booking'));
        if ($r) {
            // Stash for the cancellation-email extra line (same request).
            $GLOBALS['mclb_pass_return'][(int) $booking->id] = $r;
        }
    }

    /** Release reservations whose holds are no longer live (abandoned carts). */
    public static function reconcile() {
        global $wpdb;
        $bt = MCLB_Bookings::table();
        $lt = self::ledger_table();
        $now = current_time('mysql');
        // Hold ids with a live reserve (reserve minus release still negative).
        $rows = $wpdb->get_results(
            "SELECT hold_id, SUM(hours) AS net FROM $lt WHERE hold_id IS NOT NULL AND type IN ('reserve','release') GROUP BY hold_id HAVING net < 0"
        );
        foreach ($rows as $r) {
            $hid  = (int) $r->hold_id;
            $live = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $bt WHERE id = %d AND status = %s AND (hold_expires_at IS NULL OR hold_expires_at > %s) LIMIT 1",
                $hid, MCLB_Bookings::STATUS_HELD, $now
            ));
            // Still a live hold? leave it. Also leave if it became a confirmed
            // booking that was redeemed (redeem row exists) — not our concern here.
            $redeemed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lt WHERE hold_id = %d AND type = 'redeem'", $hid));
            if (!$live && $redeemed === 0) {
                self::release_hold($hid, 'abandoned hold swept');
            }
        }
    }

    // ── Pricing seam ───────────────────────────────────────────────────────────

    /** mclb_lane_price filter: subtract pass-covered hours for the context row. */
    public static function filter_lane_price($price, $lane_id, $starts_at, $ends_at, $context) {
        $h = 0.0;
        if (!empty($context['hold_id'])) {
            $h = self::reserved_hours_for_hold((int) $context['hold_id']);
        } elseif (!empty($context['booking_id'])) {
            $h = self::redeemed_hours_for_booking((int) $context['booking_id']);
        }
        if ($h <= 0) { return $price; }
        $rate = (float) MCLB_Lane::get_price((int) $lane_id);
        return max(0.0, round((float) $price - $h * $rate, 2));
    }

    // ── Allocation (earliest-start-first, whole hours) ─────────────────────────

    /**
     * Allocate a pass across holds (cart) earliest-start-first, whole hours, up to
     * the balance. Returns [hold_id => hours]. Does NOT write the ledger; the cart
     * reserves per the result under the lock.
     */
    public static function allocate($pass_id, array $holds) {
        usort($holds, function ($a, $b) { return strcmp((string) $a->starts_at, (string) $b->starts_at); });
        $left = (float) floor(self::balance((int) $pass_id));
        $out  = [];
        foreach ($holds as $h) {
            if ($left <= 0) { break; }
            $dur = (float) floor(MCLB_Bookings::hours_between($h->starts_at, $h->ends_at));
            $take = min($dur, $left);
            if ($take > 0) { $out[(int) $h->id] = $take; $left -= $take; }
        }
        return $out;
    }

    // ── Booking snapshot (two columns for the calendar, no ledger lookups) ─────

    private static function stamp_booking($booking_id, $pass_id, $hours) {
        global $wpdb;
        $wpdb->update(MCLB_Bookings::table(), [
            'pass_id'    => $pass_id !== null ? (int) $pass_id : null,
            'pass_hours' => $hours !== null ? round((float) $hours, 2) : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => (int) $booking_id]);
    }

    // ── Settings + holder email ─────────────────────────────────────────────────

    public static function default_expiry_months() { return max(0, (int) MCLB_Settings::get('pass_expiry_months')); }

    /** Y-m-d H:i:s expiry from today + the default months, or null when 0. */
    public static function default_expiry_datetime() {
        $m = self::default_expiry_months();
        if ($m <= 0) { return null; }
        return (new DateTimeImmutable('now', wp_timezone()))->modify('+' . $m . ' months')->format('Y-m-d H:i:s');
    }

    // ── Holder balance emails ────────────────────────────────────────────────
    // A series draw/cancel touches the same pass several times; batching collapses
    // those into ONE summary per pass+kind instead of one email per occurrence.
    private static $holder_batch = null;

    /** Start collecting holder balance emails instead of sending per occurrence. */
    public static function begin_holder_batch() {
        if (self::$holder_batch === null) { self::$holder_batch = []; }
    }

    /** Send one summary per pass+kind collected since begin_holder_batch(). */
    public static function flush_holder_batch() {
        if (self::$holder_batch === null) { return; }
        $batch = self::$holder_batch;
        self::$holder_batch = null;
        foreach ($batch as $e) {
            self::send_holder_email((int) $e['pass_id'], (float) $e['hours'], (string) $e['kind'], (int) $e['count'], $e['booking_ids']);
        }
    }

    private static function maybe_holder_email($pass_id, $hours, $kind, $booking_id = 0) {
        if ((int) MCLB_Settings::get('pass_holder_email') !== 1 || !class_exists('MCLB_Mail')) { return; }
        if (self::$holder_batch !== null) {
            $k = (int) $pass_id . ':' . $kind;
            if (!isset(self::$holder_batch[$k])) {
                self::$holder_batch[$k] = ['pass_id' => (int) $pass_id, 'kind' => $kind, 'hours' => 0.0, 'count' => 0, 'booking_ids' => []];
            }
            self::$holder_batch[$k]['hours'] += (float) $hours;
            self::$holder_batch[$k]['count'] += 1;
            if ($booking_id) { self::$holder_batch[$k]['booking_ids'][] = (int) $booking_id; }
            return;
        }
        self::send_holder_email((int) $pass_id, (float) $hours, $kind, 1, $booking_id ? [(int) $booking_id] : []);
    }

    /** The actual holder email — summarises $count sessions (dates listed when >1). */
    private static function send_holder_email($pass_id, $hours, $kind, $count = 1, array $booking_ids = []) {
        if (!class_exists('MCLB_Mail')) { return; }
        $pass = self::get($pass_id);
        if (!$pass || sanitize_email((string) $pass->holder_email) === '') { return; }
        $bal  = self::balance($pass_id);
        $hh   = rtrim(rtrim(number_format((float) $hours, 2), '0'), '.');
        $bb   = rtrim(rtrim(number_format((float) $bal, 2), '0'), '.');
        $n    = max(1, (int) $count);
        $heading = __('Your prepaid pass', 'mclb-lane-booking');
        if ($kind === 'returned') {
            $body = ($n > 1)
                /* translators: 1: hours, 2: pass code, 3: number of sessions, 4: balance. */
                ? '<p>' . sprintf(esc_html__('%1$sh have been returned to your pass %2$s across %3$d sessions. Balance: %4$sh.', 'mclb-lane-booking'), esc_html($hh), esc_html($pass->code), $n, esc_html($bb)) . '</p>'
                /* translators: 1: hours, 2: pass code, 3: balance. */
                : '<p>' . sprintf(esc_html__('%1$sh have been returned to your pass %2$s. Balance: %3$sh.', 'mclb-lane-booking'), esc_html($hh), esc_html($pass->code), esc_html($bb)) . '</p>';
        } else {
            $body = ($n > 1)
                /* translators: 1: hours, 2: pass code, 3: number of sessions, 4: balance. */
                ? '<p>' . sprintf(esc_html__('%1$sh used from your pass %2$s across %3$d sessions. Balance: %4$sh.', 'mclb-lane-booking'), esc_html($hh), esc_html($pass->code), $n, esc_html($bb)) . '</p>'
                /* translators: 1: hours, 2: pass code, 3: balance. */
                : '<p>' . sprintf(esc_html__('%1$sh used from your pass %2$s. Balance: %3$sh.', 'mclb-lane-booking'), esc_html($hh), esc_html($pass->code), esc_html($bb)) . '</p>';
        }
        if ($n > 1) {
            $dates = self::booking_dates($booking_ids);
            if ($dates !== '') {
                /* translators: %s: comma-separated session dates. */
                $body .= '<p style="color:#666;">' . esc_html(sprintf(__('Sessions: %s', 'mclb-lane-booking'), $dates)) . '</p>';
            }
        }
        if ($pass->expires_at) {
            $body .= '<p style="color:#666;">' . sprintf(esc_html__('Pass expires %s (use it to BOOK before then; your session can be later).', 'mclb-lane-booking'), esc_html(wp_date('j M Y', strtotime((string) $pass->expires_at)))) . '</p>';
        }
        MCLB_Mail::send($pass->holder_email, $heading . ' — ' . (wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'Cricketers Club'), $body, [
            'heading' => $heading, 'branded' => true, 'tag' => 'pass-balance',
        ]);
    }

    /** Chronological comma list of session dates for a set of bookings, or ''. */
    private static function booking_dates(array $booking_ids) {
        $booking_ids = array_values(array_unique(array_filter(array_map('intval', $booking_ids))));
        if (empty($booking_ids)) { return ''; }
        $tz = wp_timezone(); $out = [];
        foreach ($booking_ids as $bid) {
            $b = MCLB_Bookings::get($bid);
            if ($b && $b->starts_at) {
                $out[(string) $b->starts_at] = wp_date('j M Y', (new DateTimeImmutable($b->starts_at, $tz))->getTimestamp());
            }
        }
        ksort($out);
        return implode(', ', array_values($out));
    }
}
