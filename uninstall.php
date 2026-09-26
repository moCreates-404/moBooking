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
