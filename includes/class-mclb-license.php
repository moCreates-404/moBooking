<?php
/**
 * License handling — STUB for Phase 0.
 *
 * The real check will hit moCreates' own simple self-managed key store (no
 * EDD/Freemius). What happens on an invalid/expired key — read-only mode vs a
 * nag notice vs hard-disable — is deliberately NOT enforced yet; that's a
 * decision to make when the real endpoint is wired. Until then any non-empty
 * key is treated as valid so development is never blocked.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_License {

    /**
     * @return array{valid:bool,status:string,message:string}
     */
    public static function status() {
        $key = trim((string) MCLB_Settings::get('license_key'));

        if ($key === '') {
            return [
                'valid'   => false,
                'status'  => 'empty',
                'message' => __('No license key entered.', 'mclb-lane-booking'),
            ];
        }

        // TODO: call moCreates key store here, and implement the invalid/expired
        //       gating behaviour once Morg decides (read-only / nag / disable).
        return [
            'valid'   => true,
            'status'  => 'active',
            'message' => __('License active (development stub — not yet validated remotely).', 'mclb-lane-booking'),
        ];
    }

    public static function is_valid() {
        return self::status()['valid'];
    }

    /**
     * The single write-gate. When this is false the plugin runs in read-only /
     * demo mode: the grid still renders availability, but no booking can be
     * committed — the REST cart endpoint and the admin "Add booking" screen both
     * refuse. Everything that takes a slot funnels through here so the gating
     * lives in one place (the remote validation swap lands in status() only).
     *
     * Escape hatches for self-hosted / development installs:
     *   - define('MCLB_LICENSE_UNLOCK', true) in wp-config.php, or
     *   - add_filter('mclb_can_book', '__return_true')
     */
    public static function can_book() {
        $can = (defined('MCLB_LICENSE_UNLOCK') && MCLB_LICENSE_UNLOCK) ? true : self::is_valid();
        return (bool) apply_filters('mclb_can_book', $can);
    }

    /** Customer-/staff-facing explanation shown when writes are blocked. */
    public static function demo_message() {
        return __('This booking system is running in demo mode — bookings can’t be completed until the site’s licence is activated.', 'mclb-lane-booking');
    }
}
