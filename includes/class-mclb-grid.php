<?php
/**
 * Front-end grid — the [mclb_grid type="net|machine"] shortcode (Route A: inline
 * on-page, never inside the drawer). Renders the availability grid from
 * MCLB_Availability::for_day(), enqueues the grid CSS/JS, and exposes one public
 * REST GET so the date picker can re-render another day without a full reload.
 *
 * PHP is the single grid renderer (render_grid_html) — used for the initial
 * server-rendered day AND by the REST endpoint for date changes, so cell markup
 * lives in exactly one place. The JS only handles interaction (drag/tap select,
 * the selection list, date navigation, assembling the Phase 4 payload) and reads
 * everything it needs from data-* attributes on the rendered cells.
 *
 * Phase 3/4 boundary: "Add to cart" assembles the selection payload and POSTs it
 * to mclb/v1/cart — a route Phase 4 implements. Phase 3 never writes a hold or a
 * cart item itself.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Grid {

    const HANDLE    = 'mclb-grid';
    const REST_NS   = 'mclb/v1';

    public static function init() {
        add_shortcode('mclb_grid', [__CLASS__, 'shortcode']);
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function register_assets() {
        wp_register_style(self::HANDLE, MCLB_URL . 'assets/css/mclb-grid.css', [], MCLB_VERSION);
        wp_register_script(self::HANDLE, MCLB_URL . 'assets/js/mclb-grid.js', [], MCLB_VERSION, true);

        $symbol = function_exists('get_woocommerce_currency_symbol')
            ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8')
            : '$';

        wp_localize_script(self::HANDLE, 'mclbGrid', [
            'restUrl'      => esc_url_raw(rest_url(self::REST_NS . '/availability')),
            'cartUrl'      => esc_url_raw(rest_url(self::REST_NS . '/cart')), // Phase 4 implements the handler
            'nonce'        => wp_create_nonce('wp_rest'),
            'coachEnabled' => (int) MCLB_Settings::get('enable_coach_requests') === 1,
            'currency'     => $symbol,
            'labels'       => [
                'singular' => MCLB_Settings::get('resource_label_singular') ?: 'Lane',
                'plural'   => MCLB_Settings::get('resource_label_plural') ?: 'Lanes',
            ],
            'i18n'         => [
                'addToCart' => __('Add to cart', 'mclb-lane-booking'),
                'remove'    => __('Remove', 'mclb-lane-booking'),
                'total'     => __('Total', 'mclb-lane-booking'),
                'coach'     => __('Request a coach', 'mclb-lane-booking'),
                'coachNote' => __('Anything the coach should know? (optional)', 'mclb-lane-booking'),
                'selectHint'=> __('Select an available time to begin.', 'mclb-lane-booking'),
                'cartSoon'  => __('Booking checkout is not available yet.', 'mclb-lane-booking'),
            ],
        ]);
    }

    // ── Shortcode ─────────────────────────────────────────────────────────────

    public static function shortcode($atts) {
        $atts = shortcode_atts(['type' => ''], $atts, 'mclb_grid');
        $type = sanitize_text_field($atts['type']);

        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);

        $today  = wp_date('Y-m-d');
        $labels = ['singular' => MCLB_Settings::get('resource_label_singular') ?: 'Lane',
                   'plural'   => MCLB_Settings::get('resource_label_plural') ?: 'Lanes'];

        ob_start();
        ?>
        <div class="mclb-grid-wrap" data-type="<?php echo esc_attr($type); ?>" data-date="<?php echo esc_attr($today); ?>">
          <div class="mclb-grid__toolbar">
            <button type="button" class="mclb-nav mclb-nav--prev" aria-label="<?php esc_attr_e('Previous day', 'mclb-lane-booking'); ?>">&#8249;</button>
            <input type="date" class="mclb-grid__date" value="<?php echo esc_attr($today); ?>" min="<?php echo esc_attr($today); ?>">
            <button type="button" class="mclb-nav mclb-nav--next" aria-label="<?php esc_attr_e('Next day', 'mclb-lane-booking'); ?>">&#8250;</button>
          </div>

          <div class="mclb-grid" role="grid"><?php echo self::render_grid_html($type, $today); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping below. ?></div>

          <div class="mclb-legend" aria-hidden="true">
            <span class="mclb-legend__item"><i class="mclb-swatch mclb-swatch--available"></i><?php esc_html_e('Available', 'mclb-lane-booking'); ?></span>
            <span class="mclb-legend__item"><i class="mclb-swatch mclb-swatch--booked"></i><?php esc_html_e('Booked', 'mclb-lane-booking'); ?></span>
            <span class="mclb-legend__item"><i class="mclb-swatch mclb-swatch--closed"></i><?php esc_html_e('Closed', 'mclb-lane-booking'); ?></span>
          </div>

          <aside class="mclb-selection" hidden>
            <h3 class="mclb-selection__title"><?php echo esc_html($labels['singular']); ?> <?php esc_html_e('selection', 'mclb-lane-booking'); ?></h3>
            <ul class="mclb-selection__list"></ul>
            <p class="mclb-selection__total"></p>
            <?php if ((int) MCLB_Settings::get('enable_coach_requests') === 1) : ?>
              <label class="mclb-coach"><input type="checkbox" class="mclb-coach__toggle"> <?php esc_html_e('Request a coach', 'mclb-lane-booking'); ?></label>
              <textarea class="mclb-coach__note" rows="2" hidden placeholder="<?php esc_attr_e('Anything the coach should know? (optional)', 'mclb-lane-booking'); ?>"></textarea>
            <?php endif; ?>
            <button type="button" class="mclb-add-to-cart" disabled><?php esc_html_e('Add to cart', 'mclb-lane-booking'); ?></button>
            <p class="mclb-selection__msg" role="status"></p>
          </aside>

          <noscript><p><?php esc_html_e('This booking grid needs JavaScript enabled.', 'mclb-lane-booking'); ?></p></noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── REST: availability for a date (public read) ─────────────────────────────

    public static function register_rest() {
        register_rest_route(self::REST_NS, '/availability', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'rest_availability'],
            'args'                => [
                'date' => ['required' => true],
                'type' => ['required' => false],
            ],
        ]);
    }

    public static function rest_availability($request) {
        $date = self::sanitize_date((string) $request->get_param('date'));
        $type = sanitize_text_field((string) $request->get_param('type'));
        if ($date === '') {
            return new WP_Error('mclb_bad_date', __('Invalid date.', 'mclb-lane-booking'), ['status' => 400]);
        }
        return rest_ensure_response([
            'date' => $date,
            'type' => $type,
            'html' => self::render_grid_html($type, $date),
        ]);
    }

    // ── The single grid renderer (initial paint + REST) ─────────────────────────

    public static function render_grid_html($type, $date) {
        $data  = MCLB_Availability::for_day($date, ['type' => $type]);
        $lanes = $data['lanes'];
        $min   = $data['grid']['min_open'];
        $max   = $data['grid']['max_close'];

        if (empty($lanes) || $min === null || $max === null) {
            return '<p class="mclb-grid__empty">' . esc_html__('No availability for this day.', 'mclb-lane-booking') . '</p>';
        }

        $inc   = (int) $data['increment'];
        $min_m = self::hm($min);
        $max_m = self::hm($max);

        // Slot lookup per lane, keyed by start-minute.
        $maps = [];
        foreach ($lanes as $lid => $lane) {
            $m = [];
            foreach ($lane['slots'] as $slot) {
                $m[(int) $slot['start_min']] = $slot;
            }
            $maps[$lid] = $m;
        }

        ob_start();
        echo '<div class="mclb-grid__inner" style="--mclb-cols:' . (int) count($lanes) . '">';

        // Header
        echo '<div class="mclb-grid__head">';
        echo '<div class="mclb-grid__corner"></div>';
        foreach ($lanes as $lane) {
            echo '<div class="mclb-grid__lane-head">' . esc_html($lane['lane_name']) . '</div>';
        }
        echo '</div>';

        // Body rows
        echo '<div class="mclb-grid__body">';
        for ($m = $min_m; $m < $max_m; $m += $inc) {
            echo '<div class="mclb-grid__row">';
            echo '<div class="mclb-grid__time">' . esc_html(self::time_label($date, $m)) . '</div>';
            foreach ($lanes as $lid => $lane) {
                $slot = $maps[$lid][$m] ?? null;
                if (!$slot) {
                    echo '<div class="mclb-cell mclb-cell--na" role="gridcell" aria-disabled="true"></div>';
                    continue;
                }
                $state = $slot['state'];
                $price = (float) $lane['price_per_hour'];
                printf(
                    '<div class="mclb-cell mclb-cell--%1$s" role="gridcell" %2$s data-lane="%3$d" data-lane-name="%4$s" data-start="%5$s" data-end="%6$s" data-start-min="%7$d" data-end-min="%8$d" data-state="%1$s" data-price="%9$s"%10$s>%11$s</div>',
                    esc_attr($state),
                    $state === 'available' ? 'tabindex="0"' : 'aria-disabled="true"',
                    (int) $lid,
                    esc_attr($lane['lane_name']),
                    esc_attr($slot['start']),
                    esc_attr($slot['end']),
                    (int) $slot['start_min'],
                    (int) $slot['end_min'],
                    esc_attr((string) $price),
                    $slot['label'] ? ' title="' . esc_attr($slot['label']) . '"' : '',
                    $slot['label'] ? esc_html($slot['label']) : ''
                );
            }
            echo '</div>';
        }
        echo '</div>'; // body
        echo '</div>'; // inner

        return ob_get_clean();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** "HH:MM" → minutes from midnight. */
    private static function hm($hm) {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string) $hm, $m)) {
            return 0;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Time-of-day label for a minute offset, in the site's configured format. */
    private static function time_label($date, $minutes) {
        $dt = (new DateTimeImmutable($date . ' 00:00:00', wp_timezone()))->modify('+' . (int) $minutes . ' minutes');
        return wp_date(get_option('time_format') ?: 'g:i a', $dt->getTimestamp());
    }

    private static function sanitize_date($date) {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }
        // Reject non-calendar dates like 2026-13-40.
        [$y, $mo, $d] = array_map('intval', explode('-', $date));
        return checkdate($mo, $d, $y) ? $date : '';
    }
}
