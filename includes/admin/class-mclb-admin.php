<?php
/**
 * Admin UI — the tabbed settings screen (General / Appearance / License).
 * All fields write into the single MCLB_OPTION array via the Settings API;
 * MCLB_Settings::sanitize() merges per-tab so switching tabs never wipes the
 * others.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Admin {

    const PAGE = 'mclb-settings';

    /** @var array<string,string> tab slug => label */
    private $tabs;

    public function __construct() {
        $this->tabs = [
            'general'    => __('General', 'mclb-lane-booking'),
            'appearance' => __('Appearance', 'mclb-lane-booking'),
            'license'    => __('License', 'mclb-lane-booking'),
        ];

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function menu() {
        add_menu_page(
            'moBooking',
            'moBooking',
            'manage_options',
            self::PAGE,
            [$this, 'render_page'],
            'dashicons-calendar-alt',
            56
        );
    }

    public function enqueue($hook) {
        if ($hook !== 'toplevel_page_' . self::PAGE) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script(
            'mclb-admin',
            MCLB_URL . 'assets/admin/settings.js',
            ['jquery', 'wp-color-picker'],
            MCLB_VERSION,
            true
        );
    }

    private function current_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
        return array_key_exists($tab, $this->tabs) ? $tab : 'general';
    }

    /** One "page" slug per tab so do_settings_sections renders just that tab. */
    private function tab_page($tab) {
        return self::PAGE . '_' . $tab;
    }

    // ── Field/section registration ───────────────────────────────────────────

    public function register_fields() {
        $g = $this->tab_page('general');
        $a = $this->tab_page('appearance');
        $l = $this->tab_page('license');

        // General → wording
        add_settings_section('mclb_labels', __('Resource wording', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('What one bookable unit is called on this site — shown to customers and in admin copy. The database keeps the technical term “lane” regardless.', 'mclb-lane-booking') . '</p>';
        }, $g);
        $this->text_field($g, 'mclb_labels', 'resource_label_singular', __('Unit label (singular)', 'mclb-lane-booking'), __('e.g. Lane, Bay, Hoist', 'mclb-lane-booking'));
        $this->text_field($g, 'mclb_labels', 'resource_label_plural', __('Unit label (plural)', 'mclb-lane-booking'), __('e.g. Lanes, Bays, Hoists', 'mclb-lane-booking'));

        // General → booking rules
        add_settings_section('mclb_booking', __('Booking rules', 'mclb-lane-booking'), '__return_false', $g);
        $this->textarea_field($g, 'mclb_booking', 'resource_types', __('Resource types', 'mclb-lane-booking'), __('One per line (e.g. Net, Bowling Machine). Admin-defined — not a fixed list.', 'mclb-lane-booking'));
        $this->price_field($g, 'mclb_booking', 'price_per_hour', __('Price per hour', 'mclb-lane-booking'));
        $this->increment_field($g, 'mclb_booking', 'booking_increment', __('Booking increment', 'mclb-lane-booking'));
        $this->number_field($g, 'mclb_booking', 'hold_minutes', __('Hold window', 'mclb-lane-booking'), __('minutes', 'mclb-lane-booking'), 1, 240);

        // General → coach requests
        add_settings_section('mclb_coach', __('Coach requests', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('Cricketers-Club-specific add-on. When on, the booking flow offers a “Request a coach” option (admin assigns the coach later). Leave off for installs that don’t do coaching.', 'mclb-lane-booking') . '</p>';
        }, $g);
        $this->checkbox_field($g, 'mclb_coach', 'enable_coach_requests', __('Enable coach requests', 'mclb-lane-booking'), __('Show the “Request a coach” option on lane bookings', 'mclb-lane-booking'));

        // General → hours
        add_settings_section('mclb_hours', __('Default opening hours', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('Seeded onto new resources as their default weekly hours. Per-resource overrides come in a later phase.', 'mclb-lane-booking') . '</p>';
        }, $g);
        add_settings_field('mclb_hours_grid', __('Weekly hours', 'mclb-lane-booking'), [$this, 'render_hours'], $g, 'mclb_hours');

        // Appearance → colours
        add_settings_section('mclb_colors', __('Colours', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('These drive the booking grid’s CSS custom properties. Defaults match Cricketers Club; change them to suit your brand.', 'mclb-lane-booking') . '</p>';
        }, $a);
        $this->color_field($a, 'mclb_colors', 'accent_color', __('Accent colour', 'mclb-lane-booking'));
        $this->color_field($a, 'mclb_colors', 'state_available', __('State: Available', 'mclb-lane-booking'));
        $this->color_field($a, 'mclb_colors', 'state_booked', __('State: Booked', 'mclb-lane-booking'));
        $this->color_field($a, 'mclb_colors', 'state_unfinished', __('State: Unfinished', 'mclb-lane-booking'));
        $this->color_field($a, 'mclb_colors', 'state_closed', __('State: Closed', 'mclb-lane-booking'));

        // Appearance → fonts
        add_settings_section('mclb_fonts', __('Fonts', 'mclb-lane-booking'), '__return_false', $a);
        add_settings_field('font_mode', __('Fonts', 'mclb-lane-booking'), [$this, 'render_font_mode'], $a, 'mclb_fonts');

        // License
        add_settings_section('mclb_license', __('License', 'mclb-lane-booking'), function () {
            $st = MCLB_License::status();
            printf('<p><strong>%s</strong> %s</p>', esc_html__('Status:', 'mclb-lane-booking'), esc_html($st['message']));
        }, $l);
        $this->text_field($l, 'mclb_license', 'license_key', __('License key', 'mclb-lane-booking'), __('Provided by moCreates. Validation is stubbed in this build.', 'mclb-lane-booking'));
    }

    // ── Field renderers ──────────────────────────────────────────────────────

    private function name($key) {
        return MCLB_OPTION . '[' . $key . ']';
    }

    private function val($key) {
        return MCLB_Settings::get($key);
    }

    private function text_field($page, $section, $key, $label, $placeholder = '') {
        add_settings_field($key, $label, function () use ($key, $placeholder) {
            printf(
                '<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s">',
                esc_attr($this->name($key)),
                esc_attr($this->val($key)),
                esc_attr($placeholder)
            );
        }, $page, $section);
    }

    private function textarea_field($page, $section, $key, $label, $desc = '') {
        add_settings_field($key, $label, function () use ($key, $desc) {
            printf('<textarea name="%s" rows="4" class="large-text code">%s</textarea>', esc_attr($this->name($key)), esc_textarea($this->val($key)));
            if ($desc) {
                printf('<p class="description">%s</p>', esc_html($desc));
            }
        }, $page, $section);
    }

    private function price_field($page, $section, $key, $label) {
        add_settings_field($key, $label, function () use ($key) {
            printf(
                '<input type="number" step="0.01" min="0" name="%s" value="%s"> <span class="description">%s</span>',
                esc_attr($this->name($key)),
                esc_attr($this->val($key)),
                esc_html__('per hour', 'mclb-lane-booking')
            );
        }, $page, $section);
    }

    private function number_field($page, $section, $key, $label, $suffix = '', $min = 0, $max = null) {
        add_settings_field($key, $label, function () use ($key, $suffix, $min, $max) {
            printf(
                '<input type="number" step="1" min="%s"%s name="%s" value="%s"> <span class="description">%s</span>',
                esc_attr($min),
                $max !== null ? ' max="' . esc_attr($max) . '"' : '',
                esc_attr($this->name($key)),
                esc_attr($this->val($key)),
                esc_html($suffix)
            );
        }, $page, $section);
    }

    /**
     * Checkbox with a hidden value="0" companion posted FIRST, so an unchecked
     * box reliably records 0. Required because the per-tab sanitiser merges from
     * stored values and only overwrites posted keys — without the hidden field an
     * unchecked box would post nothing and get stuck on its previous value.
     */
    private function checkbox_field($page, $section, $key, $label, $desc = '') {
        add_settings_field($key, $label, function () use ($key, $desc) {
            printf('<input type="hidden" name="%s" value="0">', esc_attr($this->name($key)));
            printf(
                '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
                esc_attr($this->name($key)),
                checked((int) $this->val($key), 1, false),
                esc_html($desc)
            );
        }, $page, $section);
    }

    private function increment_field($page, $section, $key, $label) {
        add_settings_field($key, $label, function () use ($key) {
            $val  = (int) $this->val($key);
            $opts = [15 => __('15 minutes', 'mclb-lane-booking'), 30 => __('30 minutes', 'mclb-lane-booking'), 60 => __('1 hour', 'mclb-lane-booking'), 120 => __('2 hours', 'mclb-lane-booking')];
            echo '<select name="' . esc_attr($this->name($key)) . '">';
            foreach ($opts as $v => $lbl) {
                printf('<option value="%d" %s>%s</option>', (int) $v, selected($val, $v, false), esc_html($lbl));
            }
            echo '</select>';
        }, $page, $section);
    }

    private function color_field($page, $section, $key, $label) {
        add_settings_field($key, $label, function () use ($key) {
            printf(
                '<input type="text" class="mclb-color" data-default-color="%1$s" name="%2$s" value="%1$s">',
                esc_attr($this->val($key)),
                esc_attr($this->name($key))
            );
        }, $page, $section);
    }

    public function render_font_mode() {
        $val  = $this->val('font_mode');
        $name = $this->name('font_mode');
        printf(
            '<label><input type="radio" name="%s" value="inherit" %s> %s</label><br>',
            esc_attr($name),
            checked($val, 'inherit', false),
            esc_html__('Inherit the active theme’s fonts (recommended)', 'mclb-lane-booking')
        );
        printf(
            '<label><input type="radio" name="%s" value="override" %s> %s</label>',
            esc_attr($name),
            checked($val, 'override', false),
            esc_html__('Override with the plugin’s own font stack', 'mclb-lane-booking')
        );
    }

    public function render_hours() {
        $hours = (array) $this->val('hours');
        echo '<table class="widefat striped" style="max-width:560px"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>', esc_html__('Day', 'mclb-lane-booking'), esc_html__('Open', 'mclb-lane-booking'), esc_html__('Close', 'mclb-lane-booking'), esc_html__('Closed', 'mclb-lane-booking'));
        echo '</tr></thead><tbody>';
        foreach (MCLB_Settings::weekdays() as $i => $label) {
            $row  = isset($hours[$i]) && is_array($hours[$i]) ? $hours[$i] : ['open' => '', 'close' => '', 'closed' => 0];
            $base = MCLB_OPTION . '[hours][' . $i . ']';
            printf(
                '<tr><td>%s</td><td><input type="time" name="%s[open]" value="%s"></td><td><input type="time" name="%s[close]" value="%s"></td><td style="text-align:center"><input type="checkbox" name="%s[closed]" value="1" %s></td></tr>',
                esc_html($label),
                esc_attr($base),
                esc_attr($row['open'] ?? ''),
                esc_attr($base),
                esc_attr($row['close'] ?? ''),
                esc_attr($base),
                checked(!empty($row['closed']), true, false)
            );
        }
        echo '</tbody></table>';
    }

    // ── Page ─────────────────────────────────────────────────────────────────

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $current = $this->current_tab();

        echo '<div class="wrap"><h1>moBooking</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($this->tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab %s">%s</a>',
                esc_url(admin_url('admin.php?page=' . self::PAGE . '&tab=' . $slug)),
                $slug === $current ? 'nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h2>';

        echo '<form method="post" action="options.php">';
        settings_fields(MCLB_Settings::GROUP);
        do_settings_sections($this->tab_page($current));
        submit_button();
        echo '</form></div>';
    }
}
