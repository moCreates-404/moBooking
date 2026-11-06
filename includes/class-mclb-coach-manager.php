<?php
/**
 * Built-in coach / instructor manager (v1.7.0).
 *
 * moBooking asks for coaches through three filters (see MCLB_Coaches):
 * mclb_coach_options, mclb_coach_rate and mclb_coach_contact. Until now only a
 * theme could answer them (Cricketers Club's `coach` CPT), so Pro staff
 * features were empty on any other site. This class answers them from the
 * plugin's own `mclb_coach` CPT, managed under moBooking → Coaches (wording
 * follows the {staff} setting: Instructors, Trainers, Coaches…).
 *
 * Stays out of the way of an existing integration: if a theme or another
 * plugin already hooks `mclb_coach_options` by the time WordPress runs `init`,
 * the built-in manager doesn't register at all, so that site keeps exactly one
 * source of coaches. Force it either way with the `mclb_builtin_coaches`
 * filter (true / false).
 *
 * Fields are a hand-rolled meta box (no ACF dependency), like MCLB_Lane:
 *   _mclb_coach_rate      hourly fee; blank = not set (blocks assignment,
 *                         see MCLB_Bookings::assign_coach_locked), 0 = free
 *   _mclb_coach_email     schedule email for daily day sheets
 *   _mclb_coach_bookable  '1' / '0' — can customers and staff pick them
 * Publish = active, draft = kept but hidden, trash = removed. menu_order
 * (Page Attributes) sets dropdown order.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Coach_Manager {

    const CPT           = 'mclb_coach';
    const NONCE         = 'mclb_coach_meta';
    const META_RATE     = '_mclb_coach_rate';
    const META_EMAIL    = '_mclb_coach_email';
    const META_BOOKABLE = '_mclb_coach_bookable';

    /** @var bool|null resolved on init */
    private static $active = null;

    public static function init() {
        // Priority 1: after the theme's functions.php has added its filters
        // (after_setup_theme), before anything reads coaches.
        add_action('init', [__CLASS__, 'boot'], 1);
    }

    /** Is the built-in manager the coach source on this site? */
    public static function is_active() {
        return (bool) self::$active;
    }

    public static function boot() {
        $builtin = !has_filter('mclb_coach_options');
        /**
         * Use the built-in coach manager? Defaults to true unless a theme or
         * plugin already supplies coaches through `mclb_coach_options`.
         */
        self::$active = (bool) apply_filters('mclb_builtin_coaches', $builtin);
        if (!self::$active) {
            return;
        }

        self::register();
        add_filter('mclb_coach_options', [__CLASS__, 'filter_options'], 5);
        add_filter('mclb_coach_rate', [__CLASS__, 'filter_rate'], 5, 2);
        add_filter('mclb_coach_contact', [__CLASS__, 'filter_contact'], 5, 2);

        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_filter('enter_title_here', [__CLASS__, 'title_placeholder'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'empty_notice']);
    }

    // ── CPT ──────────────────────────────────────────────────────────────────

    public static function register() {
        $singular = MCLB_Terms::staff();
        $plural   = MCLB_Terms::staff(true);

        register_post_type(self::CPT, [
            'labels' => [
                'name'               => $plural,
                'singular_name'      => $singular,
                /* translators: %s: staff label, e.g. "Coach". */
                'add_new_item'       => sprintf(__('Add New %s', 'mobooking'), $singular),
                /* translators: %s: staff label, e.g. "Coach". */
                'edit_item'          => sprintf(__('Edit %s', 'mobooking'), $singular),
                /* translators: %s: staff label, e.g. "Coach". */
                'new_item'           => sprintf(__('New %s', 'mobooking'), $singular),
                /* translators: %s: staff label, plural, e.g. "Coaches". */
                'search_items'       => sprintf(__('Search %s', 'mobooking'), $plural),
                /* translators: %s: staff label, plural, lower case. */
                'not_found'          => sprintf(__('No %s yet.', 'mobooking'), strtolower($plural)),
                /* translators: %s: staff label, plural, lower case. */
                'not_found_in_trash' => sprintf(__('No %s found in trash.', 'mobooking'), strtolower($plural)),
                'all_items'          => $plural,
                'menu_name'          => $plural,
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => MCLB_Admin::PAGE,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'supports'            => ['title', 'page-attributes'],
            'capability_type'     => 'post',
        ]);
    }

    // ── Filter sources ───────────────────────────────────────────────────────

    /** [id => name] for published, bookable coaches, in menu order. */
    public static function filter_options($options) {
        $ids = get_posts([
            'post_type'        => self::CPT,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'orderby'          => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]);
        $out = is_array($options) ? $options : [];
        foreach ($ids as $id) {
            if (!self::is_bookable_meta($id)) {
                continue;
            }
            $out[(int) $id] = get_the_title($id);
        }
        return $out;
    }

    public static function filter_rate($rate, $coach_id) {
        if (get_post_type((int) $coach_id) !== self::CPT) {
            return $rate;
        }
        $v = get_post_meta((int) $coach_id, self::META_RATE, true);
        return ($v === '' || $v === null || $v === false) ? null : (float) $v;
    }

    public static function filter_contact($email, $coach_id) {
        if (get_post_type((int) $coach_id) !== self::CPT) {
            return $email;
        }
        return sanitize_email((string) get_post_meta((int) $coach_id, self::META_EMAIL, true));
    }

    /** Missing meta counts as bookable; only an explicit '0' hides a coach. */
    private static function is_bookable_meta($id) {
        return get_post_meta((int) $id, self::META_BOOKABLE, true) !== '0';
    }

    // ── Edit screen ──────────────────────────────────────────────────────────

    public static function title_placeholder($text, $post) {
        if ($post && $post->post_type === self::CPT) {
            return __('Full name', 'mobooking');
        }
        return $text;
    }

    public static function add_meta_box() {
        add_meta_box(
            'mclb_coach_settings',
            __('Booking settings', 'mobooking'),
            [__CLASS__, 'render_meta_box'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function render_meta_box($post) {
        wp_nonce_field(self::NONCE, self::NONCE);
        $rate     = get_post_meta($post->ID, self::META_RATE, true);
        $email    = get_post_meta($post->ID, self::META_EMAIL, true);
        $bookable = self::is_bookable_meta($post->ID);
        $staff    = strtolower(MCLB_Terms::staff());
        $currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="mclb_coach_rate">' . esc_html__('Hourly fee', 'mobooking') . '</label></th><td>';
        printf(
            '%s <input type="number" id="mclb_coach_rate" name="mclb_coach_rate" value="%s" min="0" step="0.01" class="small-text"> %s',
            esc_html($currency),
            esc_attr($rate),
            esc_html__('per hour', 'mobooking')
        );
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: staff label, lower case, e.g. "coach". */
            __('Added to the booking when this %s is requested. Enter 0 for no charge. Leave blank and they can\'t be booked until a fee is set.', 'mobooking'),
            $staff
        )) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="mclb_coach_email">' . esc_html__('Email', 'mobooking') . '</label></th><td>';
        printf('<input type="email" id="mclb_coach_email" name="mclb_coach_email" value="%s" class="regular-text">', esc_attr($email));
        echo '<p class="description">' . esc_html__('Where their daily schedule is sent. Optional.', 'mobooking') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Bookable', 'mobooking') . '</th><td><label>';
        printf('<input type="checkbox" name="mclb_coach_bookable" value="1" %s> ', checked($bookable, true, false));
        echo esc_html(sprintf(
            /* translators: %s: staff label, lower case. */
            __('Customers and staff can choose this %s', 'mobooking'),
            $staff
        ));
        echo '</label><p class="description">' . esc_html__('Untick to hide them from booking without losing their past bookings.', 'mobooking') . '</p></td></tr>';

        echo '</tbody></table>';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw = isset($_POST['mclb_coach_rate']) ? trim((string) wp_unslash($_POST['mclb_coach_rate'])) : '';
        update_post_meta($post_id, self::META_RATE, $raw === '' ? '' : MCLB_Settings::sanitize_price($raw));

        $email = isset($_POST['mclb_coach_email']) ? sanitize_email(wp_unslash($_POST['mclb_coach_email'])) : '';
        update_post_meta($post_id, self::META_EMAIL, $email);

        update_post_meta($post_id, self::META_BOOKABLE, !empty($_POST['mclb_coach_bookable']) ? '1' : '0');
    }

    // ── List table ───────────────────────────────────────────────────────────

    public static function columns($cols) {
        $out = [];
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === 'title') {
                $out['mclb_rate']     = __('Hourly fee', 'mobooking');
                $out['mclb_email']    = __('Email', 'mobooking');
                $out['mclb_bookable'] = __('Bookable', 'mobooking');
            }
        }
        return $out;
    }

    public static function column($col, $post_id) {
        if ($col === 'mclb_rate') {
            $r = get_post_meta($post_id, self::META_RATE, true);
            echo $r === '' ? '<em>' . esc_html__('Not set', 'mobooking') . '</em>' : esc_html(MCLB_Coaches::money($r));
        } elseif ($col === 'mclb_email') {
            $e = get_post_meta($post_id, self::META_EMAIL, true);
            echo $e ? esc_html($e) : '&mdash;';
        } elseif ($col === 'mclb_bookable') {
            echo self::is_bookable_meta($post_id) ? esc_html__('Yes', 'mobooking') : esc_html__('No', 'mobooking');
        }
    }

    /** On the Coaches list with none yet, explain what they're for. */
    public static function empty_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-' . self::CPT || !MCLB_Plan::is_pro()) {
            return;
        }
        $count = wp_count_posts(self::CPT);
        if (!empty($count->publish) || !empty($count->draft)) {
            return;
        }
        echo '<div class="notice notice-info"><p>' . esc_html(MCLB_Terms::fill(__('Add your {staff_plural} here. Customers can request one when they book, staff assign them from the calendar, and each {staff} can get a daily schedule email.', 'mobooking'))) . '</p></div>';
    }
}
