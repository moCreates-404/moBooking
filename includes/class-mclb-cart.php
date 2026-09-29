<?php
/**
 * WooCommerce cart integration (Phase 4a) — turns the Phase 3 selection payload
 * into held WooCommerce cart items.
 *
 * Flow: POST mclb/v1/cart → nonce + rate-limit → validate every selection against
 * live availability + re-derive price server-side → write all holds in ONE
 * FOR-UPDATE transaction (all-or-nothing) → wc_load_cart() → add each as a cart
 * item on the hidden "anchor" product, carrying the booking as cart-item data →
 * return the checkout URL. If a cart-add fails, the holds are cancelled so nothing
 * is orphaned; abandoned carts also self-heal via the Phase 1 hold expiry + sweep.
 *
 * The store runs the CLASSIC (shortcode) cart/checkout, so this uses classic
 * WooCommerce hooks, not the Store API.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Cart {

    const REST_NS  = 'mclb/v1';
    const ITEM_KEY = 'mclb'; // cart-item data key

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest']);

        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'get_cart_item_from_session'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_prices'], 20, 1);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_item_data'], 10, 2);
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'item_name'], 10, 3);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'lock_quantity'], 10, 3);
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'on_item_removed'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_order_item_meta'], 10, 4);
    }

    // ── REST endpoint ──────────────────────────────────────────────────────────

    public static function register_rest() {
        register_rest_route(self::REST_NS, '/cart', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'permission'],
            'callback'            => [__CLASS__, 'handle'],
        ]);
    }

    /**
     * Explicit CSRF check: core only enforces the REST nonce for logged-IN cookie
     * requests, so a guest add would otherwise be unprotected. Require it for all.
     */
    public static function permission($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('mclb_bad_nonce', __('Your session expired — please refresh the page and try again.', 'mclb-lane-booking'), ['status' => 403]);
        }
        return true;
    }

    public static function handle($request) {
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            return new WP_Error('mclb_no_wc', __('Bookings are temporarily unavailable.', 'mclb-lane-booking'), ['status' => 503]);
        }
        if (self::rate_limited()) {
            return new WP_Error('mclb_rate', __('Too many requests — please wait a moment and try again.', 'mclb-lane-booking'), ['status' => 429]);
        }

        $params        = (array) $request->get_json_params();
        $selections_in = (isset($params['selections']) && is_array($params['selections'])) ? $params['selections'] : [];
        if (empty($selections_in)) {
            return new WP_Error('mclb_empty', __('No booking selected.', 'mclb-lane-booking'), ['status' => 400]);
        }
        if (count($selections_in) > 50) {
            return new WP_Error('mclb_too_many', __('Too many bookings in one request.', 'mclb-lane-booking'), ['status' => 400]);
        }

        $coach_on        = (int) MCLB_Settings::get('enable_coach_requests') === 1;
        $coach_requested = $coach_on && !empty($params['coach_requested']);
        $coach_note      = $coach_requested ? sanitize_textarea_field((string) ($params['coach_note'] ?? '')) : '';

        // Validate every selection against live availability + re-derive price.
        $validated = [];
        foreach ($selections_in as $sel) {
            $v = self::validate_selection((array) $sel);
            if (is_wp_error($v)) {
                return $v;
            }
            $validated[] = $v;
        }

        // One date per order (Phase 3 resolution #2).
        $dates = array_unique(array_map(function ($v) { return substr($v['starts_at'], 0, 10); }, $validated));
        if (count($dates) > 1) {
            return new WP_Error('mclb_multidate', __('All bookings in one order must be on the same day.', 'mclb-lane-booking'), ['status' => 400]);
        }

        // Bring the cart/session up in the REST context.
        wc_load_cart();
        if (!WC()->cart) {
            return new WP_Error('mclb_no_cart', __('Cart is unavailable — please try again.', 'mclb-lane-booking'), ['status' => 500]);
        }

        $token      = WC()->session ? (string) WC()->session->get_customer_id() : '';
        $user       = wp_get_current_user();
        $cust_id    = $user->ID ? (int) $user->ID : null;
        $cust_email = $user->user_email ?: null;
        $cust_name  = trim($user->first_name . ' ' . $user->last_name);
        $cust_name  = $cust_name !== '' ? $cust_name : ($user->display_name ?: null);

        // Build hold rows + write them atomically (double-sell guard).
        $holds_in = [];
        foreach ($validated as $v) {
            $holds_in[] = [
                'lane_id'            => $v['lane_id'],
                'lane_name'          => $v['lane_name'],
                'starts_at'          => $v['starts_at'],
                'ends_at'            => $v['ends_at'],
                'price'              => $v['price'],
                'session_token'      => $token,
                'customer_id'        => $cust_id,
                'customer_email'     => $cust_email,
                'customer_name'      => $cust_name,
                'coach_requested'    => $coach_requested ? 1 : 0,
                'coach_request_note' => $coach_note,
            ];
        }
        $res = MCLB_Bookings::insert_holds_locked($holds_in);
        if (empty($res['ok'])) {
            if (!empty($res['lock_error'])) {
                // Transient DB contention that survived the retries — ask to retry.
                return new WP_Error('mclb_busy', __('The booking system is busy right now — please try again in a moment.', 'mclb-lane-booking'), ['status' => 503]);
            }
            $ci   = isset($res['conflict']) ? (int) $res['conflict'] : 0;
            $lane = $validated[$ci]['lane_name'] ?? '';
            /* translators: %s: resource name. */
            return new WP_Error('mclb_conflict', sprintf(__('Sorry — %s was just taken for that time. Please pick another slot.', 'mclb-lane-booking'), $lane), ['status' => 409]);
        }
        $hold_ids = $res['ids'];

        // Add each to the cart on the anchor product. Roll back on any failure.
        $anchor = self::anchor_product_id();
        $added  = [];
        foreach ($validated as $i => $v) {
            $data = [self::ITEM_KEY => [
                'hold_id'         => (int) $hold_ids[$i],
                'lane_id'         => $v['lane_id'],
                'lane_name'       => $v['lane_name'],
                'type'            => $v['type'],
                'date'            => substr($v['starts_at'], 0, 10),
                'starts_at'       => $v['starts_at'],
                'ends_at'         => $v['ends_at'],
                'duration'        => $v['duration'],
                'price'           => $v['price'],
                'coach_requested' => $coach_requested ? 1 : 0,
                'coach_note'      => $coach_note,
                'uniq'            => (int) $hold_ids[$i], // forces a unique cart_item_key (no merge)
            ]];
            $key = $anchor ? WC()->cart->add_to_cart($anchor, 1, 0, [], $data) : false;
            if (!$key) {
                foreach ($added as $k) {
                    WC()->cart->remove_cart_item($k); // also cancels its hold via on_item_removed
                }
                foreach ($hold_ids as $hid) {
                    MCLB_Bookings::cancel((int) $hid);
                }
                return new WP_Error('mclb_cart_fail', __('Could not add the booking to your cart — please try again.', 'mclb-lane-booking'), ['status' => 500]);
            }
            $added[] = $key;
        }

        return rest_ensure_response(['redirect' => wc_get_checkout_url()]);
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    private static function validate_selection(array $sel) {
        $re     = '/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/';
        $lane_id = isset($sel['lane_id']) ? (int) $sel['lane_id'] : 0;
        $starts  = isset($sel['starts_at']) ? (string) $sel['starts_at'] : '';
        $ends    = isset($sel['ends_at']) ? (string) $sel['ends_at'] : '';

        if (!preg_match($re, $starts) || !preg_match($re, $ends)) {
            return new WP_Error('mclb_bad_time', __('Invalid booking time.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $lane = get_post($lane_id);
        if (!$lane || $lane->post_type !== 'mclb_lane' || $lane->post_status !== 'publish') {
            return new WP_Error('mclb_bad_lane', __('That resource is not available.', 'mclb-lane-booking'), ['status' => 400]);
        }
        if (substr($starts, 0, 10) !== substr($ends, 0, 10)) {
            return new WP_Error('mclb_span', __('A booking cannot span multiple days.', 'mclb-lane-booking'), ['status' => 400]);
        }

        $tz = wp_timezone();
        $s  = new DateTimeImmutable($starts, $tz);
        $e  = new DateTimeImmutable($ends, $tz);
        if ($e <= $s) {
            return new WP_Error('mclb_order', __('Invalid booking time.', 'mclb-lane-booking'), ['status' => 400]);
        }
        if ($s < new DateTimeImmutable('now', $tz)) {
            return new WP_Error('mclb_past', __('That time is in the past.', 'mclb-lane-booking'), ['status' => 400]);
        }

        $date  = substr($starts, 0, 10);
        $avail = MCLB_Availability::for_lane($lane_id, $date);
        if (empty($avail['is_open']) || !self::range_all_available($avail, $starts, $ends)) {
            return new WP_Error('mclb_unavailable', __('That time is no longer available.', 'mclb-lane-booking'), ['status' => 409]);
        }

        $duration = ($e->getTimestamp() - $s->getTimestamp()) / 3600.0;
        $price    = round((float) MCLB_Lane::get_price($lane_id) * $duration, 2);

        return [
            'lane_id'   => $lane_id,
            'lane_name' => get_the_title($lane_id),
            'type'      => MCLB_Lane::get_type($lane_id),
            'starts_at' => $starts,
            'ends_at'   => $ends,
            'duration'  => $duration,
            'price'     => $price,
        ];
    }

    /** Every increment slot from $starts to $ends must exist and be available. */
    private static function range_all_available(array $avail, $starts, $ends) {
        $by_start = [];
        foreach ($avail['slots'] as $slot) {
            $by_start[$slot['start']] = $slot;
        }
        $cursor = $starts;
        $guard  = 0;
        while ($cursor !== $ends) {
            if (++$guard > 48 || !isset($by_start[$cursor])) {
                return false;
            }
            $slot = $by_start[$cursor];
            if ($slot['state'] !== 'available' || $slot['end'] > $ends) {
                return false;
            }
            $cursor = $slot['end'];
        }
        return true;
    }

    // ── Anchor product ─────────────────────────────────────────────────────────

    /** Configured hidden virtual product, or auto-provision + persist one. */
    public static function anchor_product_id() {
        $id = (int) MCLB_Settings::get('wc_product_id');
        if ($id) {
            $p = wc_get_product($id);
            if ($p && $p->get_status() === 'publish') {
                return $id;
            }
        }
        $p = new WC_Product_Simple();
        $p->set_name('Lane Booking');
        $p->set_status('publish');
        $p->set_catalog_visibility('hidden');
        $p->set_virtual(true);
        $p->set_price(0);
        $p->set_regular_price('0');
        $p->set_sold_individually(true);
        $new = (int) $p->save();
        if ($new) {
            $opts                  = MCLB_Settings::get();
            $opts['wc_product_id'] = $new;
            update_option(MCLB_OPTION, $opts);
        }
        return $new;
    }

    // ── Cart-item lifecycle ────────────────────────────────────────────────────

    public static function get_cart_item_from_session($item, $values) {
        if (isset($values[self::ITEM_KEY])) {
            $item[self::ITEM_KEY] = $values[self::ITEM_KEY];
        }
        return $item;
    }

    /** Server-side price: duration × rate, locked at add time. Never client-set.
     *  Setting an absolute price is idempotent, so repeated calls are harmless. */
    public static function set_prices($cart) {
        foreach ($cart->get_cart() as $item) {
            if (!empty($item[self::ITEM_KEY]) && isset($item[self::ITEM_KEY]['price']) && isset($item['data'])) {
                $item['data']->set_price((float) $item[self::ITEM_KEY]['price']);
            }
        }
    }

    public static function display_item_data($data, $item) {
        if (empty($item[self::ITEM_KEY])) {
            return $data;
        }
        $m      = $item[self::ITEM_KEY];
        $data[] = ['key' => __('When', 'mclb-lane-booking'), 'value' => self::format_when($m)];
        if (!empty($m['coach_requested'])) {
            $data[] = ['key' => __('Coach', 'mclb-lane-booking'), 'value' => __('Requested', 'mclb-lane-booking')];
        }
        return $data;
    }

    public static function item_name($name, $item, $key) {
        if (!empty($item[self::ITEM_KEY]['lane_name'])) {
            return esc_html($item[self::ITEM_KEY]['lane_name']);
        }
        return $name;
    }

    public static function lock_quantity($qty_html, $key, $item) {
        if (!empty($item[self::ITEM_KEY])) {
            return '1';
        }
        return $qty_html;
    }

    public static function on_item_removed($cart_item_key, $cart) {
        $item = isset($cart->removed_cart_contents[$cart_item_key]) ? $cart->removed_cart_contents[$cart_item_key] : null;
        if ($item && !empty($item[self::ITEM_KEY]['hold_id'])) {
            MCLB_Bookings::cancel((int) $item[self::ITEM_KEY]['hold_id']);
        }
    }

    public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values[self::ITEM_KEY])) {
            return;
        }
        $m = $values[self::ITEM_KEY];
        // Hidden (underscore) meta the plugin reads back:
        $item->add_meta_data('_mclb_hold_id', (int) $m['hold_id'], true);
        $item->add_meta_data('_mclb_lane_id', (int) $m['lane_id'], true);
        if (!empty($m['coach_note'])) {
            $item->add_meta_data('_mclb_coach_note', $m['coach_note'], true);
        }
        // Visible meta (shows on the order + emails):
        $item->add_meta_data(__('Lane', 'mclb-lane-booking'), $m['lane_name'], true);
        $item->add_meta_data(__('When', 'mclb-lane-booking'), self::format_when($m), true);
        if (!empty($m['coach_requested'])) {
            $item->add_meta_data(__('Coach requested', 'mclb-lane-booking'), __('Yes', 'mclb-lane-booking'), true);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function format_when($m) {
        $tz = wp_timezone();
        $s  = new DateTimeImmutable($m['starts_at'], $tz);
        $e  = new DateTimeImmutable($m['ends_at'], $tz);
        $tf = get_option('time_format') ?: 'g:i a';
        $df = get_option('date_format') ?: 'j M Y';
        return wp_date($df, $s->getTimestamp()) . ', ' . wp_date($tf, $s->getTimestamp()) . ' – ' . wp_date($tf, $e->getTimestamp());
    }

    private static function rate_limited() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $key = 'mclb_rl_' . md5($ip);
        $n   = (int) get_transient($key);
        if ($n >= 20) { // 20 add-to-cart calls per minute per IP
            return true;
        }
        set_transient($key, $n + 1, MINUTE_IN_SECONDS);
        return false;
    }
}
