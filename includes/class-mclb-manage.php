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
            return '<p>' . esc_html__('Please log in to use the booking manager.', 'mobooking') . '</p>';
        }
        if (!current_user_can(MCLB_Caps::MANAGE)) {
            return '<p>' . esc_html__('You don’t have access to the booking manager.', 'mobooking') . '</p>';
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
                        . esc_html__('wp-admin Calendar', 'mobooking') . '</a>';
        }

        $brand = $logo ?: '<span class="mclb-staffbar__site">' . esc_html($site) . '</span>';

        return '<div class="mclb-staffbar">'
            . '<div class="mclb-staffbar__brand">' . $brand // phpcs:ignore WordPress.Security.EscapeOutput -- logo is core markup; site name escaped above.
            . '<span class="mclb-staffbar__title">' . esc_html__('Staff bookings', 'mobooking') . '</span></div>'
            . '<div class="mclb-staffbar__meta">'
            . '<span class="mclb-staffbar__date">' . esc_html__('Today', 'mobooking') . ' · ' . esc_html($today) . '</span>'
            . ($uname ? '<span class="mclb-staffbar__user">' . esc_html($uname) . '</span>' : '')
            . $admin_link // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_url/esc_html above.
            . '<a class="mclb-staffbar__link" href="' . esc_url($logout) . '">' . esc_html__('Log out', 'mobooking') . '</a>'
            . '</div></div>';
    }

    // ── wp-admin Calendar page ────────────────────────────────────────────────

    public static function admin_menu() {
        self::$admin_hook = (string) add_submenu_page(
            MCLB_Admin::PAGE,
            __('Calendar', 'mobooking'),
            __('Calendar', 'mobooking'),
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
        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Calendar', 'mobooking') . '</h1>';
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
            'testRecipient' => MCLB_Mail::test_recipient(), // the address day sheets actually reroute to (global email_test_recipient, daysheet fallback)
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
                'loading'     => __('Loading…', 'mobooking'),
                'today'       => __('Today', 'mobooking'),
                'prev'        => __('Previous day', 'mobooking'),
                'next'        => __('Next day', 'mobooking'),
                'allCoaches'  => __('All coaches', 'mobooking'),
                'showCancel'  => __('Show cancelled', 'mobooking'),
                'toCollect'   => __('To collect today', 'mobooking'),
                'newBooking'  => __('New booking', 'mobooking'),
                'blockout'    => __('Block out', 'mobooking'),
                'inProgress'  => __('Checkout in progress', 'mobooking'),
                'sessionGone' => __('Session expired — please log in again.', 'mobooking'),
                'needInitials'=> __('Enter your initials first.', 'mobooking'),
                'confirm'     => __('Confirm', 'mobooking'),
                'cancel'      => __('Cancel', 'mobooking'),
                'back'        => __('Back', 'mobooking'),
                'saving'      => __('Saving…', 'mobooking'),
                'noteRequiredPaid' => __('A note is required because the counter payment has been taken.', 'mobooking'),
                'save'        => __('Save', 'mobooking'),
                'delete'      => __('Delete', 'mobooking'),
                'initials'    => __('Your initials', 'mobooking'),
                'noAccess'    => __('No access.', 'mobooking'),
                'name'            => __('Name', 'mobooking'),
                'email'           => __('Email', 'mobooking'),
                'phone'           => __('Phone', 'mobooking'),
                'notes'           => __('Notes', 'mobooking'),
                'history'         => __('History', 'mobooking'),
                'editDetails'     => __('Edit details', 'mobooking'),
                'saveDetails'     => __('Save details', 'mobooking'),
                'overridePrice'   => __('Override price', 'mobooking'),
                'savePrice'       => __('Save price', 'mobooking'),
                'lanePrice'       => __('Lane price', 'mobooking'),
                'overrideReason'  => __('Reason', 'mobooking'),
                'reasonRequired'  => __('A reason is required.', 'mobooking'),
                'priceOverridden' => __('Price overridden', 'mobooking'),
                'was'             => __('was', 'mobooking'),
                'move'            => __('Move…', 'mobooking'),
                'moveTitle'      => __('Move booking', 'mobooking'),
                'moveTo'         => __('Move to', 'mobooking'),
                'moveDate'       => __('Date', 'mobooking'),
                'moveResource'   => __('Resource', 'mobooking'),
                'moveStart'      => __('Start time', 'mobooking'),
                'moveDuration'   => __('Duration', 'mobooking'),
                'moveKeep'       => __('Keep same duration', 'mobooking'),
                'moveOverride'   => __('Override hours / blockouts', 'mobooking'),
                'moveOutside'    => __('⚠ Outside normal availability.', 'mobooking'),
                'moveClash'      => __('Clashes with', 'mobooking'),
                'moveChecking'   => __('Checking…', 'mobooking'),
                'moveConfirm'    => __('Move booking?', 'mobooking'),
                'moveSave'       => __('Move booking', 'mobooking'),
                'movePriceUp'    => __('to collect at counter', 'mobooking'),
                'moveRefundQ'    => __('This slot is cheaper. Refund the difference to the card?', 'mobooking'),
                'moveRefundYes'  => __('Refund %s to card', 'mobooking'),
                'moveRefundNo'   => __('No refund', 'mobooking'),
                'moveRefundReason' => __('Reason for no refund', 'mobooking'),
                'moveReduceDue'  => __('Counter due reduced by %s', 'mobooking'),
                'moveLockedPaid' => __('Counter payment already taken — any price difference is handled manually.', 'mobooking'),
                'movedToast'     => __('Moved to %s', 'mobooking'),
                'goToDay'        => __('Go to that day', 'mobooking'),
                'longPressHint'  => __('Hold, then drag to move', 'mobooking'),
                'notifyCustomer' => __('Notify customer by email', 'mobooking'),
                'resendConfirmation' => __('Resend confirmation', 'mobooking'),
                'noEmailOnFile'  => __('No email address on file', 'mobooking'),
                'repeat'         => __('Repeat', 'mobooking'),
                'repeatNone'     => __('Does not repeat', 'mobooking'),
                'repeatWeekly'   => __('Weekly', 'mobooking'),
                'repeatFortnightly' => __('Fortnightly', 'mobooking'),
                'ends'           => __('Ends', 'mobooking'),
                'forN'           => __('For a number of times', 'mobooking'),
                'untilDate'      => __('Until a date', 'mobooking'),
                'occurrences'    => __('Occurrences (max 52)', 'mobooking'),
                'paidUpfront'    => __('Paid upfront for whole series', 'mobooking'),
                'previewSeries'  => __('Preview series', 'mobooking'),
                'datesAvailable' => __('dates available', 'mobooking'),
                'bookAvailable'  => __('Book available dates only', 'mobooking'),
                'seriesBooked'   => __('Booked %n of %m', 'mobooking'),
                'skipped'        => __('skipped', 'mobooking'),
                'series'         => __('Series', 'mobooking'),
                'ofN'            => __('%n of %m', 'mobooking'),
                'reasonLane'     => __('taken', 'mobooking'),
                'reasonCoach'    => __('coach busy', 'mobooking'),
                'reasonOutside'  => __('closed / out of hours', 'mobooking'),
                'reasonBusy'     => __('busy, retry', 'mobooking'),
                'cancelFollowing' => __('Cancel this & following', 'mobooking'),
                'cancelWhole'    => __('Cancel whole series (future)', 'mobooking'),
                'confirmCancelN' => __('Cancel %n sessions (%r)?', 'mobooking'),
                'noneToCancel'   => __('No future sessions to cancel.', 'mobooking'),
                'seriesPaidWarn' => __('%n prepaid or past session(s) will be cancelled with no automatic refund:', 'mobooking'),
                'perSessionLane' => __('Lane', 'mobooking'),
                'perSessionCoach' => __('Coach', 'mobooking'),
                'perSession'     => __('per session', 'mobooking'),
                'createdNofM'    => __('Created %n of %m sessions', 'mobooking'),
                'skippedColon'   => __('Skipped:', 'mobooking'),
                'done'           => __('Done', 'mobooking'),
                'toCollectOn'    => __('To collect', 'mobooking'),
                'phoneRequired'   => __('A phone number is required.', 'mobooking'),
                'sendSheet'   => __('Send day sheet', 'mobooking'),
                'resend'      => __('Resend day sheet', 'mobooking'),
                'sheetSent'   => __('Sheet sent', 'mobooking'),
                'sheetChanged'=> __('changed since sent', 'mobooking'),
                'sendConfirm' => __('Send the day sheet now?', 'mobooking'),
                'notSent'     => __('Not sent', 'mobooking'),
                'sentOk'      => __('Sent ✓', 'mobooking'),
                'testMode'    => __('Test mode:', 'mobooking'),
                'testGoesTo'  => __('this will go to', 'mobooking'),
                'notTheCoach' => __('not the coach', 'mobooking'),
                'allSheetsTo'   => __('every day sheet will go to', 'mobooking'),
                'notTheCoaches' => __('not the coaches', 'mobooking'),
                'guest'         => __('Guest', 'mobooking'),
                // Partial coach time + block-out release (v1.4.1).
                'coachTime'       => __('Coach time', 'mobooking'),
                'due'             => __('Due', 'mobooking'),
                'coachFee'        => __('Coach fee', 'mobooking'),
                'overrideCoachFee'=> __('Override coach fee', 'mobooking'),
                'saveCoachFee'    => __('Save coach fee', 'mobooking'),
                'coachFeeOverridden' => __('Coach fee overridden', 'mobooking'),
                'coachClamped'    => __('Coach time was shortened to fit the new session.', 'mobooking'),
                'scopeThis'       => __('This session only', 'mobooking'),
                'scopeFollowing'  => __('This and all following', 'mobooking'),
                'applyTo'         => __('Apply to', 'mobooking'),
                'slotBlocked'     => __('This slot is blocked', 'mobooking'),
                'bookOverReason'  => __('Reason for booking over the blockout', 'mobooking'),
                'bookOverReasonRequired' => __('A reason is required to book over a blockout.', 'mobooking'),
                'released'        => __('Released', 'mobooking'),
                'releasedFrom'    => __('from', 'mobooking'),
                'wholeDay'        => __('whole day', 'mobooking'),
                'releaseThisDate' => __('Release this date', 'mobooking'),
                'alreadyReleased' => __('Already released', 'mobooking'),
                'releaseWholeDay' => __('Free the whole day', 'mobooking'),
                'endedEarlyAt'    => __('Ended early at', 'mobooking'),
                'howMuch'         => __('How much', 'mobooking'),
                'release'         => __('Release', 'mobooking'),
                'deleteWholeBlockout' => __('Delete whole blockout', 'mobooking'),
                'recurringBlockout'   => __('Recurring blockout', 'mobooking'),
                'editOnClosures'  => __('edit it on the Closures screen', 'mobooking'),
                'note'            => __('Note', 'mobooking'),
                'undoRelease'     => __('Undo release', 'mobooking'),
                'undoReleaseHint' => __('This re-applies the blockout for this date. It will refuse if a booking now sits in the released window.', 'mobooking'),
                // Created time + coupon (v1.4.2).
                'booked'          => __('Booked', 'mobooking'),
                'srcOnline'       => __('online', 'mobooking'),
                'srcManual'       => __('manual', 'mobooking'),
                'coupon'          => __('Coupon', 'mobooking'),
                'credit'          => __('Credit', 'mobooking'),
                'paidOnlineHint'  => __('Customer paid %s online. Use Cancel + refund to refund their card.', 'mobooking'),
                // Prepaid passes (v1.5.0).
                'pass'            => __('Pass', 'mobooking'),
                'passes'          => __('Passes', 'mobooking'),
                'paidByPass'      => __('Paid by pass', 'mobooking'),
                'passCode'        => __('Pass code', 'mobooking'),
                'drawFromPass'    => __('Draw from pass', 'mobooking'),
                'returnToPass'    => __('Return hours to pass', 'mobooking'),
                'passCodeNeeded'  => __('Enter a pass code.', 'mobooking'),
                'coveredByPass'   => __('covered by pass', 'mobooking'),
                'passEmpty'       => __('pass empty: due at counter', 'mobooking'),
                'passInvalid'     => __('That pass code is invalid or expired.', 'mobooking'),
                'passLookupHint'  => __('Look up a pass to check its balance or void it.', 'mobooking'),
                'checkBalance'    => __('Check balance', 'mobooking'),
                'expires'         => __('expires', 'mobooking'),
                'voidPass'        => __('Void', 'mobooking'),
                'voidConfirm'     => __('Void this pass? The code stops working now.', 'mobooking'),
                'voided'          => __('Voided.', 'mobooking'),
                'reissuePass'     => __('Void + reissue', 'mobooking'),
                'newCode'         => __('New code:', 'mobooking'),
                'issuePass'       => __('Issue a pass', 'mobooking'),
                'issuePassHint'   => __('Take payment on Square first, then issue the pass and record the receipt.', 'mobooking'),
                'hours'           => __('Hours', 'mobooking'),
                'expiryMonths'    => __('Expiry (months, blank = default)', 'mobooking'),
                'posRef'          => __('POS receipt / reference', 'mobooking'),
                'posRefNeeded'    => __('A POS receipt / reference is required.', 'mobooking'),
                'passHoursNeeded' => __('Enter the hours.', 'mobooking'),
                'passIssued'      => __('Pass issued:', 'mobooking'),
                'copy'            => __('Copy code', 'mobooking'),
                'copied'          => __('Copied', 'mobooking'),
                'left'            => __('left', 'mobooking'),
                'covers'          => __('covers', 'mobooking'),
                'leftAfter'       => __('left after', 'mobooking'),
                'dueAtCounter'    => __('Due at counter:', 'mobooking'),
                'fromPass'        => __('from pass', 'mobooking'),
                // Coach roster (v1.5.1).
                'lanesView'       => __('Lanes', 'mobooking'),
                'rosterView'      => __('Roster', 'mobooking'),
                'day'             => __('Day', 'mobooking'),
                'week'            => __('Week', 'mobooking'),
                'weekOf'          => __('Week of', 'mobooking'),
                'weekTotals'      => __('Week totals', 'mobooking'),
                'showAllCoaches'  => __('Show all coaches', 'mobooking'),
                'noSessions'      => __('No sessions', 'mobooking'),
                'noRate'          => __('no rate set', 'mobooking'),
                'noOpen'          => __('Closed this day.', 'mobooking'),
                'laneTime'        => __('lane', 'mobooking'),
                'send'            => __('Send', 'mobooking'),
                'sendAll'         => __('Send day sheets to all', 'mobooking'),
                'sendAllConfirm'  => __('Send day sheets to every coach with sessions that day?', 'mobooking'),
                'sentToN'         => __('Sent to %n coaches', 'mobooking'),
                'skippedN'        => __('skipped %m (no sessions)', 'mobooking'),
                'noEmailList'     => __('no email:', 'mobooking'),
                'failed'          => __('failed', 'mobooking'),
                'printWeek'       => __('Print week', 'mobooking'),
            ],
        ];

        wp_add_inline_script(self::HANDLE, 'window.mclbManage = ' . wp_json_encode($data) . ';', 'before');
    }

    private static function container_html($ctx = 'front') {
        $cls = 'mclb-manage mclb-manage--' . ($ctx === 'admin' ? 'admin' : 'front');
        return '<div class="' . esc_attr($cls) . '" data-today="' . esc_attr(wp_date('Y-m-d')) . '">'
            . '<p class="mclb-manage__loading">' . esc_html__('Loading…', 'mobooking') . '</p>'
            . '</div>';
    }
}
