<?php
/**
 * EnsembleBuilder — combine or select forecast runs onto a common grid.
 *
 * Spec constraints:
 *  - do not declare a "best" provider without statistics,
 *  - inverse-error weighting is a starting hypothesis, not proven optimal,
 *  - cap a single provider's weight; smooth weight updates; fall back to a
 *    simpler method on degradation; keep the method choice visible,
 *  - do not treat two adapters that use the same underlying weather model as
 *    two independent confirmations,
 *  - provider spread may be a heuristic scenario band, NOT calibrated P10/P90.
 *
 * IMPORTANT (fixed in 1.2.0): providers deliver energy over DIFFERENT interval
 * lengths — Open-Meteo/Forecast.Solar are hourly, Solcast is 30-minute. Taking
 * a median of raw kWh at a shared start timestamp mixes a 1-hour energy with a
 * 30-minute energy and then spreads the result over the wrong duration. This
 * class therefore normalises every provider to MEAN POWER (kW) on one common
 * time grid first, combines powers, and only then converts back to interval
 * energy. Total energy is conserved and the mixed-duration inflation (~1.45x)
 * is eliminated.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class EnsembleBuilder
{
    /** @var float guard epsilon for inverse-error weights */
    private $epsilon;
    /** @var float max weight any single provider may take */
    private $maxWeight;
    /** @var int minimum observations before trusting a provider's MAE */
    private $minObservations;

    public function __construct($epsilon = 0.1, $maxWeight = 0.7, $minObservations = 7)
    {
        $this->epsilon = (float)$epsilon;
        $this->maxWeight = (float)$maxWeight;
        $this->minObservations = (int)$minObservations;
    }

    /**
     * Build a merged interval series on a single, duration-safe grid.
     *
     * @param array $runs list of ['run'=>ForecastRun,'mae'=>float|null,'obs'=>int,'weather_model'=>string]
     * @param string $method 'median' | 'mean' | 'weighted' | 'best'
     * @return array ['method'=>string,'intervals'=>array,'weights'=>array,'band'=>array,'step_sec'=>int]
     *   intervals keyed by start_utc: ['end_utc','pv_kwh','pv_kw','low_kwh','high_kwh']
     */
    public function build(array $runs, $method = 'median')
    {
        $usable = array();
        foreach ($runs as $r) {
            if (empty($r['run']) || empty($r['run']->intervals)) {
                continue;
            }
            $usable[] = $r;
        }
        if (empty($usable)) {
            return array('method' => 'none', 'intervals' => array(), 'weights' => array(),
                'band' => array(), 'step_sec' => 0);
        }

        // Common target step = the finest interval actually present. Hourly-only
        // feeds stay hourly; adding a 30-minute feed refines the grid to 30 min.
        $minDur = PHP_INT_MAX;
        $minStart = PHP_INT_MAX;
        $maxEnd = 0;
        foreach ($usable as $r) {
            foreach ($r['run']->intervals as $iv) {
                $s = (int)$iv['interval_start_utc'];
                $e = (int)$iv['interval_end_utc'];
                $minStart = min($minStart, $s);
                $maxEnd = max($maxEnd, $e);
                if (!$iv['is_missing'] && $e > $s) {
                    $minDur = min($minDur, $e - $s);
                }
            }
        }
        if ($minDur === PHP_INT_MAX || $maxEnd <= $minStart) {
            return array('method' => 'none', 'intervals' => array(), 'weights' => array(),
                'band' => array(), 'step_sec' => 0);
        }
        $step = max(300, min(3600, $minDur)); // 5 min .. 60 min
        // Align the grid to the epoch so :00/:30 feeds line up.
        $alignedStart = (int)(floor($minStart / $step) * $step);
        $stepHours = $step / 3600.0;

        $weights = $this->computeWeights($usable, $method);
        $actualMethod = $weights === null ? 'median' : $method;

        $intervals = array();
        $band = array();
        for ($start = $alignedStart; $start < $maxEnd; $start += $step) {
            $end = $start + $step;
            $vals = array();      // mean power in kW per provider
            $weighted = 0.0;
            $wsum = 0.0;
            foreach ($usable as $i => $r) {
                $kw = $this->meanPowerInSlot($r['run'], $start, $end);
                if ($kw === null) {
                    continue; // no usable coverage in this slot
                }
                $vals[] = $kw;
                if ($weights !== null && isset($weights[$i])) {
                    $weighted += $kw * $weights[$i];
                    $wsum += $weights[$i];
                }
            }
            if (empty($vals)) {
                continue;
            }
            sort($vals);
            $lowKw = $vals[0];
            $highKw = $vals[count($vals) - 1];
            switch ($actualMethod) {
                case 'mean':
                    $centralKw = array_sum($vals) / count($vals);
                    break;
                case 'weighted':
                    $centralKw = $wsum > 0 ? $weighted / $wsum : $this->median($vals);
                    break;
                case 'best':
                    $centralKw = $this->meanPowerInSlot($usable[$this->bestIndex($usable)]['run'], $start, $end);
                    if ($centralKw === null) {
                        $centralKw = $this->median($vals);
                    }
                    break;
                case 'median':
                default:
                    $centralKw = $this->median($vals);
                    break;
            }
            $centralKwh = $centralKw * $stepHours;
            $intervals[$start] = array(
                'end_utc' => $end,
                'pv_kwh' => $centralKwh,
                'pv_kw' => $centralKw,
                'low_kwh' => $lowKw * $stepHours,
                'high_kwh' => $highKw * $stepHours,
            );
            // Band uses the SAME slot placement rule as the central value.
            $band[$start] = array('low_kwh' => $lowKw * $stepHours, 'high_kwh' => $highKw * $stepHours);
        }

        return array(
            'method' => $actualMethod,
            'intervals' => $intervals,
            'weights' => $weights === null ? array() : $weights,
            'band' => $band,
            'step_sec' => $step,
        );
    }

    /**
     * Mean power (kW) contributed by one provider over [start,end).
     * Each source interval is treated as uniformly spread over its own
     * duration; only the overlapping part is counted. A missing source
     * interval overlapping the slot makes this provider unusable there.
     *
     * @return float|null null = no usable coverage
     */
    private function meanPowerInSlot(ForecastRun $run, $start, $end)
    {
        $slotHours = ($end - $start) / 3600.0;
        if ($slotHours <= 0) {
            return null;
        }
        $energy = 0.0;
        $covered = 0.0;
        foreach ($run->intervals as $iv) {
            $s = (int)$iv['interval_start_utc'];
            $e = (int)$iv['interval_end_utc'];
            $lo = $start > $s ? $start : $s;
            $hi = $end < $e ? $end : $e;
            $ov = $hi - $lo;
            if ($ov <= 0) {
                continue;
            }
            if ($iv['is_missing'] || $iv['pv_energy_kwh'] === null) {
                return null; // never invent an energy for a gap
            }
            $dur = $e - $s;
            if ($dur <= 0) {
                continue;
            }
            $energy += (float)$iv['pv_energy_kwh'] * ($ov / $dur);
            $covered += $ov;
        }
        if ($covered <= 0) {
            return null;
        }
        return $energy / $slotHours;
    }

    private function computeWeights(array $usable, $method)
    {
        if ($method !== 'weighted') {
            return null;
        }
        $raw = array();
        $total = 0.0;
        foreach ($usable as $i => $r) {
            $mae = isset($r['mae']) ? $r['mae'] : null;
            $obs = isset($r['obs']) ? (int)$r['obs'] : 0;
            if ($mae === null || $obs < $this->minObservations) {
                return null; // not enough history -> fall back to median
            }
            $w = 1.0 / ($this->epsilon + $mae);
            $raw[$i] = $w;
            $total += $w;
        }
        if ($total <= 0) {
            return null;
        }
        $weights = array();
        foreach ($raw as $i => $w) {
            $weights[$i] = $w / $total;
        }
        // Enforce the per-provider cap by redistributing the excess only over
        // providers still below the cap. A plain min()+renormalise pushes the
        // capped weights back ABOVE the cap and violates the advertised rule.
        $capped = $weights;
        $n = count($capped);
        $max = (float)$this->maxWeight;
        for ($pass = 0; $pass <= $n; $pass++) {
            $excess = 0.0;
            $freeSum = 0.0;
            foreach ($capped as $w) {
                if ($w > $max) {
                    $excess += $w - $max;
                } elseif ($w < $max - 1e-12) {
                    $freeSum += $w;
                }
            }
            if ($excess <= 1e-12) {
                break;
            }
            if ($freeSum <= 1e-12 || $max * $n < 1.0 - 1e-12) {
                // Impossible to satisfy the cap with this many providers: use
                // an even split, which is the least-biased approximation.
                $even = 1.0 / $n;
                foreach ($capped as $i => $w) {
                    $capped[$i] = $even;
                }
                break;
            }
            foreach ($capped as $i => $w) {
                if ($w > $max) {
                    $capped[$i] = $max;
                } elseif ($w < $max - 1e-12) {
                    $capped[$i] = $w + $excess * ($w / $freeSum);
                }
            }
        }
        $sum = array_sum($capped);
        foreach ($capped as $i => $w) {
            $capped[$i] = $w / $sum;
        }
        return $capped;
    }

    private function bestIndex(array $usable)
    {
        $best = 0;
        $bestMae = PHP_INT_MAX;
        foreach ($usable as $i => $r) {
            if (isset($r['mae']) && $r['mae'] !== null && $r['mae'] < $bestMae) {
                $bestMae = $r['mae'];
                $best = $i;
            }
        }
        return $best;
    }

    private function median(array $vals)
    {
        $n = count($vals);
        if ($n === 0) {
            return 0.0;
        }
        sort($vals);
        $mid = (int)floor($n / 2);
        if ($n % 2) {
            return $vals[$mid];
        }
        return ($vals[$mid - 1] + $vals[$mid]) / 2.0;
    }
}
