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

    const SLUG     = 'mclb-bookings-view';
    const ADD_SLUG = 'mclb-add-booking';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_mclb_admin_cancel', [$this, 'handle_cancel']);
        add_action('admin_post_mclb_assign_coach', [$this, 'handle_assign_coach']);
        add_action('admin_post_mclb_add_booking', [$this, 'handle_add']);
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
        add_submenu_page(
            MCLB_Admin::PAGE,
            __('Add booking', 'mclb-lane-booking'),
            __('Add booking', 'mclb-lane-booking'),
            'manage_options',
            self::ADD_SLUG,
            [$this, 'render_add'],
            2
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

    // ── Manual booking entry ─────────────────────────────────────────────────

    public function handle_add() {
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'mclb_add_booking')) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }
        $add_url = admin_url('admin.php?page=' . self::ADD_SLUG);

        $lane_id  = isset($_POST['lane_id']) ? absint($_POST['lane_id']) : 0;
        $date     = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $start    = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
        $end      = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
        $override = !empty($_POST['override']);
        $name     = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $email    = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $note     = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : '';

        $lane = get_post($lane_id);
        $re   = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!$lane || $lane->post_type !== 'mclb_lane' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match($re, $start) || !preg_match($re, $end)) {
            wp_safe_redirect(add_query_arg('mclb_msg', 'add_invalid', $add_url));
            exit;
        }
        $starts_at = "$date $start:00";
        $ends_at   = "$date $end:00";
        if ($ends_at <= $starts_at) {
            wp_safe_redirect(add_query_arg('mclb_msg', 'add_invalid', $add_url));
            exit;
        }

        // Availability (hours/closures/past) is override-able; a real double-book is not.
        if (!$override && !MCLB_Availability::is_range_available($lane_id, $starts_at, $ends_at)) {
            wp_safe_redirect(add_query_arg('mclb_msg', 'add_unavailable', $add_url));
            exit;
        }

        $tz       = wp_timezone();
        $hours    = (new DateTimeImmutable($ends_at, $tz))->getTimestamp() - (new DateTimeImmutable($starts_at, $tz))->getTimestamp();
        $duration = $hours / 3600;
        $price    = round((float) MCLB_Lane::get_price($lane_id) * $duration, 2);

        $res = MCLB_Bookings::insert_confirmed_locked([
            'lane_id'        => $lane_id,
            'lane_name'      => get_the_title($lane_id),
            'starts_at'      => $starts_at,
            'ends_at'        => $ends_at,
            'price'          => $price,
            'customer_name'  => $name,
            'customer_email' => $email,
            'admin_note'     => $note,
        ]);

        if (!empty($res['ok'])) {
            $msg = 'add_ok';
        } elseif (!empty($res['lock_error'])) {
            $msg = 'add_busy';
        } else {
            $msg = 'add_conflict'; // real double-book, blocked even with override
        }
        wp_safe_redirect(add_query_arg('mclb_msg', $msg, $add_url));
        exit;
    }

    public function render_add() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Add booking', 'mclb-lane-booking') . '</h1>';
        $this->notice();
        echo '<p>' . esc_html__('Writes a confirmed booking directly (no cart or payment). A real double-booking is always blocked; tick “override” to book outside normal hours or a closure.', 'mclb-lane-booking') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mclb_add_booking">';
        wp_nonce_field('mclb_add_booking');
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html(MCLB_Settings::get('resource_label_singular') ?: __('Lane', 'mclb-lane-booking')) . '</th><td><select name="lane_id" required>';
        foreach (MCLB_Lane::all_bookable() as $lane) {
            printf('<option value="%d">%s</option>', (int) $lane->ID, esc_html(get_the_title($lane)));
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Date', 'mclb-lane-booking') . '</th><td><input type="date" name="date" required></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Time', 'mclb-lane-booking') . '</th><td><input type="time" name="start_time" required> &ndash; <input type="time" name="end_time" required></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Customer', 'mclb-lane-booking') . '</th><td><input type="text" class="regular-text" name="customer_name" placeholder="' . esc_attr__('Name (optional)', 'mclb-lane-booking') . '"> <input type="email" name="customer_email" placeholder="' . esc_attr__('Email (optional)', 'mclb-lane-booking') . '"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Note', 'mclb-lane-booking') . '</th><td><textarea name="admin_note" rows="2" class="large-text"></textarea></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Override', 'mclb-lane-booking') . '</th><td><label><input type="checkbox" name="override" value="1"> ' . esc_html__('Allow outside opening hours / closures (double-books are still blocked)', 'mclb-lane-booking') . '</label></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Add booking', 'mclb-lane-booking'));
        echo '</form></div>';
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
            'refunded'           => ['success', __('Booking cancelled and refunded.', 'mclb-lane-booking')],
            'cancelled'          => ['success', __('Booking cancelled.', 'mclb-lane-booking')],
            'cancelled_norefund' => ['warning', __('Booking cancelled — but the refund did not go through; refund manually (see the order note).', 'mclb-lane-booking')],
            'coach'              => ['success', __('Coach updated.', 'mclb-lane-booking')],
            'nochange'           => ['warning', __('No change — the booking was not in a cancellable state.', 'mclb-lane-booking')],
            'add_ok'             => ['success', __('Booking added.', 'mclb-lane-booking')],
            'add_conflict'       => ['error', __('That lane is already booked for part of that time — not added.', 'mclb-lane-booking')],
            'add_unavailable'    => ['error', __('That time is outside opening hours or during a closure. Tick “override” to book it anyway.', 'mclb-lane-booking')],
            'add_busy'           => ['error', __('The system was busy — please try again.', 'mclb-lane-booking')],
            'add_invalid'        => ['error', __('Please check the lane, date and times.', 'mclb-lane-booking')],
        ];
        $key = sanitize_key(wp_unslash($_GET['mclb_msg'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($map[$key])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($map[$key][0]), esc_html($map[$key][1]));
        }
    }
}
