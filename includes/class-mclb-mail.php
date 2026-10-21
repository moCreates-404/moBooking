<?php
/**
 * Shared mailer (Phase 8a-2) — one send path for every plugin email (customer
 * confirmations, the "your booking has moved" email, staff alerts). Day sheets
 * keep their own renderer but now read the SAME global test recipient here.
 *
 * Dev safety (the key invariant): nothing is sent unless the site is production
 * OR a global "email test recipient" is set, and when a test recipient is set
 * EVERY message is rerouted to it with a "[TEST → real@addr]" subject. So a
 * non-production site can never email a real customer.
 *
 * Transport is wp_mail (Gravity SMTP on this install). Customer emails are
 * wrapped in WooCommerce's branded header/footer so they match the order emails.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Mail {

    const LOG_OPTION = 'mclb_email_log';
    const LOG_MAX    = 60;

    /** @var string|null plain-text alternative for the in-flight email. */
    private static $alt = null;

    public static function init() {
        add_action('phpmailer_init', [__CLASS__, 'inject_alt']);
    }

    /**
     * The one global test recipient. Falls back to the old day-sheet value so an
     * install that only ever set that keeps working (transparent migration); the
     * sanitiser copies it across on the next settings save.
     */
    public static function test_recipient() {
        $t = sanitize_email((string) MCLB_Settings::get('email_test_recipient'));
        if ($t === '') {
            $t = sanitize_email((string) MCLB_Settings::get('daysheet_test_recipient'));
        }
        return $t;
    }

    /** Real sends only on production, or whenever a test recipient is set. */
    public static function can_send() {
        return wp_get_environment_type() === 'production' || self::test_recipient() !== '';
    }

    public static function from() {
        $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) ?: 'Cricketers Club';
        return $name . ' <' . get_option('admin_email') . '>';
    }

    /**
     * Send one email through the shared guard + reroute + log.
     *
     * @param string $to      the intended (real) recipient.
     * @param string $subject subject (before any TEST prefix).
     * @param string $html    body HTML (inner content; wrapped per $opts).
     * @param array  $opts     heading, reply_to, cc, plain (AltBody), branded (WC
     *                         header/footer), tag (log label).
     * @return array{ok:bool,result:string,recipient?:string}
     */
    public static function send($to, $subject, $html, array $opts = []) {
        $to  = sanitize_email((string) $to);
        $tag = (string) ($opts['tag'] ?? '');
        if ($to === '') {
            return ['ok' => false, 'result' => 'no-recipient'];
        }
        if (!self::can_send()) {
            self::log($tag, $to, 'blocked-nonprod');
            return ['ok' => false, 'result' => 'blocked-nonprod'];
        }

        $real = $to;
        $test = self::test_recipient();
        if ($test !== '') {
            $to      = $test;
            $subject = '[TEST → ' . $real . '] ' . $subject;
        }

        $heading = (string) ($opts['heading'] ?? '');
        $body    = !empty($opts['branded'])
            ? self::wrap_wc($heading !== '' ? $heading : $subject, $html)
            : self::wrap_plain($heading, $html);

        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . self::from()];
        if (!empty($opts['reply_to'])) {
            $rt = sanitize_email((string) $opts['reply_to']);
            if ($rt !== '') { $headers[] = 'Reply-To: ' . $rt; }
        }
        // CC only on genuine sends (never when rerouted to the test address).
        if ($test === '' && !empty($opts['cc'])) {
            $cc = sanitize_email((string) $opts['cc']);
            if ($cc !== '') { $headers[] = 'Cc: ' . $cc; }
        }

        self::$alt = isset($opts['plain']) ? (string) $opts['plain'] : wp_strip_all_tags($html);
        $ok        = wp_mail($to, $subject, $body, $headers);
        self::$alt = null;

        self::log($tag, $real, $ok ? 'sent' : 'failed');
        return ['ok' => (bool) $ok, 'result' => $ok ? 'sent' : 'failed', 'recipient' => $real];
    }

    /** Wrap inner HTML in WooCommerce's branded header/footer + inline its CSS,
     *  exactly as WooCommerce does when it sends its own order emails (so our
     *  customer emails match). style_inline() runs WC's Emogrifier pass — without
     *  it the template's <style> block is stripped by mail clients and the email
     *  renders as unstyled serif text. */
    public static function wrap_wc($heading, $html) {
        if (function_exists('WC') && WC() && method_exists(WC()->mailer(), 'wrap_message')) {
            $wrapped = WC()->mailer()->wrap_message((string) $heading, $html);
            if (class_exists('WC_Email')) {
                $inliner = new WC_Email();
                return (string) $inliner->style_inline($wrapped);
            }
            return (string) apply_filters('woocommerce_mail_content', $wrapped);
        }
        return self::wrap_plain($heading, $html);
    }

    private static function wrap_plain($heading, $html) {
        $h = $heading !== '' ? '<h2 style="font-family:sans-serif;color:#222;">' . esc_html($heading) . '</h2>' : '';
        return '<div style="font-family:sans-serif;color:#222;max-width:600px;margin:0 auto;">' . $h . $html . '</div>';
    }

    /** Attach the plain-text alternative when PHPMailer is the transport. */
    public static function inject_alt($phpmailer) {
        if (self::$alt !== null) {
            $phpmailer->AltBody = self::$alt;
        }
    }

    /** Rolling in-memory log (option), newest first, capped. */
    public static function log($tag, $recipient, $result) {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) { $log = []; }
        array_unshift($log, [
            'at'        => current_time('mysql'),
            'tag'       => (string) $tag,
            'recipient' => (string) $recipient,
            'result'    => (string) $result,
        ]);
        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_MAX), false);
    }
}
