<?php
/**
 * Admin booking view (Phase 5) — the hub staff use to see, cancel/refund, and
 * assign coaches to lane bookings. A filterable list (date range, lane, status,
 * search) matching the plugin's existing admin patterns; each row's actions call
 * the shared MCLB_Refunds helper (so admin cancels are double-fire-safe too).
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Bookings_Admin {

    const SLUG = 'mclb-bookings-view';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_mclb_admin_cancel', [$this, 'handle_cancel']);
        add_action('admin_post_mclb_assign_coach', [$this, 'handle_assign_coach']);
    }

    public function menu() {
        add_submenu_page(
            MCLB_Admin::PAGE,
            __('Bookings', 'mclb-lane-booking'),
            __('Bookings', 'mclb-lane-booking'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            1 // just under Settings
        );
    }

    private function base_url() {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    // ── Write handlers ─────────────────────────────────────────────────────────

    public function handle_cancel() {
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'mclb_admin_cancel')) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }
        $id   = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
        $mode = (isset($_POST['mode']) && $_POST['mode'] === 'refund') ? 'refund' : 'norefund';

        if ($mode === 'refund') {
            $res    = MCLB_Refunds::cancel_and_refund($id, __('Admin cancel + refund', 'mclb-lane-booking'));
            $notice = empty($res['ok']) ? 'nochange' : (!empty($res['refunded']) ? 'refunded' : 'cancelled_norefund');
        } else {
            $notice = MCLB_Bookings::claim_cancel($id) ? 'cancelled' : 'nochange';
        }
        wp_safe_redirect(add_query_arg('mclb_msg', $notice, $this->base_url()));
        exit;
    }

    public function handle_assign_coach() {
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'mclb_assign_coach')) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }
        $id       = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
        $coach_id = isset($_POST['coach_id']) ? absint($_POST['coach_id']) : 0;
        MCLB_Bookings::set_coach($id, $coach_id);
        wp_safe_redirect(add_query_arg('mclb_msg', 'coach', $this->base_url()));
        exit;
    }

    // ── Screen ─────────────────────────────────────────────────────────────────

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters.
        $f = [
            'status'    => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'lane_id'   => isset($_GET['lane_id']) ? absint($_GET['lane_id']) : 0,
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to'   => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
            'search'    => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
        ];
        // phpcs:enable

        echo '<div class="wrap"><h1>' . esc_html__('Bookings', 'mclb-lane-booking') . '</h1>';
        $this->notice();
        $this->filters($f);

        $rows = MCLB_Bookings::query($f);
        if (empty($rows)) {
            echo '<p>' . esc_html__('No bookings match.', 'mclb-lane-booking') . '</p></div>';
            return;
        }

        $tz          = wp_timezone();
        $df          = get_option('date_format') ?: 'j M Y';
        $tf          = get_option('time_format') ?: 'g:i a';
        $coach_on    = (int) MCLB_Settings::get('enable_coach_requests') === 1;
        $coach_opts  = $coach_on ? MCLB_Bookings::coach_options() : [];
        $res_label   = MCLB_Settings::get('resource_label_singular') ?: __('Resource', 'mclb-lane-booking');

        echo '<table class="widefat striped"><thead><tr>';
        printf(
            '<th>#</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
            esc_html($res_label),
            esc_html__('When', 'mclb-lane-booking'),
            esc_html__('Customer', 'mclb-lane-booking'),
            esc_html__('Status', 'mclb-lane-booking'),
            esc_html__('Order', 'mclb-lane-booking'),
            esc_html__('Actions', 'mclb-lane-booking')
        );
        echo '</tr></thead><tbody>';

        foreach ($rows as $b) {
            $start = new DateTimeImmutable($b->starts_at, $tz);
            $end   = new DateTimeImmutable($b->ends_at, $tz);
            $when  = wp_date($df, $start->getTimestamp()) . ', ' . wp_date($tf, $start->getTimestamp()) . ' – ' . wp_date($tf, $end->getTimestamp());
            $cust  = trim(($b->customer_name ?: '') . ($b->customer_email ? ' <' . $b->customer_email . '>' : ''));

            echo '<tr>';
            printf('<td>%d</td>', (int) $b->id);
            printf('<td>%s</td>', esc_html($b->lane_name));
            printf('<td>%s</td>', esc_html($when));
            printf('<td>%s</td>', esc_html($cust !== '' ? $cust : __('Guest', 'mclb-lane-booking')));
            printf('<td>%s</td>', esc_html(ucfirst($b->status)));
            if ($b->order_id) {
                printf('<td><a href="%s">#%d</a></td>', esc_url(admin_url('post.php?post=' . (int) $b->order_id . '&action=edit')), (int) $b->order_id);
            } else {
                echo '<td>' . esc_html__('—', 'mclb-lane-booking') . '</td>';
            }

            echo '<td>';
            if ($b->status === MCLB_Bookings::STATUS_CONFIRMED) {
                $this->cancel_forms((int) $b->id);
                if ($coach_on && (int) $b->coach_requested === 1) {
                    $this->coach_form($b, $coach_opts);
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function filters($f) {
        $lanes = MCLB_Lane::all_bookable();
        echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:end">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        printf('<label>%s<br><input type="date" name="date_from" value="%s"></label>', esc_html__('From', 'mclb-lane-booking'), esc_attr($f['date_from']));
        printf('<label>%s<br><input type="date" name="date_to" value="%s"></label>', esc_html__('To', 'mclb-lane-booking'), esc_attr($f['date_to']));
        echo '<label>' . esc_html(MCLB_Settings::get('resource_label_singular') ?: 'Lane') . '<br><select name="lane_id"><option value="0">' . esc_html__('All', 'mclb-lane-booking') . '</option>';
        foreach ($lanes as $lane) {
            printf('<option value="%d" %s>%s</option>', (int) $lane->ID, selected($f['lane_id'], (int) $lane->ID, false), esc_html(get_the_title($lane)));
        }
        echo '</select></label>';
        echo '<label>' . esc_html__('Status', 'mclb-lane-booking') . '<br><select name="status">';
        echo '<option value="">' . esc_html__('All', 'mclb-lane-booking') . '</option>';
        foreach (['confirmed', 'held', 'cancelled'] as $s) {
            printf('<option value="%s" %s>%s</option>', esc_attr($s), selected($f['status'], $s, false), esc_html(ucfirst($s)));
        }
        echo '</select></label>';
        printf('<label>%s<br><input type="search" name="search" value="%s"></label>', esc_html__('Search', 'mclb-lane-booking'), esc_attr($f['search']));
        submit_button(__('Filter', 'mclb-lane-booking'), 'secondary', '', false);
        echo '</form>';
    }

    private function cancel_forms($id) {
        // Cancel + refund
        printf('<form method="post" action="%s" style="display:inline" onsubmit="return confirm(%s)">', esc_url(admin_url('admin-post.php')), esc_attr('"' . esc_js(__('Cancel this booking and refund the customer?', 'mclb-lane-booking')) . '"'));
        echo '<input type="hidden" name="action" value="mclb_admin_cancel"><input type="hidden" name="mode" value="refund">';
        printf('<input type="hidden" name="booking_id" value="%d">', $id);
        wp_nonce_field('mclb_admin_cancel');
        printf('<button class="button button-small">%s</button>', esc_html__('Cancel + refund', 'mclb-lane-booking'));
        echo '</form> ';
        // Cancel without refund
        printf('<form method="post" action="%s" style="display:inline" onsubmit="return confirm(%s)">', esc_url(admin_url('admin-post.php')), esc_attr('"' . esc_js(__('Cancel this booking WITHOUT refunding?', 'mclb-lane-booking')) . '"'));
        echo '<input type="hidden" name="action" value="mclb_admin_cancel"><input type="hidden" name="mode" value="norefund">';
        printf('<input type="hidden" name="booking_id" value="%d">', $id);
        wp_nonce_field('mclb_admin_cancel');
        printf('<button class="button button-small">%s</button>', esc_html__('Cancel only', 'mclb-lane-booking'));
        echo '</form>';
    }

    private function coach_form($b, $coach_opts) {
        echo '<div style="margin-top:6px">';
        if (empty($coach_opts)) {
            printf('<small>%s</small>', esc_html__('Coach requested — no coaches available (wire the mclb_coach_options filter).', 'mclb-lane-booking'));
            echo '</div>';
            return;
        }
        printf('<form method="post" action="%s" style="display:inline">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="mclb_assign_coach">';
        printf('<input type="hidden" name="booking_id" value="%d">', (int) $b->id);
        wp_nonce_field('mclb_assign_coach');
        echo '<select name="coach_id"><option value="0">' . esc_html__('— Coach requested —', 'mclb-lane-booking') . '</option>';
        foreach ($coach_opts as $cid => $label) {
            printf('<option value="%d" %s>%s</option>', (int) $cid, selected((int) $b->assigned_coach_id, (int) $cid, false), esc_html($label));
        }
        echo '</select> ';
        printf('<button class="button button-small">%s</button>', esc_html__('Assign', 'mclb-lane-booking'));
        echo '</form></div>';
    }

    private function notice() {
        if (empty($_GET['mclb_msg'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $map = [
            'refunded'           => __('Booking cancelled and refunded.', 'mclb-lane-booking'),
            'cancelled'          => __('Booking cancelled.', 'mclb-lane-booking'),
            'cancelled_norefund' => __('Booking cancelled — but the refund did not go through; refund manually (see the order note).', 'mclb-lane-booking'),
            'coach'              => __('Coach updated.', 'mclb-lane-booking'),
            'nochange'           => __('No change — the booking was not in a cancellable state.', 'mclb-lane-booking'),
        ];
        $key = sanitize_key(wp_unslash($_GET['mclb_msg'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($map[$key])) {
            $class = $key === 'nochange' ? 'notice-warning' : 'notice-success';
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($map[$key]));
        }
    }
}
