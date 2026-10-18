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
        $cssmt = @filemtime(MCLB_DIR . 'assets/css/mclb-grid.css');
        $jsmt  = @filemtime(MCLB_DIR . 'assets/js/mclb-grid.js');
        wp_register_style(self::HANDLE, MCLB_URL . 'assets/css/mclb-grid.css', [], $cssmt ? (string) $cssmt : MCLB_VERSION);
        wp_register_script(self::HANDLE, MCLB_URL . 'assets/js/mclb-grid.js', [], $jsmt ? (string) $jsmt : MCLB_VERSION, true);

        $symbol = function_exists('get_woocommerce_currency_symbol')
            ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8')
            : '$';

        wp_localize_script(self::HANDLE, 'mclbGrid', [
            'restUrl'      => esc_url_raw(rest_url(self::REST_NS . '/availability')),
            'cartUrl'      => esc_url_raw(rest_url(self::REST_NS . '/cart')), // Phase 4 implements the handler
            'nonce'        => wp_create_nonce('wp_rest'),
            'coachEnabled' => (int) MCLB_Settings::get('enable_coach_requests') === 1,
            'canBook'      => MCLB_License::can_book(),
            'currency'     => $symbol,
            'labels'       => [
                'singular' => MCLB_Settings::get('resource_label_singular') ?: 'Lane',
                'plural'   => MCLB_Settings::get('resource_label_plural') ?: 'Lanes',
            ],
            'i18n'         => [
                'addToCart'  => __('Add to cart', 'mclb-lane-booking'),
                'remove'     => __('Remove', 'mclb-lane-booking'),
                'total'      => __('Total', 'mclb-lane-booking'),
                'slot'       => __('slot', 'mclb-lane-booking'),
                'slots'      => __('slots', 'mclb-lane-booking'),
                'coach'      => __('Request a coach', 'mclb-lane-booking'),
                'coachNote'  => __('Anything the coach should know? (optional)', 'mclb-lane-booking'),
                'cartSoon'   => __('Could not add to cart — please try again.', 'mclb-lane-booking'),
                'demo'       => MCLB_License::demo_message(),
                'instrTouch' => __('Tap a time, then tap it again to select it. Choose as many as you need, then Add to cart.', 'mclb-lane-booking'),
                'instrMouse' => __('Click a time to select it, or drag to select several.', 'mclb-lane-booking'),
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
        <?php // The server-rendered day below can be frozen in page cache (WP Rocket). The
              // grid JS locks the grid (is-loading, set synchronously in its constructor) and
              // re-pulls fresh availability before it becomes interactive. We deliberately do
              // NOT hard-code is-loading here: if a stale cached copy of the JS ever loads
              // against this newer markup, the grid must stay usable (server-side revalidation
              // under lock is the double-book backstop), never frozen behind an un-clearable veil. ?>
        <div class="mclb-grid-wrap" data-type="<?php echo esc_attr($type); ?>" data-date="<?php echo esc_attr($today); ?>"<?php echo MCLB_License::can_book() ? '' : ' data-readonly="1"'; ?>>
          <?php if (!MCLB_License::can_book()) : ?>
            <p class="mclb-demo-notice" role="status"><?php echo esc_html(MCLB_License::demo_message()); ?></p>
          <?php endif; ?>
          <div class="mclb-grid__toolbar">
            <button type="button" class="mclb-nav mclb-nav--prev" aria-label="<?php esc_attr_e('Previous day', 'mclb-lane-booking'); ?>">&#8249;</button>
            <input type="date" class="mclb-grid__date" value="<?php echo esc_attr($today); ?>" min="<?php echo esc_attr($today); ?>">
            <button type="button" class="mclb-nav mclb-nav--next" aria-label="<?php esc_attr_e('Next day', 'mclb-lane-booking'); ?>">&#8250;</button>
          </div>
          <p class="mclb-grid__datelabel" aria-live="polite"><?php echo esc_html(self::date_label($today)); ?></p>
          <p class="mclb-grid__instr"><?php esc_html_e('Click a time to select it, or drag to select several.', 'mclb-lane-booking'); ?></p>

          <p class="mclb-grid__hint" aria-hidden="true" hidden><?php esc_html_e('Swipe for more lanes', 'mclb-lane-booking'); ?> &#8594;</p>
          <div class="mclb-grid__scroll">
            <div class="mclb-grid" role="grid"><?php echo self::render_grid_html($type, $today); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping below. ?></div>
            <div class="mclb-grid__fade" aria-hidden="true"></div>
            <div class="mclb-grid__loading" aria-hidden="true"><span><?php esc_html_e('Checking availability…', 'mclb-lane-booking'); ?></span></div>
          </div>

          <div class="mclb-legend" aria-hidden="true">
            <span class="mclb-legend__item"><i class="mclb-swatch mclb-swatch--available"></i><?php esc_html_e('Available', 'mclb-lane-booking'); ?></span>
            <span class="mclb-legend__item"><i class="mclb-swatch mclb-swatch--booked"></i><?php esc_html_e('Booked', 'mclb-lane-booking'); ?></span>
            <span class="mclb-legend__item"><i class="mclb-swatch mclb-swatch--closed"></i><?php esc_html_e('Closed', 'mclb-lane-booking'); ?></span>
          </div>

          <p class="mclb-grid__notice" role="alert" hidden></p>

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

          <div class="mclb-grid__minibar" hidden aria-live="polite">
            <span class="mclb-grid__minibar-info"></span>
            <button type="button" class="mclb-grid__minibar-add"><?php esc_html_e('Add to cart', 'mclb-lane-booking'); ?></button>
            <button type="button" class="mclb-grid__minibar-clear" aria-label="<?php esc_attr_e('Clear all', 'mclb-lane-booking'); ?>">&#10005;</button>
          </div>

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

    /**
     * The customer booking window: how far ahead a CUSTOMER may book. Staff
     * (mclb_manage_bookings) are never limited, so they can book/move any date
     * even through the customer grid. `today` + `max_date` are computed server-side
     * in the site timezone; the client reads them from this fresh (no-store)
     * response rather than any cache-frozen page HTML, so the limit never drifts.
     *
     * @return array{days:int,today:string,max_date:?string,staff:bool}
     */
    public static function booking_window() {
        $days  = (int) MCLB_Settings::get('booking_window_days');
        $staff = current_user_can(MCLB_Caps::MANAGE);
        $tz    = wp_timezone();
        $max   = null;
        if (!$staff && $days > 0) {
            $max = (new DateTimeImmutable('today', $tz))->modify('+' . $days . ' days')->format('Y-m-d');
        }
        return ['days' => $days, 'today' => wp_date('Y-m-d'), 'max_date' => $max, 'staff' => $staff];
    }

    /**
     * THE single beyond-the-window test, shared by all three enforcement layers
     * (grid REST, add-to-cart, cart/checkout validation) so they can never drift.
     * Pure: a null max (staff / no limit) is never beyond; the last bookable date
     * itself is allowed (inclusive). $date is a 'Y-m-d' string.
     */
    public static function is_beyond_window($date, $max_date) {
        return $max_date !== null && (string) $date > (string) $max_date;
    }

    /** The friendly "come back later" block shown for a day beyond the window. */
    public static function window_message_html() {
        $days = (int) MCLB_Settings::get('booking_window_days');
        $note = trim((string) MCLB_Settings::get('booking_window_note'));
        /* translators: %d: number of days. */
        $msg  = sprintf(_n('Online bookings open %d day in advance.', 'Online bookings open %d days in advance.', $days, 'mclb-lane-booking'), $days);
        if ($note !== '') {
            $msg .= ' ' . $note;
        }
        $msg = (string) apply_filters('mclb_booking_window_message', $msg, $days, $note);
        return '<p class="mclb-grid__window" role="status">' . esc_html($msg) . '</p>';
    }

    public static function rest_availability($request) {
        $date = self::sanitize_date((string) $request->get_param('date'));
        $type = sanitize_text_field((string) $request->get_param('type'));
        if ($date === '') {
            return new WP_Error('mclb_bad_date', __('Invalid date.', 'mclb-lane-booking'), ['status' => 400]);
        }
        $win    = self::booking_window();
        $beyond = self::is_beyond_window($date, $win['max_date']);
        $response = rest_ensure_response([
            'date'        => $date,
            'type'        => $type,
            'date_label'  => self::date_label($date),
            'today'       => $win['today'],
            'window_days' => $win['days'],
            'max_date'    => $win['max_date'],
            'bookable'    => !$beyond,
            'html'        => $beyond ? self::window_message_html() : self::render_grid_html($type, $date),
        ]);
        // Availability is live and must never be served stale — tell every layer
        // (browser, WP Rocket, host/CDN cache) not to cache this response.
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
        return $response;
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

    /** Friendly long date label ("Monday, 26 October 2026") built in site tz. */
    private static function date_label($date) {
        return MCLB_Settings::friendly_date((new DateTimeImmutable($date . ' 00:00:00', wp_timezone()))->getTimestamp());
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
