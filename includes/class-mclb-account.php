<?php
/**
 * Customer account layer (Phase 4b) — a "My bookings" tab in WooCommerce's My
 * Account, and self-cancel-for-credit for logged-in customers.
 *
 * Cancellation policy (decision #4): a logged-in customer may self-cancel a
 * confirmed booking up to `self_cancel_hours` (24 default) before its start, in
 * exchange for store credit — issued as a per-customer WooCommerce coupon for
 * the booking's value (the store's own rails, no custom wallet). Inside the
 * window, and all guest bookings, are admin-handled (Phase 5).
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Account {

    const ENDPOINT = 'mclb-bookings';

    public static function init() {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render']);
        add_action('admin_post_mclb_self_cancel', [__CLASS__, 'handle_cancel']);
        add_action('admin_post_nopriv_mclb_self_cancel', [__CLASS__, 'handle_cancel']);
    }

    // ── My Account endpoint plumbing ────────────────────────────────────────────

    public static function add_endpoint() {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
        // One-time flush so the endpoint resolves on an already-active install
        // (e.g. the symlinked dev site) without re-saving permalinks by hand.
        if (get_option('mclb_account_ep_flushed') !== '1') {
            flush_rewrite_rules(false);
            update_option('mclb_account_ep_flushed', '1');
        }
    }

    /** Insert "My bookings" before Logout. */
    public static function menu_item($items) {
        $new = [];
        foreach ($items as $key => $label) {
            if ($key === 'customer-logout') {
                $new[self::ENDPOINT] = __('My bookings', 'mclb-lane-booking');
            }
            $new[$key] = $label;
        }
        if (!isset($new[self::ENDPOINT])) {
            $new[self::ENDPOINT] = __('My bookings', 'mclb-lane-booking');
        }
        return $new;
    }

    // ── The bookings list ──────────────────────────────────────────────────────

    public static function render() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        self::notice();

        $bookings = MCLB_Bookings::for_user($user_id);
        if (empty($bookings)) {
            echo '<p>' . esc_html__('You have no bookings yet.', 'mclb-lane-booking') . '</p>';
            return;
        }

        $tz     = wp_timezone();
        $now    = new DateTimeImmutable('now', $tz);
        $cutoff = (int) MCLB_Settings::get('self_cancel_hours');
        $df     = get_option('date_format') ?: 'j M Y';
        $tf     = get_option('time_format') ?: 'g:i a';

        echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
        printf(
            '<th>%s</th><th>%s</th><th>%s</th><th></th>',
            esc_html(MCLB_Settings::get('resource_label_singular') ?: __('Resource', 'mclb-lane-booking')),
            esc_html__('When', 'mclb-lane-booking'),
            esc_html__('Status', 'mclb-lane-booking')
        );
        echo '</tr></thead><tbody>';

        foreach ($bookings as $b) {
            $start = new DateTimeImmutable($b->starts_at, $tz);
            $end   = new DateTimeImmutable($b->ends_at, $tz);
            $when  = wp_date($df, $start->getTimestamp()) . ', '
                   . wp_date($tf, $start->getTimestamp()) . ' – ' . wp_date($tf, $end->getTimestamp());
            $hours_out = ($start->getTimestamp() - $now->getTimestamp()) / 3600;

            echo '<tr>';
            printf('<td>%s</td>', esc_html($b->lane_name));
            printf('<td>%s</td>', esc_html($when));
            printf('<td>%s</td>', esc_html(ucfirst($b->status)));
            echo '<td>';
            if ($b->status === MCLB_Bookings::STATUS_CONFIRMED && $hours_out >= $cutoff) {
                self::cancel_button($b->id);
            } elseif ($b->status === MCLB_Bookings::STATUS_CONFIRMED && $hours_out > 0) {
                /* translators: %d: hours. */
                printf('<small>%s</small>', esc_html(sprintf(__('Contact us to cancel (within %dh of start)', 'mclb-lane-booking'), $cutoff)));
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function cancel_button($booking_id) {
        printf('<form method="post" action="%s" onsubmit="return confirm(%s);">', esc_url(admin_url('admin-post.php')), esc_attr('"' . esc_js(__('Cancel this booking for account credit?', 'mclb-lane-booking')) . '"'));
        echo '<input type="hidden" name="action" value="mclb_self_cancel">';
        printf('<input type="hidden" name="booking_id" value="%d">', (int) $booking_id);
        wp_nonce_field('mclb_self_cancel');
        printf('<button type="submit" class="button">%s</button>', esc_html__('Cancel for credit', 'mclb-lane-booking'));
        echo '</form>';
    }

    private static function notice() {
        if (empty($_GET['mclb_msg'])) {
            return;
        }
        $map = [
            'cancelled' => ['success', __('Booking cancelled. Your store credit has been emailed to you and can be applied at checkout.', 'mclb-lane-booking')],
            'toolate'   => ['error', __('Sorry, this booking is too close to its start time to self-cancel. Please contact us.', 'mclb-lane-booking')],
            'invalid'   => ['error', __('That booking could not be cancelled.', 'mclb-lane-booking')],
        ];
        $key = sanitize_key(wp_unslash($_GET['mclb_msg']));
        if (isset($map[$key])) {
            printf('<div class="woocommerce-message woocommerce-%s">%s</div>', esc_attr($map[$key][0]), esc_html($map[$key][1]));
        }
    }

    // ── Self-cancel → issue credit coupon ───────────────────────────────────────

    public static function handle_cancel() {
        $redirect = wc_get_account_endpoint_url(self::ENDPOINT);

        if (!is_user_logged_in() || !check_admin_referer('mclb_self_cancel')) {
            wp_safe_redirect(add_query_arg('mclb_msg', 'invalid', $redirect));
            exit;
        }
        $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
        $b          = MCLB_Bookings::get($booking_id);

        if (!$b || (int) $b->customer_id !== get_current_user_id() || $b->status !== MCLB_Bookings::STATUS_CONFIRMED) {
            wp_safe_redirect(add_query_arg('mclb_msg', 'invalid', $redirect));
            exit;
        }

        $tz        = wp_timezone();
        $hours_out = ((new DateTimeImmutable($b->starts_at, $tz))->getTimestamp() - (new DateTimeImmutable('now', $tz))->getTimestamp()) / 3600;
        if ($hours_out < (int) MCLB_Settings::get('self_cancel_hours')) {
            wp_safe_redirect(add_query_arg('mclb_msg', 'toolate', $redirect));
            exit;
        }

        MCLB_Bookings::cancel($booking_id); // release the slot
        $code = self::issue_credit_coupon($b);

        if ($b->order_id) {
            $order = wc_get_order((int) $b->order_id);
            if ($order) {
                /* translators: 1: booking id, 2: coupon code. */
                $order->add_order_note(sprintf(__('Lane booking #%1$d self-cancelled by customer; store-credit coupon %2$s issued.', 'mclb-lane-booking'), (int) $b->id, $code));
            }
        }

        wp_safe_redirect(add_query_arg('mclb_msg', 'cancelled', $redirect));
        exit;
    }

    /**
     * Issue a per-customer WooCommerce coupon for the booking's value and email
     * it. Returns the coupon code (or '' if WC/coupon unavailable).
     */
    public static function issue_credit_coupon($b) {
        if (!class_exists('WC_Coupon')) {
            return '';
        }
        $amount = (float) $b->price;
        $email  = $b->customer_email ?: '';
        $code   = 'MCLB-' . strtoupper(wp_generate_password(10, false, false));

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($amount);
        $coupon->set_usage_limit(1);
        if ($email) {
            $coupon->set_email_restrictions([$email]);
        }
        /* translators: 1: resource name, 2: date/time. */
        $coupon->set_description(sprintf(__('Booking credit — cancelled %1$s (%2$s)', 'mclb-lane-booking'), $b->lane_name, substr($b->starts_at, 0, 16)));
        $coupon->add_meta_data('_mclb_booking_id', (int) $b->id, true);
        $coupon->save();

        if ($email) {
            self::email_credit($email, $b, $code, $amount);
        }
        return $code;
    }

    private static function email_credit($email, $b, $code, $amount) {
        $money   = function_exists('wc_price') ? wp_strip_all_tags(wc_price($amount)) : number_format((float) $amount, 2);
        $subject = sprintf(
            /* translators: %s: site name. */
            __('Your booking credit — %s', 'mclb-lane-booking'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
        $body  = '<p>' . esc_html__('Your booking has been cancelled and store credit issued.', 'mclb-lane-booking') . '</p>';
        $body .= '<p>' . sprintf(
            /* translators: 1: amount, 2: coupon code. */
            esc_html__('Credit: %1$s — apply the code %2$s at checkout on your next booking.', 'mclb-lane-booking'),
            esc_html($money),
            '<strong>' . esc_html($code) . '</strong>'
        ) . '</p>';
        $body .= '<p>' . esc_html(sprintf('%s — %s', $b->lane_name, substr($b->starts_at, 0, 16))) . '</p>';

        wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }
}
