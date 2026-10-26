<?php
/**
 * Admin "Passes" screen (Phase 1.5.0). Full pass management for administrators:
 * list + search, issue, adjust (± hours, admin-only), void/reissue, per-pass
 * ledger, CSV export, and a counter "check balance" lookup. Booking Staff do the
 * counter operations (issue / void / reissue / balance) from the front Manage
 * view instead, since they have no wp-admin access.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Passes_Admin {

    const SLUG = 'mclb-passes';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_mclb_pass_issue',   [$this, 'handle_issue']);
        add_action('admin_post_mclb_pass_adjust',  [$this, 'handle_adjust']);
        add_action('admin_post_mclb_pass_void',    [$this, 'handle_void']);
        add_action('admin_post_mclb_pass_reissue', [$this, 'handle_reissue']);
        add_action('admin_post_mclb_pass_csv',     [$this, 'handle_csv']);
    }

    public function menu() {
        add_submenu_page(MCLB_Admin::PAGE, __('Passes', 'mclb-lane-booking'), __('Passes', 'mclb-lane-booking'), 'manage_options', self::SLUG, [$this, 'render'], 3);
    }

    private function base_url() { return admin_url('admin.php?page=' . self::SLUG); }

    private function money($n) { return class_exists('MCLB_Coaches') ? MCLB_Coaches::money((float) $n) : number_format((float) $n, 2); }
    private function hrs($n)   { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.') . 'h'; }

    // ── Write handlers ─────────────────────────────────────────────────────────

    public function handle_issue() {
        if (!current_user_can('manage_options') || !check_admin_referer('mclb_pass_issue')) { wp_die(esc_html__('Permission denied.', 'mclb-lane-booking')); }
        $months = isset($_POST['expiry_months']) && $_POST['expiry_months'] !== '' ? absint($_POST['expiry_months']) : MCLB_Passes::default_expiry_months();
        $expires = $months > 0 ? (new DateTimeImmutable('now', wp_timezone()))->modify('+' . $months . ' months')->format('Y-m-d H:i:s') : null;
        $res = MCLB_Passes::issue([
            'holder_name'  => sanitize_text_field(wp_unslash($_POST['holder_name'] ?? '')),
            'holder_email' => sanitize_email(wp_unslash($_POST['holder_email'] ?? '')),
            'holder_phone' => sanitize_text_field(wp_unslash($_POST['holder_phone'] ?? '')),
            'hours'        => (float) ($_POST['hours'] ?? 0),
            'expires_at'   => $expires,
            'note'         => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
            'pos_ref'      => sanitize_text_field(wp_unslash($_POST['pos_ref'] ?? '')),
            'created_by'   => $this->initials(),
        ]);
        if (is_wp_error($res)) { $this->redirect(['mclb_msg' => 'err', 'mclb_err' => $res->get_error_message()]); }
        // Optional "email the code" to the holder.
        if (!empty($_POST['email_code']) && $res->holder_email && class_exists('MCLB_Mail')) {
            $this->email_code($res);
        }
        $this->redirect(['mclb_msg' => 'issued', 'code' => $res->code]);
    }

    public function handle_adjust() {
        if (!current_user_can('manage_options') || !check_admin_referer('mclb_pass_adjust')) { wp_die(esc_html__('Permission denied.', 'mclb-lane-booking')); }
        $res = MCLB_Passes::adjust(absint($_POST['pass_id'] ?? 0), (float) ($_POST['delta'] ?? 0), sanitize_text_field(wp_unslash($_POST['reason'] ?? '')), $this->initials());
        $this->redirect(is_wp_error($res) ? ['mclb_msg' => 'err', 'mclb_err' => $res->get_error_message()] : ['mclb_msg' => 'adjusted', 'pass' => absint($_POST['pass_id'] ?? 0)]);
    }

    public function handle_void() {
        if (!current_user_can('manage_options') || !check_admin_referer('mclb_pass_void')) { wp_die(esc_html__('Permission denied.', 'mclb-lane-booking')); }
        $res = MCLB_Passes::void(absint($_POST['pass_id'] ?? 0), $this->initials(), __('voided by admin', 'mclb-lane-booking'));
        $this->redirect(is_wp_error($res) ? ['mclb_msg' => 'err', 'mclb_err' => $res->get_error_message()] : ['mclb_msg' => 'voided']);
    }

    public function handle_reissue() {
        if (!current_user_can('manage_options') || !check_admin_referer('mclb_pass_reissue')) { wp_die(esc_html__('Permission denied.', 'mclb-lane-booking')); }
        $res = MCLB_Passes::reissue(absint($_POST['pass_id'] ?? 0), $this->initials());
        $this->redirect(is_wp_error($res) ? ['mclb_msg' => 'err', 'mclb_err' => $res->get_error_message()] : ['mclb_msg' => 'reissued', 'code' => $res->code]);
    }

    public function handle_csv() {
        if (!current_user_can('manage_options') || !check_admin_referer('mclb_pass_csv')) { wp_die(esc_html__('Permission denied.', 'mclb-lane-booking')); }
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . MCLB_Passes::table() . ' ORDER BY created_at DESC');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mclb-passes-' . gmdate('Ymd') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Code', 'Holder', 'Email', 'Phone', 'Issued hours', 'Balance', 'Status', 'Expires', 'POS ref', 'Created by', 'Created']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->code, $r->holder_name, $r->holder_email, $r->holder_phone, $r->hours_total, MCLB_Passes::balance((int) $r->id), $r->status, $r->expires_at, $r->pos_ref, $r->created_by, $r->created_at]);
        }
        fclose($out);
        exit;
    }

    private function initials() {
        $u = wp_get_current_user();
        $name = trim((string) ($u->display_name ?: $u->user_login));
        $ini = '';
        foreach (preg_split('/\s+/', $name) as $p) { if ($p !== '') { $ini .= strtoupper(substr($p, 0, 1)); } }
        return mb_substr($ini ?: strtoupper(substr($name, 0, 2)), 0, 20);
    }

    private function email_code($pass) {
        $heading = __('Your prepaid pass', 'mclb-lane-booking');
        $body  = '<p>' . sprintf(esc_html__('Your prepaid pass code is %s.', 'mclb-lane-booking'), '<strong>' . esc_html($pass->code) . '</strong>') . '</p>';
        $body .= '<p>' . sprintf(esc_html__('Balance: %sh.', 'mclb-lane-booking'), esc_html(rtrim(rtrim(number_format((float) MCLB_Passes::balance((int) $pass->id), 2), '0'), '.'))) . '</p>';
        if ($pass->expires_at) {
            $body .= '<p style="color:#666;">' . sprintf(esc_html__('Use it to BOOK before %s — your sessions can be later.', 'mclb-lane-booking'), esc_html(wp_date('j M Y', strtotime((string) $pass->expires_at)))) . '</p>';
        }
        MCLB_Mail::send($pass->holder_email, $heading . ' — ' . (wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'Cricketers Club'), $body, ['heading' => $heading, 'branded' => true, 'tag' => 'pass-issued']);
    }

    private function redirect($args) { wp_safe_redirect(add_query_arg($args, $this->base_url())); exit; }

    // ── Screen ─────────────────────────────────────────────────────────────────

    public function render() {
        if (!current_user_can('manage_options')) { return; }
        $view = isset($_GET['pass']) ? absint($_GET['pass']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="wrap"><h1>' . esc_html__('Passes', 'mclb-lane-booking') . '</h1>';
        $this->notice();
        if ($view) { $this->render_one($view); echo '</div>'; return; }
        $this->issue_form();
        $this->list_table();
        echo '</div>';
    }

    private function issue_form() {
        echo '<h2>' . esc_html__('Issue a pass', 'mclb-lane-booking') . '</h2>';
        echo '<p class="description">' . esc_html__('Take payment on the Square POS first, then issue the pass and record the POS reference. The code shows once below — copy it or email it to the holder.', 'mclb-lane-booking') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mclb_pass_issue">';
        wp_nonce_field('mclb_pass_issue');
        echo '<table class="form-table"><tbody>';
        printf('<tr><th>%s</th><td><input type="text" name="holder_name" class="regular-text"></td></tr>', esc_html__('Holder name', 'mclb-lane-booking'));
        printf('<tr><th>%s</th><td><input type="email" name="holder_email" class="regular-text"> <input type="tel" name="holder_phone" placeholder="%s"></td></tr>', esc_html__('Email / phone', 'mclb-lane-booking'), esc_attr__('Phone', 'mclb-lane-booking'));
        printf('<tr><th>%s</th><td><input type="number" name="hours" min="1" step="1" required style="width:90px"> %s</td></tr>', esc_html__('Hours', 'mclb-lane-booking'), esc_html__('whole lane hours', 'mclb-lane-booking'));
        printf('<tr><th>%s</th><td><input type="number" name="expiry_months" min="0" step="1" value="%d" style="width:90px"> %s</td></tr>', esc_html__('Expiry', 'mclb-lane-booking'), (int) MCLB_Passes::default_expiry_months(), esc_html__('months (0 = never)', 'mclb-lane-booking'));
        printf('<tr><th>%s</th><td><input type="text" name="pos_ref" class="regular-text" required placeholder="%s"></td></tr>', esc_html__('POS receipt / reference', 'mclb-lane-booking'), esc_attr__('Square sale ID / receipt #', 'mclb-lane-booking'));
        printf('<tr><th>%s</th><td><input type="text" name="note" class="large-text"></td></tr>', esc_html__('Note', 'mclb-lane-booking'));
        printf('<tr><th>%s</th><td><label><input type="checkbox" name="email_code" value="1" checked> %s</label></td></tr>', esc_html__('Email the code', 'mclb-lane-booking'), esc_html__('to the holder (if an email is set)', 'mclb-lane-booking'));
        echo '</tbody></table>';
        submit_button(__('Issue pass', 'mclb-lane-booking'));
        echo '</form><hr>';
    }

    private function list_table() {
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        printf('<input type="search" name="s" value="%s" placeholder="%s"> ', esc_attr($s), esc_attr__('code, name or email', 'mclb-lane-booking'));
        submit_button(__('Search', 'mclb-lane-booking'), 'secondary', '', false);
        printf(' <a class="button" href="%s">%s</a>', esc_url(wp_nonce_url(admin_url('admin-post.php?action=mclb_pass_csv'), 'mclb_pass_csv')), esc_html__('Export CSV', 'mclb-lane-booking'));
        echo '</form>';
        $where = '1=1'; $params = [];
        if ($s !== '') { $where .= ' AND (code LIKE %s OR holder_name LIKE %s OR holder_email LIKE %s)'; $like = '%' . $wpdb->esc_like($s) . '%'; $params = [$like, $like, $like]; }
        $sql  = 'SELECT * FROM ' . MCLB_Passes::table() . " WHERE $where ORDER BY created_at DESC LIMIT 200";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Code', 'Holder', 'Balance', 'Issued', 'Status', 'Expires', 'POS ref', ''] as $h) { printf('<th>%s</th>', esc_html($h)); }
        echo '</tr></thead><tbody>';
        if (!$rows) { echo '<tr><td colspan="8">' . esc_html__('No passes.', 'mclb-lane-booking') . '</td></tr>'; }
        foreach ((array) $rows as $r) {
            $bal = MCLB_Passes::balance((int) $r->id);
            printf(
                '<tr><td><code>%s</code></td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
                esc_html($r->code),
                esc_html($r->holder_name ?: '—'),
                $r->holder_email ? '<br><small>' . esc_html($r->holder_email) . '</small>' : '',
                esc_html($this->hrs($bal)),
                esc_html($this->hrs($r->hours_total)),
                esc_html($r->status),
                esc_html($r->expires_at ? wp_date('j M Y', strtotime((string) $r->expires_at)) : '—'),
                esc_html($r->pos_ref ?: '—'),
                esc_url(add_query_arg(['page' => self::SLUG, 'pass' => (int) $r->id], admin_url('admin.php'))),
                esc_html__('View', 'mclb-lane-booking')
            );
        }
        echo '</tbody></table>';
    }

    private function render_one($id) {
        $p = MCLB_Passes::get($id);
        if (!$p) { echo '<p>' . esc_html__('Pass not found.', 'mclb-lane-booking') . '</p>'; return; }
        $bal = MCLB_Passes::balance((int) $p->id);
        printf('<p><a href="%s">&larr; %s</a></p>', esc_url($this->base_url()), esc_html__('All passes', 'mclb-lane-booking'));
        echo '<h2><code>' . esc_html($p->code) . '</code> — ' . esc_html($this->hrs($bal)) . ' ' . esc_html__('available', 'mclb-lane-booking') . '</h2>';
        echo '<p>' . esc_html(sprintf('%s · %s · %s', $p->holder_name ?: '—', $p->holder_email ?: '—', $p->holder_phone ?: '—')) . '</p>';
        echo '<p>' . esc_html(sprintf(__('Issued %1$s · status %2$s · expires %3$s · POS %4$s · by %5$s', 'mclb-lane-booking'),
            $this->hrs($p->hours_total), $p->status, $p->expires_at ? wp_date('j M Y', strtotime((string) $p->expires_at)) : 'never', $p->pos_ref ?: '—', $p->created_by ?: '—')) . '</p>';

        if ((string) $p->status === 'active') {
            // Adjust (admin only)
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0"><input type="hidden" name="action" value="mclb_pass_adjust"><input type="hidden" name="pass_id" value="' . (int) $p->id . '">';
            wp_nonce_field('mclb_pass_adjust');
            printf('%s <input type="number" step="0.5" name="delta" style="width:90px" placeholder="±h"> <input type="text" name="reason" placeholder="%s" class="regular-text"> ', esc_html__('Adjust hours:', 'mclb-lane-booking'), esc_attr__('reason (required)', 'mclb-lane-booking'));
            submit_button(__('Apply adjustment', 'mclb-lane-booking'), 'secondary', '', false);
            echo '</form>';
            // Void + reissue
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js(__('Void this pass? The code stops working immediately.', 'mclb-lane-booking')) . '\')"><input type="hidden" name="action" value="mclb_pass_void"><input type="hidden" name="pass_id" value="' . (int) $p->id . '">';
            wp_nonce_field('mclb_pass_void'); submit_button(__('Void', 'mclb-lane-booking'), 'delete', '', false); echo '</form> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js(__('Void this code and issue a new one with the same balance?', 'mclb-lane-booking')) . '\')"><input type="hidden" name="action" value="mclb_pass_reissue"><input type="hidden" name="pass_id" value="' . (int) $p->id . '">';
            wp_nonce_field('mclb_pass_reissue'); submit_button(__('Void + reissue', 'mclb-lane-booking'), 'secondary', '', false); echo '</form>';
        }

        // Ledger
        echo '<h3>' . esc_html__('Ledger', 'mclb-lane-booking') . '</h3><table class="widefat striped"><thead><tr>';
        foreach (['When', 'Type', 'Hours', 'Booking', 'Actor', 'Reason'] as $h) { printf('<th>%s</th>', esc_html($h)); }
        echo '</tr></thead><tbody>';
        foreach (MCLB_Passes::ledger_for_pass((int) $p->id) as $l) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(wp_date('j M Y H:i', strtotime((string) $l->created_at))),
                esc_html($l->type),
                esc_html(($l->hours > 0 ? '+' : '') . rtrim(rtrim(number_format((float) $l->hours, 2), '0'), '.')),
                $l->booking_id ? esc_html('#' . (int) $l->booking_id) : ($l->hold_id ? esc_html('hold ' . (int) $l->hold_id) : '—'),
                esc_html($l->actor ?: '—'),
                esc_html($l->reason ?: '—')
            );
        }
        echo '</tbody></table>';
    }

    private function notice() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['mclb_msg'])) { return; }
        $key  = sanitize_key(wp_unslash($_GET['mclb_msg']));
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $err  = isset($_GET['mclb_err']) ? sanitize_text_field(wp_unslash($_GET['mclb_err'])) : '';
        // phpcs:enable
        $map = [
            'issued'   => ['success', sprintf(__('Pass issued: %s — copy it or share it with the holder.', 'mclb-lane-booking'), $code)],
            'reissued' => ['success', sprintf(__('Reissued. New code: %s (old code voided).', 'mclb-lane-booking'), $code)],
            'adjusted' => ['success', __('Balance adjusted.', 'mclb-lane-booking')],
            'voided'   => ['success', __('Pass voided.', 'mclb-lane-booking')],
            'err'      => ['error', $err !== '' ? $err : __('Something went wrong.', 'mclb-lane-booking')],
        ];
        if (isset($map[$key])) { printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($map[$key][0]), esc_html($map[$key][1])); }
    }
}
