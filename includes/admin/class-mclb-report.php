<?php
/**
 * Lane-utilisation report (Phase 6) — a read-only admin screen showing, per
 * lane over a date range, how many bookable hours existed vs how many were
 * booked, and the resulting utilisation %.
 *
 * It reuses the availability engine (MCLB_Availability::for_day with
 * include_past) so opening hours, recurring blocks and one-off closures are all
 * accounted for exactly as the grid sees them — capacity is "available + booked"
 * slots, booked is "booked" slots, both in whole increments. Because it is
 * slot-based, booked can never exceed capacity, and a manual override booking
 * that lands inside a closure is counted as neither (a documented edge).
 *
 * Note on "today": for the current day the figure includes any live (unpaid)
 * holds as booked, since the engine treats an active hold as occupying the slot.
 * Completed/historical days reflect confirmed bookings only (holds have expired).
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Report {

    const SLUG     = 'mclb-report';
    const MAX_DAYS = 366; // guard against a runaway range.

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu() {
        add_submenu_page(
            MCLB_Admin::PAGE,
            __('Utilisation', 'mclb-lane-booking'),
            __('Utilisation', 'mclb-lane-booking'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            3
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters.
        $today   = wp_date('Y-m-d');
        $default = wp_date('Y-m-01');
        $from    = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : $default;
        $to      = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : $today;
        $type    = isset($_GET['type']) ? sanitize_text_field(wp_unslash($_GET['type'])) : '';
        // phpcs:enable

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = $default; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = $today; }
        if ($to < $from) { $to = $from; }

        $labels = [
            'singular' => MCLB_Settings::get('resource_label_singular') ?: 'Lane',
            'plural'   => MCLB_Settings::get('resource_label_plural') ?: 'Lanes',
        ];

        echo '<div class="wrap"><h1>' . esc_html(sprintf(__('%s utilisation', 'mclb-lane-booking'), $labels['singular'])) . '</h1>';

        $this->filters($from, $to, $type);

        $data = $this->compute($from, $to, $type);
        if ($data === null) {
            printf('<div class="notice notice-error"><p>%s</p></div></div>',
                esc_html(sprintf(__('Please choose a range of %d days or fewer.', 'mclb-lane-booking'), self::MAX_DAYS)));
            return;
        }
        if (empty($data['rows'])) {
            echo '<p>' . esc_html(sprintf(__('No bookable %s for this range.', 'mclb-lane-booking'), strtolower($labels['plural']))) . '</p></div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
        echo '<th>' . esc_html($labels['singular']) . '</th>';
        echo '<th style="text-align:right">' . esc_html__('Available hrs', 'mclb-lane-booking') . '</th>';
        echo '<th style="text-align:right">' . esc_html__('Booked hrs', 'mclb-lane-booking') . '</th>';
        echo '<th style="text-align:right">' . esc_html__('Utilisation', 'mclb-lane-booking') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($data['rows'] as $r) {
            printf(
                '<tr><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>',
                esc_html($r['name']),
                esc_html(number_format_i18n($r['capacity_h'], 1)),
                esc_html(number_format_i18n($r['booked_h'], 1)),
                esc_html($this->pct($r['capacity_h'], $r['booked_h']))
            );
        }

        printf(
            '<tr style="font-weight:600"><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>',
            esc_html__('All', 'mclb-lane-booking'),
            esc_html(number_format_i18n($data['total_capacity_h'], 1)),
            esc_html(number_format_i18n($data['total_booked_h'], 1)),
            esc_html($this->pct($data['total_capacity_h'], $data['total_booked_h']))
        );
        echo '</tbody></table>';

        echo '<p class="description">' . esc_html__('Available hours = opening hours minus closures. Today includes any live holds; completed days are confirmed bookings only.', 'mclb-lane-booking') . '</p>';
        echo '</div>';
    }

    private function filters($from, $to, $type) {
        echo '<form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        echo '<label>' . esc_html__('From', 'mclb-lane-booking') . ' <input type="date" name="date_from" value="' . esc_attr($from) . '"></label> ';
        echo '<label>' . esc_html__('To', 'mclb-lane-booking') . ' <input type="date" name="date_to" value="' . esc_attr($to) . '"></label> ';

        $types = MCLB_Lane::types();
        if (!empty($types)) {
            echo '<label>' . esc_html__('Type', 'mclb-lane-booking') . ' <select name="type"><option value="">' . esc_html__('All', 'mclb-lane-booking') . '</option>';
            foreach ($types as $t) {
                printf('<option value="%s"%s>%s</option>', esc_attr($t), selected($type, $t, false), esc_html($t));
            }
            echo '</select></label> ';
        }
        submit_button(__('Show', 'mclb-lane-booking'), 'secondary', '', false);
        echo '</form>';
    }

    /**
     * @return array{rows:array,total_capacity_h:float,total_booked_h:float}|null
     *         null when the range exceeds MAX_DAYS.
     */
    private function compute($from, $to, $type) {
        $tz    = wp_timezone();
        $start = new DateTimeImmutable($from . ' 00:00:00', $tz);
        $end   = new DateTimeImmutable($to . ' 00:00:00', $tz);
        $days  = (int) $start->diff($end)->days + 1;
        if ($days > self::MAX_DAYS) {
            return null;
        }

        $acc = []; // lane_id => ['name'=>, 'capacity_slots'=>, 'booked_slots'=>]
        $inc = (int) MCLB_Settings::get('booking_increment');
        if ($inc < 1) { $inc = 60; }

        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $day  = $d->format('Y-m-d');
            $data = MCLB_Availability::for_day($day, ['type' => $type, 'increment' => $inc, 'include_past' => true]);
            foreach ($data['lanes'] as $lid => $lane) {
                if (!isset($acc[$lid])) {
                    $acc[$lid] = ['name' => $lane['lane_name'], 'capacity_slots' => 0, 'booked_slots' => 0];
                }
                foreach ($lane['slots'] as $slot) {
                    if ($slot['state'] === 'available' || $slot['state'] === 'booked') {
                        $acc[$lid]['capacity_slots']++;
                        if ($slot['state'] === 'booked') {
                            $acc[$lid]['booked_slots']++;
                        }
                    }
                }
            }
        }

        $rows = [];
        $tot_cap = 0.0;
        $tot_bkd = 0.0;
        foreach ($acc as $lid => $a) {
            $cap_h = $a['capacity_slots'] * $inc / 60;
            $bkd_h = $a['booked_slots'] * $inc / 60;
            $tot_cap += $cap_h;
            $tot_bkd += $bkd_h;
            $rows[] = ['name' => $a['name'], 'capacity_h' => $cap_h, 'booked_h' => $bkd_h];
        }
        usort($rows, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

        return ['rows' => $rows, 'total_capacity_h' => $tot_cap, 'total_booked_h' => $tot_bkd];
    }

    private function pct($capacity_h, $booked_h) {
        if ($capacity_h <= 0) {
            return '—';
        }
        return round($booked_h / $capacity_h * 100) . '%';
    }
}
