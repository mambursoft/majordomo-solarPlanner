<?php
/**
 * ForecastAdapter — contract for a forecast source.
 *
 * Each adapter is independent: an outage in one must not block the planner.
 * Adapters never store API keys in the returned ForecastRun.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

interface ForecastAdapter
{
    /** Stable provider id, e.g. 'open_meteo'. */
    public function providerId();

    /**
     * Fetch and normalize a forecast for [fromUtc, toUtc).
     *
     * @param int $fromUtc
     * @param int $toUtc
     * @param bool $critical allow use of reserved quota for a critical refinement
     * @return array ['run'=>ForecastRun|null,'status'=>string,'detail'=>string]
     *   status: fresh | stale | partial | unusable | missing
     */
    public function fetch($fromUtc, $toUtc, $critical = false);
}
