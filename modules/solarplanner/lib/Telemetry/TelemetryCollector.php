<?php
/**
 * TelemetryCollector — collect and quality-check measured facts.
 *
 * Each sample carries timestamp, units, source, freshness, quality flags and a
 * range check. A missing value is NOT zero; loss of SOC telemetry must never be
 * turned into a charge/discharge command on an invented value.
 *
 * It also separates potential_pv / deliverable_pv / measured_pv so calibration
 * is not poisoned by intervals where the station could not use available sun
 * (battery full, zero-export clipping, derating, dead array, bad telemetry,
 * grid outage, snow, config change).
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Telemetry;

class TelemetryCollector
{
    /**
     * Validate a raw telemetry snapshot.
     *
     * @param array $raw output of InverterAdapter::readTelemetry()
     * @param int $nowUtc
     * @param int $maxAgeS freshness threshold
     * @return array ['ok'=>bool,'soc_usable'=>bool,'flags'=>array,'sample'=>array]
     */
    public static function validate(array $raw, $nowUtc = null, $maxAgeS = 300)
    {
        $nowUtc = $nowUtc === null ? time() : (int)$nowUtc;
        $flags = array();

        $soc = isset($raw['battery_soc_pct']) ? $raw['battery_soc_pct'] : null;
        $socUsable = true;

        if ($soc === null) {
            $flags[] = 'soc_missing';
            $socUsable = false;
        } elseif ($soc < 0 || $soc > 100) {
            $flags[] = 'soc_out_of_range';
            $socUsable = false;
        }

        $socUpdated = isset($raw['soc_updated_utc']) ? (int)$raw['soc_updated_utc'] : null;
        if ($socUpdated !== null && ($nowUtc - $socUpdated) > $maxAgeS) {
            $flags[] = 'soc_stale';
            $socUsable = false;
        }

        // Range checks on powers (missing stays null, not 0).
        foreach (array('pv_power_kw', 'load_power_kw', 'grid_power_kw', 'battery_power_kw') as $k) {
            if (!array_key_exists($k, $raw) || $raw[$k] === null) {
                $flags[] = $k . '_missing';
            }
        }

        return array(
            'ok' => empty(array_filter($flags, function ($f) {
                return strpos($f, 'soc_') === 0;
            })),
            'soc_usable' => $socUsable,
            'flags' => $flags,
            'sample' => array(
                'utc' => $nowUtc,
                'battery_soc_pct' => $soc,
                'pv_power_kw' => isset($raw['pv_power_kw']) ? $raw['pv_power_kw'] : null,
                'load_power_kw' => isset($raw['load_power_kw']) ? $raw['load_power_kw'] : null,
                'grid_power_kw' => isset($raw['grid_power_kw']) ? $raw['grid_power_kw'] : null,
                'battery_power_kw' => isset($raw['battery_power_kw']) ? $raw['battery_power_kw'] : null,
                'source' => 'inverter_adapter',
            ),
        );
    }

    /**
     * Decide whether a measured PV interval is usable for calibration.
     *
     * @param array $ctx battery_full, zero_export_clipping, derating,
     *        array_fault, bad_telemetry, config_changed, grid_outage, snow
     * @return array ['usable'=>bool,'reason'=>string|null]
     */
    public static function pvUsableForCalibration(array $ctx)
    {
        $blockers = array('battery_full', 'zero_export_clipping', 'derating', 'array_fault',
            'bad_telemetry', 'config_changed', 'grid_outage', 'snow');
        foreach ($blockers as $b) {
            if (!empty($ctx[$b])) {
                return array('usable' => false, 'reason' => $b);
            }
        }
        return array('usable' => true, 'reason' => null);
    }
}
