<?php
/**
 * Uninstall — runs when the plugin is deleted (not merely deactivated).
 * Removes settings. Phase 1+ will drop its custom tables here (guarded).
 *
 * @package moBooking
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mclb_settings');
delete_option('mclb_db_version');
delete_option('mclb_daysheet_secret');
delete_option('mclb_daysheet_log');

// Remove capabilities + the Booking Staff role (Phase 7c).
require_once __DIR__ . '/includes/class-mclb-caps.php';
if (class_exists('MCLB_Caps')) {
    MCLB_Caps::uninstall();
}
