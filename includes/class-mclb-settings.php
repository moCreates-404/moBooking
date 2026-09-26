<?php
/**
 * Settings model — the single namespaced option (MCLB_OPTION) every other
 * subsystem reads. Cricketers Club's real values are the DEFAULTS; all editable
 * in wp-admin. Colours are consumed as CSS custom properties (see
 * MCLB_Plugin::output_css_tokens), never hardcoded hex in a stylesheet.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Settings {

    /** Settings API option group. */
    const GROUP = 'mclb_settings_group';

    /** Weekday keys 1..7 => Monday..Sunday (matches wp_date('N')). */
    public static function weekdays() {
        return [
            1 => __('Monday', 'mclb-lane-booking'),
            2 => __('Tuesday', 'mclb-lane-booking'),
            3 => __('Wednesday', 'mclb-lane-booking'),
            4 => __('Thursday', 'mclb-lane-booking'),
            5 => __('Friday', 'mclb-lane-booking'),
            6 => __('Saturday', 'mclb-lane-booking'),
            7 => __('Sunday', 'mclb-lane-booking'),
        ];
    }

    /**
     * Editable defaults = Cricketers Club's real setup (the first install).
     * Nothing here is a hard constant — every value is overridable in settings.
     */
    public static function defaults() {
        return [
            'license_key'             => '',
            'resource_label_singular' => 'Lane',
            'resource_label_plural'   => 'Lanes',
            'resource_types'          => "Net\nBowling Machine",
            'price_per_hour'          => '35',
            'booking_increment'       => 60, // minutes
            'hours'                   => [
                1 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                2 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                3 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                4 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                5 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                6 => ['open' => '09:00', 'close' => '17:00', 'closed' => 0],
                7 => ['open' => '09:00', 'close' => '19:00', 'closed' => 0],
            ],
            'accent_color'            => '#f4c430',
            'state_available'         => '#ffffff',
            'state_booked'            => '#c94a55',
            'state_unfinished'        => '#fce588',
            'state_closed'            => '#85c9c2',
            'font_mode'               => 'inherit', // inherit | override
        ];
    }

    /**
     * Read the merged settings (stored over defaults).
     *
     * @param string|null $key Single key, or null for the whole array.
     */
    public static function get($key = null) {
        $opts = wp_parse_args(get_option(MCLB_OPTION, []), self::defaults());
        if ($key === null) {
            return $opts;
        }
        return $opts[$key] ?? null;
    }

    /** Register the single option + its sanitiser (fields live in MCLB_Admin). */
    public static function register() {
        register_setting(self::GROUP, MCLB_OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
    }

    /**
     * Sanitise on save. The settings screen is tabbed but writes ONE option, so
     * each tab's form only submits its own fields — we start from the current
     * stored values and overwrite only what was posted, leaving other tabs intact.
     */
    public static function sanitize($input) {
        $d   = self::defaults();
        $out = self::get(); // current values as the base
        $in  = is_array($input) ? $input : [];

        if (isset($in['license_key'])) {
            $out['license_key'] = sanitize_text_field($in['license_key']);
        }
        if (isset($in['resource_label_singular'])) {
            $out['resource_label_singular'] = sanitize_text_field($in['resource_label_singular']) ?: $d['resource_label_singular'];
        }
        if (isset($in['resource_label_plural'])) {
            $out['resource_label_plural'] = sanitize_text_field($in['resource_label_plural']) ?: $d['resource_label_plural'];
        }
        if (isset($in['resource_types'])) {
            $out['resource_types'] = sanitize_textarea_field($in['resource_types']);
        }
        if (isset($in['price_per_hour'])) {
            $out['price_per_hour'] = self::sanitize_price($in['price_per_hour']);
        }
        if (isset($in['booking_increment'])) {
            $out['booking_increment'] = self::sanitize_increment($in['booking_increment']);
        }
        if (isset($in['hours']) && is_array($in['hours'])) {
            $out['hours'] = self::sanitize_hours($in['hours']);
        }
        foreach (['accent_color', 'state_available', 'state_booked', 'state_unfinished', 'state_closed'] as $c) {
            if (isset($in[$c])) {
                $hex     = sanitize_hex_color($in[$c]);
                $out[$c] = $hex ?: $d[$c];
            }
        }
        if (isset($in['font_mode'])) {
            $out['font_mode'] = in_array($in['font_mode'], ['inherit', 'override'], true) ? $in['font_mode'] : 'inherit';
        }

        return $out;
    }

    private static function sanitize_price($v) {
        $v = preg_replace('/[^0-9.]/', '', (string) $v);
        return $v === '' ? '0' : (string) round((float) $v, 2);
    }

    private static function sanitize_increment($v) {
        $v = absint($v);
        return in_array($v, [15, 30, 60, 120], true) ? $v : 60;
    }

    private static function sanitize_time($v) {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? $v : '';
    }

    private static function sanitize_hours($hours) {
        $d   = self::defaults()['hours'];
        $out = [];
        foreach (self::weekdays() as $i => $label) {
            $row       = isset($hours[$i]) && is_array($hours[$i]) ? $hours[$i] : [];
            $open      = self::sanitize_time($row['open'] ?? '')  ?: $d[$i]['open'];
            $close     = self::sanitize_time($row['close'] ?? '') ?: $d[$i]['close'];
            $out[$i]   = [
                'open'   => $open,
                'close'  => $close,
                'closed' => empty($row['closed']) ? 0 : 1,
            ];
        }
        return $out;
    }
}
