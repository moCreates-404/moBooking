<?php
/**
 * Coach day sheets (Phase 7d) — a daily email to each coach listing their
 * sessions, plus a signed "View my day" link. Scheduling is Action Scheduler
 * (bundled with WooCommerce); sending is wp_mail (Gravity SMTP on this install).
 *
 * Dev safety: a "test recipient" setting reroutes EVERY sheet to one address with
 * a "[TEST → coach]" subject. On top of that, real-coach sends are refused unless
 * wp_get_environment_type() === 'production' OR a test recipient is set — so a
 * non-production site can never email real coaches by accident.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Daysheets {

    const CRON_HOOK  = 'mclb_daysheet_run';
    const LOG_OPTION = 'mclb_daysheet_log';
    const LOG_DAYS   = 30;

    /** @var string|null plain-text alternative for the in-flight email. */
    private static $alt = null;

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled']);
        add_action('update_option_' . MCLB_OPTION, [__CLASS__, 'reschedule']);
        add_action('phpmailer_init', [__CLASS__, 'inject_alt']);
    }

    // ── Settings accessors ────────────────────────────────────────────────────
    private static function on()        { return (int) MCLB_Settings::get('daysheet_enabled') === 1; }
    private static function send_time() { $t = MCLB_Settings::get('daysheet_send_time'); return preg_match('/^\d{2}:\d{2}$/', (string) $t) ? $t : '19:00'; }
    private static function target()    { return MCLB_Settings::get('daysheet_target') === 'today' ? 'today' : 'tomorrow'; }
    private static function names()     { $n = MCLB_Settings::get('daysheet_names'); return in_array($n, ['full', 'first_initial', 'none'], true) ? $n : 'full'; }
    private static function cc()        { return sanitize_email((string) MCLB_Settings::get('daysheet_cc')); }
    private static function test_recipient() { return sanitize_email((string) MCLB_Settings::get('daysheet_test_recipient')); }
    private static function expiry()    { $h = (int) MCLB_Settings::get('daysheet_expiry_hours'); return ($h >= 1 && $h <= 720) ? $h : 48; }

    /** The date (Y-m-d) a run covers, in site time. */
    public static function target_date() {
        $now = new DateTimeImmutable('now', wp_timezone());
        return self::target() === 'today' ? $now->format('Y-m-d') : $now->modify('+1 day')->format('Y-m-d');
    }

    // ── Scheduling (Action Scheduler) ──────────────────────────────────────────

    public static function as_available() {
        return function_exists('as_schedule_recurring_action') && function_exists('as_unschedule_all_actions');
    }

    private static function next_run_ts() {
        $tz  = wp_timezone();
        $t   = self::send_time();
        $run = new DateTimeImmutable((new DateTimeImmutable('now', $tz))->format('Y-m-d') . ' ' . $t . ':00', $tz);
        if ($run->getTimestamp() <= time()) {
            $run = $run->modify('+1 day');
        }
        return $run->getTimestamp();
    }

    public static function schedule() {
        if (!self::as_available()) {
            return;
        }
        as_unschedule_all_actions(self::CRON_HOOK, [], 'mclb');
        if (self::on()) {
            as_schedule_recurring_action(self::next_run_ts(), DAY_IN_SECONDS, self::CRON_HOOK, [], 'mclb');
        }
    }
    public static function unschedule() {
        if (self::as_available()) {
            as_unschedule_all_actions(self::CRON_HOOK, [], 'mclb');
        }
    }
    public static function reschedule() { self::schedule(); }

    public static function run_scheduled() {
        if (self::on()) {
            self::send_for_date(self::target_date(), 'scheduled');
        }
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /** @return array<int,array> coach_id => confirmed booking rows (time order) */
    public static function coaches_with_sessions($date) {
        global $wpdb;
        $bt   = MCLB_Bookings::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $bt
               WHERE status = %s AND assigned_coach_id IS NOT NULL
                 AND starts_at < %s AND ends_at > %s
               ORDER BY assigned_coach_id, starts_at",
            MCLB_Bookings::STATUS_CONFIRMED,
            $date . ' 23:59:59',
            $date . ' 00:00:00'
        ));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->assigned_coach_id][] = $r;
        }
        return $out;
    }

    /** Stable hash of a coach's day, for "changed since sent". */
    public static function day_hash($coach_id, $date) {
        $rows = self::coaches_with_sessions($date)[(int) $coach_id] ?? [];
        $sig  = [];
        foreach ($rows as $r) {
            $sig[] = $r->id . '|' . $r->starts_at . '|' . $r->ends_at . '|' . $r->lane_name . '|' . $r->customer_name . '|' . $r->admin_note;
        }
        sort($sig);
        return md5(implode("\n", $sig));
    }

    /** Customer name formatted per the "names" setting. */
    public static function format_name($raw) {
        $raw = trim((string) $raw);
        $mode = self::names();
        if ($mode === 'none' || $raw === '') {
            return '';
        }
        if ($mode === 'full') {
            return $raw;
        }
        $parts = preg_split('/\s+/', $raw);
        if (count($parts) === 1) {
            return $parts[0];
        }
        return $parts[0] . ' ' . strtoupper(substr(end($parts), 0, 1)) . '.';
    }

    // ── Build (no send) ─────────────────────────────────────────────────────────

    /**
     * Build every coach's sheet for a date WITHOUT sending (preview / dry-run).
     * @return array<int,array{coach_id,name,recipient,subject,html,text,skip}>
     */
    public static function build_all($date) {
        $out = [];
        foreach (self::coaches_with_sessions($date) as $coach_id => $rows) {
            $out[] = self::build_for_coach((int) $coach_id, $rows, $date);
        }
        return $out;
    }

    public static function build_for_coach($coach_id, $rows, $date) {
        $name  = MCLB_Coaches::label($coach_id);
        $first = preg_split('/\s+/', trim($name))[0] ?? $name;
        $test  = self::test_recipient();
        $email = MCLB_Coaches::contact($coach_id);
        $prod  = (wp_get_environment_type() === 'production');

        $recipient = $test !== '' ? $test : $email;
        $skip      = '';
        if ($test === '') {
            if (!$prod) {
                $skip = 'skipped-nonprod';
            } elseif ($email === '') {
                $skip = 'skipped-no-email';
            }
        }

        $date_label = wp_date('l j F Y', (new DateTimeImmutable($date . ' 00:00:00', wp_timezone()))->getTimestamp());
        $base_sub   = sprintf(__('Your sessions — %s', 'mclb-lane-booking'), $date_label);
        $subject    = $test !== '' ? '[TEST → ' . $name . '] ' . $base_sub : $base_sub;
        $url        = MCLB_Daysheet_Token::url($coach_id, $date, self::expiry());

        return [
            'coach_id'  => (int) $coach_id,
            'name'      => $name,
            'recipient' => $recipient,
            'subject'   => $subject,
            'html'      => self::render_html($first, $date_label, $rows, $url),
            'text'      => self::render_text($first, $date_label, $rows, $url),
            'skip'      => $skip,
        ];
    }

    // ── Send ──────────────────────────────────────────────────────────────────

    public static function send_for_date($date, $trigger) {
        $results = [];
        foreach (self::coaches_with_sessions($date) as $coach_id => $rows) {
            $results[] = self::send_one((int) $coach_id, $rows, $date, $trigger);
        }
        return $results;
    }

    public static function send_for_coach($coach_id, $date, $trigger) {
        $rows = self::coaches_with_sessions($date)[(int) $coach_id] ?? [];
        if (empty($rows)) {
            return ['ok' => false, 'result' => 'skipped-no-sessions'];
        }
        return self::send_one((int) $coach_id, $rows, $date, $trigger);
    }

    private static function send_one($coach_id, $rows, $date, $trigger) {
        $built = self::build_for_coach($coach_id, $rows, $date);
        if ($built['skip'] !== '') {
            self::log($coach_id, $built['name'], $date, '', $trigger, $built['skip'], '');
            return ['ok' => false, 'result' => $built['skip']];
        }

        $from_name = get_bloginfo('name') ?: 'Cricketers Club';
        $headers   = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . get_option('admin_email') . '>',
        ];
        // CC only on genuine coach sends (never when rerouted to the test address).
        if (self::test_recipient() === '' && self::cc() !== '') {
            $headers[] = 'Cc: ' . self::cc();
        }

        self::$alt = $built['text'];
        $ok        = wp_mail($built['recipient'], $built['subject'], $built['html'], $headers);
        self::$alt = null;

        $result = $ok ? 'sent' : 'failed';
        self::log($coach_id, $built['name'], $date, $built['recipient'], $trigger, $result, self::day_hash($coach_id, $date));
        return ['ok' => (bool) $ok, 'result' => $result, 'recipient' => $built['recipient']];
    }

    /** Attach the plain-text alternative when PHPMailer is the transport. */
    public static function inject_alt($phpmailer) {
        if (self::$alt !== null) {
            $phpmailer->AltBody = self::$alt;
        }
    }

    // ── Email rendering (email-safe table + inline styles) ─────────────────────

    private static function render_html($first_name, $date_label, $rows, $url) {
        $tz    = wp_timezone();
        $tf    = get_option('time_format') ?: 'g:i a';
        $club  = esc_html(get_bloginfo('name') ?: 'Cricketers Club');
        $cell  = 'style="padding:8px 10px;border-bottom:1px solid #e5e5e5;font-size:14px;color:#222;"';
        $th    = 'style="padding:8px 10px;border-bottom:2px solid #ccc;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#666;text-align:left;"';

        $tr = '';
        foreach ($rows as $r) {
            $s        = new DateTimeImmutable($r->starts_at, $tz);
            $e        = new DateTimeImmutable($r->ends_at, $tz);
            $dur      = max(0, ($e->getTimestamp() - $s->getTimestamp()) / 3600);
            $time     = esc_html(wp_date($tf, $s->getTimestamp()) . ' – ' . wp_date($tf, $e->getTimestamp()));
            $customer = esc_html(self::format_name($r->customer_name));
            $note     = esc_html((string) $r->admin_note);
            $tr .= '<tr>'
                . '<td ' . $cell . '><strong>' . $time . '</strong></td>'
                . '<td ' . $cell . '>' . esc_html($r->lane_name) . '</td>'
                . '<td ' . $cell . '>' . ($customer !== '' ? $customer : '&mdash;') . '</td>'
                . '<td ' . $cell . '>' . esc_html(rtrim(rtrim(number_format($dur, 2), '0'), '.')) . 'h</td>'
                . '<td ' . $cell . '>' . ($note !== '' ? $note : '') . '</td>'
                . '</tr>';
        }

        $btn = '<a href="' . esc_url($url) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:600;font-size:15px;">'
            . esc_html__('View my day', 'mclb-lane-booking') . '</a>';

        ob_start();
        ?>
<div style="max-width:600px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#222;">
  <p style="font-size:18px;font-weight:700;margin:0 0 2px;"><?php echo $club; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></p>
  <p style="font-size:15px;margin:0 0 16px;color:#444;">
    <?php printf(esc_html__('Hi %1$s — your sessions for %2$s:', 'mclb-lane-booking'), esc_html($first_name), esc_html($date_label)); ?>
  </p>
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;margin-bottom:18px;">
    <tr>
      <th <?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php esc_html_e('Time', 'mclb-lane-booking'); ?></th>
      <th <?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html(MCLB_Settings::get('resource_label_singular') ?: 'Lane'); ?></th>
      <th <?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php esc_html_e('Customer', 'mclb-lane-booking'); ?></th>
      <th <?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php esc_html_e('Duration', 'mclb-lane-booking'); ?></th>
      <th <?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php esc_html_e('Note', 'mclb-lane-booking'); ?></th>
    </tr>
    <?php echo $tr; // phpcs:ignore WordPress.Security.EscapeOutput -- cells escaped above. ?>
  </table>
  <p style="margin:0 0 20px;"><?php echo $btn; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
  <p style="font-size:12px;color:#888;border-top:1px solid #eee;padding-top:12px;">
    <?php echo self::footer_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built/escaped in footer_html. ?>
  </p>
</div>
        <?php
        return ob_get_clean();
    }

    private static function render_text($first_name, $date_label, $rows, $url) {
        $tz   = wp_timezone();
        $tf   = get_option('time_format') ?: 'g:i a';
        $club = get_bloginfo('name') ?: 'Cricketers Club';
        $out  = $club . "\n" . sprintf(__('Hi %1$s — your sessions for %2$s:', 'mclb-lane-booking'), $first_name, $date_label) . "\n\n";
        foreach ($rows as $r) {
            $s        = new DateTimeImmutable($r->starts_at, $tz);
            $e        = new DateTimeImmutable($r->ends_at, $tz);
            $customer = self::format_name($r->customer_name);
            $out     .= wp_date($tf, $s->getTimestamp()) . ' – ' . wp_date($tf, $e->getTimestamp())
                . '  ' . $r->lane_name
                . ($customer !== '' ? '  ' . $customer : '')
                . ((string) $r->admin_note !== '' ? '  (' . $r->admin_note . ')' : '')
                . "\n";
        }
        $out .= "\n" . __('View my day:', 'mclb-lane-booking') . ' ' . $url . "\n";
        return $out;
    }

    /** Filterable footer; default is the club name + WC store address. */
    private static function footer_html() {
        $lines = [get_bloginfo('name') ?: 'Cricketers Club'];
        $parts = array_filter([
            get_option('woocommerce_store_address'),
            get_option('woocommerce_store_address_2'),
            get_option('woocommerce_store_city'),
        ]);
        if ($parts) {
            $lines[] = implode(', ', $parts);
        }
        $html = implode('<br>', array_map('esc_html', $lines));
        /* Theme can add a phone line etc. */
        return apply_filters('mclb_daysheet_footer_html', $html);
    }

    // ── Send log (option, pruned 30 days) ──────────────────────────────────────

    public static function log($coach_id, $coach_name, $date, $recipient, $trigger, $result, $hash) {
        $log   = self::get_log();
        $log[] = [
            'coach'    => (int) $coach_id,
            'name'     => $coach_name,
            'date'     => $date,
            'sent_at'  => current_time('mysql'),
            'recipient'=> $recipient,
            'trigger'  => $trigger,
            'result'   => $result,
            'hash'     => $hash,
        ];
        // Prune > LOG_DAYS by sent_at.
        $cut = time() - self::LOG_DAYS * DAY_IN_SECONDS;
        $log = array_values(array_filter($log, function ($e) use ($cut) {
            return strtotime($e['sent_at']) >= $cut;
        }));
        update_option(self::LOG_OPTION, $log, false);
    }

    public static function get_log() {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    /** Most-recent successful send for coach+date, or null. */
    public static function last_sent($coach_id, $date) {
        $best = null;
        foreach (self::get_log() as $e) {
            if ((int) $e['coach'] === (int) $coach_id && $e['date'] === $date && $e['result'] === 'sent') {
                if (!$best || strtotime($e['sent_at']) > strtotime($best['sent_at'])) {
                    $best = $e;
                }
            }
        }
        return $best;
    }

    /** {sent_at, changed} for the Manage view marker. */
    public static function status($coach_id, $date) {
        $last = self::last_sent($coach_id, $date);
        if (!$last) {
            return ['sent_at' => null, 'changed' => false];
        }
        return ['sent_at' => $last['sent_at'], 'changed' => ($last['hash'] !== self::day_hash($coach_id, $date))];
    }
}
