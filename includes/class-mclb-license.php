<?php
/**
 * License handling — backed by Freemius (see includes/freemius-init.php).
 *
 * Policy (decided Nov 2026):
 *   - Active, lifetime or trial licence → full use.
 *   - Expired licence → 14-day grace period with admin warnings, then
 *     read-only demo mode (the grid still shows availability, nothing can be
 *     committed). Existing bookings, the manage view and refunds are never
 *     gated — only taking NEW bookings funnels through can_book().
 *   - Licence missing/deactivated after having been valid → the same 14-day
 *     grace from the last time it was confirmed, so a Freemius hiccup or an
 *     accidental deactivation never stops a business mid-day.
 *   - Installs upgrading from the pre-Freemius build (a stub key was saved)
 *     get one 14-day grace window to activate, so an update can't lock out a
 *     live site.
 *
 * Freemius keeps its own local copy of the licence and syncs it periodically,
 * so a Freemius outage doesn't flip a valid site to invalid.
 *
 * Escape hatches for self-hosted / development installs (unchanged):
 *   - define('MCLB_LICENSE_UNLOCK', true) in wp-config.php, or
 *   - add_filter('mclb_can_book', '__return_true')
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_License {

    const GRACE_DAYS   = 14;
    const STATE_OPTION = 'mclb_license_state'; // ['last_valid' => int, 'legacy_seeded' => bool]

    /** @var array|null Per-request cache. */
    private static $cache = null;

    public static function init() {
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    /**
     * @return array{valid:bool,status:string,message:string,grace_until:int|null}
     */
    public static function status() {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::maybe_seed_legacy_grace();
        return self::$cache = self::compute();
    }

    public static function is_valid() {
        return self::status()['valid'];
    }

    /**
     * The single write-gate. When this is false the plugin runs in read-only /
     * demo mode: the grid still renders availability, but no booking can be
     * committed — the REST cart endpoint and the admin "Add booking" screen both
     * refuse. Everything that takes a slot funnels through here.
     */
    public static function can_book() {
        $can = (defined('MCLB_LICENSE_UNLOCK') && MCLB_LICENSE_UNLOCK) ? true : self::is_valid();
        return (bool) apply_filters('mclb_can_book', $can);
    }

    /** Customer-/staff-facing explanation shown when writes are blocked. */
    public static function demo_message() {
        return __('This booking system is running in demo mode — bookings can’t be completed until the site’s licence is activated.', 'mobooking');
    }

    /** Where an admin goes to activate / renew. */
    public static function account_url() {
        $fs = self::fs();
        if (!$fs) {
            return admin_url('admin.php?page=mclb-settings');
        }
        return $fs->is_registered() ? $fs->get_account_url() : $fs->get_activation_url();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return Freemius|false */
    private static function fs() {
        return function_exists('mobooking_fs') ? mobooking_fs() : false;
    }

    private static function compute() {
        $fs  = self::fs();
        $now = time();

        if (!$fs) {
            // A build shipped without Freemius keys must not lock out a site
            // that was previously licensed — honour the same grace window.
            $grace = self::grace_from_last_valid($now);
            if ($grace) {
                return $grace;
            }
            return self::result(false, 'unconfigured', __('Licensing isn’t configured in this build.', 'mobooking'));
        }

        if ($fs->is_trial()) {
            self::mark_valid($now);
            return self::result(true, 'trial', __('Trial active.', 'mobooking'));
        }

        $license = $fs->is_registered() ? $fs->_get_license() : false;

        if (is_object($license) && is_numeric($license->id) && $license->is_active()) {
            if ($license->is_lifetime()) {
                self::mark_valid($now);
                return self::result(true, 'active', __('Lifetime licence active.', 'mobooking'));
            }

            $expires = strtotime((string) $license->expiration);

            if (!$license->is_expired()) {
                self::mark_valid($now);
                return self::result(true, 'active', sprintf(
                    /* translators: %s: renewal date */
                    __('Licence active — renews %s.', 'mobooking'),
                    wp_date(get_option('date_format'), $expires)
                ));
            }

            // Expired: grace counts from the expiry date.
            $grace_until = $expires + self::GRACE_DAYS * DAY_IN_SECONDS;
            if ($now < $grace_until) {
                return self::result(true, 'grace', sprintf(
                    /* translators: 1: expiry date, 2: date bookings stop */
                    __('Licence expired on %1$s. Bookings keep working until %2$s — renew to avoid interruption.', 'mobooking'),
                    wp_date(get_option('date_format'), $expires),
                    wp_date(get_option('date_format'), $grace_until)
                ), $grace_until);
            }

            return self::result(false, 'expired', sprintf(
                /* translators: %s: expiry date */
                __('Licence expired on %s. New bookings are paused until it’s renewed.', 'mobooking'),
                wp_date(get_option('date_format'), $expires)
            ));
        }

        // No usable licence. Grace from the last time one was confirmed.
        $grace = self::grace_from_last_valid($now);
        if ($grace) {
            return $grace;
        }

        return self::result(false, 'inactive', __('No active licence. New bookings are paused until a licence is activated.', 'mobooking'));
    }

    /** Grace result if a licence was confirmed within GRACE_DAYS, else null. */
    private static function grace_from_last_valid($now) {
        $state = self::state();
        if (empty($state['last_valid'])) {
            return null;
        }
        $grace_until = (int) $state['last_valid'] + self::GRACE_DAYS * DAY_IN_SECONDS;
        if ($now >= $grace_until) {
            return null;
        }
        return self::result(true, 'grace', sprintf(
            /* translators: %s: date bookings stop */
            __('No active licence found. Bookings keep working until %s — activate a licence to avoid interruption.', 'mobooking'),
            wp_date(get_option('date_format'), $grace_until)
        ), $grace_until);
    }

    private static function result($valid, $status, $message, $grace_until = null) {
        return [
            'valid'       => (bool) $valid,
            'status'      => $status,
            'message'     => $message,
            'grace_until' => $grace_until,
        ];
    }

    private static function state() {
        $s = get_option(self::STATE_OPTION, []);
        return is_array($s) ? $s : [];
    }

    /** Record a confirmed-valid moment (throttled to one write an hour). */
    private static function mark_valid($now) {
        $s = self::state();
        if (empty($s['last_valid']) || ($now - (int) $s['last_valid']) > HOUR_IN_SECONDS) {
            $s['last_valid'] = $now;
            update_option(self::STATE_OPTION, $s, false);
        }
    }

    /**
     * One-time: an install that ran the pre-Freemius build (any stub key saved)
     * gets a 14-day window from first run of this version to activate.
     */
    private static function maybe_seed_legacy_grace() {
        $s = self::state();
        if (!empty($s['legacy_seeded'])) {
            return;
        }
        $s['legacy_seeded'] = true;
        $legacy_key = trim((string) MCLB_Settings::get('license_key'));
        if ($legacy_key !== '' && empty($s['last_valid'])) {
            $s['last_valid'] = time();
        }
        update_option(self::STATE_OPTION, $s, false);
    }

    /** Admin warning for anything other than a healthy licence. */
    public static function admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $st = self::status();
        if (in_array($st['status'], ['active', 'trial'], true)) {
            return;
        }
        if ($st['status'] === 'unconfigured' && defined('MCLB_LICENSE_UNLOCK') && MCLB_LICENSE_UNLOCK) {
            return; // dev install, deliberately unlocked
        }
        $class = $st['valid'] ? 'notice-warning' : 'notice-error';
        printf(
            '<div class="notice %1$s"><p><strong>moBooking:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
            esc_attr($class),
            esc_html($st['message']),
            esc_url(self::account_url()),
            esc_html__('Manage licence', 'mobooking')
        );
    }
}
