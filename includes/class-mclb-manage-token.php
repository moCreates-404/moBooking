<?php
/**
 * Guest "Manage booking" link tokens (Phase 1.4.3, WEB-17). Signed, no login.
 *
 *   token = base64url(booking_id | ver) . "." . HMAC-SHA256(booking_id|created_at|ver, secret)
 *
 * created_at is folded into the signature (not exposed) so a token is bound to the
 * exact booking row and can't be forged or transplanted. There is NO expiry baked
 * in — validity is gated at use time (MCLB_Manage_Booking): the booking must exist,
 * be confirmed, and not be past. Regenerating the secret revokes every outstanding
 * link. Verification is constant-time.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Manage_Token {

    const SECRET_OPTION = 'mclb_manage_secret';
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

    /** Rotate the secret — revokes all existing manage links. */
    public static function rotate() {
        $s = bin2hex(random_bytes(32));
        update_option(self::SECRET_OPTION, $s, false);
        return $s;
    }

    private static function b64url_encode($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function b64url_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }

    private static function sign($booking_id, $created_at) {
        return hash_hmac('sha256', (int) $booking_id . '|' . (string) $created_at . '|' . self::VER, self::secret());
    }

    /** Build a token for a booking row (needs ->id and ->created_at). */
    public static function make($b) {
        if (!$b || empty($b->id) || empty($b->created_at)) { return ''; }
        $payload = (int) $b->id . '|' . self::VER;
        return self::b64url_encode($payload) . '.' . self::sign((int) $b->id, $b->created_at);
    }

    /**
     * Verify a token against its booking. Returns the booking id (int) or false.
     * Does NOT check status/past — the page does that so it can show a clear
     * "already cancelled / past" message rather than a blank "invalid link".
     */
    public static function verify($token) {
        $token = (string) $token;
        $dot   = strrpos($token, '.');
        if ($dot === false) { return false; }
        $payload = self::b64url_decode(substr($token, 0, $dot));
        $sig     = substr($token, $dot + 1);
        if ($payload === '' || $sig === '') { return false; }
        $parts = explode('|', $payload);
        if (count($parts) !== 2 || $parts[1] !== self::VER || !ctype_digit((string) $parts[0])) { return false; }
        $id = (int) $parts[0];
        $b  = MCLB_Bookings::get($id);
        if (!$b || empty($b->created_at)) { return false; }
        if (!hash_equals(self::sign($id, $b->created_at), $sig)) { return false; }
        return $id;
    }

    /** Public URL carrying a fresh token for a booking. */
    public static function url($b) {
        $t = self::make($b);
        return $t === '' ? '' : add_query_arg('mclb_manage', $t, home_url('/'));
    }
}
