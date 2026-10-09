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

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_mclb_daysheet_preview', [$this, 'handle_daysheet_preview']);
        add_action('admin_post_mclb_daysheet_rotate', [$this, 'handle_daysheet_rotate']);
    }

    /**
     * Tab slug => label. Built on demand (never in the constructor) so no
     * translation function runs before `init` — WP 6.7+ warns about that.
     *
     * @return array<string,string>
     */
    private function tabs() {
        return [
            'general'    => __('General', 'mclb-lane-booking'),
            'appearance' => __('Appearance', 'mclb-lane-booking'),
            'events'     => __('Events', 'mclb-lane-booking'),
            'daysheets'  => __('Day sheets', 'mclb-lane-booking'),
            'license'    => __('License', 'mclb-lane-booking'),
        ];
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

        // Register the Settings page as an explicit submenu of its own top-level
        // slug, at position 0. add_menu_page() alone does NOT add a submenu entry
        // for its page — WordPress normally auto-duplicates the parent as the
        // first submenu item, but only when $submenu[parent] isn't already set.
        // The mclb_lane CPT (show_in_menu => self::PAGE) is processed in core's
        // wp-admin/menu.php *before* this admin_menu hook, so it populates
        // $submenu['mclb-settings'] first, suppressing that auto-duplicate and
        // leaving Settings with no menu entry. Adding it explicitly (position 0)
        // restores it and makes the top-level open Settings. Menu order becomes
        // Settings → Lanes → Closures.
        add_submenu_page(
            self::PAGE,
            'moBooking Settings',
            __('Settings', 'mclb-lane-booking'),
            'manage_options',
            self::PAGE,
            [$this, 'render_page'],
            0
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
        return array_key_exists($tab, $this->tabs()) ? $tab : 'general';
    }

    /** One "page" slug per tab so do_settings_sections renders just that tab. */
    private function tab_page($tab) {
        return self::PAGE . '_' . $tab;
    }

    // ── Field/section registration ───────────────────────────────────────────

    public function register_fields() {
        $g  = $this->tab_page('general');
        $a  = $this->tab_page('appearance');
        $e  = $this->tab_page('events');
        $ds = $this->tab_page('daysheets');
        $l  = $this->tab_page('license');

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
        $this->text_field($g, 'mclb_coach', 'staff_label_singular', __('Staff label (singular)', 'mclb-lane-booking'), __('e.g. Coach, Pro, Instructor', 'mclb-lane-booking'));
        $this->text_field($g, 'mclb_coach', 'staff_label_plural', __('Staff label (plural)', 'mclb-lane-booking'), __('e.g. Coaches, Pros, Instructors', 'mclb-lane-booking'));

        // General → staff access (Manage view)
        add_settings_section('mclb_staff_access', __('Staff access', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('The page holding the [mclb_manage] shortcode — the staff calendar. Booking Staff are sent here at login; the page is login-gated, never cached, and noindexed.', 'mclb-lane-booking') . '</p>';
        }, $g);
        add_settings_field('manage_page_id', __('Manage page', 'mclb-lane-booking'), [$this, 'render_manage_page'], $g, 'mclb_staff_access');

        // General → WooCommerce
        add_settings_section('mclb_wc', __('WooCommerce', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('Cart items hang on a hidden virtual “anchor” product. Leave the ID blank to auto-create one on first checkout.', 'mclb-lane-booking') . '</p>';
        }, $g);
        $this->number_field($g, 'mclb_wc', 'wc_product_id', __('Anchor product ID', 'mclb-lane-booking'), __('WooCommerce product ID', 'mclb-lane-booking'), 0);
        $this->number_field($g, 'mclb_wc', 'self_cancel_hours', __('Self-cancel cutoff', 'mclb-lane-booking'), __('hours before start (logged-in customers)', 'mclb-lane-booking'), 0, 720);
        $this->number_field($g, 'mclb_wc', 'refund_window_hours', __('Auto-refund window', 'mclb-lane-booking'), __('hours after placing (cancel within this, and before the cutoff, = real refund; otherwise store credit)', 'mclb-lane-booking'), 0, 720);

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

        // Events → blockout types
        add_settings_section('mclb_event_types', __('Event types', 'mclb-lane-booking'), function () {
            echo '<p>' . esc_html__('Categories for blockouts (e.g. Academy, Maintenance). Each has a colour for the admin calendar and a toggle for whether its name is shown to customers — otherwise a blockout simply reads “Unavailable”.', 'mclb-lane-booking') . '</p>';
        }, $e);
        add_settings_field('mclb_event_types_rows', __('Types', 'mclb-lane-booking'), [$this, 'render_event_types'], $e, 'mclb_event_types');

        // Day sheets
        add_settings_section('mclb_ds', __('Coach day sheets', 'mclb-lane-booking'), [$this, 'ds_intro'], $ds);
        add_settings_field('daysheet_enabled', __('Enable', 'mclb-lane-booking'), function () {
            printf('<input type="hidden" name="%s" value="1">', esc_attr($this->name('daysheet_submitted')));
            printf('<input type="hidden" name="%s" value="0">', esc_attr($this->name('daysheet_enabled')));
            printf('<label><input type="checkbox" name="%s" value="1" %s> %s</label>', esc_attr($this->name('daysheet_enabled')), checked((int) $this->val('daysheet_enabled'), 1, false), esc_html__('Email each coach their sessions on a schedule', 'mclb-lane-booking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_send_time', __('Send time', 'mclb-lane-booking'), function () {
            printf('<input type="time" name="%s" value="%s">', esc_attr($this->name('daysheet_send_time')), esc_attr($this->val('daysheet_send_time')));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_target', __('Which day', 'mclb-lane-booking'), function () {
            $v = $this->val('daysheet_target');
            echo '<select name="' . esc_attr($this->name('daysheet_target')) . '">';
            foreach (['tomorrow' => __('Tomorrow', 'mclb-lane-booking'), 'today' => __('Today', 'mclb-lane-booking')] as $k => $lbl) {
                printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($v, $k, false), esc_html($lbl));
            }
            echo '</select>';
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_names', __('Customer names', 'mclb-lane-booking'), function () {
            $v = $this->val('daysheet_names');
            echo '<select name="' . esc_attr($this->name('daysheet_names')) . '">';
            foreach (['full' => __('Full name', 'mclb-lane-booking'), 'first_initial' => __('First name + initial', 'mclb-lane-booking'), 'none' => __('Hide names', 'mclb-lane-booking')] as $k => $lbl) {
                printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($v, $k, false), esc_html($lbl));
            }
            echo '</select>';
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_cc', __('CC address', 'mclb-lane-booking'), function () {
            printf('<input type="email" class="regular-text" name="%s" value="%s" placeholder="%s"> <span class="description">%s</span>', esc_attr($this->name('daysheet_cc')), esc_attr($this->val('daysheet_cc')), esc_attr__('e.g. front desk', 'mclb-lane-booking'), esc_html__('optional; skipped when a test recipient is set', 'mclb-lane-booking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_test_recipient', __('Test recipient', 'mclb-lane-booking'), function () {
            printf('<input type="email" class="regular-text" name="%s" value="%s"> <span class="description">%s</span>', esc_attr($this->name('daysheet_test_recipient')), esc_attr($this->val('daysheet_test_recipient')), esc_html__('when set, EVERY sheet goes here instead of the coach', 'mclb-lane-booking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_expiry_hours', __('Link expiry', 'mclb-lane-booking'), function () {
            printf('<input type="number" min="1" max="720" name="%s" value="%s"> <span class="description">%s</span>', esc_attr($this->name('daysheet_expiry_hours')), esc_attr($this->val('daysheet_expiry_hours')), esc_html__('hours the “View my day” link stays valid', 'mclb-lane-booking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_tools', __('Tools & log', 'mclb-lane-booking'), [$this, 'render_daysheet_tools'], $ds, 'mclb_ds');

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

    public function ds_intro() {
        if (!empty($_GET['mclb_rotated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonced action.
            printf('<div class="notice notice-success inline" style="margin:8px 0"><p>%s</p></div>', esc_html__('Link secret regenerated — previous “View my day” links no longer work.', 'mclb-lane-booking'));
        }
        echo '<p>' . esc_html__('A daily email to each coach with the sessions assigned to them, plus a signed “View my day” link.', 'mclb-lane-booking') . '</p>';
        $test = sanitize_email((string) MCLB_Settings::get('daysheet_test_recipient'));
        if ($test !== '') {
            printf('<div class="notice notice-warning inline" style="margin:8px 0"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Test mode:', 'mclb-lane-booking'),
                sprintf(esc_html__('every day sheet is being sent to %s (not to coaches).', 'mclb-lane-booking'), '<code>' . esc_html($test) . '</code>'));
        }
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ($env !== 'production' && $test === '') {
            printf('<div class="notice notice-error inline" style="margin:8px 0"><p>%s</p></div>',
                sprintf(esc_html__('This site’s environment is “%s”, so real coach emails are blocked. Set a test recipient to send, or set WP_ENVIRONMENT_TYPE to production on live.', 'mclb-lane-booking'), esc_html($env)));
        }
        if (!MCLB_Daysheets::as_available()) {
            printf('<div class="notice notice-error inline" style="margin:8px 0"><p>%s</p></div>',
                esc_html__('Action Scheduler (via WooCommerce) isn’t available, so scheduled sends won’t run. Manual “Send day sheet” still works.', 'mclb-lane-booking'));
        }
    }

    public function render_daysheet_tools() {
        // NOTE: Preview + Regenerate buttons are rendered OUTSIDE the settings form
        // (see daysheet_actions() after the form) — they can't be nested here or
        // they'd submit options.php. This field shows the read-only log only.

        // Send log (most recent first).
        $log = array_reverse(MCLB_Daysheets::get_log());
        echo '<h3 style="margin-top:20px">' . esc_html__('Recent sends', 'mclb-lane-booking') . '</h3>';
        if (empty($log)) {
            echo '<p>' . esc_html__('No sends logged yet.', 'mclb-lane-booking') . '</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
            esc_html__('When', 'mclb-lane-booking'), esc_html__('Coach', 'mclb-lane-booking'), esc_html__('For date', 'mclb-lane-booking'),
            esc_html__('Recipient', 'mclb-lane-booking'), esc_html__('Trigger', 'mclb-lane-booking'), esc_html__('Result', 'mclb-lane-booking'));
        echo '</tr></thead><tbody>';
        foreach (array_slice($log, 0, 60) as $e) {
            $colour = $e['result'] === 'sent' ? '#1a7f37' : ($e['result'] === 'failed' ? '#b32d2e' : '#8a6d00');
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="color:%s">%s</td></tr>',
                esc_html($e['sent_at']), esc_html($e['name']), esc_html($e['date']),
                esc_html($e['recipient'] ?: '—'), esc_html($e['trigger']), esc_attr($colour), esc_html($e['result']));
        }
        echo '</tbody></table>';
    }

    public function handle_daysheet_preview() {
        // GET request (link in a new tab), so read from $_REQUEST.
        if (!current_user_can('manage_options') || !isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'mclb_daysheet_preview')) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }
        $date  = isset($_REQUEST['date']) ? sanitize_text_field(wp_unslash($_REQUEST['date'])) : MCLB_Daysheets::target_date();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = MCLB_Daysheets::target_date();
        }
        $built = MCLB_Daysheets::build_all($date);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Day sheet preview</title><style>body{background:#eef0f2;font-family:sans-serif;margin:0;padding:24px}.pw{max-width:640px;margin:0 auto 28px}.pw .hd{font:13px monospace;color:#444;background:#fff;border:1px solid #ccd;padding:8px 12px;border-radius:6px 6px 0 0}.pw .bd{background:#fff;border:1px solid #ccd;border-top:none;border-radius:0 0 6px 6px;padding:16px}</style></head><body>';
        printf('<p style="max-width:640px;margin:0 auto 16px;color:#333">%s <strong>%s</strong> — %s</p>',
            esc_html__('Preview for', 'mclb-lane-booking'), esc_html($date), esc_html__('nothing is sent.', 'mclb-lane-booking'));
        if (empty($built)) {
            echo '<p style="max-width:640px;margin:0 auto">' . esc_html__('No coaches have sessions that day.', 'mclb-lane-booking') . '</p>';
        }
        foreach ($built as $b) {
            $meta = $b['name'] . ' → ' . ($b['recipient'] ?: '(no recipient)') . ($b['skip'] ? '  [WOULD SKIP: ' . $b['skip'] . ']' : '') . '  |  ' . $b['subject'];
            echo '<div class="pw"><div class="hd">' . esc_html($meta) . '</div><div class="bd">' . $b['html'] . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        }
        echo '</body></html>';
        exit;
    }

    public function handle_daysheet_rotate() {
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'mclb_daysheet_rotate')) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }
        MCLB_Daysheet_Token::rotate();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => 'daysheets', 'mclb_rotated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render_manage_page() {
        wp_dropdown_pages([
            'name'              => esc_attr($this->name('manage_page_id')),
            'selected'          => (int) $this->val('manage_page_id'),
            'show_option_none'  => __('— Select a page —', 'mclb-lane-booking'),
            'option_none_value' => '0',
        ]);
        $id = (int) $this->val('manage_page_id');
        if ($id) {
            printf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($id)), esc_html__('View', 'mclb-lane-booking'));
        }
        printf('<p class="description">%s</p>', esc_html__('Create a page containing just the [mclb_manage] shortcode, then choose it here.', 'mclb-lane-booking'));
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

    /**
     * Repeatable event-type editor (Events tab). Each row carries a hidden stable
     * slug so renaming a label never orphans the closures pointing at it. A
     * hidden "submitted" marker lets the sanitiser tell "all rows removed" apart
     * from "another tab was saved". New rows are cloned client-side from the
     * <script> template below (index __i__).
     */
    public function render_event_types() {
        $types = (array) $this->val('event_types');

        printf('<input type="hidden" name="%s" value="1">', esc_attr(MCLB_OPTION . '[event_types_submitted]'));

        echo '<table class="widefat striped mclb-event-types" style="max-width:640px"><thead><tr>';
        printf(
            '<th>%s</th><th>%s</th><th style="text-align:center">%s</th><th></th>',
            esc_html__('Label', 'mclb-lane-booking'),
            esc_html__('Colour', 'mclb-lane-booking'),
            esc_html__('Show name publicly', 'mclb-lane-booking')
        );
        echo '</tr></thead><tbody class="mclb-et-rows">';
        $i = 0;
        foreach ($types as $t) {
            $this->event_type_row($i, (array) $t);
            $i++;
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button mclb-et-add">' . esc_html__('Add type', 'mclb-lane-booking') . '</button></p>';
        echo '<p class="description">' . esc_html__('Removing a type leaves existing blockouts of that type reading “Unavailable”.', 'mclb-lane-booking') . '</p>';

        echo '<script type="text/html" id="tmpl-mclb-et-row">';
        $this->event_type_row('__i__', ['slug' => '', 'label' => '', 'color' => MCLB_Event_Types::FALLBACK_COLOR, 'public' => 0]);
        echo '</script>';
    }

    private function event_type_row($i, array $t) {
        $base   = MCLB_OPTION . '[event_types][' . $i . ']';
        $slug   = isset($t['slug']) ? $t['slug'] : '';
        $label  = isset($t['label']) ? $t['label'] : '';
        $color  = (isset($t['color']) && $t['color']) ? $t['color'] : MCLB_Event_Types::FALLBACK_COLOR;
        $public = !empty($t['public']);

        echo '<tr class="mclb-et-row">';
        printf(
            '<td><input type="hidden" name="%1$s[slug]" value="%2$s"><input type="text" class="regular-text" name="%1$s[label]" value="%3$s"></td>',
            esc_attr($base),
            esc_attr($slug),
            esc_attr($label)
        );
        printf(
            '<td><input type="text" class="mclb-color" data-default-color="%2$s" name="%1$s[color]" value="%3$s"></td>',
            esc_attr($base),
            esc_attr(MCLB_Event_Types::FALLBACK_COLOR),
            esc_attr($color)
        );
        printf(
            '<td style="text-align:center"><input type="hidden" name="%1$s[public]" value="0"><input type="checkbox" name="%1$s[public]" value="1" %2$s></td>',
            esc_attr($base),
            checked($public, true, false)
        );
        printf('<td><button type="button" class="button-link mclb-et-remove" style="color:#b32d2e">%s</button></td>', esc_html__('Remove', 'mclb-lane-booking'));
        echo '</tr>';
    }

    // ── Page ─────────────────────────────────────────────────────────────────

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $current = $this->current_tab();

        echo '<div class="wrap"><h1>moBooking</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($this->tabs() as $slug => $label) {
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
        echo '</form>';

        // Day-sheet action buttons live OUTSIDE the settings form (own form actions).
        if ($current === 'daysheets') {
            $this->daysheet_actions();
        }
        echo '</div>';
    }

    private function daysheet_actions() {
        echo '<h2 style="margin-top:8px">' . esc_html__('Preview &amp; links', 'mclb-lane-booking') . '</h2>';
        // Preview — a GET form (opens the built email(s) in a new tab, never sends).
        printf('<form method="get" action="%s" target="_blank" style="display:inline-block;margin-right:14px">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="mclb_daysheet_preview">';
        wp_nonce_field('mclb_daysheet_preview');
        printf('<input type="date" name="date" value="%s"> ', esc_attr(MCLB_Daysheets::target_date()));
        printf('<button class="button">%s</button>', esc_html__('Preview (no send)', 'mclb-lane-booking'));
        echo '</form>';
        // Regenerate link secret — its own POST form with a confirm.
        printf('<form method="post" action="%s" style="display:inline-block" onsubmit="return confirm(%s)">', esc_url(admin_url('admin-post.php')), esc_attr('"' . esc_js(__('Regenerate the link secret? All existing “View my day” links will stop working.', 'mclb-lane-booking')) . '"'));
        echo '<input type="hidden" name="action" value="mclb_daysheet_rotate">';
        wp_nonce_field('mclb_daysheet_rotate');
        printf('<button class="button">%s</button>', esc_html__('Regenerate link secret', 'mclb-lane-booking'));
        echo '</form>';
    }
}
