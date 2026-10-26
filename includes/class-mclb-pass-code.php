<?php
/**
 * Prepaid-pass bearer codes (Phase 1.5.0). Format CC-XXXX-XXXX, uppercase, from
 * an unambiguous alphabet (no 0/O/1/I/L etc.). Anyone holding the code can redeem
 * it, so the code IS the capability — it must be long + random.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Pass_Code {

    // 31 unambiguous chars: no 0 O 1 I L (and no vowels-that-spell-words risk kept simple).
    const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    const PREFIX   = 'CC';

    /** A fresh random code CC-XXXX-XXXX (8 random chars). */
    public static function generate() {
        $a   = self::ALPHABET;
        $len = strlen($a);
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $a[random_int(0, $len - 1)];
            if ($i === 3) { $out .= '-'; }
        }
        return self::PREFIX . '-' . $out;
    }

    /** Normalise user input: uppercase, strip spaces, tolerate missing dashes. */
    public static function normalise($raw) {
        $s = strtoupper(preg_replace('/\s+/', '', (string) $raw));
        // Accept with or without dashes; re-insert canonical dashes if it's bare.
        $s = str_replace('-', '', $s);
        if (preg_match('/^' . self::PREFIX . '([' . self::ALPHABET . ']{8})$/', $s, $m)) {
            return self::PREFIX . '-' . substr($m[1], 0, 4) . '-' . substr($m[1], 4, 4);
        }
        // Return a lightly-normalised value (lets validation fail cleanly).
        return strtoupper(trim((string) $raw));
    }

    /** Shape check only (not existence). */
    public static function looks_valid($code) {
        return (bool) preg_match('/^' . self::PREFIX . '-[' . self::ALPHABET . ']{4}-[' . self::ALPHABET . ']{4}$/', (string) $code);
    }
}
