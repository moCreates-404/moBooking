<?php
/**
 * Public "View my day" page (Phase 7d). A signed token (?mclb_day_token=) opens a
 * static, server-rendered, no-login page showing ONLY that coach's sessions for
 * one date — a mini timeline (lanes they're on × their hours) plus a plain list
 * for phones. No JS, no actions, no other coaches' data. Noindex + no-store +
 * no-referrer (the token is in the query string).
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Coachday {

    public static function init() {
        // Early, before the theme renders — we take over the response entirely.
        add_action('template_redirect', [__CLASS__, 'maybe_render'], 0);
    }

    public static function maybe_render() {
        if (empty($_GET['mclb_day_token'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token IS the capability.
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);

        $token = sanitize_text_field(wp_unslash($_GET['mclb_day_token'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $data  = MCLB_Daysheet_Token::verify($token);
        if (!$data) {
            status_header(403);
            self::page(__('This link has expired', 'mclb-lane-booking'), '<p>' . esc_html__('This day-sheet link is no longer valid. Ask the club for a fresh one.', 'mclb-lane-booking') . '</p>');
            exit;
        }

        status_header(200);
        self::render_day((int) $data['coach_id'], $data['date']);
        exit;
    }

    private static function render_day($coach_id, $date) {
        $tz    = wp_timezone();
        $rows  = MCLB_Daysheets::coaches_with_sessions($date)[$coach_id] ?? [];
        $name  = MCLB_Coaches::label($coach_id);
        $first = preg_split('/\s+/', trim($name))[0] ?? $name;
        $label = wp_date('l j F Y', (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp());
        $tf    = get_option('time_format') ?: 'g:i a';

        if (empty($rows)) {
            self::page(
                $first . ' — ' . $label,
                '<p>' . esc_html__('No sessions scheduled for this day.', 'mclb-lane-booking') . '</p>'
            );
            return;
        }

        $mins = static function ($dt) use ($tz, $date) {
            $m = (int) floor(((new DateTimeImmutable($dt, $tz))->getTimestamp() - (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp()) / 60);
            return max(0, min(1440, $m));
        };

        // Columns = the lanes this coach is on; rows span the earliest→latest hour.
        $lanes = [];
        $minM  = 1440;
        $maxM  = 0;
        foreach ($rows as $r) {
            $lanes[(int) $r->lane_id] = $r->lane_name;
            $minM = min($minM, $mins($r->starts_at));
            $maxM = max($maxM, $mins($r->ends_at));
        }
        asort($lanes);
        $laneIdx = array_flip(array_keys($lanes));
        $top     = (int) (floor($minM / 60) * 60);
        $bot     = (int) (ceil($maxM / 60) * 60);
        if ($bot <= $top) {
            $bot = $top + 60;
        }
        $ppm   = 0.9; // px per minute
        $colW  = 120;
        $gut   = 56;
        $head  = 22; // space for the lane-name headers
        $pad   = 16; // bottom breathing room so the last hour tick isn't clipped

        // Timeline. Height = header band + hours + bottom pad, so it sizes to its
        // content and nothing is cut off by an inner scrollbar.
        $tlH = $head + ($bot - $top) * $ppm + $pad;
        $tl  = '<div class="mclb-cd-tlwrap"><div class="mclb-cd-tl" style="height:' . $tlH . 'px;width:' . ($gut + count($lanes) * $colW) . 'px;">';
        // hour ticks
        for ($m = $top; $m <= $bot; $m += 60) {
            $tlabel = wp_date($tf, (new DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+' . $m . ' minutes')->getTimestamp());
            $tl    .= '<div class="mclb-cd-tick" style="top:' . ($head + ($m - $top) * $ppm) . 'px;">' . esc_html($tlabel) . '</div>';
        }
        // lane headers
        foreach ($lanes as $lid => $lname) {
            $x   = $gut + $laneIdx[$lid] * $colW;
            $tl .= '<div class="mclb-cd-colhead" style="left:' . $x . 'px;width:' . $colW . 'px;">' . esc_html($lname) . '</div>';
        }
        // session blocks
        foreach ($rows as $r) {
            $s        = $mins($r->starts_at);
            $e        = $mins($r->ends_at);
            $x        = $gut + $laneIdx[(int) $r->lane_id] * $colW;
            $customer = self::fmt($r->customer_name);
            $note     = (string) $r->admin_note;
            $tl .= '<div class="mclb-cd-block" style="top:' . ($head + ($s - $top) * $ppm) . 'px;height:' . (max($e - $s, 30) * $ppm - 2) . 'px;left:' . ($x + 2) . 'px;width:' . ($colW - 6) . 'px;">'
                . '<strong>' . esc_html(wp_date($tf, (new DateTimeImmutable($r->starts_at, $tz))->getTimestamp())) . '</strong>'
                . ($customer !== '' ? '<br>' . esc_html($customer) : '')
                . ($note !== '' ? '<br><span class="mclb-cd-note">' . esc_html($note) . '</span>' : '')
                . '</div>';
        }
        $tl .= '</div></div>';

        // Plain list (phone-first).
        $li = '';
        foreach ($rows as $r) {
            $customer = self::fmt($r->customer_name);
            $note     = (string) $r->admin_note;
            $li .= '<li><span class="mclb-cd-time">'
                . esc_html(wp_date($tf, (new DateTimeImmutable($r->starts_at, $tz))->getTimestamp()) . ' – ' . wp_date($tf, (new DateTimeImmutable($r->ends_at, $tz))->getTimestamp()))
                . '</span> · ' . esc_html($r->lane_name)
                . ($customer !== '' ? ' · ' . esc_html($customer) : '')
                . ($note !== '' ? '<br><span class="mclb-cd-note">' . esc_html($note) . '</span>' : '')
                . '</li>';
        }

        $body = '<ul class="mclb-cd-list">' . $li . '</ul>' . $tl;
        self::page($first . ' — ' . $label, $body, $name . ' · ' . $label);
    }

    private static function fmt($raw) {
        return MCLB_Daysheets::format_name($raw);
    }

    /** A standalone minimal HTML document (we own the whole response here). */
    private static function page($title, $body, $heading = '') {
        $club = get_bloginfo('name') ?: 'Cricketers Club';
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?></title>
<style>
  :root{color-scheme:light}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;background:#f6f7f9;}
  .mclb-cd-wrap{max-width:820px;margin:0 auto;padding:16px;}
  .mclb-cd-club{font-size:14px;color:#666;margin:0 0 2px;}
  h1{font-size:20px;margin:0 0 14px;}
  .mclb-cd-list{list-style:none;margin:0 0 20px;padding:0;background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;}
  .mclb-cd-list li{padding:12px 14px;border-bottom:1px solid #eee;font-size:15px;}
  .mclb-cd-list li:last-child{border-bottom:none;}
  .mclb-cd-time{font-weight:700;}
  .mclb-cd-note{color:#777;font-size:13px;}
  /* Timeline is an enhancement for wider screens; the list always covers phones. */
  .mclb-cd-tlwrap{display:none;overflow-x:auto;background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:8px;}
  .mclb-cd-tl{position:relative;}
  .mclb-cd-tick{position:absolute;left:0;width:48px;transform:translateY(-6px);font-size:11px;color:#888;text-align:right;}
  .mclb-cd-colhead{position:absolute;top:0;height:18px;font-size:12px;font-weight:700;text-align:center;color:#444;}
  .mclb-cd-block{position:absolute;background:#eaf2ff;border:1px solid #c7dbff;border-radius:6px;padding:3px 6px;font-size:12px;line-height:1.3;overflow:hidden;}
  @media(min-width:600px){ .mclb-cd-tlwrap{display:block;} }
</style>
</head>
<body>
<div class="mclb-cd-wrap">
  <p class="mclb-cd-club"><?php echo esc_html($club); ?></p>
  <h1><?php echo esc_html($heading !== '' ? $heading : $title); ?></h1>
  <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts above. ?>
</div>
</body>
</html>
        <?php
    }
}
