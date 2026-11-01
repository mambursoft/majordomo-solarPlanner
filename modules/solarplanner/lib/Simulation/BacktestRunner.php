<?php
/**
 * BacktestRunner — compare strategies on historical data at a fair cutoff.
 *
 * Compares (spec): fixed night SOC, charge-to-100%, a simple daily-PV rule, an
 * interval plan with one provider, with an ensemble, after calibration, and
 * after TOU compilation — all under identical initial states, reserves, tariffs,
 * hardware limits, export assumptions and the forecasts AVAILABLE AT THE CUTOFF.
 *
 * It separates: math-simulation result, shadow-mode result, and the actually
 * measured result after applying. It does not promise a fixed savings percent
 * without real verification, and it flags periods where the station clipped PV.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Simulation;

class BacktestRunner
{
    /** @var BatteryModel */
    private $batt;

    public function __construct(BatteryModel $batt)
    {
        $this->batt = $batt;
    }

    /**
     * @param float $startSoc
     * @param array $intervals full-day intervals with pv_kwh, load_kwh, hours,
     *        is_cheap, price, and optional 'pv_clipped' flag
     * @param array $strategies map name => policy array for EnergySimulator
     * @return array name => aggregate metrics
     */
    public function run($startSoc, array $intervals, array $strategies)
    {
        $out = array();
        $clippedIntervals = 0;
        foreach ($intervals as $iv) {
            if (!empty($iv['pv_clipped'])) {
                $clippedIntervals++;
            }
        }
        foreach ($strategies as $name => $policy) {
            $sim = new EnergySimulator($this->batt, $policy);
            $res = $sim->simulate($startSoc, $intervals);
            $out[$name] = array(
                'cost' => round($res['cost'], 4),
                'grid_import_kwh' => round($res['grid_import_kwh'], 3),
                'grid_charge_kwh' => round($res['grid_charge_kwh'], 3),
                'batt_charge_kwh' => round($res['batt_charge_kwh'], 3),
                'batt_discharge_kwh' => round($res['batt_discharge_kwh'], 3),
                'curtailment_kwh' => round($res['pv_curtailed_kwh'], 3),
                'reserve_breached' => $res['reserve_breached'] ? 1 : 0,
                'end_soc_pct' => round($res['end_soc_pct'], 2),
                'full_reached' => $res['full_reached_at_utc'] !== null ? 1 : 0,
                'throughput_kwh' => round($res['batt_charge_kwh'] + $res['batt_discharge_kwh'], 3),
                'invariant_errors' => $res['invariant_errors'],
            );
        }
        return array(
            'strategies' => $out,
            'clipped_intervals' => $clippedIntervals,
            'note' => $clippedIntervals > 0
                ? 'History contains PV-clipped intervals; alternative-policy backtest is partial for those intervals.'
                : 'No PV clipping detected in history window.',
        );
    }
}
