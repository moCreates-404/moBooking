<?php
/**
 * Closures admin screen — a submenu of moBooking. Phase 1 ships a usable
 * list + add/edit form (clear date/time/lane fields for non-technical staff);
 * the drag-on-a-calendar editor is deferred to Phase 5 by design.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Closures_Admin {

    const SLUG  = 'mclb-closures';
    const NONCE = 'mclb_closure_save';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_mclb_save_closure', [$this, 'handle_save']);
        add_action('admin_post_mclb_delete_closure', [$this, 'handle_delete']);
    }

    public function menu() {
        add_submenu_page(
            MCLB_Admin::PAGE,
            __('Closures', 'mclb-lane-booking'),
            __('Closures', 'mclb-lane-booking'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    /** Resource label for copy — "Lanes"/"Bays"/… from settings. */
    private function label_plural() {
        return MCLB_Settings::get('resource_label_plural') ?: 'Lanes';
    }

    private function label_singular() {
        return MCLB_Settings::get('resource_label_singular') ?: 'Lane';
    }

    // ── Write handlers (admin-post, so they redirect cleanly after save) ────────

    public function handle_save() {
        if (!current_user_can('manage_options') || !isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }

        $id   = isset($_POST['closure_id']) ? absint($_POST['closure_id']) : 0;
        $kind = (isset($_POST['kind']) && $_POST['kind'] === 'recurring') ? 'recurring' : 'oneoff';

        $data = [
            'lane_id' => isset($_POST['lane_id']) ? absint($_POST['lane_id']) : 0,
            'kind'    => $kind,
            'label'   => isset($_POST['label']) ? wp_unslash($_POST['label']) : '',
        ];

        if ($kind === 'oneoff') {
            // Compose from a date + start/end time — friendlier than raw datetimes.
            $date  = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
            $start = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
            $end   = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
            $data['starts_at'] = ($date && $start) ? "{$date} {$start}" : '';
            $data['ends_at']   = ($date && $end) ? "{$date} {$end}" : '';
        } else {
            $data['weekday']      = isset($_POST['weekday']) ? absint($_POST['weekday']) : 0;
            $data['start_time']   = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
            $data['end_time']     = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
            $data['active_from']  = isset($_POST['active_from']) ? sanitize_text_field(wp_unslash($_POST['active_from'])) : '';
            $data['active_until'] = isset($_POST['active_until']) ? sanitize_text_field(wp_unslash($_POST['active_until'])) : '';
        }

        if ($id) {
            MCLB_Closures::update($id, $data);
            $notice = 'updated';
        } else {
            MCLB_Closures::insert($data);
            $notice = 'added';
        }

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'mclb_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handle_delete() {
        $id = isset($_GET['closure_id']) ? absint($_GET['closure_id']) : 0;
        if (!current_user_can('manage_options') || !$id
            || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mclb_delete_closure_' . $id)) {
            wp_die(esc_html__('Permission denied.', 'mclb-lane-booking'));
        }
        MCLB_Closures::delete($id);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'mclb_notice' => 'deleted'], admin_url('admin.php')));
        exit;
    }

    // ── Screen ──────────────────────────────────────────────────────────────

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $editing = null;
        if (isset($_GET['closure_id']) && !isset($_GET['action'])) {
            $editing = MCLB_Closures::get(absint($_GET['closure_id']));
        }

        echo '<div class="wrap"><h1>' . esc_html__('Closures / blockouts', 'mclb-lane-booking') . '</h1>';

        if (isset($_GET['mclb_notice'])) {
            $map = [
                'added'   => __('Closure added.', 'mclb-lane-booking'),
                'updated' => __('Closure updated.', 'mclb-lane-booking'),
                'deleted' => __('Closure deleted.', 'mclb-lane-booking'),
            ];
            $key = sanitize_key(wp_unslash($_GET['mclb_notice']));
            if (isset($map[$key])) {
                printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($map[$key]));
            }
        }

        $this->render_form($editing);
        echo '<hr style="margin:28px 0">';
        $this->render_list();

        echo '</div>';
    }

    private function lane_dropdown($selected) {
        $lanes = MCLB_Lane::all_bookable();
        echo '<select name="lane_id">';
        printf(
            '<option value="0" %s>%s</option>',
            selected((int) $selected, 0, false),
            /* translators: %s: plural resource label. */
            esc_html(sprintf(__('All %s (site-wide)', 'mclb-lane-booking'), strtolower($this->label_plural())))
        );
        foreach ($lanes as $lane) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $lane->ID,
                selected((int) $selected, (int) $lane->ID, false),
                esc_html(get_the_title($lane))
            );
        }
        echo '</select>';
    }

    private function render_form($c) {
        $is_edit   = is_object($c);
        $kind      = $is_edit ? $c->kind : 'oneoff';
        $lane_id   = $is_edit ? (int) $c->lane_id : 0;
        $label     = $is_edit ? $c->label : '';
        $date      = ($is_edit && $c->starts_at) ? substr($c->starts_at, 0, 10) : '';
        $o_start   = ($is_edit && $c->starts_at) ? substr($c->starts_at, 11, 5) : '';
        $o_end     = ($is_edit && $c->ends_at) ? substr($c->ends_at, 11, 5) : '';
        $weekday   = $is_edit ? (int) $c->weekday : 0;
        $r_start   = ($is_edit && $c->start_time) ? substr($c->start_time, 0, 5) : '';
        $r_end     = ($is_edit && $c->end_time) ? substr($c->end_time, 0, 5) : '';
        $a_from    = $is_edit ? (string) $c->active_from : '';
        $a_until   = $is_edit ? (string) $c->active_until : '';

        echo '<h2>' . ($is_edit ? esc_html__('Edit closure', 'mclb-lane-booking') : esc_html__('Add a closure', 'mclb-lane-booking')) . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mclb_save_closure">';
        printf('<input type="hidden" name="closure_id" value="%d">', $is_edit ? (int) $c->id : 0);
        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<table class="form-table" role="presentation"><tbody>';

        // Applies to
        echo '<tr><th scope="row">' . esc_html($this->label_singular()) . '</th><td>';
        $this->lane_dropdown($lane_id);
        echo '</td></tr>';

        // Kind
        echo '<tr><th scope="row">' . esc_html__('Type', 'mclb-lane-booking') . '</th><td>';
        printf(
            '<label style="margin-right:16px"><input type="radio" name="kind" value="oneoff" %s class="mclb-kind"> %s</label>',
            checked($kind, 'oneoff', false),
            esc_html__('One-off (specific date)', 'mclb-lane-booking')
        );
        printf(
            '<label><input type="radio" name="kind" value="recurring" %s class="mclb-kind"> %s</label>',
            checked($kind, 'recurring', false),
            esc_html__('Recurring (weekly)', 'mclb-lane-booking')
        );
        echo '</td></tr>';

        // One-off fields
        echo '<tbody class="mclb-oneoff" style="' . ($kind === 'oneoff' ? '' : 'display:none') . '">';
        echo '<tr><th scope="row">' . esc_html__('Date', 'mclb-lane-booking') . '</th><td>';
        printf('<input type="date" name="date" value="%s">', esc_attr($date));
        echo '</td></tr>';
        echo '</tbody>';

        // Recurring fields
        echo '<tbody class="mclb-recurring" style="' . ($kind === 'recurring' ? '' : 'display:none') . '">';
        echo '<tr><th scope="row">' . esc_html__('Weekday', 'mclb-lane-booking') . '</th><td><select name="weekday">';
        foreach (MCLB_Settings::weekdays() as $i => $wlabel) {
            printf('<option value="%d" %s>%s</option>', (int) $i, selected($weekday, $i, false), esc_html($wlabel));
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Active from / until', 'mclb-lane-booking') . '</th><td>';
        printf('<input type="date" name="active_from" value="%s"> &ndash; ', esc_attr($a_from));
        printf('<input type="date" name="active_until" value="%s"> ', esc_attr($a_until));
        printf('<p class="description">%s</p>', esc_html__('Optional — leave blank for an open-ended recurring closure.', 'mclb-lane-booking'));
        echo '</td></tr>';
        echo '</tbody>';

        // Shared time fields (start/end apply to both kinds)
        echo '<tbody>';
        echo '<tr><th scope="row">' . esc_html__('Time', 'mclb-lane-booking') . '</th><td>';
        printf('<input type="time" name="start_time" value="%s"> &ndash; ', esc_attr($kind === 'recurring' ? $r_start : $o_start));
        printf('<input type="time" name="end_time" value="%s">', esc_attr($kind === 'recurring' ? $r_end : $o_end));
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Label', 'mclb-lane-booking') . '</th><td>';
        printf('<input type="text" class="regular-text" name="label" value="%s" placeholder="%s">', esc_attr($label), esc_attr__('e.g. Academy, Maintenance', 'mclb-lane-booking'));
        printf('<p class="description">%s</p>', esc_html__('For your reference only.', 'mclb-lane-booking'));
        echo '</td></tr>';
        echo '</tbody>';

        echo '</tbody></table>';
        submit_button($is_edit ? __('Update closure', 'mclb-lane-booking') : __('Add closure', 'mclb-lane-booking'));
        echo '</form>';

        // Toggle one-off vs recurring field groups.
        echo '<script>(function(){function t(){var k=document.querySelector(".mclb-kind:checked");if(!k)return;var r=k.value==="recurring";document.querySelectorAll(".mclb-oneoff").forEach(function(e){e.style.display=r?"none":"";});document.querySelectorAll(".mclb-recurring").forEach(function(e){e.style.display=r?"":"none";});}document.querySelectorAll(".mclb-kind").forEach(function(e){e.addEventListener("change",t);});})();</script>';
    }

    private function render_list() {
        $rows = MCLB_Closures::all();
        echo '<h2>' . esc_html__('Existing closures', 'mclb-lane-booking') . '</h2>';

        if (empty($rows)) {
            echo '<p>' . esc_html__('No closures yet.', 'mclb-lane-booking') . '</p>';
            return;
        }

        $weekdays = MCLB_Settings::weekdays();
        echo '<table class="widefat striped"><thead><tr>';
        printf(
            '<th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th></th>',
            esc_html($this->label_singular()),
            esc_html__('Type', 'mclb-lane-booking'),
            esc_html__('When', 'mclb-lane-booking'),
            esc_html__('Time', 'mclb-lane-booking'),
            esc_html__('Label', 'mclb-lane-booking')
        );
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $lane_name = ((int) $r->lane_id === 0)
                ? sprintf(__('All %s', 'mclb-lane-booking'), strtolower($this->label_plural()))
                : get_the_title((int) $r->lane_id);

            if ($r->kind === 'recurring') {
                $when = isset($weekdays[(int) $r->weekday]) ? $weekdays[(int) $r->weekday] : '—';
                if ($r->active_from || $r->active_until) {
                    $when .= ' (' . esc_html($r->active_from ?: '…') . ' – ' . esc_html($r->active_until ?: '…') . ')';
                }
                $time = substr((string) $r->start_time, 0, 5) . ' – ' . substr((string) $r->end_time, 0, 5);
            } else {
                $when = $r->starts_at ? substr($r->starts_at, 0, 10) : '—';
                $time = substr((string) $r->starts_at, 11, 5) . ' – ' . substr((string) $r->ends_at, 11, 5);
            }

            $edit_url   = add_query_arg(['page' => self::SLUG, 'closure_id' => (int) $r->id], admin_url('admin.php'));
            $delete_url = wp_nonce_url(
                add_query_arg(['action' => 'mclb_delete_closure', 'closure_id' => (int) $r->id], admin_url('admin-post.php')),
                'mclb_delete_closure_' . (int) $r->id
            );

            echo '<tr>';
            printf('<td>%s</td>', esc_html($lane_name));
            printf('<td>%s</td>', esc_html($r->kind === 'recurring' ? __('Recurring', 'mclb-lane-booking') : __('One-off', 'mclb-lane-booking')));
            printf('<td>%s</td>', esc_html($when));
            printf('<td>%s</td>', esc_html($time));
            printf('<td>%s</td>', esc_html((string) $r->label));
            printf(
                '<td><a href="%s">%s</a> | <a href="%s" onclick="return confirm(%s)" style="color:#b32d2e">%s</a></td>',
                esc_url($edit_url),
                esc_html__('Edit', 'mclb-lane-booking'),
                esc_url($delete_url),
                esc_js('"' . __('Delete this closure?', 'mclb-lane-booking') . '"'),
                esc_html__('Delete', 'mclb-lane-booking')
            );
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
