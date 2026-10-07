<?php
/**
 * Coach add-on (Phase 7b) — the generic layer between moBooking and whatever
 * supplies coaches on a given install. The plugin ships only the filter
 * contract; the theme (CCWA) returns real coaches/rates/emails:
 *
 *   mclb_coach_options            → [ coach_id => display name ]  (bookable only)
 *   mclb_coach_rate  ($rate,$id)  → float hourly rate, or null when unset
 *   mclb_coach_contact($email,$id)→ email string, or ''
 *
 * A rate of 0.0 is valid (a free coach); null means "no rate set" and blocks
 * assignment (see MCLB_Bookings::assign_coach_locked) so a coach is never
 * silently booked at $0 by accident.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Coaches {

    /** [coach_id => name] for bookable coaches (filter decides who's bookable). */
    public static function options() {
        $opts = apply_filters('mclb_coach_options', []);
        return is_array($opts) ? $opts : [];
    }

    /** Hourly rate for a coach: float, or null when unset. 0.0 is a real rate. */
    public static function rate($coach_id) {
        $rate = apply_filters('mclb_coach_rate', null, (int) $coach_id);
        return ($rate === null || $rate === '') ? null : (float) $rate;
    }

    /** Contact email for a coach (daily sheets, Phase 7d), or '' when unset. */
    public static function contact($coach_id) {
        return sanitize_email((string) apply_filters('mclb_coach_contact', '', (int) $coach_id));
    }

    /** Is this id a currently-bookable coach? */
    public static function is_bookable($coach_id) {
        return array_key_exists((int) $coach_id, self::options());
    }

    /**
     * Display name with a graceful fallback: a coach assigned to a booking but
     * later removed from the list still shows a name, never blank or a notice.
     */
    public static function label($coach_id) {
        $coach_id = (int) $coach_id;
        $opts     = self::options();
        if (isset($opts[$coach_id])) {
            return (string) $opts[$coach_id];
        }
        $title = get_the_title($coach_id);
        if ($title) {
            /* translators: %s: coach name of a coach no longer in the bookable list. */
            return sprintf(__('%s (unlisted)', 'mclb-lane-booking'), $title);
        }
        /* translators: %d: coach id. */
        return sprintf(__('Coach #%d', 'mclb-lane-booking'), $coach_id);
    }

    // ── Fee math ─────────────────────────────────────────────────────────────

    /** Whole booking length in hours (local wall-clock), e.g. 1.5 for 90 min. */
    public static function hours($starts_at, $ends_at) {
        $tz = wp_timezone();
        $s  = (new DateTimeImmutable((string) $starts_at, $tz))->getTimestamp();
        $e  = (new DateTimeImmutable((string) $ends_at, $tz))->getTimestamp();
        return max(0, ($e - $s) / 3600);
    }

    /** Coach fee for the whole booking: rate × hours, rounded to 2dp. */
    public static function fee($rate, $starts_at, $ends_at) {
        return round((float) $rate * self::hours($starts_at, $ends_at), 2);
    }

    // ── Availability (coach double-book is a hard block) ──────────────────────

    /**
     * Coach ids with a confirmed or still-live-held booking overlapping
     * [$starts_at, $ends_at). Batch (one query) for the Manage view / add screen
     * to offer only free coaches. $exclude skips one booking (when reassigning).
     *
     * This is the UI convenience; the authoritative guard is the FOR-UPDATE lock
     * in MCLB_Bookings::assign_coach_locked / attempt_confirmed.
     *
     * @return int[] distinct coach ids
     */
    public static function busy_ids($starts_at, $ends_at, $exclude_booking_id = 0) {
        global $wpdb;
        $bt  = MCLB_Bookings::table();
        $now = current_time('mysql');
        $sql = "SELECT DISTINCT assigned_coach_id FROM $bt
                  WHERE assigned_coach_id IS NOT NULL
                    AND (status = %s OR (status = %s AND hold_expires_at > %s))
                    AND starts_at < %s AND ends_at > %s";
        $params = [MCLB_Bookings::STATUS_CONFIRMED, MCLB_Bookings::STATUS_HELD, $now, $ends_at, $starts_at];
        if ($exclude_booking_id) {
            $sql     .= ' AND id <> %d';
            $params[] = (int) $exclude_booking_id;
        }
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
    }

    /**
     * Bookable coaches free for a slot: options() minus busy_ids(). $exclude skips
     * the booking being edited so its own coach stays listed.
     *
     * @return array [coach_id => name]
     */
    public static function available_options($starts_at, $ends_at, $exclude_booking_id = 0) {
        $busy = array_flip(self::busy_ids($starts_at, $ends_at, $exclude_booking_id));
        $out  = [];
        foreach (self::options() as $id => $name) {
            if (!isset($busy[(int) $id])) {
                $out[$id] = $name;
            }
        }
        return $out;
    }

    // ── Labels ────────────────────────────────────────────────────────────────

    public static function label_singular() {
        return MCLB_Settings::get('staff_label_singular') ?: 'Coach';
    }

    public static function label_plural() {
        return MCLB_Settings::get('staff_label_plural') ?: 'Coaches';
    }

    /** Amount formatted with the store currency symbol, for notes/UI. */
    public static function money($amount) {
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price((float) $amount));
        }
        return number_format((float) $amount, 2);
    }
}
