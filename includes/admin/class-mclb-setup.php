<?php
/**
 * First-run setup wizard (v1.6.0).
 *
 * One screen that turns a fresh install into a working booking grid for any
 * kind of business: what customers book (and what it's called), its types,
 * whether staff can be requested, price, increment, opening hours, and the
 * first few bookable units.
 *
 * Shown to admins via a notice until finished or skipped. Existing installs
 * are marked complete on upgrade (MCLB_Settings::maybe_pin_legacy_terms()),
 * so a live site never sees it. Re-runnable any time from Settings.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Setup {

    const SLUG   = 'mclb-setup';
    const ACTION = 'mclb_setup_save';

    /** Presets: key => [singular, plural, types, staff singular, staff plural]. */
    public static function presets() {
        return [
            'court'     => [__('Court', 'mobooking'), __('Courts', 'mobooking'), __("Indoor\nOutdoor", 'mobooking')],
            'lane'      => [__('Lane', 'mobooking'), __('Lanes', 'mobooking'), __('Standard', 'mobooking')],
            'bay'       => [__('Bay', 'mobooking'), __('Bays', 'mobooking'), __('Standard', 'mobooking')],
            'room'      => [__('Room', 'mobooking'), __('Rooms', 'mobooking'), __("Meeting room\nEvent space", 'mobooking')],
            'studio'    => [__('Studio', 'mobooking'), __('Studios', 'mobooking'), __('Standard', 'mobooking')],
            'equipment' => [__('Machine', 'mobooking'), __('Machines', 'mobooking'), __('Standard', 'mobooking')],
        ];
    }

    public static function staff_presets() {
        return [
            'coach'      => [__('Coach', 'mobooking'), __('Coaches', 'mobooking')],
            'instructor' => [__('Instructor', 'mobooking'), __('Instructors', 'mobooking')],
            'trainer'    => [__('Trainer', 'mobooking'), __('Trainers', 'mobooking')],
            'pro'        => [__('Pro', 'mobooking'), __('Pros', 'mobooking')],
        ];
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_head', [$this, 'hide_menu_entry']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
    }

    public static function is_complete() {
        return (int) MCLB_Settings::get('setup_complete') === 1;
    }

    public static function url() {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    public function menu() {
        // Registered under moBooking so its capability check works, then the
        // menu entry is hidden in admin_head; reached from the notice or the
        // Settings screen.
        add_submenu_page(MCLB_Admin::PAGE, __('moBooking setup', 'mobooking'), __('Setup', 'mobooking'), 'manage_options', self::SLUG, [$this, 'render']);
    }

    public function hide_menu_entry() {
        remove_submenu_page(MCLB_Admin::PAGE, self::SLUG);
    }

    public function notice() {
        if (isset($_GET['mclb_setup']) && $_GET['mclb_setup'] === 'done' && current_user_can('manage_options')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(MCLB_Terms::fill(__('Setup saved. Your {resources} are ready — add the booking grid to a page with the [mclb_grid] shortcode.', 'mobooking')))
            );
        }
        if (self::is_complete() || !current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, self::SLUG) !== false) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a class="button button-primary" href="%3$s">%4$s</a></p></div>',
            esc_html__('Welcome to moBooking!', 'mobooking'),
            esc_html__('Two minutes of setup and your booking grid is ready.', 'mobooking'),
            esc_url(self::url()),
            esc_html__('Start setup', 'mobooking')
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s       = MCLB_Settings::get();
        $presets = self::presets();
        $staffp  = self::staff_presets();
        $hours   = $s['hours'];
        $lanes   = wp_count_posts(MCLB_Lane::CPT);
        $have    = $lanes ? (int) ($lanes->publish ?? 0) : 0;
        ?>
        <div class="wrap mclb-setup">
          <h1><?php esc_html_e('Set up moBooking', 'mobooking'); ?></h1>
          <p><?php esc_html_e('Pick what fits — everything here can be changed later in moBooking → Settings.', 'mobooking'); ?></p>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <?php wp_nonce_field(self::ACTION); ?>

            <h2><?php esc_html_e('1. What do customers book?', 'mobooking'); ?></h2>
            <fieldset class="mclb-setup__presets">
              <?php foreach ($presets as $key => $p) : ?>
                <label style="display:inline-block;margin:0 16px 8px 0">
                  <input type="radio" name="preset" value="<?php echo esc_attr($key); ?>" data-one="<?php echo esc_attr($p[0]); ?>" data-many="<?php echo esc_attr($p[1]); ?>" data-types="<?php echo esc_attr($p[2]); ?>" <?php checked(strcasecmp($s['resource_label_singular'], $p[0]) === 0); ?>>
                  <?php echo esc_html($p[0]); ?>
                </label>
              <?php endforeach; ?>
              <label style="display:inline-block;margin:0 16px 8px 0">
                <input type="radio" name="preset" value="custom" data-one="" data-many="" data-types=""> <?php esc_html_e('Something else', 'mobooking'); ?>
              </label>
            </fieldset>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><label for="mclb-one"><?php esc_html_e('Called (one)', 'mobooking'); ?></label></th>
                  <td><input id="mclb-one" name="resource_label_singular" class="regular-text" required value="<?php echo esc_attr($s['resource_label_singular']); ?>"></td></tr>
              <tr><th scope="row"><label for="mclb-many"><?php esc_html_e('Called (many)', 'mobooking'); ?></label></th>
                  <td><input id="mclb-many" name="resource_label_plural" class="regular-text" required value="<?php echo esc_attr($s['resource_label_plural']); ?>"></td></tr>
              <tr><th scope="row"><label for="mclb-types"><?php esc_html_e('Types', 'mobooking'); ?></label></th>
                  <td><textarea id="mclb-types" name="resource_types" rows="3" class="regular-text"><?php echo esc_textarea($s['resource_types']); ?></textarea>
                      <p class="description"><?php esc_html_e('One per line. Customers filter the grid by type (e.g. Indoor / Outdoor). Leave one line if you only have one kind.', 'mobooking'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('2. Can customers request a staff member?', 'mobooking'); ?></h2>
            <p><label><input type="checkbox" name="enable_coach_requests" value="1" <?php checked((int) $s['enable_coach_requests'], 1); ?>> <?php esc_html_e('Yes — let customers request one when booking (you assign who)', 'mobooking'); ?></label></p>
            <p>
              <?php esc_html_e('They’re called:', 'mobooking'); ?>
              <?php foreach ($staffp as $key => $p) : ?>
                <label style="margin-left:12px"><input type="radio" name="staff_preset" value="<?php echo esc_attr($key); ?>" data-one="<?php echo esc_attr($p[0]); ?>" data-many="<?php echo esc_attr($p[1]); ?>" <?php checked(strcasecmp($s['staff_label_singular'], $p[0]) === 0); ?>> <?php echo esc_html($p[0]); ?></label>
              <?php endforeach; ?>
              <input type="hidden" name="staff_label_singular" id="mclb-staff-one" value="<?php echo esc_attr($s['staff_label_singular']); ?>">
              <input type="hidden" name="staff_label_plural" id="mclb-staff-many" value="<?php echo esc_attr($s['staff_label_plural']); ?>">
            </p>

            <h2><?php esc_html_e('3. Pricing', 'mobooking'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><label for="mclb-price"><?php esc_html_e('Price per hour', 'mobooking'); ?></label></th>
                  <td><input id="mclb-price" name="price_per_hour" type="number" min="0" step="0.01" value="<?php echo esc_attr($s['price_per_hour']); ?>">
                      <p class="description"><?php esc_html_e('Individual units can override this later.', 'mobooking'); ?></p></td></tr>
              <tr><th scope="row"><label for="mclb-inc"><?php esc_html_e('Booking length steps', 'mobooking'); ?></label></th>
                  <td><select id="mclb-inc" name="booking_increment">
                    <?php foreach ([15 => __('15 minutes', 'mobooking'), 30 => __('30 minutes', 'mobooking'), 60 => __('1 hour', 'mobooking'), 120 => __('2 hours', 'mobooking')] as $v => $l) : ?>
                      <option value="<?php echo (int) $v; ?>" <?php selected((int) $s['booking_increment'], $v); ?>><?php echo esc_html($l); ?></option>
                    <?php endforeach; ?>
                  </select></td></tr>
            </table>

            <h2><?php esc_html_e('4. Opening hours', 'mobooking'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><?php esc_html_e('Monday – Friday', 'mobooking'); ?></th>
                  <td><input type="time" name="weekday_open" value="<?php echo esc_attr($hours[1]['open']); ?>"> – <input type="time" name="weekday_close" value="<?php echo esc_attr($hours[1]['close']); ?>"></td></tr>
              <tr><th scope="row"><?php esc_html_e('Saturday – Sunday', 'mobooking'); ?></th>
                  <td><input type="time" name="weekend_open" value="<?php echo esc_attr($hours[6]['open']); ?>"> – <input type="time" name="weekend_close" value="<?php echo esc_attr($hours[6]['close']); ?>">
                      <p class="description"><?php esc_html_e('Fine-tune each day, closures and holidays later in Settings.', 'mobooking'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('5. Create your first units', 'mobooking'); ?></h2>
            <?php if ($have > 0) : ?>
              <p><?php echo esc_html(sprintf(_n('You already have %d bookable unit — add more any time.', 'You already have %d bookable units — add more any time.', $have, 'mobooking'), $have)); ?></p>
              <input type="hidden" name="create_count" value="0">
            <?php else : ?>
              <p><label><?php esc_html_e('How many?', 'mobooking'); ?> <input type="number" name="create_count" min="0" max="50" value="4" style="width:5em"></label>
                 <span class="description"><?php esc_html_e('Named “Court 1”, “Court 2”… using the word above; rename them any time.', 'mobooking'); ?></span></p>
            <?php endif; ?>

            <p class="submit">
              <button type="submit" class="button button-primary button-hero"><?php esc_html_e('Finish setup', 'mobooking'); ?></button>
              <button type="submit" name="skip" value="1" class="button button-link" style="margin-left:12px"><?php esc_html_e('Skip for now', 'mobooking'); ?></button>
            </p>
          </form>
        </div>
        <script>
        (function () {
          var one = document.getElementById('mclb-one'), many = document.getElementById('mclb-many'), types = document.getElementById('mclb-types');
          document.querySelectorAll('input[name="preset"]').forEach(function (r) {
            r.addEventListener('change', function () {
              if (r.value === 'custom') { one.value = ''; many.value = ''; one.focus(); return; }
              one.value = r.dataset.one; many.value = r.dataset.many; types.value = r.dataset.types;
            });
          });
          var s1 = document.getElementById('mclb-staff-one'), s2 = document.getElementById('mclb-staff-many');
          document.querySelectorAll('input[name="staff_preset"]').forEach(function (r) {
            r.addEventListener('change', function () { s1.value = r.dataset.one; s2.value = r.dataset.many; });
          });
        })();
        </script>
        <?php
    }

    public function save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'mobooking'), 403);
        }
        check_admin_referer(self::ACTION);

        $opts = MCLB_Settings::get();

        if (!empty($_POST['skip'])) {
            $opts['setup_complete'] = 1;
            update_option(MCLB_OPTION, $opts);
            wp_safe_redirect(admin_url('admin.php?page=' . MCLB_Admin::PAGE));
            exit;
        }

        $in = wp_unslash($_POST);
        $d  = MCLB_Settings::defaults();

        $opts['resource_label_singular'] = sanitize_text_field($in['resource_label_singular'] ?? '') ?: $d['resource_label_singular'];
        $opts['resource_label_plural']   = sanitize_text_field($in['resource_label_plural'] ?? '') ?: $d['resource_label_plural'];
        $opts['resource_types']          = sanitize_textarea_field($in['resource_types'] ?? '') ?: $d['resource_types'];
        $opts['enable_coach_requests']   = empty($in['enable_coach_requests']) ? 0 : 1;
        $opts['staff_label_singular']    = sanitize_text_field($in['staff_label_singular'] ?? '') ?: $d['staff_label_singular'];
        $opts['staff_label_plural']      = sanitize_text_field($in['staff_label_plural'] ?? '') ?: $d['staff_label_plural'];
        $opts['price_per_hour']          = MCLB_Settings::sanitize_price($in['price_per_hour'] ?? $d['price_per_hour']);

        $inc = (int) ($in['booking_increment'] ?? 60);
        $opts['booking_increment'] = in_array($inc, [15, 30, 60, 120], true) ? $inc : 60;

        $hours = [];
        foreach (range(1, 7) as $day) {
            $wk          = $day >= 6 ? 'weekend' : 'weekday';
            $hours[$day] = [
                'open'   => $in[$wk . '_open'] ?? '',
                'close'  => $in[$wk . '_close'] ?? '',
                'closed' => 0,
            ];
        }
        $opts['hours']          = MCLB_Settings::sanitize_hours($hours);
        $opts['setup_complete'] = 1;
        update_option(MCLB_OPTION, $opts);
        MCLB_Terms::flush();

        // First units.
        $count = max(0, min(50, (int) ($in['create_count'] ?? 0)));
        if ($count > 0) {
            $types = MCLB_Lane::resource_types();
            $type  = $types[0] ?? '';
            for ($i = 1; $i <= $count; $i++) {
                $id = wp_insert_post([
                    'post_type'   => MCLB_Lane::CPT,
                    'post_status' => 'publish',
                    'post_title'  => $opts['resource_label_singular'] . ' ' . $i,
                    'menu_order'  => $i,
                ]);
                if ($id && !is_wp_error($id)) {
                    update_post_meta($id, MCLB_Lane::META_TYPE, $type);
                }
            }
        }

        wp_safe_redirect(add_query_arg('mclb_setup', 'done', admin_url('admin.php?page=' . MCLB_Admin::PAGE)));
        exit;
    }
}
