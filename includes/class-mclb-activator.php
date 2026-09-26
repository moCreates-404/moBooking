<?php
/**
 * Activation — seed default settings, reactivation-safe (never clobbers an
 * existing config). Phase 1 will create custom tables / register post types
 * here and flush rewrite rules.
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
    }
}
