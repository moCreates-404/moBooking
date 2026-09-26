<?php
/**
 * Availability engine — pure computation, no writes/hooks. Turns Phase 1's data
 * (lane hours, closures, confirmed bookings + live holds) into render-ready,
 * increment-aligned slots for the Phase 3 grid.
 *
 * Output is a fixed slot grid (one cell per booking increment), not
 * variable-length segments: the grid snaps drag-select to whole increments, so
 * the front end renders cells directly and never re-slices. Each slot is
 * available / booked / closed. ("Unfinished" — Phase 0's 4th palette colour —
 * is the client-side drag-in-progress style, NOT a state this engine emits.)
 *
 * Precedence on overlap: closed > booked > available. A closure or booking that
 * touches ANY part of a slot blocks the whole slot (conservative — never sell a
 * partly-blocked increment).
 *
 * Timezone: every boundary is DateTimeImmutable in wp_timezone(); interval math
 * is in minutes-from-midnight. No strtotime() anywhere — matching Phase 1's
 * local-wall-clock storage. (Same documented DST edge as Phase 1; Perth has none.)
 *
 * @package moBooking
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCLB_Availability {

    /**
     * One lane, one date.
     *
     * $args (all optional):
     *   'increment' => int minutes (defaults to the booking_increment setting)
     *   'closures'  => array pre-fetched closure rows (batch path; skips a query)
     *   'bookings'  => array pre-fetched active booking rows (batch path)
     *
     * @return array See the shape assembled below.
     */
    public static function for_lane($lane_id, $date, array $args = []) {
        $lane_id = (int) $lane_id;
        $tz      = wp_timezone();

        $increment = isset($args['increment']) ? (int) $args['increment'] : (int) MCLB_Settings::get('booking_increment');
        if ($increment < 1) {
            $increment = 60;
        }

        $midnight = new DateTimeImmutable($date . ' 00:00:00', $tz);
        $weekday  = (int) $midnight->format('N'); // 1=Mon … 7=Sun

        $result = [
            'lane_id'        => $lane_id,
            'lane_name'      => get_the_title($lane_id),
            'type'           => MCLB_Lane::get_type($lane_id),
            'price_per_hour' => MCLB_Lane::get_price($lane_id),
            'date'           => $date,
            'weekday'        => $weekday,
            'is_open'        => false,
            'open'           => null,
            'close'          => null,
            'increment'      => $increment,
            'slots'          => [],
        ];

        // 1. Resolve the day's opening window.
        $hours = MCLB_Lane::get_hours($lane_id);
        $h     = isset($hours[$weekday]) && is_array($hours[$weekday]) ? $hours[$weekday] : null;
        if (!$h || !empty($h['closed'])) {
            return $result; // closed all day
        }
        $open_min  = self::hm_to_min($h['open']);
        $close_min = self::hm_to_min($h['close']);
        if ($open_min === null || $close_min === null || $close_min <= $open_min) {
            return $result; // no / inverted window
        }
        $result['is_open'] = true;
        $result['open']    = substr($h['open'], 0, 5);
        $result['close']   = substr($h['close'], 0, 5);

        // 2 & 3. Gather blocking intervals (minutes-from-midnight) for this date.
        $day_start = $date . ' 00:00:00';
        $day_end   = $date . ' 23:59:59';

        $closures = array_key_exists('closures', $args) ? (array) $args['closures'] : MCLB_Closures::for_lane($lane_id);
        $bookings = array_key_exists('bookings', $args) ? (array) $args['bookings'] : MCLB_Bookings::active_for_lane($lane_id, $day_start, $day_end);

        $closed_intervals = self::closed_intervals($closures, $date, $weekday, $midnight, $tz);
        $booked_intervals = self::booked_intervals($bookings, $midnight, $tz);

        // 4. Slice the window into increment slots and tag each.
        $slots = [];
        for ($s = $open_min; $s + $increment <= $close_min; $s += $increment) {
            $e     = $s + $increment;
            $state = 'available';
            $label = null;
            $bid   = null;

            foreach ($closed_intervals as $ci) {
                if (self::overlaps($s, $e, $ci['start'], $ci['end'])) {
                    $state = 'closed';
                    $label = $ci['label'];
                    break;
                }
            }
            if ($state === 'available') {
                foreach ($booked_intervals as $bi) {
                    if (self::overlaps($s, $e, $bi['start'], $bi['end'])) {
                        $state = 'booked';
                        $bid   = $bi['id'];
                        break;
                    }
                }
            }

            $slots[] = self::slot($midnight, $s, $e, $state, $label, $bid);
        }

        $result['slots'] = $slots;
        return $result;
    }

    /**
     * Every bookable lane for one date — the Phase 3 grid payload. Exactly 3
     * queries regardless of lane count (lanes, closures, bookings), then PHP
     * interval math per lane.
     *
     * $args: 'type' => filter to one resource type; 'increment' => override.
     *
     * @return array{date:string,increment:int,grid:array,lanes:array}
     */
    public static function for_day($date, array $args = []) {
        $increment = isset($args['increment']) ? (int) $args['increment'] : (int) MCLB_Settings::get('booking_increment');
        if ($increment < 1) {
            $increment = 60;
        }
        $type = isset($args['type']) ? (string) $args['type'] : '';

        $result = [
            'date'      => $date,
            'increment' => $increment,
            'grid'      => ['min_open' => null, 'max_close' => null],
            'lanes'     => [],
        ];

        $lanes = MCLB_Lane::all_bookable($type);
        if (empty($lanes)) {
            return $result;
        }
        $ids = array_map(function ($p) { return (int) $p->ID; }, $lanes);

        $day_start = $date . ' 00:00:00';
        $day_end   = $date . ' 23:59:59';

        // Query 2 & 3 — closures and bookings for the whole set at once.
        $closures_all = MCLB_Closures::for_lanes($ids);
        $bookings_all = MCLB_Bookings::active_for_lanes($ids, $day_start, $day_end);

        // Group in PHP; site-wide closures (lane_id = 0) fan out to every lane.
        $site_closures = [];
        $closures_by   = [];
        foreach ($closures_all as $c) {
            if ((int) $c->lane_id === 0) {
                $site_closures[] = $c;
            } else {
                $closures_by[(int) $c->lane_id][] = $c;
            }
        }
        $bookings_by = [];
        foreach ($bookings_all as $b) {
            $bookings_by[(int) $b->lane_id][] = $b;
        }

        $min_open = null;
        $max_close = null;
        foreach ($lanes as $lane) {
            $id    = (int) $lane->ID;
            $avail = self::for_lane($id, $date, [
                'increment' => $increment,
                'closures'  => array_merge($site_closures, $closures_by[$id] ?? []),
                'bookings'  => $bookings_by[$id] ?? [],
            ]);
            $result['lanes'][$id] = $avail;

            if ($avail['is_open']) {
                if ($min_open === null || $avail['open'] < $min_open) {
                    $min_open = $avail['open'];
                }
                if ($max_close === null || $avail['close'] > $max_close) {
                    $max_close = $avail['close'];
                }
            }
        }

        $result['grid'] = ['min_open' => $min_open, 'max_close' => $max_close];
        return $result;
    }

    // ── Interval helpers ────────────────────────────────────────────────────

    /** @return array<int,array{start:int,end:int,label:?string}> */
    private static function closed_intervals(array $closures, $date, $weekday, DateTimeImmutable $midnight, DateTimeZone $tz) {
        $out = [];
        foreach ($closures as $c) {
            if ($c->kind === 'recurring') {
                if ((int) $c->weekday !== (int) $weekday) {
                    continue;
                }
                if (!empty($c->active_from) && $date < $c->active_from) {
                    continue;
                }
                if (!empty($c->active_until) && $date > $c->active_until) {
                    continue;
                }
                $s = self::hm_to_min((string) $c->start_time);
                $e = self::hm_to_min((string) $c->end_time);
                if ($s !== null && $e !== null && $e > $s) {
                    $out[] = ['start' => $s, 'end' => $e, 'label' => $c->label];
                }
            } else { // one-off
                if (empty($c->starts_at) || empty($c->ends_at)) {
                    continue;
                }
                $s = self::min_of_day(new DateTimeImmutable($c->starts_at, $tz), $midnight);
                $e = self::min_of_day(new DateTimeImmutable($c->ends_at, $tz), $midnight);
                $s = max(0, $s);
                $e = min(1440, $e);
                if ($e > $s) {
                    $out[] = ['start' => $s, 'end' => $e, 'label' => $c->label];
                }
            }
        }
        return $out;
    }

    /** @return array<int,array{start:int,end:int,id:int}> */
    private static function booked_intervals(array $bookings, DateTimeImmutable $midnight, DateTimeZone $tz) {
        $out = [];
        foreach ($bookings as $b) {
            if (empty($b->starts_at) || empty($b->ends_at)) {
                continue;
            }
            $s = max(0, self::min_of_day(new DateTimeImmutable($b->starts_at, $tz), $midnight));
            $e = min(1440, self::min_of_day(new DateTimeImmutable($b->ends_at, $tz), $midnight));
            if ($e > $s) {
                $out[] = ['start' => $s, 'end' => $e, 'id' => (int) $b->id];
            }
        }
        return $out;
    }

    private static function slot(DateTimeImmutable $midnight, $s_min, $e_min, $state, $label, $booking_id) {
        $start = $midnight->modify('+' . (int) $s_min . ' minutes');
        $end   = $midnight->modify('+' . (int) $e_min . ' minutes');
        return [
            'start'      => $start->format('Y-m-d H:i:s'),
            'end'        => $end->format('Y-m-d H:i:s'),
            'start_min'  => (int) $s_min,
            'end_min'    => (int) $e_min,
            'state'      => $state,
            'label'      => $label,
            'booking_id' => $booking_id,
        ];
    }

    /** "HH:MM" (or "HH:MM:SS") → minutes from midnight, or null if unparseable. */
    private static function hm_to_min($hm) {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string) $hm, $m)) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    private static function min_of_day(DateTimeImmutable $dt, DateTimeImmutable $midnight) {
        return (int) floor(($dt->getTimestamp() - $midnight->getTimestamp()) / 60);
    }

    /** Half-open overlap: [s,e) intersects [cs,ce). */
    private static function overlaps($s, $e, $cs, $ce) {
        return $s < $ce && $cs < $e;
    }
}
