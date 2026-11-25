<?php
/**
 * PvCalibrationService — closed feedback loop from measured PV to the forecast.
 *
 * The module historically recorded measured PV but never read it back. This
 * service turns archived forecast/actual pairs into:
 *   - accuracy metrics (MAE / bias / RMSE / MAPE),
 *   - a conservative multiplicative correction (scale),
 *   - band-derived risk factors for the night-charge scenarios,
 *   - per-provider statistics usable by EnsembleBuilder's weighted/best modes.
 *
 * It is pure PHP: persistence and scheduling stay in the MajorDoMo glue layer.
 * Only pairs that really represent available generation may be passed in —
 * callers must apply TelemetryCollector::pvUsableForCalibration() first.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Analytics;

use SolarPlanner\Forecast\AccuracyMetrics;

class PvCalibrationService
{
    /** Minimum number of verified days before a correction is applied. */
    const MIN_SAMPLES = 3;

    /**
     * @param array $pairs list of ['forecast'=>float,'actual'=>float,'date'=>string]
     * @param float $previousScale last applied scale (for smoothing)
     * @param float $alpha smoothing factor 0..1
     * @param float $minScale lower bound of the correction
     * @param float $maxScale upper bound of the correction
     * @param float $forecastScale multiplicative factor already applied to the
     *        forecast values but NOT represented in $previousScale (the manual
     *        PV_SCALE). The learned auto-scale is target / $forecastScale.
     * @return array metrics + 'scale' + 'applied'
     */
    public static function evaluate(array $pairs, $previousScale = 1.0, $alpha = 0.25,
                                    $minScale = 0.35, $maxScale = 1.60, $forecastScale = 1.0)
    {
        $valid = array();
        foreach ($pairs as $p) {
            $f = isset($p['forecast']) ? (float)$p['forecast'] : null;
            $a = isset($p['actual']) ? (float)$p['actual'] : null;
            if ($f === null || $a === null || $f < 0 || $a <= 0) {
                continue; // zero actual winter days carry no reliable ratio
            }
            $valid[] = array('forecast' => $f, 'actual' => $a);
        }
        $metrics = AccuracyMetrics::compute($valid);
        $metrics['scale'] = self::clamp($previousScale, $minScale, $maxScale);
        $metrics['recommended_scale'] = $metrics['scale'];
        $metrics['applied'] = false;

        if ($metrics['n'] < self::MIN_SAMPLES) {
            return $metrics;
        }

        // Energy-weighted ratio is more robust than the mean of daily ratios:
        // one tiny winter day must not dominate the correction.
        $sumF = 0.0;
        $sumA = 0.0;
        foreach ($valid as $p) {
            $sumF += $p['forecast'];
            $sumA += $p['actual'];
        }
        if ($sumF <= 0) {
            return $metrics;
        }
        $forecastScale = max(0.01, (float)$forecastScale);
        $raw = ($sumA / $sumF) / $forecastScale;
        $target = self::clamp($raw, $minScale, $maxScale);
        $a = self::clamp((float)$alpha, 0.0, 1.0);
        $smoothed = self::clamp((1.0 - $a) * (float)$previousScale + $a * $target, $minScale, $maxScale);

        $metrics['recommended_scale'] = round($target, 4);
        $metrics['scale'] = round($smoothed, 4);
        $metrics['applied'] = true;
        return $metrics;
    }

    /**
     * Risk factors for low_pv / high_pv scenarios derived from the ensemble's
     * own spread instead of the previous hard-coded 0.7 / 1.3.
     *
     * @param array $intervals ensemble intervals with low_kwh/pv_kwh/high_kwh
     * @return array ['low_pv_factor'=>float,'high_pv_factor'=>float]
     */
    public static function bandFactors(array $intervals)
    {
        $sumPv = 0.0;
        $sumLow = 0.0;
        $sumHigh = 0.0;
        $n = 0;
        foreach ($intervals as $iv) {
            $pv = isset($iv['pv_kwh']) ? (float)$iv['pv_kwh'] : 0.0;
            if ($pv <= 0.01) {
                continue;
            }
            $sumPv += $pv;
            $sumLow += isset($iv['low_kwh']) ? (float)$iv['low_kwh'] : $pv;
            $sumHigh += isset($iv['high_kwh']) ? (float)$iv['high_kwh'] : $pv;
            $n++;
        }
        if ($n === 0 || $sumPv <= 0 || ($sumHigh - $sumLow) < 0.02 * $sumPv) {
            // A zero-width band (single provider, or providers in perfect
            // agreement) carries NO information about uncertainty. Clamping
            // 1.0 to 0.95/1.05 would falsely imply a 5% margin; keep the
            // conservative legacy factors instead.
            return array('low_pv_factor' => 0.7, 'high_pv_factor' => 1.3);
        }
        return array(
            // Clamp: a zero-width band must still leave a prudent margin, and
            // a very wide band must not make the planner infeasible by default.
            'low_pv_factor' => round(self::clamp($sumLow / $sumPv, 0.5, 0.95), 3),
            'high_pv_factor' => round(self::clamp($sumHigh / $sumPv, 1.05, 1.6), 3),
        );
    }

    /**
     * Per-provider MAE and observation count from the calibration history,
     * keyed by provider id. Feeds EnsembleBuilder weighted/best modes.
     *
     * @param array $rows rows from SqlForecastStore::loadCalibration()
     * @return array providerId => ['mae'=>float,'obs'=>int]
     */
    public static function providerStats(array $rows)
    {
        $out = array();
        foreach ($rows as $r) {
            $id = isset($r['PROVIDER_ID']) ? $r['PROVIDER_ID'] : 'unknown';
            if (!isset($out[$id])) {
                $out[$id] = array('mae' => null, 'obs' => 0);
            }
            if (isset($r['N_SAMPLES'])) {
                $out[$id]['obs'] += (int)$r['N_SAMPLES'];
            }
            if (isset($r['MAE']) && $r['MAE'] !== null) {
                $m = (float)$r['MAE'];
                // Exponential smoothing over history, newest row first.
                $out[$id]['mae'] = $out[$id]['mae'] === null
                    ? $m : 0.6 * $m + 0.4 * $out[$id]['mae'];
            }
        }
        return $out;
    }

    private static function clamp($v, $lo, $hi)
    {
        return max($lo, min($hi, (float)$v));
    }
}
