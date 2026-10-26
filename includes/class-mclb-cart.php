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
        add_filter('woocommerce_hidden_order_itemmeta', [__CLASS__, 'hidden_order_itemmeta']);
        // Drop cart items whose hold has expired / been cancelled / is in the past,
        // on cart + checkout load. Runs at checkout too, so an expired hold can
        // never be ordered (the error notice also blocks the submit).
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_items']);
        // Prepaid-pass code field on the cart + checkout (v1.5.0), WC-style.
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_pass_assets']);
        add_action('woocommerce_before_cart_totals', [__CLASS__, 'render_pass_field'], 20);
        add_action('woocommerce_review_order_before_payment', [__CLASS__, 'render_pass_field'], 20);
    }

    /**
     * The "Prepaid pass code" box on the cart/checkout. Renders ONLY a kses-safe
     * <div data-...> (a theme that runs wp_kses on cart hook output strips bare
     * <input>/<script>); assets/js/mclb-passbox.js builds the UI inside it.
     */
    public static function render_pass_field() {
        if (!WC()->cart || !class_exists('MCLB_Passes')) { return; }
        $has = false;
        foreach (WC()->cart->get_cart() as $item) { if (!empty($item[self::ITEM_KEY]['hold_id'])) { $has = true; break; } }
        if (!$has) { return; }
        $code    = self::pass_code();
        $covered = 0.0;
        $balance = '';
        if ($code !== '') {
            $pass = MCLB_Passes::get_by_code($code);
            if ($pass) { $balance = rtrim(rtrim(number_format((float) MCLB_Passes::balance((int) $pass->id), 2), '0'), '.'); }
            foreach (self::cart_holds() as $b) { $covered += MCLB_Passes::reserved_hours_for_hold((int) $b->id); }
        }
        wp_enqueue_script('mclb-passbox');
        $i18n = wp_json_encode([
            'working' => __('Working…', 'mclb-lane-booking'),
            'invalid' => __('That code is invalid or expired.', 'mclb-lane-booking'),
            'err'     => __('Could not reach the server — try again.', 'mclb-lane-booking'),
            'applied' => __('Prepaid pass %s applied', 'mclb-lane-booking'),
            'covered' => __('%hh covered', 'mclb-lane-booking'),
            'left'    => __('%hh left on this pass', 'mclb-lane-booking'),
            'remove'  => __('Remove pass', 'mclb-lane-booking'),
            'prompt'  => __('Have a prepaid pass? Enter the code:', 'mclb-lane-booking'),
            'apply'   => __('Apply', 'mclb-lane-booking'),
        ]);
        printf(
            '<div class="mclb-passbox" style="margin:12px 0;padding:12px;border:1px solid #e5e5e5;border-radius:8px;" data-url="%s" data-nonce="%s" data-code="%s" data-covered="%s" data-balance="%s" data-i18n="%s"></div>',
            esc_url(rest_url(self::REST_NS . '/cart/pass')),
            esc_attr(wp_create_nonce('wp_rest')),
            esc_attr($code),
            esc_attr(rtrim(rtrim(number_format((float) $covered, 2), '0'), '.')),
            esc_attr($balance),
            esc_attr($i18n)
        );
    }

    /** Register the pass-box script (enqueued on demand by render_pass_field). */
    public static function register_pass_assets() {
        $v = @filemtime(MCLB_DIR . 'assets/js/mclb-passbox.js');
        wp_register_script('mclb-passbox', MCLB_URL . 'assets/js/mclb-passbox.js', ['jquery'], $v ? (string) $v : MCLB_VERSION, true);
    }

    /**
     * Remove any cart item whose moBooking hold is no longer live (missing,
     * cancelled, expired, or in the past), each with a re-pick notice. Fires on
     * the cart + checkout pages (woocommerce_check_cart_items), so checkout can
     * never accept an expired hold — the error notice halts the submit.
     */
    public static function validate_cart_items() {
        if (!WC()->cart) {
            return;
        }
        $now    = current_time('mysql');
        $win    = MCLB_Grid::booking_window(); // re-check the window at checkout (covers older carts)
        $remove = []; // key => label
        $beyond = []; // key => label
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (empty($item[self::ITEM_KEY]['hold_id'])) {
                continue;
            }
            $b = MCLB_Bookings::get((int) $item[self::ITEM_KEY]['hold_id']);
            $live = $b
                && in_array((string) $b->status, [MCLB_Bookings::STATUS_HELD, MCLB_Bookings::STATUS_CONFIRMED], true)
                && $b->ends_at > $now
                && ((string) $b->status !== MCLB_Bookings::STATUS_HELD || $b->hold_expires_at > $now);
            if (!$live) {
                $remove[$key] = ($item[self::ITEM_KEY]['lane_name'] ?? '') . ' · ' . self::format_when($item[self::ITEM_KEY]);
                continue;
            }
            if (MCLB_Grid::is_beyond_window(substr((string) $b->starts_at, 0, 10), $win['max_date'])) {
                $beyond[$key] = ($item[self::ITEM_KEY]['lane_name'] ?? '') . ' · ' . self::format_when($item[self::ITEM_KEY]);
            }
        }
        foreach ($remove as $key => $label) {
            WC()->cart->remove_cart_item($key); // on_item_removed cancels the (already dead) hold harmlessly
            /* translators: %s: lane + date/time of the expired hold. */
            wc_add_notice(sprintf(__('Your hold on %s expired — please re-select.', 'mclb-lane-booking'), $label), 'error');
        }
        foreach ($beyond as $key => $label) {
            WC()->cart->remove_cart_item($key);
            /* translators: 1: lane + date/time, 2: number of days. */
            wc_add_notice(sprintf(_n('%1$s is beyond the %2$d-day booking window and was removed.', '%1$s is beyond the %2$d-day booking window and was removed.', $win['days'], 'mclb-lane-booking'), $label, $win['days']), 'error');
        }
    }

    /** Hide the plugin's internal (_mclb_*) line-item meta on the order admin + emails. */
    public static function hidden_order_itemmeta($keys) {
        return array_merge((array) $keys, ['_mclb_hold_id', '_mclb_lane_id', '_mclb_coach_note']);
    }

    // ── REST endpoint ──────────────────────────────────────────────────────────

    public static function register_rest() {
        register_rest_route(self::REST_NS, '/cart', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'permission'],
            'callback'            => [__CLASS__, 'handle'],
        ]);
        // Prepaid-pass code on the cart/checkout (apply / remove / balance) — v1.5.0.
        register_rest_route(self::REST_NS, '/cart/pass', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'permission'],
            'callback'            => [__CLASS__, 'handle_pass'],
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
        if (!MCLB_License::can_book()) {
            return new WP_Error('mclb_demo', MCLB_License::demo_message(), ['status' => 403]);
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

        // If a pass code is already applied to this cart, cover the new line(s) too.
        if (class_exists('MCLB_Passes') && self::pass_code() !== '') { self::pass_reapply(); }

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

        $date = substr($starts, 0, 10);

        // Customer booking window (staff are never limited — see MCLB_Grid::booking_window()).
        $win = MCLB_Grid::booking_window();
        if (MCLB_Grid::is_beyond_window($date, $win['max_date'])) {
            /* translators: %d: number of days ahead customers can book. */
            return new WP_Error('mclb_window', sprintf(_n('Online bookings open %d day in advance — please pick an earlier date.', 'Online bookings open %d days in advance — please pick an earlier date.', $win['days'], 'mclb-lane-booking'), $win['days']), ['status' => 400]);
        }

        $avail = MCLB_Availability::for_lane($lane_id, $date);
        if (empty($avail['is_open']) || !self::range_all_available($avail, $starts, $ends)) {
            $tf    = get_option('time_format') ?: 'g:i a';
            $label = get_the_title($lane_id) . ' · ' . wp_date($tf, $s->getTimestamp()) . '–' . wp_date($tf, $e->getTimestamp());
            /* translators: %s: lane + time of the slot that is no longer free. */
            return new WP_Error('mclb_unavailable', sprintf(__('%s is no longer available — please re-pick that slot.', 'mclb-lane-booking'), $label), ['status' => 409]);
        }

        $duration = ($e->getTimestamp() - $s->getTimestamp()) / 3600.0;
        $price    = MCLB_Bookings::lane_price($lane_id, $starts, $ends); // ONE pricing seam (forward-compat for v1.5.0 passes)

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
        $label = MCLB_Settings::get('resource_label_singular') ?: 'Lane';
        $p = new WC_Product_Simple();
        $p->set_name(sprintf(_x('%s Booking', 'auto-provisioned anchor product name', 'mclb-lane-booking'), $label));
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
                $m = $item[self::ITEM_KEY];
                // Net of any prepaid-pass cover (v1.5.0): the live reservation on this
                // hold zeroes covered hours through the ONE pricing seam (lane_price).
                $net = !empty($m['hold_id'])
                    ? MCLB_Bookings::lane_price((int) $m['lane_id'], $m['starts_at'], $m['ends_at'], ['hold_id' => (int) $m['hold_id']])
                    : (float) $m['price'];
                $item['data']->set_price((float) $net);
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
            $hid = (int) $item[self::ITEM_KEY]['hold_id'];
            if (class_exists('MCLB_Passes')) { MCLB_Passes::release_hold($hid, 'cart item removed'); }
            MCLB_Bookings::cancel($hid);
        }
        // Re-allocate the applied pass across whatever remains (fix d).
        if (class_exists('MCLB_Passes') && self::pass_code() !== '') { self::pass_reapply(); }
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
        $res_label = MCLB_Settings::get('resource_label_singular') ?: __('Lane', 'mclb-lane-booking');
        $item->add_meta_data($res_label, $m['lane_name'], true);
        $item->add_meta_data(__('When', 'mclb-lane-booking'), self::format_when($m), true);
        if (!empty($m['coach_requested'])) {
            $item->add_meta_data(__('Coach requested', 'mclb-lane-booking'), __('Yes', 'mclb-lane-booking'), true);
        }
        // Prepaid-pass cover (v1.5.0): a VISIBLE line showing the code + hours +
        // the amount it took off this line. Not underscore-prefixed, so the
        // woocommerce_hidden_order_itemmeta filter leaves it on the order, emails
        // and thank-you page.
        if (class_exists('MCLB_Passes') && !empty($m['hold_id'])) {
            $covered = MCLB_Passes::reserved_hours_for_hold((int) $m['hold_id']);
            if ($covered > 0) {
                $pid  = MCLB_Passes::pass_for_hold((int) $m['hold_id']);
                $pass = $pid ? MCLB_Passes::get($pid) : null;
                $rate = (float) MCLB_Lane::get_price((int) $m['lane_id']);
                $amt  = class_exists('MCLB_Coaches') ? MCLB_Coaches::money($covered * $rate) : number_format($covered * $rate, 2);
                $hh   = rtrim(rtrim(number_format((float) $covered, 2), '0'), '.');
                $item->add_meta_data(
                    __('Prepaid pass', 'mclb-lane-booking'),
                    sprintf('%1$s: %2$sh (−%3$s)', $pass ? $pass->code : '', $hh, $amt),
                    true
                );
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function format_when($m) {
        return self::when_label($m['starts_at'], $m['ends_at']);
    }

    /**
     * The human "When" string for an order line item, e.g.
     * "Monday, 26 October 2026, 4:00 pm – 5:00 pm". Public so a staff move can
     * rewrite the same meta the checkout wrote. Only new/changed line items
     * snapshot this; untouched orders keep their stored text.
     */
    public static function when_label($starts_at, $ends_at) {
        $tz = wp_timezone();
        $s  = new DateTimeImmutable((string) $starts_at, $tz);
        $e  = new DateTimeImmutable((string) $ends_at, $tz);
        $tf = get_option('time_format') ?: 'g:i a';
        return MCLB_Settings::friendly_date($s->getTimestamp()) . ', ' . wp_date($tf, $s->getTimestamp()) . ' – ' . wp_date($tf, $e->getTimestamp());
    }

    // ── Prepaid passes (v1.5.0) ─────────────────────────────────────────────────

    public static function pass_code()        { return WC()->session ? (string) WC()->session->get('mclb_pass_code', '') : ''; }
    private static function pass_set($code)    { if (WC()->session) { WC()->session->set('mclb_pass_code', (string) $code); } }
    private static function pass_forget()      { if (WC()->session) { WC()->session->set('mclb_pass_code', ''); } }

    /** Live held booking rows currently in the cart. */
    private static function cart_holds() {
        $out = [];
        if (!WC()->cart) { return $out; }
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item[self::ITEM_KEY]['hold_id'])) {
                $b = MCLB_Bookings::get((int) $item[self::ITEM_KEY]['hold_id']);
                if ($b && (string) $b->status === MCLB_Bookings::STATUS_HELD) { $out[] = $b; }
            }
        }
        return $out;
    }

    /**
     * Re-run allocation for the session pass across the current cart holds
     * (release everything it reserves, then re-reserve earliest-first). Called on
     * apply, and whenever the cart changes, so cover stays correct (fix d).
     */
    public static function pass_reapply() {
        $code = self::pass_code();
        if ($code === '' || !class_exists('MCLB_Passes')) { return; }
        $pass = MCLB_Passes::get_by_code($code);
        if (!$pass) { self::pass_forget(); return; }
        if (!MCLB_Passes::lock($pass->code)) { return; } // transient; a later calc retries
        try {
            foreach (MCLB_Passes::reserved_holds((int) $pass->id) as $hid) {
                MCLB_Passes::release_hold((int) $hid, 're-allocate');
            }
            if (!MCLB_Passes::is_usable($pass)) { self::pass_forget(); return; }
            foreach (MCLB_Passes::allocate((int) $pass->id, self::cart_holds()) as $hid => $hours) {
                MCLB_Passes::reserve((int) $pass->id, (int) $hid, $hours, 'cart', true);
            }
        } finally {
            MCLB_Passes::unlock($pass->code);
        }
        if (WC()->cart) { WC()->cart->calculate_totals(); }
    }

    /** Release everything the session pass reserves + forget the code. */
    private static function pass_release_all() {
        $code = self::pass_code();
        if ($code === '' || !class_exists('MCLB_Passes')) { self::pass_forget(); return; }
        $pass = MCLB_Passes::get_by_code($code);
        if ($pass && MCLB_Passes::lock($pass->code)) {
            try {
                foreach (MCLB_Passes::reserved_holds((int) $pass->id) as $hid) {
                    MCLB_Passes::release_hold((int) $hid, 'code removed');
                }
            } finally { MCLB_Passes::unlock($pass->code); }
        }
        self::pass_forget();
    }

    /** POST /cart/pass — op = apply | remove | balance. */
    public static function handle_pass($request) {
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            return new WP_Error('mclb_no_wc', __('Bookings are temporarily unavailable.', 'mclb-lane-booking'), ['status' => 503]);
        }
        if (self::rate_limited() || self::pass_session_limited()) {
            return new WP_Error('mclb_rate', __('Too many attempts — please wait a moment and try again.', 'mclb-lane-booking'), ['status' => 429]);
        }
        wc_load_cart();
        $op   = sanitize_key((string) $request->get_param('op'));
        $code = MCLB_Pass_Code::normalise((string) $request->get_param('code'));

        if ($op === 'remove') {
            self::pass_release_all();
            if (WC()->cart) { WC()->cart->calculate_totals(); }
            return rest_ensure_response(['ok' => true, 'removed' => true]);
        }

        // apply / balance both need a valid, usable code. Never hint which failed.
        $pass = (class_exists('MCLB_Passes') && MCLB_Pass_Code::looks_valid($code)) ? MCLB_Passes::get_by_code($code) : null;
        if (!$pass || !MCLB_Passes::is_usable($pass)) {
            return new WP_Error('mclb_pass_invalid', __('That code is invalid or expired.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $balance = MCLB_Passes::balance((int) $pass->id);

        if ($op === 'balance') {
            return rest_ensure_response(['ok' => true, 'code' => $pass->code, 'balance' => $balance]);
        }

        // apply
        self::pass_set($pass->code);
        self::pass_reapply();
        $covered = 0.0;
        foreach (self::cart_holds() as $b) { $covered += MCLB_Passes::reserved_hours_for_hold((int) $b->id); }
        return rest_ensure_response([
            'ok'       => true,
            'code'     => $pass->code,
            'balance'  => MCLB_Passes::balance((int) $pass->id),
            'covered'  => $covered,
        ]);
    }

    /** Per-session companion to the per-IP limiter for code entry. */
    private static function pass_session_limited() {
        $sid = (WC()->session && method_exists(WC()->session, 'get_customer_id')) ? (string) WC()->session->get_customer_id() : '';
        if ($sid === '') { return false; }
        $key = 'mclb_passrl_' . md5($sid);
        $n   = (int) get_transient($key);
        if ($n >= 15) { return true; } // 15 code attempts / minute / session
        set_transient($key, $n + 1, MINUTE_IN_SECONDS);
        return false;
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
