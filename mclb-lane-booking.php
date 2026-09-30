<?php
/**
 * Plugin Name:       moBooking
 * Plugin URI:        https://mocreates.com.au/
 * Description:       Book out time-slotted resources (lanes, nets, bays, hoists…) on a live grid, checking out through WooCommerce. Generic and configurable per install.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            moCreates
 * Author URI:        https://mocreates.com.au/
 * License:           GPL-2.0-or-later
 * Text Domain:       mclb-lane-booking
 * Domain Path:       /languages
 *
 * moBooking — working product name; internal prefix `mclb_`. This is a
 * STANDALONE product with no dependency on any specific theme. Cricketers Club
 * is the first install: its real setup (5 nets + 4 bowling machines, $35/hr,
 * 1-hour increments, brand palette) is seeded as EDITABLE DEFAULTS here, never
 * hardcoded behaviour. Any other business can rename the "Lane" label, colours,
 * hours, types and pricing entirely from the settings screen.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MCLB_VERSION', '0.1.0');
define('MCLB_DB_VERSION', '2'); // bump to trigger dbDelta migrations (see MCLB_Activator). v2: admin_note column.
define('MCLB_FILE', __FILE__);
define('MCLB_DIR', plugin_dir_path(__FILE__));
define('MCLB_URL', plugin_dir_url(__FILE__));
define('MCLB_SLUG', 'mclb-lane-booking');
define('MCLB_OPTION', 'mclb_settings');

// ── Manual autoload ─────────────────────────────────────────────────────────
// A plain require map, not Composer/PSR-4, so the plugin stays drop-in
// installable with zero build step. Phases 1+ add their classes to this list.
require_once MCLB_DIR . 'includes/class-mclb-settings.php';
require_once MCLB_DIR . 'includes/class-mclb-license.php';
require_once MCLB_DIR . 'includes/class-mclb-lane.php';
require_once MCLB_DIR . 'includes/class-mclb-closures.php';
require_once MCLB_DIR . 'includes/class-mclb-bookings.php';
require_once MCLB_DIR . 'includes/class-mclb-availability.php';
require_once MCLB_DIR . 'includes/class-mclb-grid.php';
require_once MCLB_DIR . 'includes/class-mclb-cart.php';
require_once MCLB_DIR . 'includes/class-mclb-order.php';
require_once MCLB_DIR . 'includes/class-mclb-refunds.php';
require_once MCLB_DIR . 'includes/class-mclb-account.php';
require_once MCLB_DIR . 'includes/admin/class-mclb-admin.php';
require_once MCLB_DIR . 'includes/admin/class-mclb-closures-admin.php';
require_once MCLB_DIR . 'includes/admin/class-mclb-bookings-admin.php';
require_once MCLB_DIR . 'includes/admin/class-mclb-report.php';
require_once MCLB_DIR . 'includes/class-mclb-activator.php';
require_once MCLB_DIR . 'includes/class-mclb-deactivator.php';
require_once MCLB_DIR . 'includes/class-mclb-plugin.php';

register_activation_hook(__FILE__, ['MCLB_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['MCLB_Deactivator', 'deactivate']);

// Declare HPOS (custom order tables) compatibility — the plugin only touches
// orders via WC CRUD, so it is compatible with either order-storage backend.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MCLB_FILE, true);
    }
});

// Boot once all plugins are loaded.
add_action('plugins_loaded', ['MCLB_Plugin', 'instance']);
