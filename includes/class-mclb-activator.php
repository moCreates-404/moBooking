<?php
/**
 * Activation — seed default settings (reactivation-safe), create the custom
 * tables, register the CPT so rewrite rules flush cleanly, and schedule the
 * hold-sweep cron.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Activator {

    public static function activate() {
        if (!is_array(get_option(MCLB_OPTION, null))) {
            add_option(MCLB_OPTION, MCLB_Settings::defaults());
        }

        self::install();

        // Capabilities + Booking Staff role (Phase 7c).
        MCLB_Caps::install();

        // CPT must be registered before flushing so its (absent) rewrite rules
        // are handled correctly.
        MCLB_Lane::register();
        flush_rewrite_rules();

        MCLB_Bookings::schedule_cron();
        MCLB_Daysheets::schedule(); // no-op unless day sheets are enabled
    }

    /** Run table creation only when the stored DB version is behind the code. */
    public static function maybe_install() {
        if (get_option('mclb_db_version') !== MCLB_DB_VERSION) {
            self::install();
        }
    }

    /** Create/upgrade custom tables via dbDelta and record the version. */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prev            = get_option('mclb_db_version');
        $charset_collate = $wpdb->get_charset_collate();
        dbDelta(MCLB_Bookings::schema($charset_collate));
        dbDelta(MCLB_Closures::schema($charset_collate));

        // One-time v3 backfill: tag pre-existing orderless bookings as manual.
        // Runs ONLY when crossing up into v3 from an earlier version — not on
        // fresh installs, re-activations, or future upgrades. A manual entry has
        // no WC order AND no session_token; anything orderless that is held, or
        // carries a session_token (a live or swept-cancelled online hold), is
        // excluded so it is never mislabelled.
        if ($prev !== false && version_compare((string) $prev, '3', '<')) {
            $bt = MCLB_Bookings::table();
            $wpdb->query($wpdb->prepare(
                "UPDATE {$bt} SET source = 'manual'
                   WHERE order_id IS NULL
                     AND status <> %s
                     AND (session_token IS NULL OR session_token = '')",
                MCLB_Bookings::STATUS_HELD
            ));
        }

        update_option('mclb_db_version', MCLB_DB_VERSION);
    }
}
