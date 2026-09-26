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

        if (is_admin()) {
            new MCLB_Admin();
            new MCLB_Closures_Admin();
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

        $css = ':root{';
        foreach ($vars as $k => $v) {
            $css .= $k . ':' . esc_attr($v) . ';';
        }
        $css .= '}';

        echo "\n<style id=\"mclb-tokens\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hex-sanitised on save + esc_attr per value.
    }
}
