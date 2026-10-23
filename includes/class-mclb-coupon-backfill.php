<?php
/**
 * One-time coupon snapshot backfill (Phase 1.4.2) — fills the new `coupons`
 * column on existing online bookings (those with an order_id) so the calendar
 * can show coupon usage without hitting WooCommerce on day load.
 *
 * Runs in small Action Scheduler batches (WC bundles Action Scheduler): each run
 * snapshots a handful of orders and re-enqueues itself while any booking still
 * has coupons IS NULL AND order_id IS NOT NULL. EVERY touched row gets a value
 * ('[]' when no coupon reduced the line, or the snapshot), so NULL reliably means
 * "not yet checked" and the loop terminates. Idempotent + re-entrancy-guarded.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Coupon_Backfill {

    const HOOK  = 'mclb_backfill_coupons';
    const FLAG  = 'mclb_coupon_backfill_pending';
    const BATCH = 25; // orders per run

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'run_batch']);
        // Self-heal: the v7 migration sets the pending flag; once WC/Action
        // Scheduler is loaded (init), (re)queue until the work is done. Cheap once
        // finished — the flag is cleared and the COUNT stops running.
        add_action('init', [__CLASS__, 'ensure'], 20);
    }

    /** On a normal load, (re)start the backfill while the pending flag stands. */
    public static function ensure() {
        if (!get_option(self::FLAG)) {
            return;
        }
        if (self::remaining() === 0) {
            delete_option(self::FLAG);
            return;
        }
        self::maybe_start();
    }

    /** Are there still online bookings left to snapshot? */
    public static function remaining() {
        global $wpdb;
        $bt = MCLB_Bookings::table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $bt WHERE coupons IS NULL AND order_id IS NOT NULL");
    }

    /**
     * Kick off the backfill if there is anything to do and it isn't already
     * queued. Called from the DB v7 migration (upgrade-gated). Safe to call more
     * than once — the scheduled-action guard prevents duplicates. If Action
     * Scheduler isn't available yet, runs one synchronous batch as a fallback.
     */
    public static function maybe_start() {
        if (self::remaining() === 0) {
            return;
        }
        if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action')) {
            if (!as_has_scheduled_action(self::HOOK)) {
                as_enqueue_async_action(self::HOOK, [], 'mclb');
            }
        } else {
            // No scheduler (e.g. WC not loaded during CLI activation) — do one batch
            // now; the rest self-heals on the next load once AS is present.
            self::run_batch();
        }
    }

    /**
     * Snapshot up to BATCH orders, then re-enqueue while work remains. Processes
     * by DISTINCT order so each order is read once (snapshot_coupons writes every
     * one of that order's booking rows). A missing/deleted order still marks its
     * rows '[]' so the loop can't stall on it.
     */
    public static function run_batch() {
        global $wpdb;
        $bt = MCLB_Bookings::table();
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM $bt WHERE coupons IS NULL AND order_id IS NOT NULL ORDER BY order_id LIMIT %d",
            self::BATCH
        ));
        if (empty($order_ids)) {
            return;
        }
        $now = current_time('mysql');
        foreach ($order_ids as $oid) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $oid) : null;
            if ($order && is_a($order, 'WC_Order')) {
                MCLB_Bookings::snapshot_coupons($order);
            } else {
                // Order gone: mark its rows checked-empty so they stop matching.
                $wpdb->update($bt, ['coupons' => '[]', 'updated_at' => $now], ['order_id' => (int) $oid], ['%s', '%s'], ['%d']);
            }
        }
        // More to do? Queue the next batch. Otherwise clear the pending flag.
        if (self::remaining() > 0) {
            if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action') && !as_has_scheduled_action(self::HOOK)) {
                as_enqueue_async_action(self::HOOK, [], 'mclb');
            }
        } else {
            delete_option(self::FLAG);
        }
    }
}
