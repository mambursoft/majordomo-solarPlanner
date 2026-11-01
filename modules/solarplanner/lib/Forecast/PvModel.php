<?php
/**
 * PvModel — convert plane-of-array / global irradiance into PV energy.
 *
 * This is a transparent first-order model, NOT a claim of validated accuracy.
 * It is unit-tested for the azimuth conventions and unit conversions the spec
 * calls out, because the history of the reference HA integration shows that
 * azimuth and temperature handling are where bugs hide.
 *
 * Internal azimuth convention (module standard):
 *   0 = North, 90 = East, 180 = South, 270 = West.
 *
 * Open-Meteo GTI azimuth convention:
 *   0 = South, -90 = East, +90 = West, +/-180 = North.
 *
 * Conversion is done ONLY in the adapter boundary via azimuthModuleToOpenMeteo()
 * and verified by tests for the four cardinal directions.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class PvModel
{
    /** @var array list of arrays, each: kwp, tilt_deg, azimuth_module_deg, dc_loss, temp_coeff_pct_per_c */
    private $arrays;
    /** @var float shared inverter AC limit in kW (optional cap) */
    private $inverterAcKw;

    public function __construct(array $arrays, $inverterAcKw = 0.0)
    {
        $this->arrays = $arrays;
        $this->inverterAcKw = (float)$inverterAcKw;
    }

    /**
     * Convert module azimuth (0=N,90=E,180=S,270=W) to Open-Meteo GTI azimuth
     * (0=S, -90=E, +90=W, 180=N). Verified by tests.
     *
     * @param float $moduleAz
     * @return float open-meteo azimuth in (-180, 180]
     */
    public static function azimuthModuleToOpenMeteo($moduleAz)
    {
        // module south = 180 -> OM 0; module east = 90 -> OM -90;
        // module west = 270 -> OM +90; module north = 0/360 -> OM 180.
        $om = $moduleAz - 180.0;
        // normalise to (-180, 180]
        while ($om <= -180.0) {
            $om += 360.0;
        }
        while ($om > 180.0) {
            $om -= 360.0;
        }
        return $om;
    }

    /**
     * Estimate PV energy (kWh) for one interval from plane-of-array irradiance
     * for each array plus ambient temperature.
     *
     * @param array $poaWhm2ByArray array_id => POA irradiance energy Wh/m2 over the interval
     * @param float $ambientTempC
     * @param float $hours interval length (for applying the AC cap on mean power)
     * @return array ['total_kwh'=>float, 'by_array'=>array, 'curtailed_by_inverter_kwh'=>float]
     */
    public function intervalEnergy(array $poaWhm2ByArray, $ambientTempC, $hours)
    {
        $totalKwh = 0.0;
        $byArray = array();
        $ambientTempC = (float)$ambientTempC;

        foreach ($this->arrays as $a) {
            $aid = isset($a['array_id']) ? $a['array_id'] : (isset($a['id']) ? $a['id'] : 'array');
            $kwp = (float)$a['kwp'];
            $dcLoss = isset($a['dc_loss']) ? (float)$a['dc_loss'] : 0.14; // 14% default losses
            $tempCoeff = isset($a['temp_coeff_pct_per_c']) ? (float)$a['temp_coeff_pct_per_c'] : -0.004; // -0.4%/C
            $noct = isset($a['noct_c']) ? (float)$a['noct_c'] : 45.0;

            $poa = isset($poaWhm2ByArray[$aid]) ? max(0.0, (float)$poaWhm2ByArray[$aid]) : 0.0;

            // Nominal energy at STC-like ratio: kWp * (POA / 1000 Wh/m2).
            $nominalKwh = $kwp * ($poa / 1000.0);

            // Cell temperature estimate (NOCT-style), POA mean power approx.
            $poaMeanWm2 = $hours > 0 ? $poa / $hours : 0.0;
            $cellTemp = $ambientTempC + ($noct - 20.0) / 800.0 * $poaMeanWm2;
            $tempFactor = 1.0 + $tempCoeff * ($cellTemp - 25.0);
            if ($tempFactor < 0) {
                $tempFactor = 0.0;
            }

            $arrKwh = $nominalKwh * (1.0 - $dcLoss) * $tempFactor;
            if ($arrKwh < 0) {
                $arrKwh = 0.0;
            }
            $byArray[$aid] = $arrKwh;
            $totalKwh += $arrKwh;
        }

        $curtailed = 0.0;
        if ($this->inverterAcKw > 0 && $hours > 0) {
            $capKwh = $this->inverterAcKw * $hours;
            if ($totalKwh > $capKwh) {
                $curtailed = $totalKwh - $capKwh;
                $totalKwh = $capKwh;
            }
        }

        return array(
            'total_kwh' => $totalKwh,
            'by_array' => $byArray,
            'curtailed_by_inverter_kwh' => $curtailed,
        );
    }
}
