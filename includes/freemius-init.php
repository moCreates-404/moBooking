<?php
/**
 * Freemius bootstrap — licensing, checkout and automatic updates.
 *
 * moBooking is sold as a premium-only plugin through Freemius. The Product ID
 * and Public Key below are PUBLIC values (safe to commit). The Secret Key is
 * NOT — never put it in this file or anywhere in the repo.
 *
 * Until both values are filled in, the SDK is not loaded at all and
 * MCLB_License reports "not configured" (bookings stay in demo mode unless
 * MCLB_LICENSE_UNLOCK is defined), so a half-configured build can never
 * silently hand out free licences.
 *
 * Freemius dashboard must use slug = premium slug = "mobooking" so update
 * checks match this plugin's folder name.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Fill these in from Freemius → Product → Settings → Keys ─────────────────
if (!defined('MCLB_FS_ID')) {
    define('MCLB_FS_ID', '');          // e.g. '12345'
}
if (!defined('MCLB_FS_PUBLIC_KEY')) {
    define('MCLB_FS_PUBLIC_KEY', '');  // e.g. 'pk_abc123…'
}

if (!function_exists('mobooking_fs')) {
    /**
     * Freemius instance, or false when the product isn't configured yet.
     *
     * @return Freemius|false
     */
    function mobooking_fs() {
        global $mobooking_fs;

        if (!isset($mobooking_fs)) {
            if (MCLB_FS_ID === '' || MCLB_FS_PUBLIC_KEY === '') {
                $mobooking_fs = false;
                return $mobooking_fs;
            }

            require_once MCLB_DIR . 'freemius/start.php';

            $mobooking_fs = fs_dynamic_init([
                'id'                  => MCLB_FS_ID,
                'slug'                => 'mobooking',
                'premium_slug'        => 'mobooking',
                'type'                => 'plugin',
                'public_key'          => MCLB_FS_PUBLIC_KEY,
                'is_premium'          => true,
                'is_premium_only'     => true,
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => false,
                'is_live'             => true,
                'menu'                => [
                    // Hang Freemius' Account / Pricing pages off the existing
                    // moBooking menu, so staff bookmarks and URLs don't change.
                    'slug'    => 'mclb-settings',
                    'contact' => false,
                    'support' => false,
                ],
            ]);
        }

        return $mobooking_fs;
    }

    mobooking_fs();
    do_action('mobooking_fs_loaded');
}
