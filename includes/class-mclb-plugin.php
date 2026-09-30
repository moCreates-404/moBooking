<?php
/**
 * Plugin bootstrap — wires the subsystems. Deliberately thin in Phase 0:
 * registers the settings option, loads the admin UI, and emits the brand/state
 * colours as CSS custom properties so later front-end phases consume tokens
 * (--mclb-*) rather than hardcoded hex.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Plugin {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Bring the DB schema up to date on load too (not only on activation) —
        // covers a symlinked dev install that was activated before Phase 1's
        // tables existed, and future migrations when MCLB_DB_VERSION bumps.
        MCLB_Activator::maybe_install();

        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', ['MCLB_Settings', 'register']);

        // Data subsystems.
        MCLB_Lane::init();
        MCLB_Bookings::init();

        // Front-end grid (shortcode + REST + assets).
        MCLB_Grid::init();

        // WooCommerce cart + order lifecycle (hooks are inert until WC fires them).
        MCLB_Cart::init();
        MCLB_Order::init();
        MCLB_Account::init();

        if (is_admin()) {
            new MCLB_Admin();
            new MCLB_Closures_Admin();
            new MCLB_Bookings_Admin();
        }

        add_action('wp_head', [$this, 'output_css_tokens'], 20);
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'mclb-lane-booking',
            false,
            dirname(plugin_basename(MCLB_FILE)) . '/languages'
        );
    }

    /**
     * Print the configurable palette as CSS custom properties on :root, so the
     * booking grid (Phase 3) is themed entirely from settings. Values are
     * hex-sanitised on save and escaped again here.
     */
    public function output_css_tokens() {
        $s    = MCLB_Settings::get();
        $vars = [
            '--mclb-accent'     => $s['accent_color'],
            '--mclb-available'  => $s['state_available'],
            '--mclb-booked'     => $s['state_booked'],
            '--mclb-unfinished' => $s['state_unfinished'],
            '--mclb-closed'     => $s['state_closed'],
        ];

        // Font handling: 'inherit' (default) lets the grid pick up the active
        // theme's fonts; 'override' emits the plugin's own stack. Grid CSS reads
        // font-family: var(--mclb-font, inherit), so this is the only wiring needed.
        $vars['--mclb-font'] = ($s['font_mode'] === 'override')
            ? 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'
            : 'inherit';

        $css = ':root{';
        foreach ($vars as $k => $v) {
            // The font stack is a value list, not a single token sanitised on save,
            // so escape it as a CSS-ish string rather than esc_attr'ing commas away.
            $css .= $k . ':' . ($k === '--mclb-font' ? wp_strip_all_tags($v) : esc_attr($v)) . ';';
        }
        $css .= '}';

        echo "\n<style id=\"mclb-tokens\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hex-sanitised on save + esc_attr per value.
    }
}
