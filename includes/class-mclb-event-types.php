<?php
/**
 * Event types for blockouts (Phase 7a) — a small settings-backed vocabulary
 * (label + colour + whether the label shows publicly) that closures tag
 * themselves with. Stored in the MCLB_OPTION array under 'event_types'; this
 * class is the read/resolve layer the closures form, the availability engine
 * and (later) the Manage view share.
 *
 * Slugs are the stable key closures store — renaming a type's label keeps its
 * slug, so existing blockouts keep their type. A closure whose event_type is
 * NULL or points at a since-deleted type resolves to the neutral generic
 * "Unavailable" with the fallback colour — never a blank or a PHP notice.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Event_Types {

    /** Neutral fallback colour for unknown / deleted / untyped blockouts. */
    const FALLBACK_COLOR = '#85c9c2';

    /** @return array<int,array{slug:string,label:string,color:string,public:int}> */
    public static function all() {
        $types = MCLB_Settings::get('event_types');
        return is_array($types) ? $types : [];
    }

    /** One type row by slug, or null when not found. */
    public static function get($slug) {
        $slug = (string) $slug;
        if ($slug === '') {
            return null;
        }
        foreach (self::all() as $t) {
            if (isset($t['slug']) && $t['slug'] === $slug) {
                return $t;
            }
        }
        return null;
    }

    /** [slug => label] for dropdowns. */
    public static function options() {
        $out = [];
        foreach (self::all() as $t) {
            if (!empty($t['slug'])) {
                $out[$t['slug']] = $t['label'];
            }
        }
        return $out;
    }

    /** The generic, never-leaks label shown to customers. */
    public static function generic_label() {
        return __('Unavailable', 'mclb-lane-booking');
    }

    /**
     * Public-facing label for a closure's event_type: the type's own label ONLY
     * when the type exists and is flagged public; otherwise generic "Unavailable".
     */
    public static function public_label($slug) {
        $t = self::get($slug);
        if ($t && !empty($t['public'])) {
            return $t['label'];
        }
        return self::generic_label();
    }

    /** Admin-facing label: the type label if the type is known, else generic. */
    public static function admin_label($slug) {
        $t = self::get($slug);
        return $t ? $t['label'] : self::generic_label();
    }

    /** Colour for a type, with the neutral fallback for unknown/untyped. */
    public static function color($slug) {
        $t = self::get($slug);
        return ($t && !empty($t['color'])) ? $t['color'] : self::FALLBACK_COLOR;
    }
}
