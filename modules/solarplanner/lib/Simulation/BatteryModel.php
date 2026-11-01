<?php
/**
 * BatteryModel — AC-equivalent battery energy model.
 *
 * Spec constraints honoured:
 *  - Do NOT subtract usable depth twice (once via "usable capacity" and again
 *    via a SOC floor). This model stores TOTAL usable capacity on the SOC scale
 *    the inverter reports, and treats reserve / min SOC as operational limits,
 *    not as capacity reductions.
 *  - Charge acceptance may taper near full; configurable taper.
 *  - Separate charge / discharge efficiencies on the chosen measurement boundary.
 *  - 100% is not assumed to be a universally mandatory daily state.
 *
 * All energy in kWh, power in kW, SOC in percent (0..100).
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Simulation;

class BatteryModel
{
    /** @var float usable capacity across the full 0..100 SOC scale, kWh */
    public $capacityKwh;
    /** @var float minimum allowed SOC percent (hardware/BMS floor) */
    public $minSocPct;
    /** @var float emergency reserve SOC percent (>= minSocPct) */
    public $reserveSocPct;
    /** @var float maximum SOC percent */
    public $maxSocPct;
    /** @var float max charge power kW (AC-equivalent on chosen boundary) */
    public $maxChargeKw;
    /** @var float max discharge power kW */
    public $maxDischargeKw;
    /** @var float charge path efficiency (0..1) */
    public $etaCharge;
    /** @var float discharge path efficiency (0..1) */
    public $etaDischarge;
    /** @var float standby/self-consumption kW */
    public $selfConsumptionKw;
    /** @var float SOC percent above which charge power tapers linearly to ~0 at 100% */
    public $taperStartSocPct;
    /** @var float tolerance for "full" detection, percent */
    public $fullTolerancePct;

    public function __construct(array $cfg = array())
    {
        $this->capacityKwh      = isset($cfg['capacity_kwh']) ? (float)$cfg['capacity_kwh'] : 10.0;
        $this->minSocPct        = isset($cfg['min_soc_pct']) ? (float)$cfg['min_soc_pct'] : 10.0;
        $this->reserveSocPct    = isset($cfg['reserve_soc_pct']) ? (float)$cfg['reserve_soc_pct'] : 20.0;
        $this->maxSocPct        = isset($cfg['max_soc_pct']) ? (float)$cfg['max_soc_pct'] : 100.0;
        $this->maxChargeKw      = isset($cfg['max_charge_kw']) ? (float)$cfg['max_charge_kw'] : 5.0;
        $this->maxDischargeKw   = isset($cfg['max_discharge_kw']) ? (float)$cfg['max_discharge_kw'] : 5.0;
        $this->etaCharge        = isset($cfg['eta_charge']) ? (float)$cfg['eta_charge'] : 0.95;
        $this->etaDischarge     = isset($cfg['eta_discharge']) ? (float)$cfg['eta_discharge'] : 0.95;
        $this->selfConsumptionKw= isset($cfg['self_consumption_kw']) ? (float)$cfg['self_consumption_kw'] : 0.0;
        $this->taperStartSocPct = isset($cfg['taper_start_soc_pct']) ? (float)$cfg['taper_start_soc_pct'] : 90.0;
        $this->fullTolerancePct = isset($cfg['full_tolerance_pct']) ? (float)$cfg['full_tolerance_pct'] : 1.0;

        if ($this->reserveSocPct < $this->minSocPct) {
            $this->reserveSocPct = $this->minSocPct;
        }
    }

    /** SOC percent -> stored energy kWh. */
    public function socToKwh($socPct)
    {
        return $this->capacityKwh * ($socPct / 100.0);
    }

    /** Stored energy kWh -> SOC percent. */
    public function kwhToSoc($kwh)
    {
        if ($this->capacityKwh <= 0) {
            return 0.0;
        }
        return ($kwh / $this->capacityKwh) * 100.0;
    }

    /**
     * Effective max charge power at a given SOC, applying a linear taper above
     * taperStartSocPct. This models the real inability to hold max power up to
     * the last minute before 100%.
     */
    public function maxChargeAtSoc($socPct)
    {
        if ($socPct <= $this->taperStartSocPct) {
            return $this->maxChargeKw;
        }
        $span = $this->maxSocPct - $this->taperStartSocPct;
        if ($span <= 0) {
            return 0.0;
        }
        $frac = ($this->maxSocPct - $socPct) / $span; // 1..0
        return max(0.0, $this->maxChargeKw * $frac);
    }

    public function isFull($socPct)
    {
        return $socPct >= ($this->maxSocPct - $this->fullTolerancePct);
    }
}
