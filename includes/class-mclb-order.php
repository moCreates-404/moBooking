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

        // Guest self-cancel "Manage booking" links (WEB-17): in the customer order
        // emails and on the thank-you / view-order pages, for orders with bookings.
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_manage_links'], 20, 4);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'page_manage_links'], 20, 1);
    }

    /** Confirmed, future bookings on an order (for the manage links). */
    private static function manageable_bookings($order_id) {
        if ((int) MCLB_Settings::get('guest_self_cancel') !== 1 || !class_exists('MCLB_Manage_Token')) {
            return [];
        }
        $out = [];
        $now = current_time('mysql');
        foreach (MCLB_Bookings::for_order((int) $order_id) as $b) {
            if ((string) $b->status === MCLB_Bookings::STATUS_CONFIRMED && (string) $b->starts_at > $now) {
                $out[] = $b;
            }
        }
        return $out;
    }

    public static function email_manage_links($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin || !($order instanceof WC_Order)) { return; }
        $rows = self::manageable_bookings($order->get_id());
        if (empty($rows)) { return; }
        $label = __('Need to change a booking? Manage or cancel it here:', 'mclb-lane-booking');
        if ($plain_text) {
            echo "\n" . $label . "\n";
            foreach ($rows as $b) { echo MCLB_Manage_Token::url($b) . "\n"; } // phpcs:ignore WordPress.Security.EscapeOutput -- plain URL
            return;
        }
        echo '<p style="margin:16px 0 6px;">' . esc_html($label) . '</p><ul>';
        foreach ($rows as $b) {
            printf(
                '<li><a href="%s">%s — %s</a></li>',
                esc_url(MCLB_Manage_Token::url($b)),
                esc_html($b->lane_name),
                esc_html(MCLB_Cart::when_label($b->starts_at, $b->ends_at))
            );
        }
        echo '</ul>';
    }

    public static function page_manage_links($order) {
        if (!($order instanceof WC_Order)) { return; }
        $rows = self::manageable_bookings($order->get_id());
        if (empty($rows)) { return; }
        echo '<section class="mclb-manage-links"><h2>' . esc_html__('Your bookings', 'mclb-lane-booking') . '</h2><ul>';
        foreach ($rows as $b) {
            printf(
                '<li><a href="%s" target="_blank" rel="noopener">%s — %s</a></li>',
                esc_url(MCLB_Manage_Token::url($b)),
                esc_html($b->lane_name),
                esc_html(MCLB_Cart::when_label($b->starts_at, $b->ends_at))
            );
        }
        echo '</ul></section>';
    }

    /** Order saved: link each line item's hold row to the order. */
    public static function on_order_processed($order_id, $posted_data, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        $attached = 0;
        foreach ($order->get_items() as $item_id => $item) {
            $hold_id = (int) $item->get_meta('_mclb_hold_id');
            if ($hold_id) {
                MCLB_Bookings::attach_order($hold_id, $order->get_id(), (int) $item_id);
                $attached++;
            }
        }
        // Snapshot the order's billing holder name + email onto the linked rows,
        // so guest checkouts show the real name (not "Guest") on the calendar.
        // Only empty/"Guest" values are filled, never a staff-edited name.
        if ($attached) {
            MCLB_Bookings::fill_customer_from_order($order->get_id());
            // Snapshot coupon usage per line (v1.4.2) so the calendar never hits
            // WooCommerce on day load. Writes a value ('[]' or the array) to every
            // linked row, so NULL only ever means "pre-v7, not yet backfilled".
            MCLB_Bookings::snapshot_coupons($order);
        }
    }

    /** Payment succeeded (incl. a $0 pass-covered order WC auto-completes):
     *  confirm the booking(s), then redeem any reserved pass hours (v1.5.0). */
    public static function on_payment_complete($order_id) {
        MCLB_Bookings::confirm_by_order((int) $order_id);
        if (class_exists('MCLB_Passes')) {
            MCLB_Passes::redeem_for_order((int) $order_id);
        }
    }

    /** Order cancelled or refunded: release the slot(s) back to available. */
    public static function on_release($order_id) {
        MCLB_Bookings::cancel_by_order((int) $order_id);
    }

    /**
     * Rewrite an online booking's order line item after a staff MOVE so the order
     * screen + customer emails show the new lane/time, and add an order note. The
     * booking row (passed in) is already the source of truth — this keeps the WC
     * copy in step, best-effort: a failure here never rolls back the move. Payment
     * is untouched. HPOS-safe (CRUD only).
     */
    public static function update_item_for_move($b) {
        if (empty($b) || !$b->order_id || !$b->order_item_id || !function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order((int) $b->order_id);
        if (!$order) {
            return;
        }
        $item = $order->get_item((int) $b->order_item_id);
        $when = MCLB_Cart::when_label($b->starts_at, $b->ends_at);
        if ($item) {
            $res_label = MCLB_Settings::get('resource_label_singular') ?: __('Lane', 'mclb-lane-booking');
            $item->update_meta_data('_mclb_lane_id', (int) $b->lane_id);
            $item->update_meta_data($res_label, (string) $b->lane_name);
            $item->update_meta_data(__('When', 'mclb-lane-booking'), $when);
            $item->save();
        }
        $order->add_order_note(sprintf(
            /* translators: 1: booking id, 2: resource name, 3: when. */
            __('moBooking: booking #%1$d moved to %2$s — %3$s.', 'mclb-lane-booking'),
            (int) $b->id,
            (string) $b->lane_name,
            $when
        ));
    }
}
