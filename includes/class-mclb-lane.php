<?php
/**
 * The bookable resource — a CPT (`mclb_lane`). Internal name stays "lane"
 * regardless of the customer-facing label set in settings (Lane / Bay / Hoist…).
 *
 * A CPT (not a custom table) because resources are low-volume, admin-curated,
 * and benefit from WordPress's built-in list table / add-edit-trash UI — the
 * same shape as the theme's `coach` CPT. Unlike that one, fields are a
 * hand-rolled meta box, NOT ACF: moBooking is a standalone product and cannot
 * assume ACF is installed on a licensee's site.
 *
 * Retire control is native post_status: publish = live/bookable,
 * draft = retired-but-kept, trash = removed. No separate "active" flag.
 * `menu_order` (Page Attributes) drives grid display order, like `coach`.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Lane {

    const CPT      = 'mclb_lane';
    const NONCE    = 'mclb_lane_meta';
    const META_TYPE          = '_mclb_type';
    const META_PRICE         = '_mclb_price_override';
    const META_HOURS_ENABLED = '_mclb_hours_override';
    const META_HOURS         = '_mclb_hours';

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
    }

    /**
     * Register the CPT. Labels use the admin's configured resource wording so the
     * whole admin UI reads "Nets"/"Bays"/"Hoists" rather than a hardcoded word.
     * Nested under the moBooking menu so lanes, closures and settings sit together.
     */
    public static function register() {
        $singular = MCLB_Settings::get('resource_label_singular') ?: 'Lane';
        $plural   = MCLB_Settings::get('resource_label_plural') ?: 'Lanes';

        register_post_type(self::CPT, [
            'labels' => [
                'name'               => $plural,
                'singular_name'      => $singular,
                /* translators: %s: resource label, e.g. "Lane". */
                'add_new_item'       => sprintf(__('Add New %s', 'mclb-lane-booking'), $singular),
                'edit_item'          => sprintf(__('Edit %s', 'mclb-lane-booking'), $singular),
                'new_item'           => sprintf(__('New %s', 'mclb-lane-booking'), $singular),
                'view_item'          => sprintf(__('View %s', 'mclb-lane-booking'), $singular),
                'search_items'       => sprintf(__('Search %s', 'mclb-lane-booking'), $plural),
                'not_found'          => sprintf(__('No %s found.', 'mclb-lane-booking'), strtolower($plural)),
                'not_found_in_trash' => sprintf(__('No %s found in trash.', 'mclb-lane-booking'), strtolower($plural)),
                'all_items'          => $plural,
                'menu_name'          => $plural,
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => MCLB_Admin::PAGE, // submenu of "moBooking"
            'show_in_rest'        => false,
            'has_archive'         => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'supports'            => ['title', 'page-attributes'],
            'capability_type'     => 'post',
        ]);
    }

    public static function add_meta_box() {
        add_meta_box(
            'mclb_lane_settings',
            __('Booking settings', 'mclb-lane-booking'),
            [__CLASS__, 'render_meta_box'],
            self::CPT,
            'normal',
            'high'
        );
    }

    // ── Meta box ──────────────────────────────────────────────────────────────

    public static function render_meta_box($post) {
        wp_nonce_field(self::NONCE, self::NONCE);

        $type            = get_post_meta($post->ID, self::META_TYPE, true);
        $price_override  = get_post_meta($post->ID, self::META_PRICE, true);
        $hours_enabled   = (int) get_post_meta($post->ID, self::META_HOURS_ENABLED, true);
        $hours           = get_post_meta($post->ID, self::META_HOURS, true);
        if (!is_array($hours)) {
            $hours = MCLB_Settings::get('hours');
        }

        $types          = self::resource_types();
        $site_price     = MCLB_Settings::get('price_per_hour');

        echo '<table class="form-table" role="presentation"><tbody>';

        // Type
        echo '<tr><th scope="row"><label for="mclb_type">' . esc_html__('Type', 'mclb-lane-booking') . '</label></th><td>';
        if (!empty($types)) {
            echo '<select id="mclb_type" name="mclb_type">';
            printf('<option value="">%s</option>', esc_html__('— Select —', 'mclb-lane-booking'));
            foreach ($types as $t) {
                printf('<option value="%s" %s>%s</option>', esc_attr($t), selected($type, $t, false), esc_html($t));
            }
            echo '</select>';
            printf('<p class="description">%s</p>', esc_html__('Defined under moBooking → Settings → Resource types.', 'mclb-lane-booking'));
        } else {
            printf('<em>%s</em>', esc_html__('No resource types defined yet — add some under moBooking → Settings.', 'mclb-lane-booking'));
        }
        echo '</td></tr>';

        // Price override
        echo '<tr><th scope="row"><label for="mclb_price">' . esc_html__('Price per hour', 'mclb-lane-booking') . '</label></th><td>';
        printf(
            '<input type="number" step="0.01" min="0" id="mclb_price" name="mclb_price_override" value="%s"> ',
            esc_attr($price_override)
        );
        printf(
            '<span class="description">%s</span>',
            sprintf(
                /* translators: %s: the site default price. */
                esc_html__('Leave blank to use the site default (%s/hr).', 'mclb-lane-booking'),
                esc_html($site_price)
            )
        );
        echo '</td></tr>';

        // Hours override toggle
        echo '<tr><th scope="row">' . esc_html__('Opening hours', 'mclb-lane-booking') . '</th><td>';
        printf(
            '<label><input type="checkbox" id="mclb_hours_override" name="mclb_hours_override" value="1" %s> %s</label>',
            checked($hours_enabled, 1, false),
            esc_html__('Override the site-default hours for this resource', 'mclb-lane-booking')
        );
        echo '<div id="mclb_hours_table" style="margin-top:12px;' . ($hours_enabled ? '' : 'display:none;') . '">';
        self::render_hours_table($hours);
        echo '</div>';
        printf(
            '<p class="description">%s</p>',
            esc_html__('When off, this resource uses the site-wide default hours from settings.', 'mclb-lane-booking')
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        // Tiny inline toggle — no separate asset needed for one show/hide.
        echo '<script>(function(){var c=document.getElementById("mclb_hours_override"),t=document.getElementById("mclb_hours_table");if(c&&t){c.addEventListener("change",function(){t.style.display=c.checked?"":"none";});}})();</script>';
    }

    private static function render_hours_table($hours) {
        echo '<table class="widefat striped" style="max-width:520px"><thead><tr>';
        printf(
            '<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
            esc_html__('Day', 'mclb-lane-booking'),
            esc_html__('Open', 'mclb-lane-booking'),
            esc_html__('Close', 'mclb-lane-booking'),
            esc_html__('Closed', 'mclb-lane-booking')
        );
        echo '</tr></thead><tbody>';
        foreach (MCLB_Settings::weekdays() as $i => $label) {
            $row  = isset($hours[$i]) && is_array($hours[$i]) ? $hours[$i] : ['open' => '', 'close' => '', 'closed' => 0];
            $base = 'mclb_hours[' . $i . ']';
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

    public static function save($post_id, $post) {
        // Standard guards: nonce, autosave, capability.
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Type — only accept a value that's in the configured list.
        $types = self::resource_types();
        $type  = isset($_POST['mclb_type']) ? sanitize_text_field(wp_unslash($_POST['mclb_type'])) : '';
        if ($type !== '' && !in_array($type, $types, true)) {
            $type = '';
        }
        update_post_meta($post_id, self::META_TYPE, $type);

        // Price override — blank stays blank (means "inherit"); anything else is
        // sanitised exactly like the site default price.
        $raw_price = isset($_POST['mclb_price_override']) ? wp_unslash($_POST['mclb_price_override']) : '';
        if (trim((string) $raw_price) === '') {
            update_post_meta($post_id, self::META_PRICE, '');
        } else {
            update_post_meta($post_id, self::META_PRICE, MCLB_Settings::sanitize_price($raw_price));
        }

        // Hours override.
        $hours_enabled = !empty($_POST['mclb_hours_override']) ? 1 : 0;
        update_post_meta($post_id, self::META_HOURS_ENABLED, $hours_enabled);
        if ($hours_enabled && isset($_POST['mclb_hours']) && is_array($_POST['mclb_hours'])) {
            update_post_meta($post_id, self::META_HOURS, MCLB_Settings::sanitize_hours(wp_unslash($_POST['mclb_hours'])));
        }
    }

    // ── Resolved getters (Phase 2 consumes these, never re-derives the logic) ──

    /** The admin-defined resource type list, from settings (one per line). */
    public static function resource_types() {
        $raw   = (string) MCLB_Settings::get('resource_types');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    public static function get_type($lane_id) {
        return (string) get_post_meta($lane_id, self::META_TYPE, true);
    }

    /** Resolved hourly price: per-lane override if set, else the site default. */
    public static function get_price($lane_id) {
        $override = get_post_meta($lane_id, self::META_PRICE, true);
        if ($override !== '' && $override !== null) {
            return (float) $override;
        }
        return (float) MCLB_Settings::get('price_per_hour');
    }

    /** Resolved weekly hours: per-lane override if enabled, else the site default. */
    public static function get_hours($lane_id) {
        if ((int) get_post_meta($lane_id, self::META_HOURS_ENABLED, true) === 1) {
            $hours = get_post_meta($lane_id, self::META_HOURS, true);
            if (is_array($hours)) {
                return $hours;
            }
        }
        return MCLB_Settings::get('hours');
    }

    public static function is_bookable($lane_id) {
        return get_post_status($lane_id) === 'publish';
    }

    /**
     * All live (published) lanes in grid display order.
     *
     * @param string $type Optional type filter.
     * @return WP_Post[]
     */
    public static function all_bookable($type = '') {
        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ];
        if ($type !== '') {
            $args['meta_query'] = [[
                'key'   => self::META_TYPE,
                'value' => $type,
            ]];
        }
        return get_posts($args);
    }

    /**
     * Distinct non-empty lane types across published lanes, sorted. Drives the
     * type filter on admin screens.
     *
     * @return string[]
     */
    public static function types() {
        $out = [];
        foreach (self::all_bookable() as $lane) {
            $t = self::get_type($lane->ID);
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        sort($out);
        return $out;
    }
}
