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

    /**
     * Friendly long date label in the SITE timezone, e.g. "Monday, 26 October
     * 2026". $ts is a Unix timestamp, taken from a timezone-aware
     * DateTimeImmutable's getTimestamp() (never strtotime of a Y-m-d string,
     * which would be parsed in UTC and can shift the day). Filterable.
     */
    public static function friendly_date($ts) {
        return wp_date(apply_filters('mclb_date_format', 'l, j F Y'), (int) $ts);
    }

    /** Short friendly date for tables, e.g. "Mon 26 Oct 2026". Filterable. */
    public static function friendly_date_short($ts) {
        return wp_date(apply_filters('mclb_date_format_short', 'D j M Y'), (int) $ts);
    }

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
            'hold_minutes'            => 15, // mid-checkout hold window before a slot releases
            'booking_window_days'     => 14, // how many days ahead CUSTOMERS can book (0 = no limit); staff never limited
            'booking_window_note'     => '', // appended to the "open N days ahead" message (e.g. a phone number for later dates)
            'enable_coach_requests'   => 0,  // gates the Phase 3 "Request a coach" UI; off by default
            'staff_label_singular'    => 'Coach',  // what an assignable staff member is called (Coach, Pro, Instructor…)
            'staff_label_plural'      => 'Coaches',
            'manage_page_id'          => 0,  // page holding [mclb_manage] (the staff calendar); 0 = none set
            'terms_page_id'           => 0,  // T&Cs page shown by the customer grid; 0 = fall back to the WooCommerce terms page (hidden if neither resolves)
            // Coach day sheets (Phase 7d)
            'daysheet_enabled'        => 0,
            'daysheet_send_time'      => '19:00',
            'daysheet_target'         => 'tomorrow', // tomorrow | today
            'daysheet_names'          => 'full',     // full | first_initial | none
            'daysheet_cc'             => '',
            'daysheet_test_recipient' => '',          // when set, ALL sheets go here (dev safety)
            'daysheet_expiry_hours'   => 48,
            'wc_product_id'           => 4707, // hidden virtual "anchor" product WC cart items hang on (CCWA's; auto-provisioned elsewhere)
            'self_cancel_hours'       => 24, // logged-in self-cancel cutoff before booking start
            'refund_window_hours'     => 24, // cancel within this long after placing (and >cutoff before start) = real refund, else credit
            // Emails (Phase 8a-2)
            'email_test_recipient'      => '', // dev safety: reroutes EVERY plugin email (incl. day sheets) here
            'email_bookings'            => '', // lane-order WC admin emails + staff alerts
            'email_reception'           => '', // shop-order WC admin emails
            'email_manual_confirmation' => 1,  // email the customer when staff create a manual booking
            'alert_self_cancel'         => 1,  // staff alert on a customer self-cancel
            'alert_coach_request'       => 1,  // staff alert when an online booking requests a coach
            'alert_manual_created'      => 0,  // staff alert on a staff-created manual booking
            'alert_move'                => 0,  // staff alert on a staff move
            'hours'                   => [
                1 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                2 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                3 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                4 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                5 => ['open' => '10:00', 'close' => '22:00', 'closed' => 0],
                6 => ['open' => '09:00', 'close' => '17:00', 'closed' => 0],
                7 => ['open' => '09:00', 'close' => '19:00', 'closed' => 0],
            ],
            'event_types'             => [
                // Blockout categories (CCWA defaults). public=0 → customers see
                // "Unavailable"; toggle a type public to surface its name on the grid.
                ['slug' => 'academy',         'label' => 'Academy',         'color' => '#6c5ce7', 'public' => 0],
                ['slug' => 'coaching-clinic', 'label' => 'Coaching Clinic', 'color' => '#0984e3', 'public' => 0],
                ['slug' => 'school-group',    'label' => 'School / Group',  'color' => '#00b894', 'public' => 0],
                ['slug' => 'private-hire',    'label' => 'Private Hire',    'color' => '#e17055', 'public' => 0],
                ['slug' => 'maintenance',     'label' => 'Maintenance',     'color' => '#636e72', 'public' => 0],
                ['slug' => 'public-holiday',  'label' => 'Public Holiday',  'color' => '#d63031', 'public' => 1],
                ['slug' => 'other',           'label' => 'Other',           'color' => '#b2bec3', 'public' => 0],
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

    /**
     * Resolve the customer T&Cs link (v1.4.2). Uses the chosen page, else the
     * WooCommerce terms page; returns '' when neither resolves (grid hides the
     * line). URL only — the grid opens it in a new tab.
     */
    public static function terms_page_url() {
        $pid = (int) self::get('terms_page_id');
        if (!$pid && function_exists('wc_terms_and_conditions_page_id')) {
            $pid = (int) wc_terms_and_conditions_page_id();
        }
        if (!$pid) {
            $pid = (int) get_option('woocommerce_terms_page_id');
        }
        if (!$pid || get_post_status($pid) !== 'publish') {
            return '';
        }
        return (string) get_permalink($pid);
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
        if (isset($in['hold_minutes'])) {
            $v = absint($in['hold_minutes']);
            $out['hold_minutes'] = ($v >= 1 && $v <= 240) ? $v : 15;
        }
        if (isset($in['booking_window_days'])) {
            $out['booking_window_days'] = min(3650, absint($in['booking_window_days'])); // 0 = no limit
        }
        if (isset($in['booking_window_note'])) {
            $out['booking_window_note'] = sanitize_text_field($in['booking_window_note']);
        }
        if (isset($in['wc_product_id'])) {
            $out['wc_product_id'] = absint($in['wc_product_id']);
        }
        if (isset($in['self_cancel_hours'])) {
            $out['self_cancel_hours'] = absint($in['self_cancel_hours']);
        }
        if (isset($in['refund_window_hours'])) {
            $out['refund_window_hours'] = absint($in['refund_window_hours']);
        }
        // Checkbox: the General tab always posts a hidden value="0" companion (see
        // MCLB_Admin::checkbox_field), so an unchecked box reliably records 0 rather
        // than sticking on the previous value through this per-tab merge.
        if (isset($in['enable_coach_requests'])) {
            $out['enable_coach_requests'] = empty($in['enable_coach_requests']) ? 0 : 1;
        }
        if (isset($in['staff_label_singular'])) {
            $out['staff_label_singular'] = sanitize_text_field($in['staff_label_singular']) ?: $d['staff_label_singular'];
        }
        if (isset($in['staff_label_plural'])) {
            $out['staff_label_plural'] = sanitize_text_field($in['staff_label_plural']) ?: $d['staff_label_plural'];
        }
        if (isset($in['manage_page_id'])) {
            $out['manage_page_id'] = absint($in['manage_page_id']);
        }
        if (isset($in['terms_page_id'])) {
            $out['terms_page_id'] = absint($in['terms_page_id']);
        }
        // Emails (General tab). Address fields guarded by isset so another tab's
        // save doesn't clear them; checkboxes carry a hidden companion so unchecked
        // records 0. The test recipient absorbs the old day-sheet value once, so
        // the single field becomes the source of truth going forward.
        if (isset($in['email_bookings'])) {
            $out['email_bookings'] = sanitize_email($in['email_bookings']);
        }
        if (isset($in['email_reception'])) {
            $out['email_reception'] = sanitize_email($in['email_reception']);
        }
        if (isset($in['email_test_recipient'])) {
            $v = sanitize_email($in['email_test_recipient']);
            if ($v === '' && !empty($out['daysheet_test_recipient'])) {
                $v = sanitize_email((string) $out['daysheet_test_recipient']); // migrate the old value across
            }
            $out['email_test_recipient'] = $v;
        }
        foreach (['email_manual_confirmation', 'alert_self_cancel', 'alert_coach_request', 'alert_manual_created', 'alert_move'] as $cb) {
            if (isset($in[$cb])) {
                $out[$cb] = empty($in[$cb]) ? 0 : 1;
            }
        }
        // Day sheets. The tab posts a hidden marker so unchecked/empty fields are
        // distinguishable from another tab's save (same pattern as the checkbox).
        if (!empty($in['daysheet_submitted'])) {
            $out['daysheet_enabled']        = empty($in['daysheet_enabled']) ? 0 : 1;
            $out['daysheet_send_time']      = self::sanitize_time($in['daysheet_send_time'] ?? '') ?: $d['daysheet_send_time'];
            $out['daysheet_target']         = in_array($in['daysheet_target'] ?? '', ['tomorrow', 'today'], true) ? $in['daysheet_target'] : 'tomorrow';
            $out['daysheet_names']          = in_array($in['daysheet_names'] ?? '', ['full', 'first_initial', 'none'], true) ? $in['daysheet_names'] : 'full';
            $out['daysheet_cc']             = sanitize_email($in['daysheet_cc'] ?? '');
            $out['daysheet_test_recipient'] = sanitize_email($in['daysheet_test_recipient'] ?? '');
            $v                              = absint($in['daysheet_expiry_hours'] ?? 0);
            $out['daysheet_expiry_hours']   = ($v >= 1 && $v <= 720) ? $v : 48;
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
        // Event types tab posts a hidden marker so "all rows removed" is
        // distinguishable from "a different tab was saved" (same reason as the
        // checkbox hidden-companion). Only then do we rewrite the list.
        if (!empty($in['event_types_submitted'])) {
            $out['event_types'] = self::sanitize_event_types($in['event_types'] ?? []);
        }

        return $out;
    }

    /**
     * Normalise the repeatable event-type rows. A blank label = a removed row.
     * Slugs are stable: a row carries its existing slug (hidden) so renaming the
     * label keeps closures pointed at the same type; new rows derive a slug from
     * the label, and collisions are de-duplicated.
     */
    public static function sanitize_event_types($rows) {
        if (!is_array($rows)) {
            return self::get('event_types');
        }
        $out  = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ($label === '') {
                continue;
            }
            $slug = isset($row['slug']) ? sanitize_title($row['slug']) : '';
            if ($slug === '') {
                $slug = sanitize_title($label);
            }
            if ($slug === '') {
                $slug = 'type';
            }
            $base = $slug;
            $n    = 2;
            while (isset($seen[$slug])) {
                $slug = $base . '-' . $n;
                $n++;
            }
            $seen[$slug] = true;

            $color = isset($row['color']) ? sanitize_hex_color($row['color']) : '';
            if (!$color) {
                $color = MCLB_Event_Types::FALLBACK_COLOR;
            }
            $out[] = [
                'slug'   => $slug,
                'label'  => $label,
                'color'  => $color,
                'public' => empty($row['public']) ? 0 : 1,
            ];
        }
        return $out;
    }

    // Public so per-lane overrides (MCLB_Lane) reuse the same rules rather than
    // duplicating them — a lane's price/hours are sanitised exactly like the site
    // defaults.
    public static function sanitize_price($v) {
        $v = preg_replace('/[^0-9.]/', '', (string) $v);
        return $v === '' ? '0' : (string) round((float) $v, 2);
    }

    private static function sanitize_increment($v) {
        $v = absint($v);
        return in_array($v, [15, 30, 60, 120], true) ? $v : 60;
    }

    public static function sanitize_time($v) {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? $v : '';
    }

    public static function sanitize_hours($hours) {
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
