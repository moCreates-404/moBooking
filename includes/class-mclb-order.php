<?php
/**
 * Order lifecycle (Phase 4a) — carries held bookings through to confirmed, and
 * releases them on cancel/refund. HPOS-safe: order access is CRUD only.
 *
 *   order created  → stamp order_id + order_item_id onto the held rows (stay held)
 *   payment_complete → confirm (decision: woocommerce_payment_complete)
 *   cancelled / refunded → release the slot(s)
 *
 * Abandoned-before-payment holds are handled by the Phase 1 lazy-expiry + sweep.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Order {

    public static function init() {
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_order_processed'], 10, 3);
        add_action('woocommerce_payment_complete', [__CLASS__, 'on_payment_complete'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_release'], 10, 1);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'on_release'], 10, 1);
    }

    /** Order saved: link each line item's hold row to the order. */
    public static function on_order_processed($order_id, $posted_data, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        foreach ($order->get_items() as $item_id => $item) {
            $hold_id = (int) $item->get_meta('_mclb_hold_id');
            if ($hold_id) {
                MCLB_Bookings::attach_order($hold_id, $order->get_id(), (int) $item_id);
            }
        }
    }

    /** Payment succeeded (any gateway, incl. Square): confirm the booking(s). */
    public static function on_payment_complete($order_id) {
        MCLB_Bookings::confirm_by_order((int) $order_id);
    }

    /** Order cancelled or refunded: release the slot(s) back to available. */
    public static function on_release($order_id) {
        MCLB_Bookings::cancel_by_order((int) $order_id);
    }
}
