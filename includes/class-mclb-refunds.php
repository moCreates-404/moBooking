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
    public static function cancel_and_refund($booking_id, $reason = '') {
        $b = MCLB_Bookings::get($booking_id);
        if (!$b) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!MCLB_Bookings::claim_cancel($booking_id)) {
            return ['ok' => false, 'error' => 'not_confirmed']; // already cancelled / never confirmed
        }
        // Slot released the instant claim_cancel() flipped the status. Now refund.
        $refunded = self::refund_line($b, $reason);
        return ['ok' => true, 'refunded' => $refunded, 'amount' => (float) $b->price];
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
