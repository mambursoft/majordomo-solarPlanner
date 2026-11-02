<?php
/**
 * FetchScheduler — decide, per provider, WHEN the next real HTTP fetch is
 * allowed, so we spread a limited daily quota sensibly over the day and night
 * instead of burning it in a burst.
 *
 * Policy (agreed with the owner):
 *  - A plan recompute happens often (every ~15 min) but reads from the cache;
 *    only an actual fetch spends quota.
 *  - DAYTIME (sunrise..sunset): PV for *today* is already materialising, so its
 *    value is low -> refresh sparsely (a few times across the daylight window).
 *  - NIGHT (the cheap-tariff window, e.g. 23:00..07:00): the forecast for
 *    *tomorrow* decides how many kWh to buy cheaply, so it matters most ->
 *    refresh several times, DENSER as we approach the last useful top-up time.
 *  - A per-provider minimum interval protects hourly-IP limits (Forecast.Solar)
 *    and tiny daily limits (Solcast).
 *
 * Pure logic: sunrise/sunset and "now" are passed in (UTC unix seconds), so it
 * is fully unit-testable. No network, no globals.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class FetchScheduler
{
    /** @var array per-provider policy */
    private $policy;

    /**
     * @param array $policy keyed by provider id; each:
     *   min_interval_sec (int)      hard floor between fetches
     *   day_fetches (int)           target fetches across the daylight window
     *   night_fetches (int)         target fetches across the cheap-night window
     *   night_only (bool)           if true, only fetch during the night window
     *                               (useful for a scarce quota you want to spend
     *                               on the decision that matters: tomorrow)
     */
    public function __construct(array $policy = array())
    {
        $this->policy = $policy;
    }

    /**
     * Should we fetch this provider now?
     *
     * @param string $providerId
     * @param int $nowUtc
     * @param int|null $lastFetchUtc last successful fetch (null = never)
     * @param array $ctx keys:
     *   sunrise_utc, sunset_utc        today's daylight window (UTC)
     *   night_start_utc, night_end_utc current/next cheap-tariff window (UTC)
     *   critical_refine_utc            last-useful night top-up time (UTC)
     * @return array ['fetch'=>bool, 'reason'=>string, 'next_utc'=>int]
     */
    public function shouldFetch($providerId, $nowUtc, $lastFetchUtc, array $ctx)
    {
        $p = $this->providerPolicy($providerId);
        $nowUtc = (int)$nowUtc;

        // Never fetched -> fetch now (bootstrap).
        if ($lastFetchUtc === null || $lastFetchUtc <= 0) {
            return array('fetch' => true, 'reason' => 'bootstrap (no cached forecast yet)', 'next_utc' => $nowUtc);
        }
        $lastFetchUtc = (int)$lastFetchUtc;
        $age = $nowUtc - $lastFetchUtc;

        // Hard minimum interval (protects IP/day limits) always applies.
        if ($age < $p['min_interval_sec']) {
            return array('fetch' => false, 'reason' => 'min interval not elapsed',
                'next_utc' => $lastFetchUtc + $p['min_interval_sec']);
        }

        $isNight = $this->inWindow($nowUtc, $ctx, 'night_start_utc', 'night_end_utc');
        $isDay = $this->inWindow($nowUtc, $ctx, 'sunrise_utc', 'sunset_utc');

        // The interval we aim for right now depends on the phase.
        if ($isNight) {
            $interval = $this->nightInterval($p, $nowUtc, $ctx);
            $phase = 'night';
        } elseif ($isDay) {
            $interval = $this->windowInterval($ctx['sunrise_utc'], $ctx['sunset_utc'], $p['day_fetches'], $p['min_interval_sec']);
            $phase = 'day';
        } else {
            // Dusk/pre-dawn gap outside both windows: PV ~0 and not the decision
            // window. Only fetch if we are very stale (keeps the cache warm).
            if (!empty($p['night_only'])) {
                return array('fetch' => false, 'reason' => 'outside night window (night_only provider)',
                    'next_utc' => isset($ctx['night_start_utc']) ? (int)$ctx['night_start_utc'] : $nowUtc + 3600);
            }
            $interval = max($p['min_interval_sec'], 3 * 3600); // idle: at most every 3h
            $phase = 'idle';
        }

        if ($phase !== 'night' && !empty($p['night_only'])) {
            return array('fetch' => false, 'reason' => 'night_only provider, not night',
                'next_utc' => isset($ctx['night_start_utc']) ? (int)$ctx['night_start_utc'] : $nowUtc + 3600);
        }

        if ($age >= $interval) {
            return array('fetch' => true, 'reason' => $phase . ' refresh due (age ' . $age . 's >= ' . $interval . 's)',
                'next_utc' => $nowUtc);
        }
        return array('fetch' => false, 'reason' => $phase . ' refresh not due',
            'next_utc' => $lastFetchUtc + $interval);
    }

    /**
     * Night interval: spread night_fetches across the cheap window, but make the
     * cadence DENSER as we approach the critical last-top-up time, because that
     * is when "how many kWh to buy" is finalised. We halve the interval within
     * the last ~2h before the critical refine time.
     */
    private function nightInterval(array $p, $nowUtc, array $ctx)
    {
        $start = isset($ctx['night_start_utc']) ? (int)$ctx['night_start_utc'] : $nowUtc;
        $end = isset($ctx['night_end_utc']) ? (int)$ctx['night_end_utc'] : $nowUtc + 8 * 3600;
        $base = $this->windowInterval($start, $end, $p['night_fetches'], $p['min_interval_sec']);

        $critical = isset($ctx['critical_refine_utc']) ? (int)$ctx['critical_refine_utc'] : $end;
        $toCritical = $critical - $nowUtc;
        if ($toCritical > 0 && $toCritical <= 2 * 3600) {
            // Densify near the decision point.
            $base = max($p['min_interval_sec'], (int)round($base / 2));
        }
        return $base;
    }

    /** Even spacing of N fetches across [start,end), floored by the min interval. */
    private function windowInterval($start, $end, $fetches, $minInterval)
    {
        $start = (int)$start;
        $end = (int)$end;
        $span = max(0, $end - $start);
        $fetches = max(1, (int)$fetches);
        if ($span <= 0) {
            return max($minInterval, 3600);
        }
        // N fetches across the window -> interval = span / N (so the first is at
        // the start and subsequent ones spread out).
        return max($minInterval, (int)floor($span / $fetches));
    }

    private function inWindow($nowUtc, array $ctx, $startKey, $endKey)
    {
        if (!isset($ctx[$startKey]) || !isset($ctx[$endKey])) {
            return false;
        }
        $s = (int)$ctx[$startKey];
        $e = (int)$ctx[$endKey];
        if ($e <= $s) {
            return false;
        }
        return $nowUtc >= $s && $nowUtc < $e;
    }

    private function providerPolicy($providerId)
    {
        $defaults = array(
            'min_interval_sec' => 900,   // 15 min hard floor
            'day_fetches' => 4,          // a few daytime refreshes
            'night_fetches' => 4,        // several night refreshes (tomorrow matters)
            'night_only' => false,
        );
        if (isset($this->policy[$providerId])) {
            return array_merge($defaults, $this->policy[$providerId]);
        }
        return $defaults;
    }
}
