<?php
/**
 * Booking emails + WooCommerce admin-email routing (Phase 8a-2).
 *
 * 1. Routes WooCommerce's OWN admin order emails (New / Cancelled / Failed) by
 *    order type — lane-only → Bookings address, shop-only → Reception address,
 *    mixed → both. If the relevant address isn't set the recipient WooCommerce
 *    passed is returned UNCHANGED (never admin_email), so nothing changes on
 *    deploy until the addresses are configured. Customer-facing WC emails are
 *    untouched.
 * 2. Sends the manual-booking confirmation (+ resend) and the "your booking has
 *    moved" email to the customer, both wrapped in WooCommerce's branded
 *    header/footer via MCLB_Mail, Reply-To the Bookings address.
 * 3. Sends our own staff alerts (to the Bookings address, Reply-To the customer)
 *    for the events toggled on in settings.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Emails {

    public static function init() {
        // WooCommerce admin-email routing by order type.
        add_filter('woocommerce_email_recipient_new_order', [__CLASS__, 'route_admin'], 10, 2);
        add_filter('woocommerce_email_recipient_cancelled_order', [__CLASS__, 'route_admin'], 10, 2);
        add_filter('woocommerce_email_recipient_failed_order', [__CLASS__, 'route_admin'], 10, 2);

        // Customer "your booking has moved" (per-move opt-out via the Move panel).
        add_action('mclb_booking_moved', [__CLASS__, 'on_booking_moved'], 10, 4);

        // Staff alerts (each gated by its own setting). Cancellation emails
        // (customer + staff) fire from MCLB_Cancel::finish(), not a hook here, so
        // they can carry the money outcome and be deduped by the claim_cancel winner.
        add_action('woocommerce_payment_complete', [__CLASS__, 'alert_coach_request'], 20, 1);
    }

    // ── Settings accessors ────────────────────────────────────────────────────
    private static function bookings_addr()  { return sanitize_email((string) MCLB_Settings::get('email_bookings')); }
    private static function reception_addr() { return sanitize_email((string) MCLB_Settings::get('email_reception')); }
    private static function on($key)         { return (int) MCLB_Settings::get($key) === 1; }

    // ── 1. WooCommerce admin-email routing ─────────────────────────────────────

    /** lane | shop | mixed — by whether line items carry _mclb_hold_id. */
    public static function order_type($order) {
        $has_lane = false;
        $has_shop = false;
        foreach ($order->get_items('line_item') as $item) {
            if ($item->get_meta('_mclb_hold_id')) { $has_lane = true; }
            else { $has_shop = true; }
        }
        if ($has_lane && $has_shop) { return 'mixed'; }
        if ($has_lane)              { return 'lane'; }
        return 'shop';
    }

    /**
     * Re-route an admin order email. Returns the incoming recipient unchanged when
     * the relevant address isn't configured (so a fresh deploy behaves exactly as
     * before). Never substitutes admin_email.
     */
    public static function route_admin($recipient, $order) {
        if (!$order instanceof WC_Order) {
            return $recipient;
        }
        $type      = self::order_type($order);
        $bookings  = self::bookings_addr();
        $reception = self::reception_addr();

        $targets = [];
        if ($type !== 'shop' && $bookings !== '')  { $targets[] = $bookings; }  // lane or mixed
        if ($type !== 'lane' && $reception !== '') { $targets[] = $reception; } // shop or mixed

        if (empty($targets)) {
            return $recipient; // relevant address not set → leave WooCommerce's recipient alone
        }
        return implode(',', array_unique($targets));
    }

    // ── 2. Customer emails (branded, Reply-To Bookings) ────────────────────────

    /** Confirmation for a MANUAL booking (online bookings use WooCommerce's own). */
    public static function send_manual_confirmation($booking_id, $force = false) {
        $b = MCLB_Bookings::get((int) $booking_id);
        if (!$b || (string) $b->source !== 'manual' || (string) $b->status !== MCLB_Bookings::STATUS_CONFIRMED) {
            return ['ok' => false, 'result' => 'not-applicable'];
        }
        if (!$force && !self::on('email_manual_confirmation')) {
            return ['ok' => false, 'result' => 'disabled'];
        }
        $email = sanitize_email((string) $b->customer_email);
        if ($email === '') {
            return ['ok' => false, 'result' => 'no-email'];
        }
        $heading = __('Your booking is confirmed', 'mobooking');
        $html    = '<p>' . sprintf(
            /* translators: %s: customer first name or "there". */
            esc_html__('Hi %s,', 'mobooking'),
            esc_html(self::first_name($b->customer_name))
        ) . '</p>';
        $html .= '<p>' . esc_html__('Your booking is confirmed:', 'mobooking') . '</p>';
        $html .= self::booking_table($b);
        $html .= '<p>' . esc_html__('Payment is taken at the counter. See you then!', 'mobooking') . '</p>';
        $html .= self::manage_link_html($b);

        return MCLB_Mail::send($email, self::subject($heading), $html, [
            'heading'  => $heading,
            'branded'  => true,
            'reply_to' => self::bookings_addr(),
            'tag'      => 'manual-confirmation',
        ]);
    }

    /** "Your booking has moved" — fired from the move handler via the hook. */
    public static function on_booking_moved($booking_id, $old, $new, $context = []) {
        if (empty($context['notify'])) {
            return; // staff chose not to notify
        }
        // Skip when nothing actually changed.
        $changed = ((int) ($old['lane_id'] ?? 0) !== (int) ($new['lane_id'] ?? 0))
            || ((string) ($old['starts_at'] ?? '') !== (string) ($new['starts_at'] ?? ''))
            || ((string) ($old['ends_at'] ?? '') !== (string) ($new['ends_at'] ?? ''));
        if (!$changed) {
            return;
        }
        $b = MCLB_Bookings::get((int) $booking_id);
        if (!$b) { return; }
        $email = sanitize_email((string) $b->customer_email);
        if ($email === '') { return; }

        $heading = __('Your booking has moved', 'mobooking');
        $html    = '<p>' . sprintf(esc_html__('Hi %s,', 'mobooking'), esc_html(self::first_name($b->customer_name))) . '</p>';
        $html   .= '<p>' . esc_html__('Your booking has been moved. The new details are:', 'mobooking') . '</p>';
        $html   .= self::booking_table($b);
        $html   .= '<p style="color:#666;">' . esc_html__('Previously:', 'mobooking') . ' '
                 . esc_html(($old['lane_name'] ?? '') . ' · ' . self::when_label($old['starts_at'] ?? '', $old['ends_at'] ?? '')) . '</p>';

        MCLB_Mail::send($email, self::subject($heading), $html, [
            'heading'  => $heading,
            'branded'  => true,
            'reply_to' => self::bookings_addr(),
            'tag'      => 'booking-moved',
        ]);
    }

    // ── 3. Staff alerts (to Bookings, Reply-To customer) ───────────────────────

    /** On an online order confirming, alert staff if any of its bookings asked for a coach. */
    public static function alert_coach_request($order_id) {
        if (!self::on('alert_coach_request')) { return; }
        $rows = MCLB_Bookings::for_order((int) $order_id);
        $wanted = array_filter($rows, static function ($r) { return (int) $r->coach_requested === 1; });
        if (empty($wanted)) { return; }
        $html = '<p>' . esc_html(MCLB_Terms::fill(__('An online booking requested a {staff}:', 'mobooking'))) . '</p>';
        foreach ($wanted as $b) {
            $html .= self::booking_table($b);
            if (trim((string) $b->coach_request_note) !== '') {
                $html .= '<p style="color:#666;">' . esc_html__('Note:', 'mobooking') . ' ' . esc_html($b->coach_request_note) . '</p>';
            }
        }
        $first = reset($wanted);
        self::staff_alert(MCLB_Terms::fill(__('{Staff} requested on an online booking', 'mobooking')), $html, $first, 'alert-coach-request');
    }

    /** Optional alert for a staff-created manual booking (default off). */
    public static function alert_manual_created($booking_id) {
        if (!self::on('alert_manual_created')) { return; }
        $b = MCLB_Bookings::get((int) $booking_id);
        if (!$b) { return; }
        $html = '<p>' . esc_html__('A manual booking was created:', 'mobooking') . '</p>' . self::booking_table($b);
        self::staff_alert(__('Manual booking created', 'mobooking'), $html, $b, 'alert-manual-created');
    }

    /** Optional alert for a staff move (default off). */
    public static function alert_move($booking_id, $old, $new) {
        if (!self::on('alert_move')) { return; }
        $b = MCLB_Bookings::get((int) $booking_id);
        if (!$b) { return; }
        $html = '<p>' . esc_html__('A booking was moved:', 'mobooking') . '</p>' . self::booking_table($b)
              . '<p style="color:#666;">' . esc_html__('Previously:', 'mobooking') . ' '
              . esc_html(($old['lane_name'] ?? '') . ' · ' . self::when_label($old['starts_at'] ?? '', $old['ends_at'] ?? '')) . '</p>';
        self::staff_alert(__('Booking moved', 'mobooking'), $html, $b, 'alert-move');
    }

    /** ONE confirmation for a whole series (not one per occurrence). */
    public static function send_series_confirmation($series_id, $skipped = []) {
        $rows = MCLB_Bookings::for_series((int) $series_id);
        if (empty($rows)) { return ['ok' => false, 'result' => 'empty']; }
        $first = $rows[0];
        if (!self::on('email_manual_confirmation')) { return ['ok' => false, 'result' => 'disabled']; }
        $email = sanitize_email((string) $first->customer_email);
        if ($email === '') { return ['ok' => false, 'result' => 'no-email']; }

        $heading = __('Your recurring booking is confirmed', 'mobooking');
        $html  = '<p>' . sprintf(esc_html__('Hi %s,', 'mobooking'), esc_html(self::first_name($first->customer_name))) . '</p>';
        /* translators: %d: number of sessions. */
        $html .= '<p>' . sprintf(esc_html(_n('Your booking is confirmed for %d session:', 'Your booking is confirmed for %d sessions:', count($rows), 'mobooking')), count($rows)) . '</p>';
        $html .= self::dates_table($rows);
        if (!empty($skipped)) {
            $html .= '<p style="color:#666;">' . esc_html__('These dates were already taken and could not be booked:', 'mobooking') . '</p><ul>';
            foreach ($skipped as $s) { $html .= '<li>' . esc_html(self::nice_date($s['date'])) . '</li>'; }
            $html .= '</ul>';
        }
        // Total across the series: "Paid upfront" when every occurrence is already
        // counter-paid (the whole-series prepayment), otherwise due at the counter.
        $total   = 0.0; $allPaid = true;
        foreach ($rows as $x) {
            $total += ($x->counter_due !== null) ? (float) $x->counter_due : 0.0;
            if ($x->counter_paid_at === null || $x->counter_paid_at === '0000-00-00 00:00:00') { $allPaid = false; }
        }
        $money = class_exists('MCLB_Coaches') ? MCLB_Coaches::money($total) : number_format($total, 2);
        $html .= '<p style="font-weight:bold;">' . ($allPaid
            ? sprintf(esc_html__('Paid upfront: %s', 'mobooking'), esc_html($money))
            : sprintf(esc_html__('Total due at the counter: %s', 'mobooking'), esc_html($money))) . '</p>';
        $html .= '<p>' . ($allPaid ? esc_html__('Thanks — see you then!', 'mobooking') : esc_html__('Payment is taken at the counter. See you then!', 'mobooking')) . '</p>';
        $html .= self::manage_link_html($first);

        return MCLB_Mail::send($email, self::subject($heading), $html, [
            'heading'  => $heading,
            'branded'  => true,
            'reply_to' => self::bookings_addr(),
            'tag'      => 'series-confirmation',
        ]);
    }

    /** ONE staff alert for a whole series (gated by the manual-created toggle). */
    public static function alert_series_created($series_id, $skipped = []) {
        if (!self::on('alert_manual_created')) { return; }
        $rows = MCLB_Bookings::for_series((int) $series_id);
        if (empty($rows)) { return; }
        $first = $rows[0];
        /* translators: %d: number of sessions. */
        $html  = '<p>' . sprintf(esc_html(_n('A recurring booking (%d session) was created:', 'A recurring booking (%d sessions) was created:', count($rows), 'mobooking')), count($rows)) . '</p>';
        $html .= '<p>' . esc_html(trim((string) $first->customer_name) ?: 'Guest') . '</p>';
        $html .= self::dates_table($rows);
        if (!empty($skipped)) {
            $html .= '<p style="color:#666;">' . esc_html__('Skipped:', 'mobooking') . ' ' . esc_html(implode(', ', array_map(function ($s) { return self::nice_date($s['date']); }, $skipped))) . '</p>';
        }
        self::staff_alert(__('Recurring booking created', 'mobooking'), $html, $first, 'alert-series-created');
    }

    // ── Cancellations (WEB-12, v1.4.3) — fired from MCLB_Cancel::finish() ───────

    /** Customer "your booking has been cancelled" email with the money outcome. */
    public static function send_cancellation($b, array $outcome) {
        $email = sanitize_email((string) $b->customer_email);
        if ($email === '') { return ['ok' => false, 'result' => 'no-email']; }
        $heading = __('Your booking has been cancelled', 'mobooking');
        $html  = '<p>' . sprintf(esc_html__('Hi %s,', 'mobooking'), esc_html(self::first_name($b->customer_name))) . '</p>';
        $html .= '<p>' . esc_html__('This booking has been cancelled:', 'mobooking') . '</p>';
        $html .= self::booking_table($b, false) . self::coach_hours_line($b);
        $line = self::outcome_customer_text($outcome);
        if ($line !== '') { $html .= '<p style="font-weight:bold;">' . esc_html($line) . '</p>'; }
        // Prepaid-pass hours returned (v1.5.0): the cancel hook recorded the return
        // earlier this request; show it as its own line.
        if (!empty($GLOBALS['mclb_pass_return'][(int) $b->id])) {
            $r  = $GLOBALS['mclb_pass_return'][(int) $b->id];
            $hh = rtrim(rtrim(number_format((float) $r['hours'], 2), '0'), '.');
            $bb = rtrim(rtrim(number_format((float) $r['balance'], 2), '0'), '.');
            $exp = !empty($r['expires_at']) ? ' ' . sprintf(__('(expires %s)', 'mobooking'), wp_date('j M Y', strtotime((string) $r['expires_at']))) : '';
            $html .= '<p style="font-weight:bold;">' . esc_html(sprintf(MCLB_Terms::fill(__('%1$s hours returned to {pass} %2$s (balance %3$sh%4$s).', 'mobooking')), $hh, $r['code'], $bb, $exp)) . '</p>';
        }
        // Guest email-link cancels: a safety line in case a link was used by someone
        // other than the booker (v1.4.3).
        if (!empty($outcome['via_link'])) {
            $phone = MCLB_Settings::club_phone();
            $warn  = $phone !== ''
                ? sprintf(__('Didn’t cancel this? Contact the club as soon as possible on %s.', 'mobooking'), $phone)
                : __('Didn’t cancel this? Contact the club as soon as possible.', 'mobooking');
            $html .= '<p style="color:#b32d2e;">' . esc_html($warn) . '</p>';
        }
        return MCLB_Mail::send($email, self::subject($heading), $html, [
            'heading' => $heading, 'branded' => true, 'reply_to' => self::bookings_addr(), 'tag' => 'booking-cancelled',
        ]);
    }

    /** Staff alert to the Bookings address for a single cancellation. */
    public static function alert_cancel($b, array $outcome) {
        $html  = '<p>' . esc_html(self::outcome_staff_text($outcome)) . '</p>';
        $html .= self::booking_table($b, false) . self::coach_hours_line($b);
        $line  = self::outcome_customer_text($outcome);
        if ($line !== '') { $html .= '<p>' . esc_html($line) . '</p>'; }
        if (!empty($outcome['manual_flag'])) {
            $html .= '<p style="color:#b32d2e;font-weight:bold;">' . esc_html__('⚠ Handle manually — the money was not settled automatically.', 'mobooking') . '</p>';
        }
        self::staff_alert(__('Booking cancelled', 'mobooking'), $html, $b, 'alert-cancel');
    }

    /** ONE customer email for a series cancel, listing each date + its outcome. */
    public static function send_series_cancellation($anchor, array $items) {
        $email = sanitize_email((string) $anchor->customer_email);
        if ($email === '' || empty($items)) { return ['ok' => false, 'result' => 'no-email']; }
        $heading = __('Your recurring booking has been cancelled', 'mobooking');
        $html  = '<p>' . sprintf(esc_html__('Hi %s,', 'mobooking'), esc_html(self::first_name($anchor->customer_name))) . '</p>';
        /* translators: %d: number of sessions cancelled. */
        $html .= '<p>' . sprintf(esc_html(_n('%d session has been cancelled:', '%d sessions have been cancelled:', count($items), 'mobooking')), count($items)) . '</p>';
        $html .= self::cancel_items_table($items);
        self::staff_or_customer_manual_note($html, $items); // appends a manual-money note if any
        return MCLB_Mail::send($email, self::subject($heading), $html, [
            'heading' => $heading, 'branded' => true, 'reply_to' => self::bookings_addr(), 'tag' => 'series-cancelled',
        ]);
    }

    /** ONE staff alert for a series cancel. */
    public static function alert_series_cancel($anchor, array $items, $actor) {
        if (empty($items)) { return; }
        /* translators: 1: who cancelled, 2: count. */
        $who  = ($actor === 'customer' || $actor === '') ? __('the customer', 'mobooking') : $actor;
        $html = '<p>' . sprintf(esc_html__('%1$s cancelled %2$d session(s):', 'mobooking'), esc_html($who), count($items)) . '</p>';
        $html .= '<p>' . esc_html(trim((string) $anchor->customer_name) ?: 'Guest') . '</p>';
        $html .= self::cancel_items_table($items);
        self::staff_or_customer_manual_note($html, $items);
        self::staff_alert(__('Recurring booking cancelled', 'mobooking'), $html, $anchor, 'alert-series-cancel');
    }

    /** A table of cancelled series dates with each date's plain-words outcome. */
    private static function cancel_items_table($items) {
        $tz   = wp_timezone();
        $tf   = get_option('time_format') ?: 'g:i a';
        $cell = 'style="padding:5px 10px;border-bottom:1px solid #e5e5e5;font-size:14px;color:#222;"';
        $out  = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0;">';
        foreach ($items as $it) {
            $r = $it['booking'];
            $s = (new DateTimeImmutable($r->starts_at, $tz))->getTimestamp();
            $e = (new DateTimeImmutable($r->ends_at, $tz))->getTimestamp();
            $when = wp_date('D j M Y', $s) . ', ' . wp_date($tf, $s) . ' – ' . wp_date($tf, $e);
            $out .= '<tr><td ' . $cell . '>' . esc_html($when) . '</td><td ' . $cell . '>' . esc_html(self::outcome_customer_text($it['outcome'])) . '</td></tr>';
        }
        return $out . '</table>';
    }

    private static function staff_or_customer_manual_note(&$html, $items) {
        foreach ($items as $it) {
            if (!empty($it['outcome']['manual_flag'])) {
                $html .= '<p>' . esc_html__('Some sessions involved a payment the club will settle with you directly.', 'mobooking') . '</p>';
                return;
            }
        }
    }

    /** Plain-words money sentence for an outcome (customer-facing). */
    public static function outcome_customer_text(array $o) {
        $money = (isset($o['amount']) && class_exists('MCLB_Coaches')) ? MCLB_Coaches::money((float) $o['amount']) : '';
        switch ($o['type'] ?? '') {
            case 'refund_card':
                return sprintf(__('Refunded %s to your card (please allow 3–5 business days).', 'mobooking'), $money);
            case 'refund_failed':
                return sprintf(__('A refund of %s is being arranged — the club will confirm it with you.', 'mobooking'), $money);
            case 'store_credit':
                $exp = (!empty($o['credit_expires'])) ? ' ' . sprintf(__('(expires %s)', 'mobooking'), wp_date('j M Y', (int) $o['credit_expires'])) : '';
                return sprintf(__('Store credit: %1$s, %2$s%3$s. Apply it at checkout.', 'mobooking'), (string) ($o['credit_code'] ?? ''), $money, $exp);
            case 'counter_refund':
                return sprintf(__('Refund at the counter: %s.', 'mobooking'), $money);
            case 'paid_not_refunded':
                return sprintf(__('Your payment of %s hasn’t been refunded automatically. The club will contact you about it.', 'mobooking'), $money);
            case 'manual_no_refund':
                return __('Please contact the club about any payment on this booking.', 'mobooking');
            case 'order_refunded':
                return __('Your refund details are in a separate email.', 'mobooking');
            case 'order_cancelled':
            case 'cancelled':
                return '';
            case 'no_refund_cutoff':
                return __('No refund: cancelled within the cancellation cut-off before the start time.', 'mobooking');
        }
        return '';
    }

    /** Who cancelled + context, for the staff alert's opening line. */
    private static function outcome_staff_text(array $o) {
        $actor = (string) ($o['actor'] ?? '');
        $who   = ($actor === 'customer' || $actor === '') ? __('the customer', 'mobooking') : $actor;
        $note  = trim((string) ($o['actor_note'] ?? ''));
        /* translators: 1: who cancelled (customer / staff initials), 2: optional context like "via email link". */
        return trim(sprintf(__('Cancelled by %1$s%2$s.', 'mobooking'), $who, $note !== '' ? ' ' . $note : ''));
    }

    /** "Coach: Rob Turner, 4:00 – 5:00 pm" line when the booking has a coach. */
    private static function coach_hours_line($b) {
        if ((int) $b->assigned_coach_id <= 0 || !class_exists('MCLB_Coaches')) { return ''; }
        $tz = wp_timezone();
        $tf = get_option('time_format') ?: 'g:i a';
        list($cs, $ce) = MCLB_Bookings::coach_window($b->starts_at, $b->ends_at, $b->coach_starts_at, $b->coach_ends_at);
        $hrs = wp_date($tf, (new DateTimeImmutable((string) $cs, $tz))->getTimestamp()) . ' – ' . wp_date($tf, (new DateTimeImmutable((string) $ce, $tz))->getTimestamp());
        return '<p style="color:#666;">' . esc_html(MCLB_Coaches::label_singular() . ': ' . MCLB_Coaches::label((int) $b->assigned_coach_id) . ', ' . $hrs) . '</p>';
    }

    /** "Manage or cancel this booking" link (guest token) for confirmation emails. */
    public static function manage_link_html($b) {
        if ((int) MCLB_Settings::get('guest_self_cancel') !== 1 || !class_exists('MCLB_Manage_Token')) { return ''; }
        $url = MCLB_Manage_Token::url($b);
        if ($url === '') { return ''; }
        return '<p><a href="' . esc_url($url) . '">' . esc_html__('Manage or cancel this booking', 'mobooking') . '</a></p>';
    }

    private static function nice_date($ymd) {
        $tz = wp_timezone();
        return wp_date('D j M Y', (new DateTimeImmutable((string) $ymd . ' 00:00:00', $tz))->getTimestamp());
    }

    /** A compact table of a series' occurrences (date + time, coach once if set). */
    private static function dates_table($rows) {
        $tz   = wp_timezone();
        $tf   = get_option('time_format') ?: 'g:i a';
        $cell = 'style="padding:5px 10px;border-bottom:1px solid #e5e5e5;font-size:14px;color:#222;"';
        $out  = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0;">';
        foreach ($rows as $r) {
            $s = (new DateTimeImmutable($r->starts_at, $tz))->getTimestamp();
            $e = (new DateTimeImmutable($r->ends_at, $tz))->getTimestamp();
            $out .= '<tr><td ' . $cell . '>' . esc_html(wp_date('D j M Y', $s)) . '</td><td ' . $cell . '>' . esc_html(wp_date($tf, $s) . ' – ' . wp_date($tf, $e)) . '</td></tr>';
        }
        $out .= '</table>';
        $first = $rows[0];
        if ((int) $first->assigned_coach_id > 0 && class_exists('MCLB_Coaches')) {
            $out .= '<p>' . esc_html(MCLB_Coaches::label_singular()) . ': ' . esc_html(MCLB_Coaches::label((int) $first->assigned_coach_id)) . ' · ' . esc_html($first->lane_name) . '</p>';
        } else {
            $out .= '<p>' . esc_html($first->lane_name) . '</p>';
        }
        return $out;
    }

    /**
     * Where staff alerts go: the Bookings address, else WooCommerce's New Order
     * recipient, else the site admin email — so a blank Bookings address never
     * silently drops an alert (v1.4.3 fix).
     */
    public static function staff_recipient() {
        $to = self::bookings_addr();
        if ($to !== '') { return $to; }
        $wc = get_option('woocommerce_new_order_settings');
        if (is_array($wc) && !empty($wc['recipient'])) {
            return (string) $wc['recipient']; // may be comma-separated
        }
        return (string) get_option('admin_email');
    }

    private static function staff_alert($heading, $html, $booking, $tag) {
        $to = self::staff_recipient();
        if ($to === '') { return; } // truly nowhere to send (no admin email) — bail
        $reply = $booking ? sanitize_email((string) $booking->customer_email) : '';
        MCLB_Mail::send($to, self::subject($heading), $html, [
            'heading'  => $heading,
            'branded'  => false,
            'reply_to' => $reply !== '' ? $reply : '',
            'tag'      => $tag,
        ]);
    }

    // ── Shared bits ─────────────────────────────────────────────────────────────

    private static function subject($heading) {
        return $heading . ' — ' . (wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) ?: 'Cricketers Club');
    }

    private static function first_name($name) {
        $name = trim((string) $name);
        if ($name === '') { return __('there', 'mobooking'); }
        $parts = preg_split('/\s+/', $name);
        return $parts[0];
    }

    public static function when_label($starts_at, $ends_at) {
        if (class_exists('MCLB_Cart')) {
            return MCLB_Cart::when_label($starts_at, $ends_at);
        }
        return (string) $starts_at . ' – ' . (string) $ends_at;
    }

    /**
     * A small email-safe details table for one booking (incl. coach if assigned).
     * $with_price=false omits the price row — used by cancellation emails so the
     * money statement is ONLY the outcome line (never conflicting with WooCommerce's
     * own refund email amounts — WEB-12 addition b).
     */
    private static function booking_table($b, $with_price = true) {
        $cell = 'style="padding:6px 10px;border-bottom:1px solid #e5e5e5;font-size:14px;color:#222;"';
        $lbl  = 'style="padding:6px 10px;border-bottom:1px solid #e5e5e5;font-size:14px;color:#666;white-space:nowrap;"';
        $rows = '';
        $rows .= '<tr><td ' . $lbl . '>' . esc_html(MCLB_Terms::resource()) . '</td><td ' . $cell . '>' . esc_html($b->lane_name) . '</td></tr>';
        $rows .= '<tr><td ' . $lbl . '>' . esc_html__('When', 'mobooking') . '</td><td ' . $cell . '>' . esc_html(self::when_label($b->starts_at, $b->ends_at)) . '</td></tr>';
        if ((int) $b->assigned_coach_id > 0 && class_exists('MCLB_Coaches')) {
            $rows .= '<tr><td ' . $lbl . '>' . esc_html(MCLB_Coaches::label_singular()) . '</td><td ' . $cell . '>' . esc_html(MCLB_Coaches::label((int) $b->assigned_coach_id)) . '</td></tr>';
        }
        if ($with_price && $b->price !== null && class_exists('MCLB_Coaches')) {
            $rows .= '<tr><td ' . $lbl . '>' . esc_html__('Price', 'mobooking') . '</td><td ' . $cell . '>' . esc_html(MCLB_Coaches::money((float) $b->price)) . '</td></tr>';
        }
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0;">' . $rows . '</table>';
    }
}
