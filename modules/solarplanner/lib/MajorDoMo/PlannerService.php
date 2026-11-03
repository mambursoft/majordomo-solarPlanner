<?php
/**
 * PlannerService — orchestration: forecasts -> load -> simulate -> plan -> TOU.
 *
 * This ties ProviderRegistry, EnsembleBuilder, LoadForecaster, EnergySimulator,
 * NightChargePlanner, TouCompiler and ExplanationBuilder together. It has no
 * MajorDoMo globals and no DB calls directly — dependencies are injected — so it
 * can be exercised in integration tests without the platform or hardware.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\MajorDoMo;

use SolarPlanner\Common\TimeGrid;
use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Planning\LoadForecaster;
use SolarPlanner\Planning\NightChargePlanner;
use SolarPlanner\Planning\TouCompiler;
use SolarPlanner\Planning\ExplanationBuilder;
use SolarPlanner\Forecast\EnsembleBuilder;

class PlannerService
{
    /** @var BatteryModel */
    private $batt;
    /** @var TimeGrid */
    private $grid;
    /** @var array config */
    private $cfg;

    public function __construct(BatteryModel $batt, TimeGrid $grid, array $cfg)
    {
        $this->batt = $batt;
        $this->grid = $grid;
        $this->cfg = $cfg;
    }

    /**
     * Build a plan from already-fetched provider runs and load history.
     *
     * @param float $startSocPct
     * @param array $providerRuns list of ['run'=>ForecastRun,'mae'=>?,'obs'=>?]
     * @param array $loadHistory
     * @param int $horizonStartUtc
     * @param int $horizonEndUtc
     * @param array $ctx forecast_quality, unavailable_providers, tou_changed
     * @return array
     */
    public function makePlan($startSocPct, array $providerRuns, array $loadHistory,
                             $horizonStartUtc, $horizonEndUtc, array $ctx = array())
    {
        $intervals = $this->grid->intervals($horizonStartUtc, $horizonEndUtc);
        if (empty($intervals)) {
            return array('status' => 'error', 'reason' => 'empty_horizon');
        }

        // 1) Ensemble PV onto the grid.
        $method = isset($this->cfg['ensemble_method']) ? $this->cfg['ensemble_method'] : 'median';
        $ens = (new EnsembleBuilder(
            isset($this->cfg['ensemble_epsilon']) ? $this->cfg['ensemble_epsilon'] : 0.1,
            isset($this->cfg['ensemble_max_weight']) ? $this->cfg['ensemble_max_weight'] : 0.7,
            isset($this->cfg['ensemble_min_obs']) ? $this->cfg['ensemble_min_obs'] : 7
        ))->build($providerRuns, $method);

        // 2) Load forecast onto the same grid.
        $lf = new LoadForecaster(
            $this->grid->getTimezone(),
            isset($this->cfg['load_min_samples']) ? $this->cfg['load_min_samples'] : 3,
            isset($this->cfg['manual_hourly_kw']) ? $this->cfg['manual_hourly_kw'] : array()
        );
        $loadRes = $lf->forecast($intervals, $loadHistory);

        // 3) Merge PV + load + tariff flags into simulation intervals.
        // Provider forecasts arrive on their own grid (Open-Meteo is hourly),
        // which does NOT line up with our 30-min simulation grid. Allocating by
        // exact start match would drop all PV. Instead distribute each ensemble
        // interval's energy onto the sim grid in proportion to time overlap.
        $pvByStart = $this->allocatePvToGrid($ens['intervals'], $loadRes['intervals']);
        $cheap = $this->buildCheapMask($horizonStartUtc, $horizonEndUtc);
        $simIntervals = array();
        foreach ($loadRes['intervals'] as $iv) {
            $start = (int)$iv['start_utc'];
            $pv = isset($pvByStart[$start]) ? $pvByStart[$start] : 0.0;
            $isCheap = $this->inCheap($start, (int)$iv['end_utc'], $cheap);
            $simIntervals[] = array(
                'start_utc' => $start,
                'end_utc' => (int)$iv['end_utc'],
                'hours' => (float)$iv['hours'],
                'pv_kwh' => $pv,
                'load_kwh' => (float)$iv['load_kwh'],
                'is_cheap' => $isCheap,
                'price' => $isCheap
                    ? (float)$this->cfg['tariff_cheap_price']
                    : (float)$this->cfg['tariff_day_price'],
                'export_price' => isset($this->cfg['export_price']) ? (float)$this->cfg['export_price'] : 0.0,
            );
        }

        // 4) Night charge planner -> TargetSocAt07.
        $planner = new NightChargePlanner($this->batt, array(
            'mode' => isset($this->cfg['mode']) ? $this->cfg['mode'] : 'reach_full',
            'soc_step_pct' => isset($this->cfg['soc_step_pct']) ? $this->cfg['soc_step_pct'] : 5.0,
            'allow_export' => !empty($this->cfg['allow_export']),
            'night_house_from_grid' => isset($this->cfg['night_house_from_grid']) ? (bool)$this->cfg['night_house_from_grid'] : true,
            'reserve_is_hard' => true,
            'full_window_start_utc' => isset($ctx['full_window_start_utc']) ? $ctx['full_window_start_utc'] : null,
            'full_window_end_utc' => isset($ctx['full_window_end_utc']) ? $ctx['full_window_end_utc'] : null,
        ));
        $plan = $planner->plan($startSocPct, $simIntervals);

        // 5) Explanation.
        $plan['explanation_obj'] = ExplanationBuilder::build($plan, array(
            'forecast_quality' => isset($ctx['forecast_quality']) ? $ctx['forecast_quality'] : $loadRes['quality'],
            'unavailable_providers' => isset($ctx['unavailable_providers']) ? $ctx['unavailable_providers'] : array(),
            'tou_changed' => !empty($ctx['tou_changed']),
        ));
        $plan['ensemble_method'] = $ens['method'];
        $plan['load_quality'] = $loadRes['quality'];
        $plan['sim_intervals'] = $simIntervals;

        // Headline "tomorrow" figures must describe a SINGLE local day, not the
        // whole ~44h planning horizon. Summing the entire horizon double-counts
        // (tomorrow + the day after) and roughly doubles the number. The full
        // horizon is still used by the simulation/night-charge planner above;
        // only the UI-facing totals are restricted to tomorrow's local day.
        $tw = $this->localDayWindow($horizonStartUtc, 1); // tomorrow [start,end)
        $plan['pv_tomorrow_kwh'] = $this->sumPvInWindow($ens['intervals'], $tw['start'], $tw['end']);
        $plan['pv_tomorrow_low_kwh'] = $this->sumBandInWindow($ens['band'], 'low_kwh', $tw['start'], $tw['end']);
        $plan['pv_tomorrow_high_kwh'] = $this->sumBandInWindow($ens['band'], 'high_kwh', $tw['start'], $tw['end']);
        $plan['load_tomorrow_kwh'] = $this->sumLoadInWindow($simIntervals, $tw['start'], $tw['end']);

        // Extra, clearly-labelled context so the full horizon is still visible
        // without pretending it is "tomorrow".
        $todayW = $this->localDayWindow($horizonStartUtc, 0);
        $dayAfterW = $this->localDayWindow($horizonStartUtc, 2);
        $plan['pv_today_remaining_kwh'] = $this->sumPvInWindow($ens['intervals'], $horizonStartUtc, $todayW['end']);
        $plan['pv_day_after_kwh'] = $this->sumPvInWindow($ens['intervals'], $dayAfterW['start'], $dayAfterW['end']);
        $plan['pv_horizon_kwh'] = $this->sumPv($ens['intervals']); // whole horizon (for reference)
        $plan['tomorrow_local_date'] = (new \DateTime('@' . $tw['start']))
            ->setTimezone(new \DateTimeZone($this->grid->getTimezone()))->format('Y-m-d');

        // Plausibility guardrail (diagnostic only, never silently alters the
        // plan): flag when tomorrow's modelled PV per installed kWp exceeds a
        // generous physical ceiling. Real daily yield per kWp tops out around
        // ~5-6 kWh in peak summer at this latitude and far less in winter, so a
        // value above ~6.5 kWh/kWp/day almost certainly means the forecast or
        // PvModel conversion is wrong.
        $totalKwp = 0.0;
        foreach ($this->pvArraysFromConfig() as $a) {
            $totalKwp += isset($a['kwp']) ? (float)$a['kwp'] : 0.0;
        }
        $plan['pv_total_kwp'] = round($totalKwp, 3);
        if ($totalKwp > 0) {
            $perKwp = $plan['pv_tomorrow_kwh'] / $totalKwp;
            $plan['pv_tomorrow_kwh_per_kwp'] = round($perKwp, 3);
            $plan['pv_forecast_implausible'] = ($perKwp > 6.5) ? 1 : 0;
        } else {
            $plan['pv_tomorrow_kwh_per_kwp'] = null;
            $plan['pv_forecast_implausible'] = 0;
        }

        return $plan;
    }

    /** Read PV arrays from config (supports pv_arrays array or PV_ARRAYS_JSON). */
    private function pvArraysFromConfig()
    {
        if (isset($this->cfg['pv_arrays']) && is_array($this->cfg['pv_arrays'])) {
            return $this->cfg['pv_arrays'];
        }
        $raw = isset($this->cfg['PV_ARRAYS_JSON']) ? $this->cfg['PV_ARRAYS_JSON'] : '';
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) {
                return $d;
            }
        }
        return array();
    }

    /**
     * Local-day [start,end) in UTC seconds for the day that is $dayOffset local
     * days after the local day containing $refUtc. offset 0 = today, 1 = tomorrow.
     */
    private function localDayWindow($refUtc, $dayOffset)
    {
        $tz = new \DateTimeZone($this->grid->getTimezone());
        $d = (new \DateTime('@' . (int)$refUtc))->setTimezone($tz);
        $d->setTime(0, 0, 0);
        if ($dayOffset !== 0) {
            $d->modify(($dayOffset > 0 ? '+' : '-') . abs((int)$dayOffset) . ' day');
        }
        $start = (int)$d->format('U');
        $end = (int)(clone $d)->modify('+1 day')->format('U');
        return array('start' => $start, 'end' => $end);
    }

    private function sumPvInWindow(array $intervals, $winStart, $winEnd)
    {
        $t = 0.0;
        foreach ($intervals as $start => $iv) {
            $s = (int)$start;
            $e = isset($iv['end_utc']) ? (int)$iv['end_utc'] : $s;
            $mid = (int)(($s + $e) / 2);
            if ($mid >= $winStart && $mid < $winEnd) {
                $t += isset($iv['pv_kwh']) ? (float)$iv['pv_kwh'] : 0.0;
            }
        }
        return round($t, 3);
    }

    private function sumBandInWindow(array $band, $key, $winStart, $winEnd)
    {
        $t = 0.0;
        foreach ($band as $start => $b) {
            $s = (int)$start;
            // Band entries may lack end_utc; use the start as the placement key.
            if ($s >= $winStart && $s < $winEnd) {
                $t += isset($b[$key]) ? (float)$b[$key] : 0.0;
            }
        }
        return round($t, 3);
    }

    private function sumLoadInWindow(array $intervals, $winStart, $winEnd)
    {
        $t = 0.0;
        foreach ($intervals as $iv) {
            $s = (int)$iv['start_utc'];
            $e = (int)$iv['end_utc'];
            $mid = (int)(($s + $e) / 2);
            if ($mid >= $winStart && $mid < $winEnd) {
                $t += (float)$iv['load_kwh'];
            }
        }
        return round($t, 3);
    }

    /**
     * Compile a plan into TOU slots and re-simulate to verify the approximation.
     *
     * @param array $plan output of makePlan
     * @return array ['compile'=>..., 'resim'=>..., 'accurate'=>bool]
     */
    public function compileAndVerify(array $plan)
    {
        if (!isset($plan['target_soc_at_07'])) {
            return array('compile' => array('ok' => false, 'error' => 'no_feasible_plan'), 'accurate' => false);
        }

        // Build a desired-behaviour day from the sim intervals, expressed in
        // local HH:MM, separating grid-charge vs discharge-floor behaviour.
        $tz = $this->grid->getTimezone();
        $target = (float)$plan['target_soc_at_07'];
        $reserve = $this->batt->reserveSocPct;
        $desired = array();
        foreach ($plan['sim_intervals'] as $iv) {
            $startLocal = (new \DateTime('@' . $iv['start_utc']))->setTimezone(new \DateTimeZone($tz))->format('H:i');
            $endLocal = (new \DateTime('@' . $iv['end_utc']))->setTimezone(new \DateTimeZone($tz))->format('H:i');
            $desired[] = array(
                'start_local' => $startLocal,
                'end_local' => $endLocal,
                'grid_charge' => $iv['is_cheap'] ? 1 : 0,
                'target_soc' => $iv['is_cheap'] ? $target : $this->batt->maxSocPct,
                'discharge_floor_soc' => $reserve,
            );
        }

        $compiler = new TouCompiler(
            isset($this->cfg['tou_slots']) ? $this->cfg['tou_slots'] : 6,
            isset($this->cfg['tou_time_step_min']) ? $this->cfg['tou_time_step_min'] : 1,
            isset($this->cfg['tou_soc_step_pct']) ? $this->cfg['tou_soc_step_pct'] : 1.0
        );
        $compiled = $compiler->compile($desired);

        return array(
            'compile' => $compiled,
            'accurate' => $compiled['ok'],
        );
    }

    /**
     * Distribute ensemble PV energy (on the providers' own grid) onto the
     * simulation grid by time overlap, so hourly provider data maps correctly
     * onto 30-min (or 15/60-min) sim intervals without losing energy.
     *
     * Energy is treated as uniformly spread within each ensemble interval: a
     * sim interval that covers half of an ensemble interval receives half its
     * kWh. Total PV energy is preserved (modulo intervals outside the horizon).
     *
     * @param array $ensIntervals keyed by start_utc => ['end_utc','pv_kwh',...]
     * @param array $gridIntervals list of ['start_utc','end_utc',...]
     * @return array map start_utc => pv_kwh
     */
    private function allocatePvToGrid(array $ensIntervals, array $gridIntervals)
    {
        // Normalise ensemble intervals to a simple list with numeric bounds.
        $src = array();
        foreach ($ensIntervals as $start => $e) {
            $s = (int)$start;
            $en = isset($e['end_utc']) ? (int)$e['end_utc'] : 0;
            $kwh = isset($e['pv_kwh']) ? (float)$e['pv_kwh'] : 0.0;
            if ($en > $s) {
                $src[] = array('s' => $s, 'e' => $en, 'kwh' => $kwh, 'dur' => $en - $s);
            }
        }

        $out = array();
        foreach ($gridIntervals as $iv) {
            $gs = (int)$iv['start_utc'];
            $ge = (int)$iv['end_utc'];
            $acc = 0.0;
            foreach ($src as $p) {
                // Overlap of [gs,ge) with [p.s,p.e).
                $lo = $gs > $p['s'] ? $gs : $p['s'];
                $hi = $ge < $p['e'] ? $ge : $p['e'];
                $ov = $hi - $lo;
                if ($ov > 0 && $p['dur'] > 0) {
                    $acc += $p['kwh'] * ($ov / $p['dur']);
                }
            }
            $out[$gs] = $acc;
        }
        return $out;
    }

    private function buildCheapMask($fromUtc, $toUtc)
    {
        $startHour = isset($this->cfg['cheap_start_hour']) ? (int)$this->cfg['cheap_start_hour'] : 23;
        $endHour = isset($this->cfg['cheap_end_hour']) ? (int)$this->cfg['cheap_end_hour'] : 7;
        $windows = array();
        // Build cheap windows for each local day that intersects the horizon.
        for ($off = -1; $off <= 2; $off++) {
            $w = $this->grid->cheapWindow($fromUtc + $off * 86400, $startHour, $endHour);
            if ($w['end_utc'] >= $fromUtc && $w['start_utc'] <= $toUtc) {
                $windows[] = $w;
            }
        }
        return $windows;
    }

    private function inCheap($start, $end, array $windows)
    {
        $mid = (int)(($start + $end) / 2);
        foreach ($windows as $w) {
            if ($mid >= $w['start_utc'] && $mid < $w['end_utc']) {
                return true;
            }
        }
        return false;
    }

    private function sumPv(array $intervals)
    {
        $t = 0.0;
        foreach ($intervals as $iv) {
            $t += $iv['pv_kwh'];
        }
        return round($t, 3);
    }

    private function sumBand(array $band, $key)
    {
        $t = 0.0;
        foreach ($band as $b) {
            $t += isset($b[$key]) ? $b[$key] : 0.0;
        }
        return round($t, 3);
    }

    private function sumLoad(array $intervals)
    {
        $t = 0.0;
        foreach ($intervals as $iv) {
            $t += $iv['load_kwh'];
        }
        return round($t, 3);
    }
}
