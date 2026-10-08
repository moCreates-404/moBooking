<?php
/**
 * Capabilities + the Booking Staff role (Phase 7c).
 *
 *   mclb_manage_bookings — use the Manage view: see the calendar, create manual
 *       bookings, assign coaches, record counter payments, cancel (no refund),
 *       and add/edit one-off blockouts. Granted to administrator, shop_manager,
 *       and the dedicated Booking Staff role (the shared counter login).
 *   mclb_refund_bookings — the real WC/gateway "Cancel + refund". Administrator
 *       and shop_manager ONLY — never counter staff.
 *
 * Granted on activation and self-healed on admin_init via a version option
 * (activation doesn't run on a plugin update). Removed in uninstall.php.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Caps {

    const ROLE           = 'mclb_booking_staff';
    const MANAGE         = 'mclb_manage_bookings';
    const REFUND         = 'mclb_refund_bookings';
    const VERSION        = '1';
    const VERSION_OPTION = 'mclb_caps_version';

    public static function init() {
        // 'init' (not 'admin_init'): it fires before 'admin_menu', so on the first
        // admin load after a plugin update the Manage/Calendar capability is already
        // granted when the submenu's capability is checked. Guarded by a version
        // option, so it writes to the roles only once.
        add_action('init', [__CLASS__, 'maybe_sync']);
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);
    }

    /** Create/refresh the role and grant caps. Idempotent. */
    public static function install() {
        remove_role(self::ROLE); // drop then re-add so the cap set is always current
        add_role(self::ROLE, __('Booking Staff', 'mclb-lane-booking'), [
            'read'       => true,
            self::MANAGE => true,
        ]);
        foreach (['administrator', 'shop_manager'] as $r) {
            $role = get_role($r);
            if ($role) {
                $role->add_cap(self::MANAGE);
                $role->add_cap(self::REFUND);
            }
        }
        update_option(self::VERSION_OPTION, self::VERSION);
    }

    /** Self-heal on plugin update (the activation hook doesn't run then). */
    public static function maybe_sync() {
        if (get_option(self::VERSION_OPTION) !== self::VERSION) {
            self::install();
        }
    }

    /** Remove everything this adds (uninstall.php). */
    public static function uninstall() {
        remove_role(self::ROLE);
        foreach (['administrator', 'shop_manager'] as $r) {
            $role = get_role($r);
            if ($role) {
                $role->remove_cap(self::MANAGE);
                $role->remove_cap(self::REFUND);
            }
        }
        delete_option(self::VERSION_OPTION);
    }

    /**
     * Booking Staff (can manage, but has no wp-admin access — WooCommerce bounces
     * them) land on the Manage page at login instead of the dashboard.
     */
    public static function login_redirect($redirect_to, $requested, $user) {
        if ($user instanceof WP_User
            && user_can($user, self::MANAGE)
            && !user_can($user, 'edit_posts')
            && !user_can($user, 'manage_woocommerce')) {
            $url = MCLB_Manage::page_url();
            if ($url) {
                return $url;
            }
        }
        return $redirect_to;
    }
}
