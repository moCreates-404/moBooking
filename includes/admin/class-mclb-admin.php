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
            'general'    => __('General', 'mobooking'),
            'appearance' => __('Appearance', 'mobooking'),
            'events'     => __('Events', 'mobooking'),
            'daysheets'  => __('Day sheets', 'mobooking'),
            'license'    => __('License', 'mobooking'),
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
            __('Settings', 'mobooking'),
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
        add_settings_section('mclb_labels', __('Wording', 'mobooking'), function () {
            echo '<p>' . esc_html__('What your business calls things. These words are used everywhere customers and staff see them — the booking grid, emails, the calendar and these settings.', 'mobooking') . '</p>';
        }, $g);
        $this->text_field($g, 'mclb_labels', 'resource_label_singular', __('Bookable unit (singular)', 'mobooking'), __('e.g. Court, Lane, Bay, Room, Studio', 'mobooking'));
        $this->text_field($g, 'mclb_labels', 'resource_label_plural', __('Bookable unit (plural)', 'mobooking'), __('e.g. Courts, Lanes, Bays, Rooms, Studios', 'mobooking'));
        $this->text_field($g, 'mclb_labels', 'pass_label_singular', __('Prepaid credit (singular)', 'mobooking'), __('e.g. Pass, Pack, Bundle', 'mobooking'));
        $this->text_field($g, 'mclb_labels', 'pass_label_plural', __('Prepaid credit (plural)', 'mobooking'), __('e.g. Passes, Packs, Bundles', 'mobooking'));

        // General → booking rules
        add_settings_section('mclb_booking', __('Booking rules', 'mobooking'), '__return_false', $g);
        $this->textarea_field($g, 'mclb_booking', 'resource_types', __('Resource types', 'mobooking'), __('One per line (e.g. Net, Bowling Machine). Admin-defined — not a fixed list.', 'mobooking'));
        $this->price_field($g, 'mclb_booking', 'price_per_hour', __('Price per hour', 'mobooking'));
        $this->increment_field($g, 'mclb_booking', 'booking_increment', __('Booking increment', 'mobooking'));
        $this->number_field($g, 'mclb_booking', 'hold_minutes', __('Hold window', 'mobooking'), __('minutes', 'mobooking'), 1, 240);
        $this->number_field($g, 'mclb_booking', 'booking_window_days', __('Customer booking window', 'mobooking'), __('days ahead customers can book (0 = no limit). Staff are never limited.', 'mobooking'), 0, 3650);
        $this->text_field($g, 'mclb_booking', 'booking_window_note', __('Later-dates message', 'mobooking'), __('Shown after “Online bookings open N days in advance.” — e.g. Call 08 9393 1000 for later dates.', 'mobooking'));

        // General → coach requests
        add_settings_section('mclb_coach', MCLB_Terms::fill(__('{Staff} requests', 'mobooking')), function () {
            echo '<p>' . esc_html(MCLB_Terms::fill(__('Optional add-on. When on, customers can tick “Request a {staff}” when booking; staff assign the actual {staff} afterwards. Leave off if you don’t offer {staff_plural}.', 'mobooking'))) . '</p>';
            MCLB_Admin::pro_note();
        }, $g);
        $this->checkbox_field($g, 'mclb_coach', 'enable_coach_requests', MCLB_Terms::fill(__('Enable {staff} requests', 'mobooking')), MCLB_Terms::fill(__('Show the “Request a {staff}” option on {resource} bookings', 'mobooking')));
        $this->text_field($g, 'mclb_coach', 'staff_label_singular', __('Staff label (singular)', 'mobooking'), __('e.g. Coach, Pro, Instructor', 'mobooking'));
        $this->text_field($g, 'mclb_coach', 'staff_label_plural', __('Staff label (plural)', 'mobooking'), __('e.g. Coaches, Pros, Instructors', 'mobooking'));

        // General → staff access (Manage view)
        add_settings_section('mclb_staff_access', __('Staff access', 'mobooking'), function () {
            echo '<p>' . esc_html__('The page holding the [mclb_manage] shortcode — the staff calendar. Booking Staff are sent here at login; the page is login-gated, never cached, and noindexed.', 'mobooking') . '</p>';
        }, $g);
        add_settings_field('manage_page_id', __('Manage page', 'mobooking'), [$this, 'render_manage_page'], $g, 'mclb_staff_access');

        // General → WooCommerce
        add_settings_section('mclb_wc', __('WooCommerce', 'mobooking'), function () {
            echo '<p>' . esc_html__('Cart items hang on a hidden virtual “anchor” product. Leave the ID blank to auto-create one on first checkout.', 'mobooking') . '</p>';
        }, $g);
        $this->number_field($g, 'mclb_wc', 'wc_product_id', __('Anchor product ID', 'mobooking'), __('WooCommerce product ID', 'mobooking'), 0);
        $this->number_field($g, 'mclb_wc', 'self_cancel_hours', __('Self-cancel cutoff', 'mobooking'), __('hours before start (logged-in customers)', 'mobooking'), 0, 720);
        $this->number_field($g, 'mclb_wc', 'refund_window_hours', __('Auto-refund window', 'mobooking'), __('hours after placing (cancel within this, and before the cutoff, = real refund; otherwise store credit)', 'mobooking'), 0, 720);
        $this->number_field($g, 'mclb_wc', 'credit_expiry_days', __('Store-credit expiry', 'mobooking'), __('days a cancellation credit coupon lasts (0 = never); set when the coupon is issued', 'mobooking'), 0, 3650);
        $this->checkbox_field($g, 'mclb_wc', 'guest_self_cancel', __('Guest self-cancel', 'mobooking'), __('Let customers cancel from a secure link in their confirmation email (no login)', 'mobooking'));
        $this->text_field($g, 'mclb_wc', 'club_phone', __('Club phone', 'mobooking'), __('shown when a booking is too close to its start time to cancel online (the theme can supply this via the mclb_club_phone filter)', 'mobooking'));
        $this->number_field($g, 'mclb_wc', 'pass_expiry_months', MCLB_Terms::fill(__('Prepaid {pass} expiry', 'mobooking')), MCLB_Terms::fill(__('months a new {pass} lasts (0 = never); per-{pass} override at issue. A {pass} is valid if unexpired when the booking is made.', 'mobooking')), 0, 120);
        add_settings_field('terms_page_id', __('Terms & Conditions page', 'mobooking'), [$this, 'render_terms_page'], $g, 'mclb_wc');

        // General → Emails
        add_settings_section('mclb_emails', __('Emails', 'mobooking'), function () {
            echo '<p>' . esc_html(MCLB_Terms::fill(__('WooCommerce admin order emails (New / Cancelled / Failed) are routed by order type: {resource} bookings → Bookings email, shop orders → Reception email, mixed → both. Leave an address blank to keep WooCommerce’s own recipient for that type. Customer emails are never changed here.', 'mobooking'))) . '</p>';
            // Warn when the Bookings address is blank: staff alerts fall back to
            // WooCommerce's New Order recipient / the admin email (v1.4.3).
            if (sanitize_email((string) MCLB_Settings::get('email_bookings')) === '') {
                $fallback = get_option('woocommerce_new_order_settings');
                $fallback = (is_array($fallback) && !empty($fallback['recipient'])) ? $fallback['recipient'] : get_option('admin_email');
                echo '<div class="notice notice-warning inline" style="margin:6px 0;"><p>'
                    . esc_html(sprintf(__('No Bookings email is set — staff alerts (incl. cancellations) currently go to %s. Set one below so booking alerts reach the right inbox.', 'mobooking'), $fallback))
                    . '</p></div>';
            }
        }, $g);
        $this->text_field($g, 'mclb_emails', 'email_bookings', __('Bookings email', 'mobooking'), MCLB_Terms::fill(__('e.g. bookings@yourclub.com — {resource}-booking admin emails + staff alerts', 'mobooking')));
        $this->text_field($g, 'mclb_emails', 'email_reception', __('Reception / shop email', 'mobooking'), __('e.g. reception@yourclub.com — shop-order admin emails', 'mobooking'));
        $this->checkbox_field($g, 'mclb_emails', 'email_manual_confirmation', __('Manual booking confirmation', 'mobooking'), __('Email the customer a confirmation when staff create a manual booking (if an address is on file)', 'mobooking'));
        $this->checkbox_field($g, 'mclb_emails', 'email_customer_on_cancel', __('Customer cancellation email', 'mobooking'), __('Email the customer whenever a booking is cancelled, with the refund/credit outcome (if an address is on file)', 'mobooking'));
        $this->checkbox_field($g, 'mclb_emails', 'alert_cancel', __('Alert: cancellation', 'mobooking'), __('Email the Bookings address whenever a booking is cancelled (staff, customer or order)', 'mobooking'));
        $this->checkbox_field($g, 'mclb_emails', 'alert_coach_request', MCLB_Terms::fill(__('Alert: online {staff} request', 'mobooking')), MCLB_Terms::fill(__('Email the Bookings address when an online booking asks for a {staff}', 'mobooking')));
        $this->checkbox_field($g, 'mclb_emails', 'alert_manual_created', __('Alert: manual booking created', 'mobooking'), __('Email the Bookings address when staff create a manual booking', 'mobooking'));
        $this->checkbox_field($g, 'mclb_emails', 'alert_move', __('Alert: booking moved', 'mobooking'), __('Email the Bookings address when staff move a booking', 'mobooking'));
        $this->checkbox_field($g, 'mclb_emails', 'pass_holder_email', MCLB_Terms::fill(__('Prepaid {pass} balance emails', 'mobooking')), MCLB_Terms::fill(__('Email the {pass} holder when hours are used or returned (if an email is on the {pass})', 'mobooking')));
        $this->text_field($g, 'mclb_emails', 'email_test_recipient', __('Email test recipient', 'mobooking'), __('Dev safety: when set, EVERY plugin email (incl. day sheets) is rerouted here. On a non-production site nothing is sent unless this is set.', 'mobooking'));

        // General → hours
        add_settings_section('mclb_hours', __('Default opening hours', 'mobooking'), function () {
            echo '<p>' . esc_html__('Seeded onto new resources as their default weekly hours. Per-resource overrides come in a later phase.', 'mobooking') . '</p>';
        }, $g);
        add_settings_field('mclb_hours_grid', __('Weekly hours', 'mobooking'), [$this, 'render_hours'], $g, 'mclb_hours');

        // Appearance → colours
        add_settings_section('mclb_colors', __('Colours', 'mobooking'), function () {
            echo '<p>' . esc_html__('These drive the booking grid’s CSS custom properties. Defaults match Cricketers Club; change them to suit your brand.', 'mobooking') . '</p>';
        }, $a);
        $this->color_field($a, 'mclb_colors', 'accent_color', __('Accent colour', 'mobooking'));
        $this->color_field($a, 'mclb_colors', 'state_available', __('State: Available', 'mobooking'));
        $this->color_field($a, 'mclb_colors', 'state_booked', __('State: Booked', 'mobooking'));
        $this->color_field($a, 'mclb_colors', 'state_unfinished', __('State: Unfinished', 'mobooking'));
        $this->color_field($a, 'mclb_colors', 'state_closed', __('State: Closed', 'mobooking'));

        // Appearance → fonts
        add_settings_section('mclb_fonts', __('Fonts', 'mobooking'), '__return_false', $a);
        add_settings_field('font_mode', __('Fonts', 'mobooking'), [$this, 'render_font_mode'], $a, 'mclb_fonts');

        // Events → blockout types
        add_settings_section('mclb_event_types', __('Event types', 'mobooking'), function () {
            echo '<p>' . esc_html__('Categories for blockouts (e.g. Academy, Maintenance). Each has a colour for the admin calendar and a toggle for whether its name is shown to customers — otherwise a blockout simply reads “Unavailable”.', 'mobooking') . '</p>';
        }, $e);
        add_settings_field('mclb_event_types_rows', __('Types', 'mobooking'), [$this, 'render_event_types'], $e, 'mclb_event_types');

        // Day sheets
        add_settings_section('mclb_ds', MCLB_Terms::fill(__('{Staff} day sheets', 'mobooking')), [$this, 'ds_intro'], $ds);
        add_settings_field('daysheet_enabled', __('Automatic send', 'mobooking'), function () {
            printf('<input type="hidden" name="%s" value="1">', esc_attr($this->name('daysheet_submitted')));
            printf('<input type="hidden" name="%s" value="0">', esc_attr($this->name('daysheet_enabled')));
            printf('<label><input type="checkbox" name="%s" value="1" %s> %s</label>', esc_attr($this->name('daysheet_enabled')), checked((int) $this->val('daysheet_enabled'), 1, false), esc_html(MCLB_Terms::fill(__('Email each {staff} their sessions automatically on a schedule', 'mobooking'))));
            printf('<p class="description">%s</p>', esc_html__('Turn this off to send only by hand — the “Send” / “Send to all” buttons on the Roster keep working either way.', 'mobooking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_send_time', __('Send time', 'mobooking'), function () {
            printf('<input type="time" name="%s" value="%s">', esc_attr($this->name('daysheet_send_time')), esc_attr($this->val('daysheet_send_time')));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_target', __('Which day', 'mobooking'), function () {
            $v = $this->val('daysheet_target');
            echo '<select name="' . esc_attr($this->name('daysheet_target')) . '">';
            foreach (['tomorrow' => __('Tomorrow', 'mobooking'), 'today' => __('Today', 'mobooking')] as $k => $lbl) {
                printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($v, $k, false), esc_html($lbl));
            }
            echo '</select>';
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_names', __('Customer names', 'mobooking'), function () {
            $v = $this->val('daysheet_names');
            echo '<select name="' . esc_attr($this->name('daysheet_names')) . '">';
            foreach (['full' => __('Full name', 'mobooking'), 'first_initial' => __('First name + initial', 'mobooking'), 'none' => __('Hide names', 'mobooking')] as $k => $lbl) {
                printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($v, $k, false), esc_html($lbl));
            }
            echo '</select>';
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_cc', __('CC address', 'mobooking'), function () {
            printf('<input type="email" class="regular-text" name="%s" value="%s" placeholder="%s"> <span class="description">%s</span>', esc_attr($this->name('daysheet_cc')), esc_attr($this->val('daysheet_cc')), esc_attr__('e.g. front desk', 'mobooking'), esc_html__('optional; skipped when a test recipient is set', 'mobooking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_test_recipient', __('Test recipient', 'mobooking'), function () {
            printf('<span class="description">%s</span>', esc_html__('Moved to General → Emails → “Email test recipient”, which now reroutes every plugin email (day sheets included).', 'mobooking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_expiry_hours', __('Link expiry', 'mobooking'), function () {
            printf('<input type="number" min="1" max="720" name="%s" value="%s"> <span class="description">%s</span>', esc_attr($this->name('daysheet_expiry_hours')), esc_attr($this->val('daysheet_expiry_hours')), esc_html__('hours the “View my day” link stays valid', 'mobooking'));
        }, $ds, 'mclb_ds');
        add_settings_field('daysheet_tools', __('Tools & log', 'mobooking'), [$this, 'render_daysheet_tools'], $ds, 'mclb_ds');

        // License (Freemius handles activation; this tab shows status + links).
        add_settings_section('mclb_license', __('License', 'mobooking'), function () {
            $st     = MCLB_License::status();
            $labels = [
                'active'       => __('Active', 'mobooking'),
                'trial'        => __('Trial', 'mobooking'),
                'grace'        => __('Grace period', 'mobooking'),
                'expired'      => __('Expired', 'mobooking'),
                'inactive'     => __('Not activated', 'mobooking'),
                'unconfigured' => __('Not configured', 'mobooking'),
            ];
            printf(
                '<p><strong>%s</strong> %s — %s</p>',
                esc_html__('Status:', 'mobooking'),
                esc_html($labels[$st['status']] ?? $st['status']),
                esc_html($st['message'])
            );
            printf('<p><strong>%s</strong> %s</p>', esc_html__('Plan:', 'mobooking'), esc_html(MCLB_Plan::name()));
            if (!MCLB_Plan::is_pro()) {
                printf(
                    '<p>%s <a href="%s">%s</a></p>',
                    esc_html(MCLB_Terms::fill(__('Pro adds {staff} requests and roster, day sheets, prepaid {passes}, recurring bookings and reports.', 'mobooking'))),
                    esc_url(MCLB_Plan::upgrade_url()),
                    esc_html__('Upgrade to Pro', 'mobooking')
                );
            }
            if (defined('MCLB_LICENSE_UNLOCK') && MCLB_LICENSE_UNLOCK) {
                printf('<p><em>%s</em></p>', esc_html__('MCLB_LICENSE_UNLOCK is defined in wp-config.php, so bookings are allowed regardless of licence status.', 'mobooking'));
            }
            if ($st['status'] !== 'unconfigured') {
                printf(
                    '<p><a class="button button-primary" href="%s">%s</a></p>',
                    esc_url(MCLB_License::account_url()),
                    esc_html__('Manage licence', 'mobooking')
                );
            }
        }, $l);

        // Data retention (lives on the License tab — rarely touched).
        add_settings_section('mclb_data', __('Data', 'mobooking'), function () {
            echo '<p>' . esc_html(MCLB_Terms::fill(__('By default, deleting the plugin keeps all bookings, {passes} and settings so a reinstall picks up where it left off.', 'mobooking'))) . '</p>';
        }, $l);
        $this->checkbox_field($l, 'mclb_data', 'delete_data_on_uninstall', __('Delete data on uninstall', 'mobooking'), MCLB_Terms::fill(__('Permanently remove ALL moBooking bookings, {passes}, closures and settings when the plugin is deleted', 'mobooking')));
    }

    /** "Pro feature" line for settings sections that do nothing on Essentials. */
    public static function pro_note() {
        if (MCLB_Plan::is_pro()) {
            return;
        }
        printf(
            '<p><span class="dashicons dashicons-lock" style="color:#996800"></span> <strong>%s</strong> <a href="%s">%s</a></p>',
            esc_html__('Pro feature — these settings have no effect on your current plan.', 'mobooking'),
            esc_url(MCLB_Plan::upgrade_url()),
            esc_html__('Upgrade', 'mobooking')
        );
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
                esc_html__('per hour', 'mobooking')
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
            $opts = [15 => __('15 minutes', 'mobooking'), 30 => __('30 minutes', 'mobooking'), 60 => __('1 hour', 'mobooking'), 120 => __('2 hours', 'mobooking')];
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
            esc_html__('Inherit the active theme’s fonts (recommended)', 'mobooking')
        );
        printf(
            '<label><input type="radio" name="%s" value="override" %s> %s</label>',
            esc_attr($name),
            checked($val, 'override', false),
            esc_html__('Override with the plugin’s own font stack', 'mobooking')
        );
    }

    public function ds_intro() {
        self::pro_note();
        if (!empty($_GET['mclb_rotated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonced action.
            printf('<div class="notice notice-success inline" style="margin:8px 0"><p>%s</p></div>', esc_html__('Link secret regenerated — previous “View my day” links no longer work.', 'mobooking'));
        }
        echo '<p>' . esc_html(MCLB_Terms::fill(__('A daily email to each {staff} with the sessions assigned to them, plus a signed “View my day” link.', 'mobooking'))) . '</p>';
        $test = sanitize_email((string) MCLB_Settings::get('daysheet_test_recipient'));
        if ($test !== '') {
            printf('<div class="notice notice-warning inline" style="margin:8px 0"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Test mode:', 'mobooking'),
                sprintf(esc_html(MCLB_Terms::fill(__('every day sheet is being sent to %s (not to {staff_plural}).', 'mobooking'))), '<code>' . esc_html($test) . '</code>'));
        }
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ($env !== 'production' && $test === '') {
            printf('<div class="notice notice-error inline" style="margin:8px 0"><p>%s</p></div>',
                sprintf(esc_html(MCLB_Terms::fill(__('This site’s environment is “%s”, so real {staff} emails are blocked. Set a test recipient to send, or set WP_ENVIRONMENT_TYPE to production on live.', 'mobooking'))), esc_html($env)));
        }
        if (!MCLB_Daysheets::as_available()) {
            printf('<div class="notice notice-error inline" style="margin:8px 0"><p>%s</p></div>',
                esc_html__('Action Scheduler (via WooCommerce) isn’t available, so scheduled sends won’t run. Manual “Send day sheet” still works.', 'mobooking'));
        }
    }

    public function render_daysheet_tools() {
        // NOTE: Preview + Regenerate buttons are rendered OUTSIDE the settings form
        // (see daysheet_actions() after the form) — they can't be nested here or
        // they'd submit options.php. This field shows the read-only log only.

        // Send log (most recent first).
        $log = array_reverse(MCLB_Daysheets::get_log());
        echo '<h3 style="margin-top:20px">' . esc_html__('Recent sends', 'mobooking') . '</h3>';
        if (empty($log)) {
            echo '<p>' . esc_html__('No sends logged yet.', 'mobooking') . '</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
            esc_html__('When', 'mobooking'), esc_html(MCLB_Terms::fill(__('{Staff}', 'mobooking'))), esc_html__('For date', 'mobooking'),
            esc_html__('Recipient', 'mobooking'), esc_html__('Trigger', 'mobooking'), esc_html__('Result', 'mobooking'));
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
            wp_die(esc_html__('Permission denied.', 'mobooking'));
        }
        $date  = isset($_REQUEST['date']) ? sanitize_text_field(wp_unslash($_REQUEST['date'])) : MCLB_Daysheets::target_date();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = MCLB_Daysheets::target_date();
        }
        $built = MCLB_Daysheets::build_all($date);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Day sheet preview</title><style>body{background:#eef0f2;font-family:sans-serif;margin:0;padding:24px}.pw{max-width:640px;margin:0 auto 28px}.pw .hd{font:13px monospace;color:#444;background:#fff;border:1px solid #ccd;padding:8px 12px;border-radius:6px 6px 0 0}.pw .bd{background:#fff;border:1px solid #ccd;border-top:none;border-radius:0 0 6px 6px;padding:16px}</style></head><body>';
        printf('<p style="max-width:640px;margin:0 auto 16px;color:#333">%s <strong>%s</strong> — %s</p>',
            esc_html__('Preview for', 'mobooking'), esc_html($date), esc_html__('nothing is sent.', 'mobooking'));
        if (empty($built)) {
            echo '<p style="max-width:640px;margin:0 auto">' . esc_html(MCLB_Terms::fill(__('No {staff_plural} have sessions that day.', 'mobooking'))) . '</p>';
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
            wp_die(esc_html__('Permission denied.', 'mobooking'));
        }
        MCLB_Daysheet_Token::rotate();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => 'daysheets', 'mclb_rotated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render_manage_page() {
        wp_dropdown_pages([
            'name'              => esc_attr($this->name('manage_page_id')),
            'selected'          => (int) $this->val('manage_page_id'),
            'show_option_none'  => __('— Select a page —', 'mobooking'),
            'option_none_value' => '0',
        ]);
        $id = (int) $this->val('manage_page_id');
        if ($id) {
            printf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($id)), esc_html__('View', 'mobooking'));
        }
        printf('<p class="description">%s</p>', esc_html__('Create a page containing just the [mclb_manage] shortcode, then choose it here.', 'mobooking'));
    }

    public function render_terms_page() {
        wp_dropdown_pages([
            'name'              => esc_attr($this->name('terms_page_id')),
            'selected'          => (int) $this->val('terms_page_id'),
            'show_option_none'  => __('— Use the WooCommerce terms page —', 'mobooking'),
            'option_none_value' => '0',
        ]);
        $url = MCLB_Settings::terms_page_url();
        if ($url) {
            printf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($url), esc_html__('View', 'mobooking'));
        }
        printf(
            '<p class="description">%s</p>',
            esc_html__('A “By booking you agree to our Terms & Conditions” link shows beside the customer grid’s Add to cart. Defaults to the WooCommerce terms page; the line is hidden if no page is set.', 'mobooking')
        );
    }

    public function render_hours() {
        $hours = (array) $this->val('hours');
        echo '<table class="widefat striped" style="max-width:560px"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>', esc_html__('Day', 'mobooking'), esc_html__('Open', 'mobooking'), esc_html__('Close', 'mobooking'), esc_html__('Closed', 'mobooking'));
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
            esc_html__('Label', 'mobooking'),
            esc_html__('Colour', 'mobooking'),
            esc_html__('Show name publicly', 'mobooking')
        );
        echo '</tr></thead><tbody class="mclb-et-rows">';
        $i = 0;
        foreach ($types as $t) {
            $this->event_type_row($i, (array) $t);
            $i++;
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button mclb-et-add">' . esc_html__('Add type', 'mobooking') . '</button></p>';
        echo '<p class="description">' . esc_html__('Removing a type leaves existing blockouts of that type reading “Unavailable”.', 'mobooking') . '</p>';

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
        printf('<td><button type="button" class="button-link mclb-et-remove" style="color:#b32d2e">%s</button></td>', esc_html__('Remove', 'mobooking'));
        echo '</tr>';
    }

    // ── Page ─────────────────────────────────────────────────────────────────

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $current = $this->current_tab();

        printf(
            '<div class="wrap"><h1 class="wp-heading-inline">moBooking</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
            esc_url(MCLB_Setup::url()),
            esc_html__('Run setup wizard', 'mobooking')
        );
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
        echo '<h2 style="margin-top:8px">' . esc_html__('Preview &amp; links', 'mobooking') . '</h2>';
        // Preview — a GET form (opens the built email(s) in a new tab, never sends).
        printf('<form method="get" action="%s" target="_blank" style="display:inline-block;margin-right:14px">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="mclb_daysheet_preview">';
        wp_nonce_field('mclb_daysheet_preview');
        printf('<input type="date" name="date" value="%s"> ', esc_attr(MCLB_Daysheets::target_date()));
        printf('<button class="button">%s</button>', esc_html__('Preview (no send)', 'mobooking'));
        echo '</form>';
        // Regenerate link secret — its own POST form with a confirm.
        printf('<form method="post" action="%s" style="display:inline-block" onsubmit="return confirm(%s)">', esc_url(admin_url('admin-post.php')), esc_attr('"' . esc_js(__('Regenerate the link secret? All existing “View my day” links will stop working.', 'mobooking')) . '"'));
        echo '<input type="hidden" name="action" value="mclb_daysheet_rotate">';
        wp_nonce_field('mclb_daysheet_rotate');
        printf('<button class="button">%s</button>', esc_html__('Regenerate link secret', 'mobooking'));
        echo '</form>';
    }
}
