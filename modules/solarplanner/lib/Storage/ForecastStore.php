<?php
/**
 * ForecastStore — immutable forecast snapshots and quota state persistence.
 *
 * Abstracted behind a simple interface so the core is testable without a DB.
 * The MajorDoMo module wires a SQL-backed implementation; tests use the file
 * or in-memory one.
 *
 * Rules (spec):
 *  - never overwrite an old forecast with a newer one (append-only snapshots),
 *  - store primary, normalized, adjusted forecasts, calibration version, the
 *    ensemble used for a plan, the planning cutoff and the real outcome,
 *  - never store API keys in a snapshot,
 *  - migrations must be re-safe; updates must not wipe history.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Storage;

interface ForecastStore
{
    /** Persist an immutable forecast run snapshot. Returns an id. */
    public function saveForecastRun(array $runArray);

    /** Load forecast runs available at or before a cutoff (no peeking). */
    public function loadRunsBefore($cutoffUtc, $providerId = null);

    /** Persist a planning run with all inputs and the chosen outcome. */
    public function savePlanningRun(array $planArray);

    /** Load and save opaque quota state (no secrets). */
    public function loadQuotaState();

    public function saveQuotaState(array $state);
}
