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

        // CPT must be registered before flushing so its (absent) rewrite rules
        // are handled correctly.
        MCLB_Lane::register();
        flush_rewrite_rules();

        MCLB_Bookings::schedule_cron();
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

        $charset_collate = $wpdb->get_charset_collate();
        dbDelta(MCLB_Bookings::schema($charset_collate));
        dbDelta(MCLB_Closures::schema($charset_collate));

        update_option('mclb_db_version', MCLB_DB_VERSION);
    }
}
