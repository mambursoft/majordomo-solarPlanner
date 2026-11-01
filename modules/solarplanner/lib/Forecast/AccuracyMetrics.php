<?php
/**
 * AccuracyMetrics — error statistics for forecast vs actual.
 *
 * error = forecast - actual.
 *
 * Provides MAE, Bias, RMSE and daily-energy error. MAPE is intentionally NOT
 * emphasised: near-zero winter values make relative error misleading, so MAPE
 * is only returned when the actual is above a guard threshold.
 *
 * Learning intervals should be excluded where the measured PV is not the
 * potentially available generation (battery full, zero-export clipping,
 * derating, dead array, bad telemetry). The caller passes only valid pairs.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class AccuracyMetrics
{
    /**
     * @param array $pairs list of ['forecast'=>float,'actual'=>float]
     * @param float $mapeGuard minimum actual to include in MAPE
     * @return array
     */
    public static function compute(array $pairs, $mapeGuard = 0.5)
    {
        $n = count($pairs);
        if ($n === 0) {
            return array('n' => 0, 'mae' => null, 'bias' => null, 'rmse' => null, 'mape' => null);
        }
        $sumAbs = 0.0;
        $sumErr = 0.0;
        $sumSq = 0.0;
        $mapeSum = 0.0;
        $mapeN = 0;
        foreach ($pairs as $p) {
            $err = (float)$p['forecast'] - (float)$p['actual'];
            $sumAbs += abs($err);
            $sumErr += $err;
            $sumSq += $err * $err;
            if ((float)$p['actual'] >= $mapeGuard) {
                $mapeSum += abs($err) / (float)$p['actual'];
                $mapeN++;
            }
        }
        return array(
            'n' => $n,
            'mae' => $sumAbs / $n,
            'bias' => $sumErr / $n,
            'rmse' => sqrt($sumSq / $n),
            'mape' => $mapeN > 0 ? ($mapeSum / $mapeN) * 100.0 : null,
        );
    }
}
