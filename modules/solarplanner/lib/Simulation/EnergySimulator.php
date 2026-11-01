<?php
/**
 * EnergySimulator — interval-by-interval AC-equivalent energy balance.
 *
 * This is the heart of the module. It is deliberately free of network, DB,
 * MajorDoMo and inverter dependencies so it can be unit-tested in isolation.
 *
 * For each interval it decides, under an explicit priority order and the
 * current policy, how PV, grid and battery meet the house load, then advances
 * SOC using BatteryModel. It records enough detail to explain the result and
 * to check the simulator invariants required by the spec:
 *
 *   - energy is conserved (no energy from nothing),
 *   - SOC never leaves physical bounds,
 *   - no simultaneous charge and discharge,
 *   - no simultaneous import and export,
 *   - powers never exceed the configured profile,
 *   - reserve breaches are flagged explicitly (not hidden),
 *   - missing data is not silently treated as zero (caller must supply data).
 *
 * Priority order (from the spec):
 *   1. hardware/BMS safety limits,
 *   2. critical load + reserve,
 *   3. reduce expensive daytime import,
 *   4. reach full target when possible,
 *   5. minimise night top-up,
 *   6. reduce avoidable curtailment,
 *   7. reduce extra cycles/writes.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Simulation;

use SolarPlanner\Common\Units;

class EnergySimulator
{
    /** @var BatteryModel */
    private $batt;

    /** @var array policy flags */
    private $policy;

    /**
     * @param BatteryModel $batt
     * @param array $policy keys:
     *   allow_export (bool, default false)
     *   allow_grid_charge (bool) — whether grid may charge battery this run
     *   night_house_from_grid (bool) — on cheap tariff feed house from grid
     *   grid_charge_target_soc (float) — stop grid charging at this SOC
     *   reserve_is_hard (bool) — refuse to discharge below reserve
     */
    public function __construct(BatteryModel $batt, array $policy = array())
    {
        $this->batt = $batt;
        $this->policy = array_merge(array(
            'allow_export' => false,
            'allow_grid_charge' => false,
            'night_house_from_grid' => true,
            'grid_charge_target_soc' => $batt->maxSocPct,
            'reserve_is_hard' => true,
        ), $policy);
    }

    /**
     * Simulate across a list of intervals.
     *
     * Each interval must contain:
     *   start_utc, end_utc, hours,
     *   pv_kwh         (float, deliverable PV energy in the interval),
     *   load_kwh       (float, house load energy),
     *   is_cheap       (bool, cheap tariff window),
     *   price          (float, import price per kWh; export_price optional).
     * Missing pv_kwh or load_kwh must be absent, NOT zero — the simulator throws
     * so callers cannot silently treat gaps as real zeros.
     *
     * @param float $startSocPct initial SOC percent
     * @param array $intervals
     * @return array result with per-interval detail and aggregates
     */
    public function simulate($startSocPct, array $intervals)
    {
        $batt = $this->batt;
        $energy = $batt->socToKwh(Units::clamp($startSocPct, 0.0, 100.0));

        $rows = array();
        $agg = array(
            'grid_import_kwh' => 0.0,
            'grid_export_kwh' => 0.0,
            'grid_charge_kwh' => 0.0,
            'batt_charge_kwh' => 0.0,
            'batt_discharge_kwh' => 0.0,
            'pv_used_kwh' => 0.0,
            'pv_curtailed_kwh' => 0.0,
            'load_kwh' => 0.0,
            'cost' => 0.0,
            'reserve_breached' => false,
            'min_soc_pct' => 100.0,
            'full_reached_at_utc' => null,
            'invariant_errors' => array(),
        );

        foreach ($intervals as $idx => $iv) {
            if (!isset($iv['pv_kwh']) || !isset($iv['load_kwh'])) {
                throw new \InvalidArgumentException(
                    'Interval ' . $idx . ' is missing pv_kwh or load_kwh. Missing data must not be treated as zero.'
                );
            }
            $h = (float)$iv['hours'];
            $pv = max(0.0, (float)$iv['pv_kwh']);
            $load = max(0.0, (float)$iv['load_kwh']) + $batt->selfConsumptionKw * $h;
            $isCheap = !empty($iv['is_cheap']);
            $price = isset($iv['price']) ? (float)$iv['price'] : 0.0;
            $exportPrice = isset($iv['export_price']) ? (float)$iv['export_price'] : 0.0;

            $socPct = $batt->kwhToSoc($energy);

            $gridImport = 0.0;
            $gridExport = 0.0;
            $gridCharge = 0.0;
            $battCharge = 0.0;
            $battDischarge = 0.0;
            $pvUsed = 0.0;
            $curtail = 0.0;

            // 1) PV meets load first (free, in front of the meter path chosen AC-equiv).
            $pvToLoad = min($pv, $load);
            $pvUsed += $pvToLoad;
            $loadRemain = $load - $pvToLoad;
            $pvRemain = $pv - $pvToLoad;

            // 2) Serve remaining load.
            if ($loadRemain > 0) {
                if ($isCheap && $this->policy['night_house_from_grid']) {
                    // On cheap tariff feed the house directly from grid; do NOT
                    // also debit the battery for the same energy.
                    $gridImport += $loadRemain;
                    $loadRemain = 0.0;
                } else {
                    // Discharge battery down to the operational floor.
                    $floorSoc = $this->policy['reserve_is_hard']
                        ? max($batt->minSocPct, $batt->reserveSocPct)
                        : $batt->minSocPct;
                    $availKwh = max(0.0, $energy - $batt->socToKwh($floorSoc));
                    $maxDisByPower = $batt->maxDischargeKw * $h;
                    // Energy delivered to load = stored * etaDischarge.
                    $deliverable = min($loadRemain, $availKwh * $batt->etaDischarge, $maxDisByPower);
                    if ($deliverable > 0) {
                        $drawn = $deliverable / $batt->etaDischarge;
                        $energy -= $drawn;
                        $battDischarge += $drawn;
                        $loadRemain -= $deliverable;
                    }
                    if ($loadRemain > 0) {
                        $gridImport += $loadRemain;
                        $loadRemain = 0.0;
                    }
                }
            }

            // 3) Surplus PV -> charge battery (respecting taper and headroom).
            if ($pvRemain > 0) {
                $headroomKwh = max(0.0, $batt->socToKwh($batt->maxSocPct) - $energy);
                $maxChByPower = $batt->maxChargeAtSoc($socPct) * $h;
                // Stored = input * etaCharge; limit input by headroom/eta and power.
                $maxInputByHeadroom = $batt->etaCharge > 0 ? $headroomKwh / $batt->etaCharge : 0.0;
                $charged = min($pvRemain, $maxInputByHeadroom, $maxChByPower);
                if ($charged > 0) {
                    $energy += $charged * $batt->etaCharge;
                    $battCharge += $charged;
                    $pvUsed += $charged;
                    $pvRemain -= $charged;
                }
            }

            // 4) Remaining PV -> export (if allowed) else curtailment.
            if ($pvRemain > 0) {
                if ($this->policy['allow_export']) {
                    $gridExport += $pvRemain;
                    $pvRemain = 0.0;
                } else {
                    $curtail += $pvRemain;
                    $pvRemain = 0.0;
                }
            }

            // 5) Grid charging on cheap tariff (night top-up), if allowed.
            if ($isCheap && $this->policy['allow_grid_charge']) {
                $targetSoc = (float)$this->policy['grid_charge_target_soc'];
                $socNow = $batt->kwhToSoc($energy);
                if ($socNow < $targetSoc) {
                    $targetKwh = $batt->socToKwh($targetSoc);
                    $headroomKwh = max(0.0, $targetKwh - $energy);
                    $maxChByPower = $batt->maxChargeAtSoc($socNow) * $h;
                    $maxInputByHeadroom = $batt->etaCharge > 0 ? $headroomKwh / $batt->etaCharge : 0.0;
                    // Battery charge power is shared with any PV charge already done.
                    $remainingPowerKwh = max(0.0, $maxChByPower - $battCharge);
                    $gc = min($maxInputByHeadroom, $remainingPowerKwh);
                    if ($gc > 0) {
                        $energy += $gc * $batt->etaCharge;
                        $battCharge += $gc;
                        $gridCharge += $gc;
                        $gridImport += $gc;
                    }
                }
            }

            // ---- bookkeeping ----
            $socAfter = $batt->kwhToSoc($energy);
            $cost = $gridImport * $price - $gridExport * $exportPrice;

            // Invariant checks.
            $errs = array();
            if ($battCharge > 1e-9 && $battDischarge > 1e-9) {
                $errs[] = 'simultaneous_charge_discharge';
            }
            if ($gridImport > 1e-9 && $gridExport > 1e-9) {
                $errs[] = 'simultaneous_import_export';
            }
            if ($socAfter < -1e-6 || $socAfter > 100.0 + 1e-6) {
                $errs[] = 'soc_out_of_bounds';
            }
            $chargePowerKw = Units::energyToMeanPowerKw($battCharge, $h);
            $disPowerKw = Units::energyToMeanPowerKw($battDischarge, $h);
            if ($chargePowerKw > $batt->maxChargeKw + 1e-6) {
                $errs[] = 'charge_power_exceeded';
            }
            if ($disPowerKw > $batt->maxDischargeKw + 1e-6) {
                $errs[] = 'discharge_power_exceeded';
            }
            // Energy conservation: sources == sinks for this interval.
            $sources = $pv + $gridImport + $battDischarge * $batt->etaDischarge;
            $sinks = $load + $gridExport + $curtail + $battCharge;
            // battDischarge*eta already delivered to load; charge consumes input.
            if (abs(($pvUsed + $gridImport + $battDischarge * $batt->etaDischarge)
                    - ($load + $gridExport + $battCharge)) > 1e-6) {
                // Not a hard error for every topology, but flagged for review.
                $errs[] = 'balance_mismatch';
            }

            $reserveBreached = $socAfter < ($batt->reserveSocPct - 1e-9);
            if ($reserveBreached) {
                $agg['reserve_breached'] = true;
            }
            if ($socAfter < $agg['min_soc_pct']) {
                $agg['min_soc_pct'] = $socAfter;
            }
            if ($agg['full_reached_at_utc'] === null && $batt->isFull($socAfter)) {
                $agg['full_reached_at_utc'] = (int)$iv['end_utc'];
            }

            $agg['grid_import_kwh'] += $gridImport;
            $agg['grid_export_kwh'] += $gridExport;
            $agg['grid_charge_kwh'] += $gridCharge;
            $agg['batt_charge_kwh'] += $battCharge;
            $agg['batt_discharge_kwh'] += $battDischarge;
            $agg['pv_used_kwh'] += $pvUsed;
            $agg['pv_curtailed_kwh'] += $curtail;
            $agg['load_kwh'] += $load;
            $agg['cost'] += $cost;
            if (!empty($errs)) {
                foreach ($errs as $e) {
                    $agg['invariant_errors'][$e] = isset($agg['invariant_errors'][$e])
                        ? $agg['invariant_errors'][$e] + 1 : 1;
                }
            }

            $rows[] = array(
                'start_utc' => (int)$iv['start_utc'],
                'end_utc' => (int)$iv['end_utc'],
                'hours' => $h,
                'is_cheap' => $isCheap ? 1 : 0,
                'pv_kwh' => $pv,
                'load_kwh' => $load,
                'pv_used_kwh' => $pvUsed,
                'curtailed_kwh' => $curtail,
                'grid_import_kwh' => $gridImport,
                'grid_export_kwh' => $gridExport,
                'grid_charge_kwh' => $gridCharge,
                'batt_charge_kwh' => $battCharge,
                'batt_discharge_kwh' => $battDischarge,
                'soc_pct' => round($socAfter, 3),
                'reserve_breached' => $reserveBreached ? 1 : 0,
                'invariant_errors' => $errs,
            );
        }

        $agg['end_soc_pct'] = $batt->kwhToSoc($energy);
        $agg['intervals'] = $rows;
        return $agg;
    }
}
