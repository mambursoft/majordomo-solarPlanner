<?php
/**
 * ForecastRun — normalized, immutable forecast snapshot.
 *
 * Every adapter returns this single shape. Fields follow the spec's required
 * run-level and interval-level metadata so that forecasts can be archived
 * without peeking (a plan made at 22:30 must only use data available at 22:30).
 *
 * This object carries NO API keys and NO raw secrets.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class ForecastRun
{
    public $providerId;
    public $forecastRunId;
    public $retrievedAtUtc;
    public $issuedAtUtc;        // null if unknown — do NOT invent
    public $validFromUtc;
    public $validToUtc;
    public $locationConfigHash;
    public $pvConfigHash;
    public $modelId;            // null if unknown
    public $energyBasis;        // 'interval' | 'cumulative' | 'mean_power'
    public $rawResponseHash;
    public $qualityFlags = array();
    public $quotaState = array();

    /** @var array list of interval rows */
    public $intervals = array();

    public function __construct($providerId)
    {
        $this->providerId = $providerId;
        $this->forecastRunId = $providerId . '-' . uniqid('', true);
        $this->retrievedAtUtc = time();
    }

    /**
     * Add a normalized interval.
     *
     * @param int $startUtc
     * @param int $endUtc
     * @param float|null $pvEnergyKwh energy in the interval (null = missing)
     * @param array $extra optional: pv_mean_power_kw, array_id, is_interpolated,
     *              quantile_low_kwh, quantile_median_kwh, quantile_high_kwh
     */
    public function addInterval($startUtc, $endUtc, $pvEnergyKwh, array $extra = array())
    {
        $row = array(
            'interval_start_utc' => (int)$startUtc,
            'interval_end_utc' => (int)$endUtc,
            'pv_energy_kwh' => $pvEnergyKwh === null ? null : (float)$pvEnergyKwh,
            'pv_mean_power_kw' => isset($extra['pv_mean_power_kw']) ? (float)$extra['pv_mean_power_kw'] : null,
            'array_id' => isset($extra['array_id']) ? $extra['array_id'] : 'aggregate',
            'is_interpolated' => !empty($extra['is_interpolated']) ? 1 : 0,
            'is_missing' => ($pvEnergyKwh === null) ? 1 : 0,
            'quantile_low_kwh' => isset($extra['quantile_low_kwh']) ? (float)$extra['quantile_low_kwh'] : null,
            'quantile_median_kwh' => isset($extra['quantile_median_kwh']) ? (float)$extra['quantile_median_kwh'] : null,
            'quantile_high_kwh' => isset($extra['quantile_high_kwh']) ? (float)$extra['quantile_high_kwh'] : null,
        );
        $this->intervals[] = $row;
    }

    /** Total non-missing PV energy over the run. */
    public function totalPvKwh()
    {
        $t = 0.0;
        foreach ($this->intervals as $iv) {
            if ($iv['pv_energy_kwh'] !== null) {
                $t += $iv['pv_energy_kwh'];
            }
        }
        return $t;
    }

    /**
     * Whether the run covers [fromUtc, toUtc) with no missing intervals.
     * A successful HTTP 200 does not guarantee a complete horizon.
     */
    public function coversHorizon($fromUtc, $toUtc)
    {
        if (empty($this->intervals)) {
            return false;
        }
        $minStart = PHP_INT_MAX;
        $maxEnd = 0;
        foreach ($this->intervals as $iv) {
            if ($iv['is_missing']) {
                return false;
            }
            $minStart = min($minStart, $iv['interval_start_utc']);
            $maxEnd = max($maxEnd, $iv['interval_end_utc']);
        }
        return $minStart <= $fromUtc && $maxEnd >= $toUtc;
    }

    /**
     * Reconstruct a ForecastRun from a toArray() payload (used by the cache).
     * Returns null if the payload is not a usable run.
     */
    public static function fromArray($data)
    {
        if (!is_array($data) || empty($data['provider_id'])) {
            return null;
        }
        $run = new self($data['provider_id']);
        $run->forecastRunId = isset($data['forecast_run_id']) ? $data['forecast_run_id'] : $run->forecastRunId;
        $run->retrievedAtUtc = isset($data['retrieved_at_utc']) ? (int)$data['retrieved_at_utc'] : $run->retrievedAtUtc;
        $run->issuedAtUtc = isset($data['issued_at_utc']) ? $data['issued_at_utc'] : null;
        $run->validFromUtc = isset($data['valid_from_utc']) ? (int)$data['valid_from_utc'] : null;
        $run->validToUtc = isset($data['valid_to_utc']) ? (int)$data['valid_to_utc'] : null;
        $run->locationConfigHash = isset($data['location_config_hash']) ? $data['location_config_hash'] : '';
        $run->pvConfigHash = isset($data['pv_config_hash']) ? $data['pv_config_hash'] : '';
        $run->modelId = isset($data['model_id']) ? $data['model_id'] : null;
        $run->energyBasis = isset($data['energy_basis']) ? $data['energy_basis'] : '';
        $run->rawResponseHash = isset($data['raw_response_hash']) ? $data['raw_response_hash'] : '';
        $run->qualityFlags = isset($data['quality_flags']) && is_array($data['quality_flags']) ? $data['quality_flags'] : array();
        $run->quotaState = isset($data['quota_state']) && is_array($data['quota_state']) ? $data['quota_state'] : array();
        $run->intervals = isset($data['intervals']) && is_array($data['intervals']) ? $data['intervals'] : array();
        return $run;
    }

    public function toArray()
    {
        return array(
            'provider_id' => $this->providerId,
            'forecast_run_id' => $this->forecastRunId,
            'retrieved_at_utc' => $this->retrievedAtUtc,
            'issued_at_utc' => $this->issuedAtUtc,
            'valid_from_utc' => $this->validFromUtc,
            'valid_to_utc' => $this->validToUtc,
            'location_config_hash' => $this->locationConfigHash,
            'pv_config_hash' => $this->pvConfigHash,
            'model_id' => $this->modelId,
            'energy_basis' => $this->energyBasis,
            'raw_response_hash' => $this->rawResponseHash,
            'quality_flags' => $this->qualityFlags,
            'quota_state' => $this->quotaState,
            'intervals' => $this->intervals,
        );
    }
}
