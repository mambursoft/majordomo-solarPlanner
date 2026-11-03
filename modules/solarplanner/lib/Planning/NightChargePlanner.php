<?php
/**
 * NightChargePlanner — compute TargetSocAt07 and the night charge plan.
 *
 * This is the main result of the whole module. It does a deterministic search
 * over candidate morning SOC targets, simulating each full day interval-by-
 * interval (not just comparing daily PV vs load sums), and chooses the best
 * feasible candidate under the agreed priority order.
 *
 * It explicitly accounts for the initial battery state, night consumption,
 * losses, the remaining cheap window, power/time limits and the reserve. It
 * returns a feasibility status with a numeric explanation when no plan meets
 * every goal — it never invents a feasible plan or silently lowers the reserve.
 *
 * Two modes:
 *   - reach_full: plan to reach full in the configured day window; report the
 *     extra night energy required for that requirement.
 *   - minimum_cost: minimise cost under reserve/limits; 100% not mandatory.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Planning;

use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Simulation\EnergySimulator;

class NightChargePlanner
{
    /** @var BatteryModel */
    private $batt;
    /** @var array config */
    private $cfg;

    public function __construct(BatteryModel $batt, array $cfg = array())
    {
        $this->batt = $batt;
        $this->cfg = array_merge(array(
            'mode' => 'reach_full',          // reach_full | minimum_cost
            'soc_step_pct' => 5.0,           // candidate granularity
            'allow_export' => false,
            'night_house_from_grid' => true,
            'reserve_is_hard' => true,
            'full_window_start_utc' => null, // when full is expected
            'full_window_end_utc' => null,
            'full_tolerance_pct' => 1.0,
            'risk_low_pv_factor' => 0.7,     // low_pv scenario
            'risk_high_load_factor' => 1.2,  // high_load scenario
            'risk_high_pv_factor' => 1.3,    // high_pv (headroom check)
        ), $cfg);
    }

    /**
     * @param float $startSocPct SOC now (start of plan horizon / evening)
     * @param array $intervals full-horizon intervals with pv_kwh, load_kwh,
     *        hours, is_cheap, price (and optional export_price)
     * @return array plan with status, target_soc_at_07, explanation, scenarios
     */
    public function plan($startSocPct, array $intervals)
    {
        $candidates = $this->candidateTargets();
        $results = array();

        foreach ($candidates as $targetSoc) {
            $policyBase = array(
                'allow_export' => $this->cfg['allow_export'],
                'allow_grid_charge' => true,
                'night_house_from_grid' => $this->cfg['night_house_from_grid'],
                'grid_charge_target_soc' => $targetSoc,
                'reserve_is_hard' => $this->cfg['reserve_is_hard'],
            );

            $base = $this->runScenario($startSocPct, $intervals, $policyBase, 1.0, 1.0);
            $lowPv = $this->runScenario($startSocPct, $intervals, $policyBase, $this->cfg['risk_low_pv_factor'], 1.0);
            $highLoad = $this->runScenario($startSocPct, $intervals, $policyBase, 1.0, $this->cfg['risk_high_load_factor']);
            $highPv = $this->runScenario($startSocPct, $intervals, $policyBase, $this->cfg['risk_high_pv_factor'], 1.0);

            $feasible = $this->isFeasible($base, $lowPv, $highLoad);
            $fullReached = $this->fullReachedInWindow($base);

            $results[] = array(
                'target_soc' => $targetSoc,
                'base' => $base,
                'low_pv' => $lowPv,
                'high_load' => $highLoad,
                'high_pv' => $highPv,
                'feasible' => $feasible,
                'full_reached' => $fullReached,
                'night_import_kwh' => $base['night_import_kwh'],
                'grid_charge_kwh' => $base['grid_charge_kwh'],
                'day_import_kwh' => $base['day_import_kwh'],
                'curtailment_kwh' => $highPv['pv_curtailed_kwh'],
                'cost' => $base['cost'],
            );
        }

        return $this->selectBest($results, $startSocPct);
    }

    private function candidateTargets()
    {
        $step = max(1.0, (float)$this->cfg['soc_step_pct']);
        $min = max($this->batt->reserveSocPct, $this->batt->minSocPct);
        $max = $this->batt->maxSocPct;
        $list = array();
        for ($s = $min; $s <= $max + 1e-9; $s += $step) {
            $list[] = round($s, 2);
        }
        if (empty($list) || end($list) < $max) {
            $list[] = $max;
        }
        return $list;
    }

    private function runScenario($startSoc, array $intervals, array $policy, $pvFactor, $loadFactor)
    {
        $scaled = array();
        foreach ($intervals as $iv) {
            $row = $iv;
            $row['pv_kwh'] = max(0.0, (float)$iv['pv_kwh'] * $pvFactor);
            $row['load_kwh'] = max(0.0, (float)$iv['load_kwh'] * $loadFactor);
            $scaled[] = $row;
        }
        $sim = new EnergySimulator($this->batt, $policy);
        $res = $sim->simulate($startSoc, $scaled);

        // Split night vs day import by the cheap flag.
        $nightImport = 0.0;
        $dayImport = 0.0;
        foreach ($res['intervals'] as $r) {
            if ($r['is_cheap']) {
                $nightImport += $r['grid_import_kwh'];
            } else {
                $dayImport += $r['grid_import_kwh'];
            }
        }
        $res['night_import_kwh'] = $nightImport;
        $res['day_import_kwh'] = $dayImport;
        return $res;
    }

    private function isFeasible($base, $lowPv, $highLoad)
    {
        // Feasible if the reserve is never breached even in the adverse low_pv
        // and high_load scenarios, and the simulator reported no invariant
        // errors in the base run.
        if (!empty($base['invariant_errors'])) {
            return false;
        }
        if ($lowPv['reserve_breached'] || $highLoad['reserve_breached']) {
            return false;
        }
        return true;
    }

    private function fullReachedInWindow($res)
    {
        $ws = $this->cfg['full_window_start_utc'];
        $we = $this->cfg['full_window_end_utc'];
        if ($res['full_reached_at_utc'] === null) {
            return false;
        }
        if ($ws === null || $we === null) {
            return true;
        }
        return $res['full_reached_at_utc'] >= $ws && $res['full_reached_at_utc'] <= $we;
    }

    private function selectBest(array $results, $startSoc)
    {
        $feasible = array_values(array_filter($results, function ($r) {
            return $r['feasible'];
        }));

        if (empty($feasible)) {
            return $this->infeasibleReport($results, $startSoc);
        }

        $mode = $this->cfg['mode'];
        usort($feasible, function ($a, $b) use ($mode) {
            if ($mode === 'reach_full') {
                // Prefer plans that reach full; among those, least night import;
                // then least cost; then least curtailment.
                if ($a['full_reached'] !== $b['full_reached']) {
                    return $b['full_reached'] <=> $a['full_reached'];
                }
            }
            // minimum_cost (and tie-break for reach_full): least cost, then
            // least night import, then least curtailment.
            if (abs($a['cost'] - $b['cost']) > 1e-6) {
                return $a['cost'] <=> $b['cost'];
            }
            if (abs($a['night_import_kwh'] - $b['night_import_kwh']) > 1e-6) {
                return $a['night_import_kwh'] <=> $b['night_import_kwh'];
            }
            return $a['curtailment_kwh'] <=> $b['curtailment_kwh'];
        });

        $best = $feasible[0];
        $expl = $this->explain($best, $startSoc, $results);

        return array(
            'status' => 'feasible',
            'mode' => $mode,
            'target_soc_at_07' => $best['target_soc'],
            'planned_grid_charge_kwh' => round($best['grid_charge_kwh'], 3),
            'planned_night_import_kwh' => round($best['night_import_kwh'], 3),
            'expected_day_import_kwh' => round($best['day_import_kwh'], 3),
            'expected_curtailment_kwh' => round($best['curtailment_kwh'], 3),
            'expected_full_at_utc' => $best['base']['full_reached_at_utc'],
            'expected_soc_at_23' => round($best['base']['end_soc_pct'], 2),
            'reserve_soc' => $this->batt->reserveSocPct,
            'full_reached' => $best['full_reached'] ? 1 : 0,
            'cost' => round($best['cost'], 4),
            'explanation' => $expl,
            'candidates' => $this->summariseCandidates($results),
        );
    }

    private function infeasibleReport(array $results, $startSoc)
    {
        // Find the least-bad candidate (highest target reached) for guidance.
        $minDayImport = PHP_INT_MAX;
        $bestGuide = null;
        foreach ($results as $r) {
            if ($r['day_import_kwh'] < $minDayImport) {
                $minDayImport = $r['day_import_kwh'];
                $bestGuide = $r;
            }
        }
        // Machine-readable reason code (localized at the display layer) plus a
        // neutral English detail for logs. The UI must not show reason_code raw.
        $reasonCode = 'reserve_not_holdable';
        $reason = 'No candidate keeps the reserve in adverse scenarios without '
            . 'infeasible night charge power/time.';
        return array(
            'status' => 'infeasible',
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'min_expected_day_import_kwh' => $bestGuide ? round($bestGuide['day_import_kwh'], 3) : null,
            'suggested_safe_compromise_soc' => $bestGuide ? $bestGuide['target_soc'] : null,
            'requires_manual_confirmation' => true,
            'reserve_soc' => $this->batt->reserveSocPct,
            'note' => 'Reserve is NOT lowered automatically to manufacture a feasible plan.',
        );
    }

    private function explain($best, $startSoc, array $results)
    {
        $morningNeed = max(0.0, $best['target_soc'] - $startSoc);
        $lines = array();
        $lines[] = sprintf('Chosen TargetSocAt07 = %.1f%% (mode: %s).', $best['target_soc'], $this->cfg['mode']);
        $lines[] = sprintf('Start SOC ~ %.1f%%; need to raise by ~%.1f%% overnight.', $startSoc, $morningNeed);
        $lines[] = sprintf('Planned night grid charge: %.2f kWh; total night import: %.2f kWh.',
            $best['grid_charge_kwh'], $best['night_import_kwh']);
        $lines[] = sprintf('Expected daytime import: %.2f kWh.', $best['day_import_kwh']);
        if ($best['full_reached']) {
            $lines[] = 'Full charge is reachable within the configured window from PV.';
        } else {
            $lines[] = 'Full charge is NOT guaranteed in the window under the central forecast.';
        }
        if ($best['curtailment_kwh'] > 0.05) {
            $lines[] = sprintf('High-PV scenario shows ~%.2f kWh unavoidable/avoidable surplus — check controllable loads/export.',
                $best['curtailment_kwh']);
        }
        $lines[] = sprintf('Reserve held at %.0f%% across low-PV and high-load scenarios.', $this->batt->reserveSocPct);
        return implode(' ', $lines);
    }

    private function summariseCandidates(array $results)
    {
        $out = array();
        foreach ($results as $r) {
            $out[] = array(
                'target_soc' => $r['target_soc'],
                'feasible' => $r['feasible'] ? 1 : 0,
                'full_reached' => $r['full_reached'] ? 1 : 0,
                'night_import_kwh' => round($r['night_import_kwh'], 3),
                'day_import_kwh' => round($r['day_import_kwh'], 3),
                'curtailment_kwh' => round($r['curtailment_kwh'], 3),
                'cost' => round($r['cost'], 4),
            );
        }
        return $out;
    }
}
