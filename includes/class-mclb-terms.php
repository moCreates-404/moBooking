<?php
/**
 * Site terminology — what this install calls its bookable units, its staff
 * and its prepaid credit.
 *
 * Every user-facing string that names one of these concepts is written with a
 * token and passed through fill(), e.g.
 *
 *     MCLB_Terms::fill(__('That {resource} is already booked.', 'mobooking'))
 *
 * so a tennis club reads "court", a gym "studio", a garage "hoist" — while the
 * database keeps its technical names (mclb_lane, coach_id, pass_id) forever.
 *
 * Tokens (lower-case form for mid-sentence, capitalised for starts/labels):
 *   {resource} {resources} {Resource} {Resources}
 *   {staff}    {staff_plural} {Staff} {Staff_plural}
 *   {pass}     {passes}    {Pass}     {Passes}
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Terms {

    /** Neutral defaults for NEW installs (see MCLB_Settings::defaults()). */
    const DEFAULTS = [
        'resource_label_singular' => 'Resource',
        'resource_label_plural'   => 'Resources',
        'staff_label_singular'    => 'Instructor',
        'staff_label_plural'      => 'Instructors',
        'pass_label_singular'     => 'Pass',
        'pass_label_plural'       => 'Passes',
    ];

    /**
     * What pre-1.6 installs effectively used. Existing installs that never
     * saved a label get these pinned on upgrade, so their wording never changes
     * underneath them (see MCLB_Settings::maybe_migrate()).
     */
    const LEGACY = [
        'resource_label_singular' => 'Lane',
        'resource_label_plural'   => 'Lanes',
        'staff_label_singular'    => 'Coach',
        'staff_label_plural'      => 'Coaches',
        'pass_label_singular'     => 'Pass',
        'pass_label_plural'       => 'Passes',
    ];

    /** @var array|null Per-request cache of token => replacement. */
    private static $map = null;

    public static function resource($plural = false) {
        return self::label($plural ? 'resource_label_plural' : 'resource_label_singular');
    }

    public static function staff($plural = false) {
        return self::label($plural ? 'staff_label_plural' : 'staff_label_singular');
    }

    public static function pass($plural = false) {
        return self::label($plural ? 'pass_label_plural' : 'pass_label_singular');
    }

    /** Replace terminology tokens in an already-translated string. */
    public static function fill($text) {
        if (!is_string($text) || strpos($text, '{') === false) {
            return $text;
        }
        $map = self::map();
        // English article agreement: "a {staff}" → "an instructor" / "a coach".
        $text = preg_replace_callback('/\b([Aa]) (\{[A-Za-z_]+\})/', function ($m) use ($map) {
            $word = $map[$m[2]] ?? null;
            if ($word === null) {
                return $m[0];
            }
            $an = (bool) preg_match('/^[aeiou]/i', $word);
            return ($an ? $m[1] . 'n' : $m[1]) . ' ' . $m[2];
        }, $text);
        return strtr($text, $map);
    }

    /** Labels for front-end/admin scripts. */
    public static function for_js() {
        return [
            'resourceSingular' => self::resource(),
            'resourcePlural'   => self::resource(true),
            'staffSingular'    => self::staff(),
            'staffPlural'      => self::staff(true),
            'passSingular'     => self::pass(),
            'passPlural'       => self::pass(true),
        ];
    }

    /** Call after settings change within a request. */
    public static function flush() {
        self::$map = null;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private static function label($key) {
        $v = trim((string) MCLB_Settings::get($key));
        return $v !== '' ? $v : self::DEFAULTS[$key];
    }

    private static function map() {
        if (self::$map !== null) {
            return self::$map;
        }
        $pairs = [
            'resource' => [self::resource(), self::resource(true)],
            'staff'    => [self::staff(), self::staff(true)],
            'pass'     => [self::pass(), self::pass(true)],
        ];
        $map = [];
        foreach ($pairs as $token => $forms) {
            list($one, $many) = $forms;
            $plural_token = $token === 'resource' ? 'resources' : ($token === 'pass' ? 'passes' : 'staff_plural');
            $map['{' . $token . '}']                = self::lower($one);
            $map['{' . $plural_token . '}']         = self::lower($many);
            $map['{' . ucfirst($token) . '}']       = self::upper($one);
            $map['{' . ucfirst($plural_token) . '}'] = self::upper($many);
        }
        return self::$map = $map;
    }

    /**
     * Mid-sentence form: lower-case each word, but leave acronyms alone
     * ("VIP Room" → "VIP room", "Bowling Machine" → "bowling machine").
     */
    private static function lower($label) {
        return preg_replace_callback('/\S+/u', function ($m) {
            $w = $m[0];
            if (preg_match('/^\p{Lu}{2,}/u', $w)) {
                return $w; // acronym
            }
            return mb_strtolower($w);
        }, $label);
    }

    private static function upper($label) {
        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }
}
