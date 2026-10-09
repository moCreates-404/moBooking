<?php
/**
 * Deactivation — no data removal (that's uninstall.php's job). Phase 1+ will
 * unschedule cron (hold-expiry sweeps) and flush rewrite rules here.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Deactivator {

    public static function deactivate() {
        MCLB_Bookings::clear_cron();
        MCLB_Daysheets::unschedule();
        flush_rewrite_rules();
    }
}
