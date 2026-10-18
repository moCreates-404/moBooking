<?php
/**
 * Regression test for the customer booking-window comparison.
 *
 * All three enforcement layers — the grid REST (MCLB_Grid::rest_availability),
 * add-to-cart (MCLB_Cart::validate_selection) and cart/checkout validation
 * (MCLB_Cart::validate_cart_items) — funnel their beyond-the-window decision
 * through the one pure helper MCLB_Grid::is_beyond_window(). This test pins that
 * helper's behaviour so the layers can't drift (e.g. a stray `>=`, an off-by-one,
 * or dropping the staff/no-limit `null` exemption).
 *
 * Pure PHP — no WordPress bootstrap. Run from the plugin root:
 *   php tests/test-window.php
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/'); // satisfy the class file's direct-access guard
}
require __DIR__ . '/../includes/class-mclb-grid.php';

$fail = 0;
function t($label, $got, $want) {
    global $fail;
    $ok = ($got === $want);
    echo ($ok ? 'PASS' : 'FAIL') . "  $label\n";
    if (!$ok) { $fail++; echo "      got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"; }
}

// null max = staff / no limit → nothing is ever beyond the window.
t('staff/no-limit (null): far-future date not beyond', MCLB_Grid::is_beyond_window('2099-01-01', null), false);
t('staff/no-limit (null): today not beyond',            MCLB_Grid::is_beyond_window('2026-10-18', null), false);

// Inclusive boundary: the last bookable date itself is allowed.
t('the max date itself is NOT beyond', MCLB_Grid::is_beyond_window('2026-11-01', '2026-11-01'), false);

// One day past the limit is beyond.
t('one day after max IS beyond', MCLB_Grid::is_beyond_window('2026-11-02', '2026-11-01'), true);

// Dates before the limit are fine.
t('an earlier date is not beyond', MCLB_Grid::is_beyond_window('2026-10-20', '2026-11-01'), false);

// Lexicographic Y-m-d edge: month/year rollovers compare correctly.
t('year rollover beyond', MCLB_Grid::is_beyond_window('2027-01-01', '2026-12-31'), true);
t('year rollover not-beyond', MCLB_Grid::is_beyond_window('2026-12-31', '2027-01-01'), false);

echo "\n" . ($fail === 0 ? "ALL PASSED" : "$fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
