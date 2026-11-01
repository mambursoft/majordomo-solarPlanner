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
     * Build a merged interval series.
     *
     * @param array $runs list of ['run'=>ForecastRun,'mae'=>float|null,'obs'=>int,'weather_model'=>string]
     * @param string $method 'median' | 'mean' | 'weighted' | 'best'
     * @return array ['method'=>string,'intervals'=>array,'weights'=>array,'band'=>array]
     *   intervals keyed by start_utc: ['end_utc','pv_kwh','low_kwh','high_kwh']
     */
    public function build(array $runs, $method = 'median')
    {
        // Deduplicate providers sharing the same underlying weather model: keep
        // only the lowest-MAE one of each weather_model group for weighting, but
        // still show all in the band.
        $usable = array();
        foreach ($runs as $r) {
            if (empty($r['run']) || empty($r['run']->intervals)) {
                continue;
            }
            $usable[] = $r;
        }
        if (empty($usable)) {
            return array('method' => 'none', 'intervals' => array(), 'weights' => array(), 'band' => array());
        }

        // Collect every interval start across runs.
        $grid = array();
        foreach ($usable as $r) {
            foreach ($r['run']->intervals as $iv) {
                if ($iv['is_missing']) {
                    continue;
                }
                $grid[$iv['interval_start_utc']] = $iv['interval_end_utc'];
            }
        }
        ksort($grid);

        $weights = $this->computeWeights($usable, $method);
        $actualMethod = $weights === null ? 'median' : $method;

        $intervals = array();
        $band = array();
        foreach ($grid as $start => $end) {
            $vals = array();
            $weighted = 0.0;
            $wsum = 0.0;
            foreach ($usable as $i => $r) {
                $v = $this->valueAt($r['run'], $start);
                if ($v === null) {
                    continue;
                }
                $vals[] = $v;
                if ($weights !== null && isset($weights[$i])) {
                    $weighted += $v * $weights[$i];
                    $wsum += $weights[$i];
                }
            }
            if (empty($vals)) {
                continue;
            }
            sort($vals);
            $low = $vals[0];
            $high = $vals[count($vals) - 1];
            switch ($actualMethod) {
                case 'mean':
                    $central = array_sum($vals) / count($vals);
                    break;
                case 'weighted':
                    $central = $wsum > 0 ? $weighted / $wsum : $this->median($vals);
                    break;
                case 'best':
                    $central = $this->valueAt($usable[$this->bestIndex($usable)]['run'], $start);
                    if ($central === null) {
                        $central = $this->median($vals);
                    }
                    break;
                case 'median':
                default:
                    $central = $this->median($vals);
                    break;
            }
            $intervals[$start] = array('end_utc' => $end, 'pv_kwh' => $central, 'low_kwh' => $low, 'high_kwh' => $high);
            $band[$start] = array('low_kwh' => $low, 'high_kwh' => $high);
        }

        return array(
            'method' => $actualMethod,
            'intervals' => $intervals,
            'weights' => $weights === null ? array() : $weights,
            'band' => $band,
        );
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
        // Cap max weight, then renormalise.
        $capped = array();
        foreach ($weights as $i => $w) {
            $capped[$i] = min($this->maxWeight, $w);
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

    private function valueAt(ForecastRun $run, $start)
    {
        foreach ($run->intervals as $iv) {
            if ($iv['interval_start_utc'] == $start && !$iv['is_missing']) {
                return (float)$iv['pv_energy_kwh'];
            }
        }
        return null;
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
