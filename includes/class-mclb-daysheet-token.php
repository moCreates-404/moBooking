<?php
/**
 * Day-sheet "View my day" link tokens (Phase 7d). Signed, expiring, revocable.
 *
 *   token = base64url(coach_id|Ymd|exp|ver) . "." . HMAC-SHA256(payload, secret)
 *
 * The secret is a generated option, so regenerating it instantly invalidates every
 * outstanding link. Verification is constant-time (hash_equals) and checks expiry.
 * No login — the token itself is the capability, scoped to one coach + one date.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Daysheet_Token {

    const SECRET_OPTION = 'mclb_daysheet_secret';
    const VER           = '1';

    /** The signing secret, generated once on first use. */
    public static function secret() {
        $s = get_option(self::SECRET_OPTION);
        if (!$s || !is_string($s)) {
            $s = bin2hex(random_bytes(32));
            update_option(self::SECRET_OPTION, $s, false);
        }
        return $s;
    }

    /** Rotate the secret — revokes all existing links. */
    public static function rotate() {
        $s = bin2hex(random_bytes(32));
        update_option(self::SECRET_OPTION, $s, false);
        return $s;
    }

    private static function b64url_encode($s) {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
    private static function b64url_decode($s) {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    /** Build a token for a coach + date (Y-m-d), valid for $expiry_hours. */
    public static function make($coach_id, $date, $expiry_hours) {
        $exp     = time() + max(1, (int) $expiry_hours) * 3600;
        $payload = (int) $coach_id . '|' . str_replace('-', '', $date) . '|' . $exp . '|' . self::VER;
        $sig     = hash_hmac('sha256', $payload, self::secret());
        return self::b64url_encode($payload) . '.' . $sig;
    }

    /**
     * Verify a token. Returns ['coach_id'=>int,'date'=>'Y-m-d'] or false (bad
     * signature, malformed, or expired).
     */
    public static function verify($token) {
        $token = (string) $token;
        $dot   = strrpos($token, '.');
        if ($dot === false) {
            return false;
        }
        $payload = self::b64url_decode(substr($token, 0, $dot));
        $sig     = substr($token, $dot + 1);
        if ($payload === '' || $sig === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, self::secret());
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $parts = explode('|', $payload);
        if (count($parts) !== 4) {
            return false;
        }
        list($coach_id, $ymd, $exp, $ver) = $parts;
        if ($ver !== self::VER || !preg_match('/^\d{8}$/', $ymd)) {
            return false;
        }
        if ((int) $exp < time()) {
            return false;
        }
        return [
            'coach_id' => (int) $coach_id,
            'date'     => substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2),
        ];
    }

    /** Public URL carrying a fresh token. */
    public static function url($coach_id, $date, $expiry_hours) {
        return add_query_arg('mclb_day_token', self::make($coach_id, $date, $expiry_hours), home_url('/'));
    }
}
