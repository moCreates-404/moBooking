<?php
/**
 * Plan gating — Essentials vs Pro (v1.6.0).
 *
 * Essentials: the booking grid, WooCommerce checkout, closures, booking emails,
 *             branding and guest self-cancel.
 * Pro:        everything in Essentials plus staff (coach/instructor) requests,
 *             assignment and roster, staff day sheets, prepaid passes,
 *             recurring bookings and the report.
 *
 * Gating happens at a few choke points so it can't be missed:
 *   - MCLB_Coaches::options() returns no staff → roster/assignment/day sheets
 *     have nothing to show;
 *   - the coach-request and day-sheet settings read as "off";
 *   - Pro REST routes answer 403 with an upgrade message;
 *   - the Passes and Report screens and the customer pass box are hidden.
 * Existing data is never deleted or hidden from the database — upgrading
 * brings it straight back.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Plan {

    const PRO = 'pro';

    /** REST routes (regex on the route path) that need Pro. */
    const PRO_ROUTES = [
        '#^/mclb/v1/admin/coaches#',
        '#^/mclb/v1/admin/series#',
        '#^/mclb/v1/admin/booking/\d+/cancel-series#',
        '#^/mclb/v1/admin/booking/\d+/coach#',      // coach + coach-fee
        '#^/mclb/v1/admin/pass#',
        '#^/mclb/v1/admin/booking/\d+/pass#',
        '#^/mclb/v1/admin/daysheet#',               // daysheet + daysheets/all
        '#^/mclb/v1/cart/pass#',
    ];

    /** @var bool|null Per-request cache. */
    private static $pro = null;

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_rest'], 10, 3);
        add_action('admin_menu', [__CLASS__, 'hide_pro_screens'], 99);
    }

    public static function is_pro() {
        if (self::$pro !== null) {
            return self::$pro;
        }
        return self::$pro = (bool) apply_filters('mclb_is_pro', self::detect());
    }

    public static function name() {
        return self::is_pro() ? 'Pro' : 'Essentials';
    }

    public static function upgrade_url() {
        $fs = function_exists('mobooking_fs') ? mobooking_fs() : false;
        return $fs ? $fs->get_upgrade_url() : admin_url('admin.php?page=mclb-settings&tab=license');
    }

    public static function upgrade_message() {
        return __('This is a moBooking Pro feature. Upgrade to Pro to use it.', 'mobooking');
    }

    /**
     * @param mixed           $result
     * @param WP_REST_Server  $server
     * @param WP_REST_Request $request
     */
    public static function guard_rest($result, $server, $request) {
        if ($result !== null || self::is_pro()) {
            return $result;
        }
        $route = $request->get_route();
        foreach (self::PRO_ROUTES as $re) {
            if (preg_match($re, $route)) {
                return new WP_Error('mclb_pro_only', self::upgrade_message(), ['status' => 403]);
            }
        }
        return $result;
    }

    public static function hide_pro_screens() {
        if (self::is_pro()) {
            return;
        }
        remove_submenu_page(MCLB_Admin::PAGE, MCLB_Passes_Admin::SLUG);
        remove_submenu_page(MCLB_Admin::PAGE, MCLB_Report::SLUG);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private static function detect() {
        // Self-hosted / development unlock covers every feature.
        if (defined('MCLB_LICENSE_UNLOCK') && MCLB_LICENSE_UNLOCK) {
            return true;
        }

        // Make sure the licence state (incl. the one-time upgrade seeding that
        // flags pre-tier installs as Pro) exists before we read it.
        MCLB_License::status();

        $fs = function_exists('mobooking_fs') ? mobooking_fs() : false;
        if ($fs && $fs->is_registered()) {
            $license = $fs->_get_license();
            if ($fs->is_trial()) {
                $pro = $fs->is_trial_plan(self::PRO);
                self::remember($pro);
                return $pro;
            }
            if (is_object($license)) {
                $pro = $fs->is_plan(self::PRO);
                self::remember($pro);
                return $pro;
            }
        }

        // No live licence (grace period, unconfigured build, upgrade window):
        // keep whatever plan the site last had, so a lapse never strips
        // features mid-grace. Installs that predate tiers had everything.
        $state = get_option(MCLB_License::STATE_OPTION, []);
        if (is_array($state) && isset($state['plan'])) {
            return $state['plan'] === self::PRO;
        }
        return is_array($state) && !empty($state['legacy_pro']);
    }

    private static function remember($pro) {
        $state = get_option(MCLB_License::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        $plan  = $pro ? self::PRO : 'essentials';
        if (($state['plan'] ?? '') !== $plan) {
            $state['plan'] = $plan;
            update_option(MCLB_License::STATE_OPTION, $state, false);
        }
    }
}
