<?php
/**
 * License handling — STUB for Phase 0.
 *
 * The real check will hit moCreates' own simple self-managed key store (no
 * EDD/Freemius). What happens on an invalid/expired key — read-only mode vs a
 * nag notice vs hard-disable — is deliberately NOT enforced yet; that's a
 * decision to make when the real endpoint is wired. Until then any non-empty
 * key is treated as valid so development is never blocked.
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_License {

    /**
     * @return array{valid:bool,status:string,message:string}
     */
    public static function status() {
        $key = trim((string) MCLB_Settings::get('license_key'));

        if ($key === '') {
            return [
                'valid'   => false,
                'status'  => 'empty',
                'message' => __('No license key entered.', 'mclb-lane-booking'),
            ];
        }

        // TODO: call moCreates key store here, and implement the invalid/expired
        //       gating behaviour once Morg decides (read-only / nag / disable).
        return [
            'valid'   => true,
            'status'  => 'active',
            'message' => __('License active (development stub — not yet validated remotely).', 'mclb-lane-booking'),
        ];
    }

    public static function is_valid() {
        return self::status()['valid'];
    }
}
