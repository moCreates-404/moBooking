<?php
/**
 * Uninstall — runs when the plugin is DELETED from WP Admin (not deactivated).
 *
 * Default: keep everything. Bookings, passes, closures, settings, roles and the
 * licence state all survive, so deleting and reinstalling (or swapping the
 * plugin folder during an upgrade) never loses a business's data.
 *
 * Only when the site owner has ticked Settings → License → "Delete data on
 * uninstall" is everything removed.
 *
 * @package moBooking
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$mclb_settings = get_option('mclb_settings', []);
if (!is_array($mclb_settings) || empty($mclb_settings['delete_data_on_uninstall'])) {
    return; // Keep all data.
}

global $wpdb;

// Custom tables.
foreach (['mclb_bookings', 'mclb_closures', 'mclb_closure_exceptions', 'mclb_passes', 'mclb_pass_ledger'] as $mclb_table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$mclb_table}`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names.
}

// Lane CPT posts (and their meta, via wp_delete_post).
$mclb_lanes = get_posts([
    'post_type'      => 'mclb_lane',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
]);
foreach ($mclb_lanes as $mclb_lane_id) {
    wp_delete_post($mclb_lane_id, true);
}

// Scheduled events.
$mclb_cron = _get_cron_array();
if (is_array($mclb_cron)) {
    foreach ($mclb_cron as $mclb_events) {
        foreach (array_keys((array) $mclb_events) as $mclb_hook) {
            if (strpos($mclb_hook, 'mclb_') === 0) {
                wp_clear_scheduled_hook($mclb_hook);
            }
        }
    }
}

// Every mclb_* option and transient.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE 'mclb\\_%'
         OR option_name LIKE '\\_transient\\_mclb\\_%'
         OR option_name LIKE '\\_transient\\_timeout\\_mclb\\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Capabilities + the Booking Staff role.
require_once __DIR__ . '/includes/class-mclb-caps.php';
if (class_exists('MCLB_Caps')) {
    MCLB_Caps::uninstall();
}
