<?php
/**
 * Manage view (Phase 7c) — the staff calendar, rendered the same way in two
 * places: the [mclb_manage] shortcode on a normal page (counter staff) and the
 * moBooking → Calendar wp-admin screen (admins). Both just drop a container and
 * enqueue mclb-manage.js, which renders client-side from the mclb/v1/admin/day
 * JSON payload and writes through the REST layer.
 *
 * The front-end page is login-gated, capability-gated, never cached, and
 * noindexed. The REST layer is the real authority (capability + nonce on every
 * route); the page HTML carries no booking data.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Manage {

    const SHORTCODE  = 'mclb_manage';
    const ADMIN_SLUG = 'mclb-calendar';
    const HANDLE     = 'mclb-manage';

    /** @var string admin page hook suffix, for scoped enqueue. */
    private static $admin_hook = '';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        // Priority 11: the parent top-level menu (mclb-settings) is registered by
        // MCLB_Admin on admin_menu at the default priority 10, and MCLB_Manage::init()
        // runs before `new MCLB_Admin()`, so this must fire AFTER 10 or the submenu
        // orphans (raw-slug href, no page hook, "not allowed").
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 11);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('template_redirect', [__CLASS__, 'guard_front_page']);
        add_filter('rocket_cache_reject_uri', [__CLASS__, 'rocket_exclude']);
        add_filter('wp_robots', [__CLASS__, 'robots_noindex']);
    }

    // ── Manage page identity ────────────────────────────────────────────────

    public static function page_id() {
        return (int) MCLB_Settings::get('manage_page_id');
    }
    public static function page_url() {
        $id = self::page_id();
        return $id ? (string) get_permalink($id) : '';
    }
    public static function is_manage_page() {
        $id = self::page_id();
        return $id && is_page($id);
    }

    // ── Front-end page: guard + cache/robots ──────────────────────────────────

    public static function guard_front_page() {
        if (!self::is_manage_page()) {
            return;
        }
        // Never cache this page: it carries a per-session REST nonce and is
        // login-gated. (REST data is cap-checked regardless, but a cached nonce
        // would 403 every call.)
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::page_url() ?: home_url('/')));
            exit;
        }
    }

    public static function robots_noindex($robots) {
        if (self::is_manage_page()) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    public static function rocket_exclude($uris) {
        $id = self::page_id();
        if ($id) {
            $path = wp_parse_url(get_permalink($id), PHP_URL_PATH);
            if ($path) {
                $uris[] = $path;
            }
        }
        return $uris;
    }

    // ── Shortcode (front end) ─────────────────────────────────────────────────

    public static function shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to use the booking manager.', 'mclb-lane-booking') . '</p>';
        }
        if (!current_user_can(MCLB_Caps::MANAGE)) {
            return '<p>' . esc_html__('You don’t have access to the booking manager.', 'mclb-lane-booking') . '</p>';
        }
        self::enqueue();
        return self::container_html('front');
    }

    // ── wp-admin Calendar page ────────────────────────────────────────────────

    public static function admin_menu() {
        self::$admin_hook = (string) add_submenu_page(
            MCLB_Admin::PAGE,
            __('Calendar', 'mclb-lane-booking'),
            __('Calendar', 'mclb-lane-booking'),
            MCLB_Caps::MANAGE,
            self::ADMIN_SLUG,
            [__CLASS__, 'render_admin']
        );
    }

    public static function admin_enqueue($hook) {
        if ($hook === self::$admin_hook) {
            self::enqueue();
        }
    }

    public static function render_admin() {
        if (!current_user_can(MCLB_Caps::MANAGE)) {
            return;
        }
        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Calendar', 'mclb-lane-booking') . '</h1>';
        echo self::container_html('admin'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
        echo '</div>';
    }

    // ── Assets ──────────────────────────────────────────────────────────────

    public static function register_assets() {
        if (wp_style_is(self::HANDLE, 'registered')) {
            return;
        }
        wp_register_style(self::HANDLE, MCLB_URL . 'assets/css/mclb-manage.css', [], self::asset_ver('assets/css/mclb-manage.css'));
        wp_register_script(self::HANDLE, MCLB_URL . 'assets/js/mclb-manage.js', [], self::asset_ver('assets/js/mclb-manage.js'), true);
    }

    /** filemtime-based version so browsers never serve a stale build. */
    public static function asset_ver($rel) {
        $path = MCLB_DIR . $rel;
        $mt   = @filemtime($path);
        return $mt ? (string) $mt : MCLB_VERSION;
    }

    private static function enqueue() {
        self::register_assets();
        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);

        $symbol = function_exists('get_woocommerce_currency_symbol')
            ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8')
            : '$';

        // wp_add_inline_script + wp_json_encode so numbers/booleans/arrays keep
        // their types in JS (wp_localize_script casts every scalar to a string,
        // which broke the minute arithmetic — "60" instead of 60).
        $data = [
            'rest'      => esc_url_raw(rest_url('mclb/v1/admin')),
            'ajaxUrl'   => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'canRefund'  => current_user_can(MCLB_Caps::REFUND),
            'testRecipient' => sanitize_email((string) MCLB_Settings::get('daysheet_test_recipient')),
            'eventTypes' => array_map(static function ($t) {
                return ['slug' => $t['slug'], 'label' => $t['label']];
            }, MCLB_Event_Types::all()),
            'increment' => (int) MCLB_Settings::get('booking_increment') ?: 60,
            'currency'  => $symbol,
            'today'     => wp_date('Y-m-d'),
            'loginUrl'  => esc_url_raw(wp_login_url(self::page_url() ?: home_url('/'))),
            'labels'    => [
                'resourceSingular' => MCLB_Settings::get('resource_label_singular') ?: 'Lane',
                'resourcePlural'   => MCLB_Settings::get('resource_label_plural') ?: 'Lanes',
                'staffSingular'    => MCLB_Coaches::label_singular(),
            ],
            'i18n'      => [
                'loading'     => __('Loading…', 'mclb-lane-booking'),
                'today'       => __('Today', 'mclb-lane-booking'),
                'prev'        => __('Previous day', 'mclb-lane-booking'),
                'next'        => __('Next day', 'mclb-lane-booking'),
                'allCoaches'  => __('All coaches', 'mclb-lane-booking'),
                'showCancel'  => __('Show cancelled', 'mclb-lane-booking'),
                'toCollect'   => __('To collect today', 'mclb-lane-booking'),
                'newBooking'  => __('New booking', 'mclb-lane-booking'),
                'blockout'    => __('Block out', 'mclb-lane-booking'),
                'inProgress'  => __('Checkout in progress', 'mclb-lane-booking'),
                'sessionGone' => __('Session expired — please log in again.', 'mclb-lane-booking'),
                'needInitials'=> __('Enter your initials first.', 'mclb-lane-booking'),
                'confirm'     => __('Confirm', 'mclb-lane-booking'),
                'cancel'      => __('Cancel', 'mclb-lane-booking'),
                'back'        => __('Back', 'mclb-lane-booking'),
                'saving'      => __('Saving…', 'mclb-lane-booking'),
                'noteRequiredPaid' => __('A note is required because the counter payment has been taken.', 'mclb-lane-booking'),
                'save'        => __('Save', 'mclb-lane-booking'),
                'delete'      => __('Delete', 'mclb-lane-booking'),
                'initials'    => __('Your initials', 'mclb-lane-booking'),
                'noAccess'    => __('No access.', 'mclb-lane-booking'),
                'sendSheet'   => __('Send day sheet', 'mclb-lane-booking'),
                'resend'      => __('Resend day sheet', 'mclb-lane-booking'),
                'sheetSent'   => __('Sheet sent', 'mclb-lane-booking'),
                'sheetChanged'=> __('changed since sent', 'mclb-lane-booking'),
                'sendConfirm' => __('Send the day sheet now?', 'mclb-lane-booking'),
                'notSent'     => __('Not sent', 'mclb-lane-booking'),
                'sentOk'      => __('Sent ✓', 'mclb-lane-booking'),
                'testMode'    => __('Test mode:', 'mclb-lane-booking'),
                'testGoesTo'  => __('this will go to', 'mclb-lane-booking'),
                'notTheCoach' => __('not the coach', 'mclb-lane-booking'),
            ],
        ];

        wp_add_inline_script(self::HANDLE, 'window.mclbManage = ' . wp_json_encode($data) . ';', 'before');
    }

    private static function container_html($ctx = 'front') {
        $cls = 'mclb-manage mclb-manage--' . ($ctx === 'admin' ? 'admin' : 'front');
        return '<div class="' . esc_attr($cls) . '" data-today="' . esc_attr(wp_date('Y-m-d')) . '">'
            . '<p class="mclb-manage__loading">' . esc_html__('Loading…', 'mclb-lane-booking') . '</p>'
            . '</div>';
    }
}
