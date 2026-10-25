<?php
/**
 * Guest "Manage booking" page (Phase 1.4.3, WEB-17). A no-login, signed-link page
 * where a customer cancels their own booking(s) under the SAME tiered policy as
 * logged-in self-cancel (MCLB_Cancel). Reverses the 30 Sep "guests always contact
 * admin" rule, per WEB-17.
 *
 * URL: home_url('/?mclb_manage=<token>')  (MCLB_Manage_Token)
 * States: list → confirm (names lane/date/time + the money outcome) → result.
 *
 * Security: no-store + noindex + no-referrer headers, DONOTCACHEPAGE, rate-limited,
 * a CSRF nonce on the cancel POST, and a confirm step. The page only ever shows
 * bookings that share the token booking's order or series (same customer), and the
 * link stops acting once the booking is cancelled or past.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Manage_Booking {

    const QV = 'mclb_manage';

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_render']);
        // Never let a page cache serve this personalised page.
        add_filter('rocket_cache_reject_uri', [__CLASS__, 'rocket_reject']);
        add_filter('rocket_cache_query_strings', [__CLASS__, 'rocket_reject_qs']);
    }

    public static function rocket_reject($uris) {
        // Any URL carrying our token is personalised — never cache it.
        $uris[] = '/(.*)' . self::QV . '=(.*)';
        return $uris;
    }
    public static function rocket_reject_qs($qs) {
        // Ensure the param isn't treated as a cacheable query string.
        return array_values(array_diff((array) $qs, [self::QV]));
    }

    public static function maybe_render() {
        if (!isset($_GET[self::QV]) && !(isset($_POST[self::QV]))) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        // Headers: block all caching + indexing + referrer leakage.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);

        if (self::rate_limited()) {
            status_header(429);
            self::page(__('Please wait', 'mclb-lane-booking'), '<p>' . esc_html__('Too many requests — please wait a moment and try again.', 'mclb-lane-booking') . '</p>');
            exit;
        }

        if ((int) MCLB_Settings::get('guest_self_cancel') !== 1) {
            self::invalid();
        }

        // POST = perform a cancel (confirm step already shown).
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handle_cancel();
            exit;
        }

        $token = sanitize_text_field(wp_unslash($_GET[self::QV]));
        $id    = MCLB_Manage_Token::verify($token);
        if (!$id) { self::invalid(); }
        $b = MCLB_Bookings::get((int) $id);
        if (!$b) { self::invalid(); }

        $confirm = isset($_GET['confirm']) ? absint($_GET['confirm']) : 0;
        if ($confirm) {
            $target = self::sibling($b, $confirm);
            if (!$target) { self::invalid(); }
            self::render_confirm($token, $b, $target);
            exit;
        }
        self::render_list($token, $b);
        exit;
    }

    // ── Cancel (POST from the confirm step) ────────────────────────────────────

    private static function handle_cancel() {
        $token = isset($_POST[self::QV]) ? sanitize_text_field(wp_unslash($_POST[self::QV])) : '';
        $id    = MCLB_Manage_Token::verify($token);
        if (!$id || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'mclb_guest_cancel')) {
            self::invalid();
        }
        $anchor = MCLB_Bookings::get((int) $id);
        $target = $anchor ? self::sibling($anchor, isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0) : null;
        if (!$target) { self::invalid(); }

        if (!self::is_cancellable($target)) {
            self::page(__('Booking', 'mclb-lane-booking'), '<p>' . esc_html__('This booking can no longer be cancelled online.', 'mclb-lane-booking') . '</p>');
            exit;
        }

        $label = trim((string) $target->customer_name) ?: __('customer', 'mclb-lane-booking');
        $res   = MCLB_Cancel::self_cancel($target, $label, [
            'notify'     => true,
            'actor_note' => __('via email link', 'mclb-lane-booking'), // addition e
            'via_link'   => true, // adds the "didn't cancel this?" safety line to the email
        ]);

        if (!empty($res['blocked'])) {
            self::page(__('Booking', 'mclb-lane-booking'), self::blocked_html());
            exit;
        }
        if (empty($res['ok'])) {
            self::page(__('Booking', 'mclb-lane-booking'), '<p>' . esc_html__('Sorry — that booking could not be cancelled. Please contact the club.', 'mclb-lane-booking') . '</p>');
            exit;
        }
        $body  = '<p class="mclb-mb-ok">' . esc_html__('Your booking has been cancelled.', 'mclb-lane-booking') . '</p>';
        $body .= self::detail_html($target);
        $line  = MCLB_Emails::outcome_customer_text($res['outcome']);
        if ($line !== '') { $body .= '<p><strong>' . esc_html($line) . '</strong></p>'; }
        $body .= '<p class="mclb-mb-sub">' . esc_html__('This link no longer cancels that booking. You can close this page.', 'mclb-lane-booking') . '</p>';
        self::page(__('Booking cancelled', 'mclb-lane-booking'), $body);
        exit;
    }

    // ── Views ──────────────────────────────────────────────────────────────────

    private static function render_list($token, $anchor) {
        $sibs = self::siblings($anchor);
        $body = '<p>' . sprintf(esc_html__('Hi %s, here are your bookings:', 'mclb-lane-booking'), esc_html(self::holder_name($anchor))) . '</p>';
        foreach ($sibs as $b) {
            $body .= '<div class="mclb-mb-card">' . self::detail_html($b);
            if (self::is_cancellable($b)) {
                $url   = add_query_arg(['mclb_manage' => $token, 'confirm' => (int) $b->id], home_url('/'));
                $body .= '<p><a class="mclb-mb-btn" href="' . esc_url($url) . '">' . esc_html__('Cancel this booking', 'mclb-lane-booking') . '</a></p>';
            } else {
                $body .= '<p class="mclb-mb-sub">' . esc_html(self::not_cancellable_reason($b)) . '</p>';
            }
            $body .= '</div>';
        }
        self::page(__('Manage your booking', 'mclb-lane-booking'), $body);
    }

    private static function render_confirm($token, $anchor, $target) {
        if (!self::is_cancellable($target)) {
            self::page(__('Booking', 'mclb-lane-booking'), self::detail_html($target) . '<p class="mclb-mb-sub">' . esc_html(self::not_cancellable_reason($target)) . '</p>');
            return;
        }
        $tier = MCLB_Cancel::self_cancel_tier($target)['tier'];
        if ($tier === 'blocked') {
            self::page(__('Booking', 'mclb-lane-booking'), self::detail_html($target) . self::blocked_html());
            return;
        }
        $when = self::when_label($target);
        $q    = sprintf(
            /* translators: 1: lane, 2: when. */
            __('Cancel %1$s, %2$s?', 'mclb-lane-booking'),
            (string) $target->lane_name,
            $when
        );
        $body  = self::detail_html($target);
        $body .= '<p><strong>' . esc_html($q) . '</strong></p>';
        $pv = self::preview_text($target, $tier);
        if ($pv !== '') { $body .= '<p>' . esc_html($pv) . '</p>'; }
        $body .= '<form method="post" action="' . esc_url(home_url('/')) . '">';
        $body .= '<input type="hidden" name="' . self::QV . '" value="' . esc_attr($token) . '">';
        $body .= '<input type="hidden" name="booking_id" value="' . (int) $target->id . '">';
        $body .= wp_nonce_field('mclb_guest_cancel', '_wpnonce', true, false);
        $body .= '<button type="submit" class="mclb-mb-btn mclb-mb-btn--danger">' . esc_html__('Yes, cancel this booking', 'mclb-lane-booking') . '</button> ';
        $body .= '<a class="mclb-mb-back" href="' . esc_url(add_query_arg('mclb_manage', $token, home_url('/'))) . '">' . esc_html__('Back', 'mclb-lane-booking') . '</a>';
        $body .= '</form>';
        self::page(__('Confirm cancellation', 'mclb-lane-booking'), $body);
    }

    // ── Policy preview (future tense, before cancelling) ───────────────────────

    private static function preview_text($b, $tier) {
        $money = class_exists('MCLB_Coaches') ? MCLB_Coaches::money((float) $b->price) : (string) $b->price;
        if (!MCLB_Cancel::is_online_paid($b)) {
            $paid_counter = ($b->counter_paid_at !== null && $b->counter_paid_at !== '0000-00-00 00:00:00' && $b->counter_due !== null);
            if ($paid_counter) { return __('The club will arrange any refund with you directly.', 'mclb-lane-booking'); }
            if ((string) $b->source === 'manual') { return __('Please contact the club about any payment on this booking.', 'mclb-lane-booking'); }
            return '';
        }
        if ($tier === 'refund') {
            // Only promise a card refund if the order's gateway can actually do one
            // automatically (COD etc. can't) — otherwise the club arranges it.
            return self::gateway_can_refund($b)
                ? sprintf(__('You’ll receive a %s refund to your card.', 'mclb-lane-booking'), $money)
                : sprintf(__('A refund of %s will be arranged by the club.', 'mclb-lane-booking'), $money);
        }
        // credit tier
        $days = (int) MCLB_Settings::get('credit_expiry_days');
        $exp  = $days > 0 ? ' ' . sprintf(__('(expires %s)', 'mclb-lane-booking'), wp_date('j M Y', time() + $days * DAY_IN_SECONDS)) : '';
        return sprintf(__('You’ll receive %1$s in store credit%2$s to use on a future booking.', 'mclb-lane-booking'), $money, $exp);
    }

    /** Can the order's payment gateway refund automatically (supports 'refunds')? */
    private static function gateway_can_refund($b) {
        if (empty($b->order_id) || !function_exists('wc_get_order')) { return false; }
        $order = wc_get_order((int) $b->order_id);
        if (!$order) { return false; }
        $gw = function_exists('wc_get_payment_gateway_by_order') ? wc_get_payment_gateway_by_order($order) : null;
        return ($gw && $gw->supports('refunds'));
    }

    private static function blocked_html() {
        $cutoff = (int) MCLB_Settings::get('self_cancel_hours');
        $phone  = MCLB_Settings::club_phone();
        $msg    = $phone !== ''
            ? sprintf(__('This booking is within %1$d hours of its start time and can’t be cancelled online. Call us on %2$s to discuss.', 'mclb-lane-booking'), $cutoff, $phone)
            : sprintf(__('This booking is within %d hours of its start time and can’t be cancelled online. Please contact the club to discuss.', 'mclb-lane-booking'), $cutoff);
        return '<p class="mclb-mb-sub">' . esc_html($msg) . '</p>';
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /** Confirmed + future? (what the link may still act on). */
    private static function is_cancellable($b) {
        if (!$b || (string) $b->status !== MCLB_Bookings::STATUS_CONFIRMED) { return false; }
        return (string) $b->starts_at > current_time('mysql');
    }

    private static function not_cancellable_reason($b) {
        if ((string) $b->status === MCLB_Bookings::STATUS_CANCELLED) { return __('Already cancelled.', 'mclb-lane-booking'); }
        if ((string) $b->starts_at <= current_time('mysql')) { return __('This booking has passed.', 'mclb-lane-booking'); }
        return __('This booking can’t be cancelled online.', 'mclb-lane-booking');
    }

    /** The bookings a token may show/act on: the anchor's order OR series mates. */
    private static function siblings($anchor) {
        global $wpdb;
        $bt = MCLB_Bookings::table();
        if (!empty($anchor->series_id)) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $bt WHERE series_id = %d ORDER BY starts_at, id", (int) $anchor->series_id));
        } elseif (!empty($anchor->order_id)) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $bt WHERE order_id = %d ORDER BY starts_at, id", (int) $anchor->order_id));
        } else {
            $rows = [$anchor];
        }
        // Never surface cancelled/past noise beyond the anchor itself.
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->id === (int) $anchor->id || self::is_cancellable($r)) { $out[] = $r; }
        }
        return $out ?: [$anchor];
    }

    /** A sibling by id (same order/series as the token's booking), or null. */
    private static function sibling($anchor, $target_id) {
        $target_id = (int) $target_id;
        if ($target_id === (int) $anchor->id) { return $anchor; }
        foreach (self::siblings($anchor) as $r) {
            if ((int) $r->id === $target_id) { return $r; }
        }
        return null;
    }

    private static function holder_name($b) {
        $name = trim((string) $b->customer_name);
        if ($name === '') { return __('there', 'mclb-lane-booking'); }
        $parts = preg_split('/\s+/', $name);
        $first = $parts[0];
        $last  = (count($parts) > 1) ? ' ' . strtoupper(substr(end($parts), 0, 1)) . '.' : '';
        return $first . $last;
    }

    private static function when_label($b) {
        $tz = wp_timezone();
        $tf = get_option('time_format') ?: 'g:i a';
        $s  = (new DateTimeImmutable((string) $b->starts_at, $tz))->getTimestamp();
        $e  = (new DateTimeImmutable((string) $b->ends_at, $tz))->getTimestamp();
        return wp_date('D j M Y', $s) . ', ' . wp_date($tf, $s) . ' – ' . wp_date($tf, $e);
    }

    private static function detail_html($b) {
        $res  = MCLB_Settings::get('resource_label_singular') ?: 'Lane';
        $out  = '<div class="mclb-mb-detail">';
        $out .= '<div class="mclb-mb-when">' . esc_html(self::when_label($b)) . '</div>';
        $out .= '<div class="mclb-mb-lane">' . esc_html($res . ': ' . $b->lane_name) . '</div>';
        if ((int) $b->assigned_coach_id > 0 && class_exists('MCLB_Coaches')) {
            $tz = wp_timezone(); $tf = get_option('time_format') ?: 'g:i a';
            list($cs, $ce) = MCLB_Bookings::coach_window($b->starts_at, $b->ends_at, $b->coach_starts_at, $b->coach_ends_at);
            $hrs = wp_date($tf, (new DateTimeImmutable((string) $cs, $tz))->getTimestamp()) . ' – ' . wp_date($tf, (new DateTimeImmutable((string) $ce, $tz))->getTimestamp());
            $out .= '<div class="mclb-mb-coach">' . esc_html(MCLB_Coaches::label_singular() . ': ' . MCLB_Coaches::label((int) $b->assigned_coach_id) . ', ' . $hrs) . '</div>';
        }
        return $out . '</div>';
    }

    private static function invalid() {
        status_header(404);
        self::page(__('Link not valid', 'mclb-lane-booking'), '<p>' . esc_html__('This link isn’t valid. If you need to change a booking, please contact the club.', 'mclb-lane-booking') . '</p>');
        exit;
    }

    private static function rate_limited() {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0';
        $key = 'mclb_mb_rl_' . md5($ip);
        $n   = (int) get_transient($key);
        if ($n >= 30) { return true; } // 30 hits / 5 min per IP
        set_transient($key, $n + 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    /** The site's custom logo (or site icon) as a plain <img>, or '' — no link. */
    private static function logo_html() {
        $lid = (int) get_theme_mod('custom_logo');
        if ($lid) {
            $img = wp_get_attachment_image($lid, 'medium', false, [
                'style' => 'max-height:64px;width:auto;height:auto;', 'alt' => esc_attr(get_bloginfo('name')),
            ]);
            if ($img) { return $img; }
        }
        if (function_exists('has_site_icon') && has_site_icon()) {
            return '<img src="' . esc_url(get_site_icon_url(128)) . '" alt="" style="width:56px;height:56px;border-radius:12px;">';
        }
        return '';
    }

    /** A standalone, branded page (we own the whole response). Styles inline. */
    private static function page($title, $body) {
        $club  = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) ?: 'Cricketers Club';
        $logo  = self::logo_html();
        $phone = MCLB_Settings::club_phone();
        // The caller sets any non-200 status (invalid/rate-limited) before this.
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title . ' — ' . $club); ?></title>
<style>
  :root{color-scheme:light}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;background:#f6f7f9;line-height:1.45;}
  .mclb-mb-wrap{max-width:560px;margin:0 auto;padding:24px 16px 40px;}
  .mclb-mb-head{text-align:center;margin:8px 0 20px;}
  .mclb-mb-head img{display:inline-block;}
  .mclb-mb-club{font-size:17px;font-weight:700;margin:8px 0 0;}
  h1{font-size:21px;margin:0 0 16px;}
  .mclb-mb-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:16px;margin:0 0 14px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
  .mclb-mb-detail{margin:0 0 6px;}
  .mclb-mb-when{font-weight:700;font-size:16px;}
  .mclb-mb-lane,.mclb-mb-coach{color:#444;font-size:14px;}
  .mclb-mb-sub{color:#777;font-size:14px;}
  .mclb-mb-ok{color:#1a7f37;font-weight:700;font-size:16px;}
  .mclb-mb-btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;font-size:15px;border:0;cursor:pointer;}
  .mclb-mb-btn--danger{background:#b32d2e;}
  .mclb-mb-back{display:inline-block;margin-left:10px;color:#444;}
  .mclb-mb-foot{margin-top:28px;text-align:center;color:#888;font-size:13px;border-top:1px solid #eee;padding-top:16px;}
  .mclb-mb-foot a{color:#555;}
</style>
</head>
<body>
<div class="mclb-mb-wrap">
  <div class="mclb-mb-head">
    <?php
    if ($logo !== '') {
        echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image / escaped icon URL.
    }
    ?>
    <p class="mclb-mb-club"><?php echo esc_html($club); ?></p>
  </div>
  <h1><?php echo esc_html($title); ?></h1>
  <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts above. ?>
  <div class="mclb-mb-foot">
    <?php
    if ($phone !== '') {
        printf(
            /* translators: 1: club name, 2: phone number. */
            esc_html__('%1$s · Call us on %2$s', 'mclb-lane-booking'),
            esc_html($club),
            esc_html($phone)
        );
    } else {
        echo esc_html($club);
    }
    ?>
  </div>
</div>
</body>
</html>
        <?php
    }
}
