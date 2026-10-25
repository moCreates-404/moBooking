<?php
/**
 * Cancellation coordinator (Phase 1.4.3). ONE place every cancel path funnels
 * through, so:
 *   - a single action fires per cancelled booking — do_action('mclb_booking_cancelled',
 *     $booking, $outcome) — the seam v1.5.0 prepaid passes hook to return hours;
 *   - the customer + Bookings cancellation emails (WEB-12) dispatch consistently;
 *   - the tiered self-cancel policy is shared by logged-in (MCLB_Account) and guest
 *     (MCLB_Manage_Booking) flows (WEB-17).
 *
 * finish() is only ever called by the caller that WON the claim_cancel() flip, so
 * a plugin-initiated cancel that later trips the WC order hook can't double-send.
 *
 * $outcome shape:
 *   type        refund_card | refund_failed | store_credit | counter_refund |
 *               paid_not_refunded | manual_no_refund | order_cancelled |
 *               order_refunded | cancelled | no_refund_cutoff
 *   amount      float (money involved, 0 when none)
 *   credit_code string (store_credit)
 *   credit_expires int|null  unix ts, null = never (store_credit)
 *   actor       'customer' | staff initials | 'WooCommerce order'
 *   actor_note  optional human string for the staff alert ("via email link")
 *   manual_flag bool  → staff "handle manually"
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Cancel {

    /** Settings gates. */
    private static function customer_on() { return (int) MCLB_Settings::get('email_customer_on_cancel') === 1; }
    private static function alert_on()    { return (int) MCLB_Settings::get('alert_cancel') === 1; }

    /**
     * Fire the single seam + dispatch emails for ONE cancelled booking. Call this
     * ONLY after winning claim_cancel() (or from cancel_and_refund, which does).
     * $opts: notify (bool, default true — the staff "Notify customer" box),
     *        batch (bool — a series member; suppresses the per-booking emails so
     *        the series path can send one combined email, but still fires the seam).
     */
    public static function finish($booking, array $outcome, array $opts = []) {
        do_action('mclb_booking_cancelled', $booking, $outcome);
        if (!empty($opts['batch'])) {
            return;
        }
        $notify = array_key_exists('notify', $opts) ? (bool) $opts['notify'] : true;
        if (self::alert_on() && class_exists('MCLB_Emails')) {
            MCLB_Emails::alert_cancel($booking, $outcome);
        }
        if ($notify && self::customer_on() && sanitize_email((string) $booking->customer_email) !== '' && class_exists('MCLB_Emails')) {
            MCLB_Emails::send_cancellation($booking, $outcome);
        }
    }

    /** Was this booking paid online by card (a refund would hit the card)? */
    public static function is_online_paid($b) {
        return ((string) $b->source === 'online' && $b->order_id && (float) $b->price > 0);
    }

    // ── Shared self-cancel policy (logged-in + guest) ──────────────────────────

    /**
     * The tier a customer self-cancel would land in, WITHOUT acting.
     * @return array{tier:'refund'|'credit'|'blocked',hours_out:float}
     */
    public static function self_cancel_tier($b) {
        $tz        = wp_timezone();
        $now       = new DateTimeImmutable('now', $tz);
        $hours_out = ((new DateTimeImmutable((string) $b->starts_at, $tz))->getTimestamp() - $now->getTimestamp()) / 3600;
        $cutoff    = (int) MCLB_Settings::get('self_cancel_hours');
        if ($hours_out < $cutoff) {
            return ['tier' => 'blocked', 'hours_out' => $hours_out];
        }
        $since  = ($now->getTimestamp() - (new DateTimeImmutable((string) $b->created_at, $tz))->getTimestamp()) / 3600;
        $window = (int) MCLB_Settings::get('refund_window_hours');
        return ['tier' => ($since <= $window ? 'refund' : 'credit'), 'hours_out' => $hours_out];
    }

    /**
     * Perform a customer self-cancel (logged-in or guest). Reuses the existing
     * money paths — no new money logic. Returns the result + the outcome.
     *
     * @return array{ok:bool,blocked?:bool,outcome?:array}
     */
    public static function self_cancel($b, $actor_label = 'customer', array $opts = []) {
        $tier = self::self_cancel_tier($b)['tier'];
        if ($tier === 'blocked') {
            return ['ok' => false, 'blocked' => true];
        }
        $actor_note = isset($opts['actor_note']) ? (string) $opts['actor_note'] : '';
        $notify     = array_key_exists('notify', $opts) ? (bool) $opts['notify'] : true;
        $via_link   = !empty($opts['via_link']); // guest email-link cancel → "didn't cancel this?" line

        // Manual / counter / not-card-paid: cancel, but never an automatic refund.
        if (!self::is_online_paid($b)) {
            if (!MCLB_Bookings::claim_cancel((int) $b->id)) {
                return ['ok' => false];
            }
            $paid_counter = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00' && $b->counter_due !== null);
            $outcome = [
                'type'        => $paid_counter ? 'counter_refund' : ((string) $b->source === 'manual' ? 'manual_no_refund' : 'cancelled'),
                'amount'      => $paid_counter ? (float) $b->counter_due : 0.0,
                'actor'       => $actor_label,
                'actor_note'  => $actor_note,
                'via_link'    => $via_link,
                'manual_flag' => $paid_counter || (string) $b->source === 'manual',
            ];
            self::finish($b, $outcome, ['notify' => $notify]);
            return ['ok' => true, 'outcome' => $outcome];
        }

        if ($tier === 'refund') {
            // Real card refund via the existing atomic path (which fires finish()).
            $res = MCLB_Refunds::cancel_and_refund((int) $b->id, __('Customer self-cancel (within refund window)', 'mclb-lane-booking'), [
                'actor' => $actor_label, 'actor_note' => $actor_note, 'notify' => $notify, 'via_link' => $via_link,
            ]);
            return ['ok' => !empty($res['ok']), 'outcome' => $res['outcome'] ?? null];
        }

        // Store-credit tier.
        if (!MCLB_Bookings::claim_cancel((int) $b->id)) {
            return ['ok' => false];
        }
        $credit = MCLB_Account::issue_credit_coupon($b); // ['code'=>..,'expires'=>ts|null]
        if (!empty($b->order_id) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $b->order_id);
            if ($order) {
                /* translators: 1: booking id, 2: coupon code. */
                $order->add_order_note(sprintf(__('Lane booking #%1$d self-cancelled by customer; store-credit coupon %2$s issued.', 'mclb-lane-booking'), (int) $b->id, $credit['code']));
            }
        }
        $outcome = [
            'type'           => 'store_credit',
            'amount'         => (float) $b->price,
            'credit_code'    => $credit['code'],
            'credit_expires' => $credit['expires'],
            'actor'          => $actor_label,
            'actor_note'     => $actor_note,
            'via_link'       => $via_link,
            'manual_flag'    => false,
        ];
        self::finish($b, $outcome, ['notify' => $notify]);
        return ['ok' => true, 'outcome' => $outcome];
    }
}
