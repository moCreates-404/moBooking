<?php
/**
 * Manage view (Phase 7c) — the staff calendar, rendered the same way in two
 * places: the [mclb_manage] shortcode on a normal page (counter staff) and the
 * moBooking → Calendar wp-admin screen (admins). Both just drop a container and
 * enqueue mclb-manage.js, which renders client-side from the mclb/v1/admin/day
 * JSON payload and writes through the REST layer.
 *
 * The front-end page is login-gated, capability-gated, never cached, and
 * noindexed. The REST layer is the real authority (capability + nonce on every
 * route); the page HTML carries no booking data.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Manage {

    const SHORTCODE  = 'mclb_manage';
    const ADMIN_SLUG = 'mclb-calendar';
    const HANDLE     = 'mclb-manage';

    /** @var string admin page hook suffix, for scoped enqueue. */
    private static $admin_hook = '';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        // Priority 11: the parent top-level menu (mclb-settings) is registered by
        // MCLB_Admin on admin_menu at the default priority 10, and MCLB_Manage::init()
        // runs before `new MCLB_Admin()`, so this must fire AFTER 10 or the submenu
        // orphans (raw-slug href, no page hook, "not allowed").
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 11);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('template_redirect', [__CLASS__, 'guard_front_page']);
        add_filter('rocket_cache_reject_uri', [__CLASS__, 'rocket_exclude']);
        add_filter('wp_robots', [__CLASS__, 'robots_noindex']);
        // B4 staff app shell: a body class the theme keys off to hide its own
        // header/footer on the Manage page (front-end only).
        add_filter('body_class', [__CLASS__, 'body_class']);
    }

    /** Mark the front-end Manage page as the staff app shell. */
    public static function body_class($classes) {
        if (self::is_manage_page()) {
            $classes[] = 'mclb-staff-app';
        }
        return $classes;
    }

    // ── Manage page identity ────────────────────────────────────────────────

    public static function page_id() {
        return (int) MCLB_Settings::get('manage_page_id');
    }
    public static function page_url() {
        $id = self::page_id();
        return $id ? (string) get_permalink($id) : '';
    }
    public static function is_manage_page() {
        $id = self::page_id();
        return $id && is_page($id);
    }

    // ── Front-end page: guard + cache/robots ──────────────────────────────────

    public static function guard_front_page() {
        if (!self::is_manage_page()) {
            return;
        }
        // Never cache this page: it carries a per-session REST nonce and is
        // login-gated. (REST data is cap-checked regardless, but a cached nonce
        // would 403 every call.)
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::page_url() ?: home_url('/')));
            exit;
        }
        // Staff app shell: drop the WP admin bar so the page reads as a clean app.
        add_filter('show_admin_bar', '__return_false');
    }

    public static function robots_noindex($robots) {
        if (self::is_manage_page()) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    public static function rocket_exclude($uris) {
        $id = self::page_id();
        if ($id) {
            $path = wp_parse_url(get_permalink($id), PHP_URL_PATH);
            if ($path) {
                $uris[] = $path;
            }
        }
        return $uris;
    }

    // ── Shortcode (front end) ─────────────────────────────────────────────────

    public static function shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to use the booking manager.', 'mclb-lane-booking') . '</p>';
        }
        if (!current_user_can(MCLB_Caps::MANAGE)) {
            return '<p>' . esc_html__('You don’t have access to the booking manager.', 'mclb-lane-booking') . '</p>';
        }
        self::enqueue();
        // The staff bar is the app shell's own header; render it only on the
        // designated Manage page (the body class there lets the theme drop its
        // chrome). The bar is a SIBLING of .mclb-manage because the calendar
        // script replaces that container's innerHTML on every render.
        $bar = self::is_manage_page() ? self::staffbar_html() : '';
        return $bar . self::container_html('front');
    }

    /** Slim server-rendered staff bar for the front-end Manage page (B4). */
    private static function staffbar_html() {
        $user  = wp_get_current_user();
        $uname = $user ? ($user->display_name ?: $user->user_login) : '';
        $site  = get_bloginfo('name');
        $today = MCLB_Settings::friendly_date((new DateTimeImmutable('now', wp_timezone()))->getTimestamp());
        // Log out → back to the login screen (login_redirect returns staff to this page).
        $logout = wp_logout_url(wp_login_url(self::page_url() ?: home_url('/')));

        $logo_id = (int) get_theme_mod('custom_logo');
        $logo    = $logo_id ? wp_get_attachment_image($logo_id, 'medium', false, ['class' => 'mclb-staffbar__logo', 'alt' => $site]) : '';

        // wp-admin Calendar link only for users who can actually reach wp-admin
        // (admins / shop managers); counter staff are kept out of wp-admin.
        $admin_link = '';
        if (current_user_can('manage_woocommerce') || current_user_can('manage_options')) {
            $admin_link = '<a class="mclb-staffbar__link" href="' . esc_url(admin_url('admin.php?page=' . self::ADMIN_SLUG)) . '">'
                        . esc_html__('wp-admin Calendar', 'mclb-lane-booking') . '</a>';
        }

        $brand = $logo ?: '<span class="mclb-staffbar__site">' . esc_html($site) . '</span>';

        return '<div class="mclb-staffbar">'
            . '<div class="mclb-staffbar__brand">' . $brand // phpcs:ignore WordPress.Security.EscapeOutput -- logo is core markup; site name escaped above.
            . '<span class="mclb-staffbar__title">' . esc_html__('Staff bookings', 'mclb-lane-booking') . '</span></div>'
            . '<div class="mclb-staffbar__meta">'
            . '<span class="mclb-staffbar__date">' . esc_html__('Today', 'mclb-lane-booking') . ' · ' . esc_html($today) . '</span>'
            . ($uname ? '<span class="mclb-staffbar__user">' . esc_html($uname) . '</span>' : '')
            . $admin_link // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_url/esc_html above.
            . '<a class="mclb-staffbar__link" href="' . esc_url($logout) . '">' . esc_html__('Log out', 'mclb-lane-booking') . '</a>'
            . '</div></div>';
    }

    // ── wp-admin Calendar page ────────────────────────────────────────────────

    public static function admin_menu() {
        self::$admin_hook = (string) add_submenu_page(
            MCLB_Admin::PAGE,
            __('Calendar', 'mclb-lane-booking'),
            __('Calendar', 'mclb-lane-booking'),
            MCLB_Caps::MANAGE,
            self::ADMIN_SLUG,
            [__CLASS__, 'render_admin']
        );
    }

    public static function admin_enqueue($hook) {
        if ($hook === self::$admin_hook) {
            self::enqueue();
        }
    }

    public static function render_admin() {
        if (!current_user_can(MCLB_Caps::MANAGE)) {
            return;
        }
        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Calendar', 'mclb-lane-booking') . '</h1>';
        echo self::container_html('admin'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
        echo '</div>';
    }

    // ── Assets ──────────────────────────────────────────────────────────────

    public static function register_assets() {
        if (wp_style_is(self::HANDLE, 'registered')) {
            return;
        }
        wp_register_style(self::HANDLE, MCLB_URL . 'assets/css/mclb-manage.css', [], self::asset_ver('assets/css/mclb-manage.css'));
        wp_register_script(self::HANDLE, MCLB_URL . 'assets/js/mclb-manage.js', [], self::asset_ver('assets/js/mclb-manage.js'), true);
    }

    /** filemtime-based version so browsers never serve a stale build. */
    public static function asset_ver($rel) {
        $path = MCLB_DIR . $rel;
        $mt   = @filemtime($path);
        return $mt ? (string) $mt : MCLB_VERSION;
    }

    private static function enqueue() {
        self::register_assets();
        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);

        $symbol = function_exists('get_woocommerce_currency_symbol')
            ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8')
            : '$';

        // wp_add_inline_script + wp_json_encode so numbers/booleans/arrays keep
        // their types in JS (wp_localize_script casts every scalar to a string,
        // which broke the minute arithmetic — "60" instead of 60).
        $data = [
            'rest'      => esc_url_raw(rest_url('mclb/v1/admin')),
            'ajaxUrl'   => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'canRefund'  => current_user_can(MCLB_Caps::REFUND),
            'testRecipient' => sanitize_email((string) MCLB_Settings::get('daysheet_test_recipient')),
            'eventTypes' => array_map(static function ($t) {
                return ['slug' => $t['slug'], 'label' => $t['label']];
            }, MCLB_Event_Types::all()),
            'increment' => (int) MCLB_Settings::get('booking_increment') ?: 60,
            'currency'  => $symbol,
            'today'     => wp_date('Y-m-d'),
            'loginUrl'  => esc_url_raw(wp_login_url(self::page_url() ?: home_url('/'))),
            'labels'    => [
                'resourceSingular' => MCLB_Settings::get('resource_label_singular') ?: 'Lane',
                'resourcePlural'   => MCLB_Settings::get('resource_label_plural') ?: 'Lanes',
                'staffSingular'    => MCLB_Coaches::label_singular(),
            ],
            'i18n'      => [
                'loading'     => __('Loading…', 'mclb-lane-booking'),
                'today'       => __('Today', 'mclb-lane-booking'),
                'prev'        => __('Previous day', 'mclb-lane-booking'),
                'next'        => __('Next day', 'mclb-lane-booking'),
                'allCoaches'  => __('All coaches', 'mclb-lane-booking'),
                'showCancel'  => __('Show cancelled', 'mclb-lane-booking'),
                'toCollect'   => __('To collect today', 'mclb-lane-booking'),
                'newBooking'  => __('New booking', 'mclb-lane-booking'),
                'blockout'    => __('Block out', 'mclb-lane-booking'),
                'inProgress'  => __('Checkout in progress', 'mclb-lane-booking'),
                'sessionGone' => __('Session expired — please log in again.', 'mclb-lane-booking'),
                'needInitials'=> __('Enter your initials first.', 'mclb-lane-booking'),
                'confirm'     => __('Confirm', 'mclb-lane-booking'),
                'cancel'      => __('Cancel', 'mclb-lane-booking'),
                'back'        => __('Back', 'mclb-lane-booking'),
                'saving'      => __('Saving…', 'mclb-lane-booking'),
                'noteRequiredPaid' => __('A note is required because the counter payment has been taken.', 'mclb-lane-booking'),
                'save'        => __('Save', 'mclb-lane-booking'),
                'delete'      => __('Delete', 'mclb-lane-booking'),
                'initials'    => __('Your initials', 'mclb-lane-booking'),
                'noAccess'    => __('No access.', 'mclb-lane-booking'),
                'name'            => __('Name', 'mclb-lane-booking'),
                'email'           => __('Email', 'mclb-lane-booking'),
                'phone'           => __('Phone', 'mclb-lane-booking'),
                'notes'           => __('Notes', 'mclb-lane-booking'),
                'history'         => __('History', 'mclb-lane-booking'),
                'editDetails'     => __('Edit details', 'mclb-lane-booking'),
                'saveDetails'     => __('Save details', 'mclb-lane-booking'),
                'overridePrice'   => __('Override price', 'mclb-lane-booking'),
                'savePrice'       => __('Save price', 'mclb-lane-booking'),
                'lanePrice'       => __('Lane price', 'mclb-lane-booking'),
                'overrideReason'  => __('Reason', 'mclb-lane-booking'),
                'reasonRequired'  => __('A reason is required.', 'mclb-lane-booking'),
                'priceOverridden' => __('Price overridden', 'mclb-lane-booking'),
                'was'             => __('was', 'mclb-lane-booking'),
                'move'            => __('Move…', 'mclb-lane-booking'),
                'moveTitle'      => __('Move booking', 'mclb-lane-booking'),
                'moveTo'         => __('Move to', 'mclb-lane-booking'),
                'moveDate'       => __('Date', 'mclb-lane-booking'),
                'moveResource'   => __('Resource', 'mclb-lane-booking'),
                'moveStart'      => __('Start time', 'mclb-lane-booking'),
                'moveDuration'   => __('Duration', 'mclb-lane-booking'),
                'moveKeep'       => __('Keep same duration', 'mclb-lane-booking'),
                'moveOverride'   => __('Override hours / blockouts', 'mclb-lane-booking'),
                'moveOutside'    => __('⚠ Outside normal availability.', 'mclb-lane-booking'),
                'moveClash'      => __('Clashes with', 'mclb-lane-booking'),
                'moveChecking'   => __('Checking…', 'mclb-lane-booking'),
                'moveConfirm'    => __('Move booking?', 'mclb-lane-booking'),
                'moveSave'       => __('Move booking', 'mclb-lane-booking'),
                'movePriceUp'    => __('to collect at counter', 'mclb-lane-booking'),
                'moveRefundQ'    => __('This slot is cheaper. Refund the difference to the card?', 'mclb-lane-booking'),
                'moveRefundYes'  => __('Refund %s to card', 'mclb-lane-booking'),
                'moveRefundNo'   => __('No refund', 'mclb-lane-booking'),
                'moveRefundReason' => __('Reason for no refund', 'mclb-lane-booking'),
                'moveReduceDue'  => __('Counter due reduced by %s', 'mclb-lane-booking'),
                'moveLockedPaid' => __('Counter payment already taken — any price difference is handled manually.', 'mclb-lane-booking'),
                'movedToast'     => __('Moved to %s', 'mclb-lane-booking'),
                'goToDay'        => __('Go to that day', 'mclb-lane-booking'),
                'longPressHint'  => __('Hold, then drag to move', 'mclb-lane-booking'),
                'notifyCustomer' => __('Notify customer by email', 'mclb-lane-booking'),
                'resendConfirmation' => __('Resend confirmation', 'mclb-lane-booking'),
                'noEmailOnFile'  => __('No email address on file', 'mclb-lane-booking'),
                'repeat'         => __('Repeat', 'mclb-lane-booking'),
                'repeatNone'     => __('Does not repeat', 'mclb-lane-booking'),
                'repeatWeekly'   => __('Weekly', 'mclb-lane-booking'),
                'repeatFortnightly' => __('Fortnightly', 'mclb-lane-booking'),
                'ends'           => __('Ends', 'mclb-lane-booking'),
                'forN'           => __('For a number of times', 'mclb-lane-booking'),
                'untilDate'      => __('Until a date', 'mclb-lane-booking'),
                'occurrences'    => __('Occurrences (max 52)', 'mclb-lane-booking'),
                'paidUpfront'    => __('Paid upfront for whole series', 'mclb-lane-booking'),
                'previewSeries'  => __('Preview series', 'mclb-lane-booking'),
                'datesAvailable' => __('dates available', 'mclb-lane-booking'),
                'bookAvailable'  => __('Book available dates only', 'mclb-lane-booking'),
                'seriesBooked'   => __('Booked %n of %m', 'mclb-lane-booking'),
                'skipped'        => __('skipped', 'mclb-lane-booking'),
                'series'         => __('Series', 'mclb-lane-booking'),
                'ofN'            => __('%n of %m', 'mclb-lane-booking'),
                'reasonLane'     => __('taken', 'mclb-lane-booking'),
                'reasonCoach'    => __('coach busy', 'mclb-lane-booking'),
                'reasonOutside'  => __('closed / out of hours', 'mclb-lane-booking'),
                'reasonBusy'     => __('busy, retry', 'mclb-lane-booking'),
                'cancelFollowing' => __('Cancel this & following', 'mclb-lane-booking'),
                'cancelWhole'    => __('Cancel whole series (future)', 'mclb-lane-booking'),
                'confirmCancelN' => __('Cancel %n sessions (%r)?', 'mclb-lane-booking'),
                'noneToCancel'   => __('No future sessions to cancel.', 'mclb-lane-booking'),
                'seriesPaidWarn' => __('%n prepaid or past session(s) will be cancelled with no automatic refund:', 'mclb-lane-booking'),
                'perSessionLane' => __('Lane', 'mclb-lane-booking'),
                'perSessionCoach' => __('Coach', 'mclb-lane-booking'),
                'perSession'     => __('per session', 'mclb-lane-booking'),
                'createdNofM'    => __('Created %n of %m sessions', 'mclb-lane-booking'),
                'skippedColon'   => __('Skipped:', 'mclb-lane-booking'),
                'done'           => __('Done', 'mclb-lane-booking'),
                'toCollectOn'    => __('To collect', 'mclb-lane-booking'),
                'phoneRequired'   => __('A phone number is required.', 'mclb-lane-booking'),
                'sendSheet'   => __('Send day sheet', 'mclb-lane-booking'),
                'resend'      => __('Resend day sheet', 'mclb-lane-booking'),
                'sheetSent'   => __('Sheet sent', 'mclb-lane-booking'),
                'sheetChanged'=> __('changed since sent', 'mclb-lane-booking'),
                'sendConfirm' => __('Send the day sheet now?', 'mclb-lane-booking'),
                'notSent'     => __('Not sent', 'mclb-lane-booking'),
                'sentOk'      => __('Sent ✓', 'mclb-lane-booking'),
                'testMode'    => __('Test mode:', 'mclb-lane-booking'),
                'testGoesTo'  => __('this will go to', 'mclb-lane-booking'),
                'notTheCoach' => __('not the coach', 'mclb-lane-booking'),
                // Partial coach time + block-out release (v1.4.1).
                'coachTime'       => __('Coach time', 'mclb-lane-booking'),
                'due'             => __('Due', 'mclb-lane-booking'),
                'coachFee'        => __('Coach fee', 'mclb-lane-booking'),
                'overrideCoachFee'=> __('Override coach fee', 'mclb-lane-booking'),
                'saveCoachFee'    => __('Save coach fee', 'mclb-lane-booking'),
                'coachFeeOverridden' => __('Coach fee overridden', 'mclb-lane-booking'),
                'coachClamped'    => __('Coach time was shortened to fit the new session.', 'mclb-lane-booking'),
                'scopeThis'       => __('This session only', 'mclb-lane-booking'),
                'scopeFollowing'  => __('This and all following', 'mclb-lane-booking'),
                'applyTo'         => __('Apply to', 'mclb-lane-booking'),
                'slotBlocked'     => __('This slot is blocked', 'mclb-lane-booking'),
                'bookOverReason'  => __('Reason for booking over the blockout', 'mclb-lane-booking'),
                'bookOverReasonRequired' => __('A reason is required to book over a blockout.', 'mclb-lane-booking'),
                'released'        => __('Released', 'mclb-lane-booking'),
                'releasedFrom'    => __('from', 'mclb-lane-booking'),
                'wholeDay'        => __('whole day', 'mclb-lane-booking'),
                'releaseThisDate' => __('Release this date', 'mclb-lane-booking'),
                'alreadyReleased' => __('Already released', 'mclb-lane-booking'),
                'releaseWholeDay' => __('Free the whole day', 'mclb-lane-booking'),
                'endedEarlyAt'    => __('Ended early at', 'mclb-lane-booking'),
                'howMuch'         => __('How much', 'mclb-lane-booking'),
                'release'         => __('Release', 'mclb-lane-booking'),
                'deleteWholeBlockout' => __('Delete whole blockout', 'mclb-lane-booking'),
                'recurringBlockout'   => __('Recurring blockout', 'mclb-lane-booking'),
                'editOnClosures'  => __('edit it on the Closures screen', 'mclb-lane-booking'),
                'note'            => __('Note', 'mclb-lane-booking'),
                'undoRelease'     => __('Undo release', 'mclb-lane-booking'),
                'undoReleaseHint' => __('This re-applies the blockout for this date. It will refuse if a booking now sits in the released window.', 'mclb-lane-booking'),
            ],
        ];

        wp_add_inline_script(self::HANDLE, 'window.mclbManage = ' . wp_json_encode($data) . ';', 'before');
    }

    private static function container_html($ctx = 'front') {
        $cls = 'mclb-manage mclb-manage--' . ($ctx === 'admin' ? 'admin' : 'front');
        return '<div class="' . esc_attr($cls) . '" data-today="' . esc_attr(wp_date('Y-m-d')) . '">'
            . '<p class="mclb-manage__loading">' . esc_html__('Loading…', 'mclb-lane-booking') . '</p>'
            . '</div>';
    }
}
