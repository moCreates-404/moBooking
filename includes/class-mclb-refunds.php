<?php
/**
 * Cancel/refund helper (Phase 5/6) — shared by the customer self-cancel flow
 * (MCLB_Account) and the admin cancel/refund action (MCLB_Bookings_Admin).
 *
 * The atomic status flip (confirmed → cancelled) lives INSIDE cancel_and_refund()
 * and claim_cancel(), so the booking is the mutex: only the first caller wins the
 * flip and proceeds to release + refund; a second call finds it no longer
 * 'confirmed' and does nothing. Neither caller can double-release or double-refund.
 *
 * Refunds go through WooCommerce (wc_create_refund with refund_payment => true),
 * which invokes the gateway's own refund (Square supports it), scoped to the one
 * order line item for that booking — the multi-lane partial-refund case that a
 * whole-order status change would otherwise miss.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Refunds {

    /**
     * Atomically cancel a confirmed booking (release its slot) AND refund its
     * order line. Idempotent: a second call is a no-op.
     *
     * @return array{ok:bool,error?:string,refunded?:bool,amount?:float}
     */
    public static function cancel_and_refund($booking_id, $reason = '', array $ctx = []) {
        $b = MCLB_Bookings::get($booking_id);
        if (!$b) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!MCLB_Bookings::claim_cancel($booking_id)) {
            return ['ok' => false, 'error' => 'not_confirmed']; // already cancelled / never confirmed
        }
        // Slot released the instant claim_cancel() flipped the status.
        // $0 card part (e.g. a fully prepaid-pass-covered booking): nothing to
        // refund — cancel cleanly, no $0 refund call, no "refund" wording. The
        // pass hours return via the mclb_booking_cancelled hook (fix c, v1.5.0).
        if ((float) $b->price <= 0) {
            $outcome = [
                'type' => 'cancelled', 'amount' => 0.0,
                'actor' => isset($ctx['actor']) ? (string) $ctx['actor'] : '',
                'actor_note' => isset($ctx['actor_note']) ? (string) $ctx['actor_note'] : '',
                'via_link' => !empty($ctx['via_link']), 'manual_flag' => false,
            ];
            if (class_exists('MCLB_Cancel')) {
                MCLB_Cancel::finish($b, $outcome, ['notify' => array_key_exists('notify', $ctx) ? (bool) $ctx['notify'] : true]);
            }
            return ['ok' => true, 'refunded' => false, 'amount' => 0.0, 'outcome' => $outcome];
        }

        // Now refund the card part.
        $refunded = self::refund_line($b, $reason);
        // Single cancel seam + WEB-12 emails (v1.4.3). Only reached by the winner
        // of claim_cancel() above, so the WC refund hook can't double-fire it.
        $outcome = [
            'type'        => $refunded ? 'refund_card' : 'refund_failed',
            'amount'      => (float) $b->price,
            'actor'       => isset($ctx['actor']) ? (string) $ctx['actor'] : '',
            'actor_note'  => isset($ctx['actor_note']) ? (string) $ctx['actor_note'] : '',
            'via_link'    => !empty($ctx['via_link']),
            'manual_flag' => !$refunded, // a failed auto-refund needs staff follow-up
        ];
        if (class_exists('MCLB_Cancel')) {
            MCLB_Cancel::finish($b, $outcome, ['notify' => array_key_exists('notify', $ctx) ? (bool) $ctx['notify'] : true]);
        }
        return ['ok' => true, 'refunded' => $refunded, 'amount' => (float) $b->price, 'outcome' => $outcome];
    }

    /**
     * Refund a PARTIAL amount against a booking's order line — used when a move to
     * a lower-priced slot refunds just the difference to the card. The amount is
     * capped to what remains refundable on that line (so a second move or a prior
     * refund can never over-refund). Returns ok + the amount actually refunded, or
     * a flag that the gateway refund failed (an order note then prompts a manual
     * refund). Never flips booking status — the booking stays confirmed on its new
     * slot.
     *
     * @return array{ok:bool,amount?:float,failed?:bool}
     */
    public static function refund_delta($b, $amount, $reason = '') {
        $amount = round((float) $amount, 2);
        if ($amount <= 0 || !$b->order_id || !$b->order_item_id || !function_exists('wc_create_refund')) {
            return ['ok' => false];
        }
        $order = wc_get_order((int) $b->order_id);
        if (!$order) {
            return ['ok' => false];
        }
        // Cap to the line's remaining refundable total.
        $item = $order->get_item((int) $b->order_item_id);
        if ($item) {
            $line_total = (float) $order->get_line_total($item, true, false);
            $already    = (float) $order->get_total_refunded_for_item((int) $b->order_item_id);
            $remaining  = round($line_total - $already, 2);
            if ($remaining <= 0) {
                return ['ok' => false];
            }
            if ($amount > $remaining) {
                $amount = $remaining;
            }
        }

        $refund = wc_create_refund([
            'amount'         => $amount,
            'reason'         => $reason !== '' ? $reason : __('Lane booking moved to a lower-priced slot', 'mclb-lane-booking'),
            'order_id'       => (int) $b->order_id,
            'line_items'     => [(int) $b->order_item_id => ['qty' => 0, 'refund_total' => $amount]],
            'refund_payment' => true,
            'restock_items'  => false,
        ]);

        $money = function_exists('wc_price') ? wp_strip_all_tags(wc_price($amount)) : (string) $amount;
        if (is_wp_error($refund)) {
            $order->add_order_note(sprintf(
                /* translators: 1: booking id, 2: amount, 3: error. */
                __('moBooking: refund of %2$s for moved booking #%1$d FAILED (%3$s) — please refund manually.', 'mclb-lane-booking'),
                (int) $b->id,
                $money,
                $refund->get_error_message()
            ));
            return ['ok' => false, 'failed' => true];
        }
        $order->add_order_note(sprintf(
            /* translators: 1: booking id, 2: amount. */
            __('moBooking: booking #%1$d moved to a lower-priced slot; %2$s refunded.', 'mclb-lane-booking'),
            (int) $b->id,
            $money
        ));
        return ['ok' => true, 'amount' => $amount];
    }

    /**
     * Refund the single order line for a booking (already claimed/cancelled).
     * Returns true on a successful WC refund, false if there's no order to refund
     * or the gateway refund failed (in which case an order note flags it).
     */
    private static function refund_line($b, $reason) {
        if (!$b->order_id || !function_exists('wc_create_refund')) {
            return false;
        }
        $order = wc_get_order((int) $b->order_id);
        if (!$order) {
            return false;
        }

        $amount     = (float) $b->price;
        $line_items = [];
        if ($b->order_item_id) {
            // qty 0 = refund the amount without restocking (bookings aren't stock).
            $line_items[(int) $b->order_item_id] = ['qty' => 0, 'refund_total' => $amount];
        }

        $refund = wc_create_refund([
            'amount'         => $amount,
            'reason'         => $reason !== '' ? $reason : __('Lane booking cancelled', 'mclb-lane-booking'),
            'order_id'       => (int) $b->order_id,
            'line_items'     => $line_items,
            'refund_payment' => true,  // trigger the gateway (Square) refund
            'restock_items'  => false,
        ]);

        $money = function_exists('wc_price') ? wp_strip_all_tags(wc_price($amount)) : (string) $amount;
        if (is_wp_error($refund)) {
            $order->add_order_note(sprintf(
                /* translators: 1: booking id, 2: error. */
                __('moBooking: automatic refund for cancelled booking #%1$d FAILED (%2$s) — please refund manually.', 'mclb-lane-booking'),
                (int) $b->id,
                $refund->get_error_message()
            ));
            return false;
        }
        $order->add_order_note(sprintf(
            /* translators: 1: booking id, 2: amount. */
            __('moBooking: booking #%1$d cancelled and %2$s refunded.', 'mclb-lane-booking'),
            (int) $b->id,
            $money
        ));
        return true;
    }
}
