<?php
/**
 * Phone helpers (Phase 8a) — loose, forgiving validation and AU display
 * formatting. moBooking is generic, so validation is deliberately permissive
 * (any 8+ digit number, optional leading +); the pretty AU grouping is applied
 * only when a number clearly looks Australian, otherwise the cleaned input is
 * returned unchanged.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Phone {

    /** Store-ready form: trim, drop spaces/separators, keep a leading + and digits. */
    public static function clean($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $plus = (strpos($raw, '+') === 0) ? '+' : '';
        return $plus . preg_replace('/\D+/', '', $raw);
    }

    /** Just the digits (no +), for counting/normalising. */
    public static function digits($raw) {
        return preg_replace('/\D+/', '', (string) $raw);
    }

    /** Loose validity: at least 8 digits after cleaning. */
    public static function valid($raw) {
        return strlen(self::digits($raw)) >= 8;
    }

    /** AU national digits (0XXXXXXXXX) when the number looks Australian, else ''. */
    private static function national($raw) {
        $d = self::digits($raw);
        if (strpos(self::clean($raw), '+61') === 0 || (strlen($d) === 11 && strpos($d, '61') === 0)) {
            $d = '0' . substr($d, 2); // +61 4xx… → 04xx…
        }
        return (strlen($d) === 10 && $d[0] === '0') ? $d : '';
    }

    /** Pretty display, e.g. "0412 345 678" / "08 9471 2345"; unknown forms pass through. */
    public static function format_au($raw) {
        $raw = (string) $raw;
        if ($raw === '') {
            return '';
        }
        $n = self::national($raw);
        if ($n === '') {
            return self::clean($raw); // international or unrecognised — leave as entered (cleaned)
        }
        if ($n[1] === '4') { // mobile: 04xx xxx xxx
            return substr($n, 0, 4) . ' ' . substr($n, 4, 3) . ' ' . substr($n, 7, 3);
        }
        return substr($n, 0, 2) . ' ' . substr($n, 2, 4) . ' ' . substr($n, 6, 4); // landline: 0x xxxx xxxx
    }
}
