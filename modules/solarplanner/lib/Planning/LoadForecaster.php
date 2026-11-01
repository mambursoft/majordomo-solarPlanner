<?php
/**
 * LoadForecaster — transparent statistical house-load forecast.
 *
 * First-stage method (spec): similar-hour profile, weekday/weekend split, a
 * robust estimator (median), heavier weight on recent history, separate night
 * handling, exclusion of bad intervals. ML is not required for the first release.
 *
 * Controllable loads must be subtracted from the base profile if the optimizer
 * schedules them separately (avoid double counting).
 *
 * If history is insufficient, an explicit manual fallback profile is used and
 * the result is flagged as "manual assumption".
 *
 * All energy in kWh per interval.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Planning;

class LoadForecaster
{
    /** @var string IANA timezone */
    private $tz;
    /** @var int minimum samples per bucket before trusting history */
    private $minSamples;
    /** @var array manual fallback: 24 hourly kW values */
    private $manualHourlyKw;

    public function __construct($tz = 'Europe/Kyiv', $minSamples = 3, array $manualHourlyKw = array())
    {
        $this->tz = $tz;
        $this->minSamples = (int)$minSamples;
        if (count($manualHourlyKw) === 24) {
            $this->manualHourlyKw = $manualHourlyKw;
        } else {
            // Conservative default night-heavy-ish profile (kW); flagged manual.
            $this->manualHourlyKw = array_fill(0, 24, 0.4);
        }
    }

    /**
     * Forecast load for a set of intervals.
     *
     * @param array $intervals list of ['start_utc','end_utc','hours']
     * @param array $history list of samples ['utc'=>int,'load_kw'=>float,'valid'=>bool]
     * @param array $controllable optional list of controllable-load energy to
     *        subtract, keyed by start_utc => kwh
     * @return array same intervals with added 'load_kwh' and run-level 'quality'
     */
    public function forecast(array $intervals, array $history, array $controllable = array())
    {
        $buckets = $this->buildBuckets($history);
        $usedManual = false;
        $out = array();

        foreach ($intervals as $iv) {
            $tz = new \DateTimeZone($this->tz);
            $dt = (new \DateTime('@' . (int)$iv['start_utc']))->setTimezone($tz);
            $hour = (int)$dt->format('G');
            $isWeekend = in_array((int)$dt->format('N'), array(6, 7), true) ? 1 : 0;
            $key = $isWeekend . ':' . $hour;

            if (isset($buckets[$key]) && count($buckets[$key]) >= $this->minSamples) {
                $kw = $this->weightedMedian($buckets[$key]);
            } else {
                $kw = $this->manualHourlyKw[$hour];
                $usedManual = true;
            }
            $kwh = $kw * (float)$iv['hours'];

            // Subtract controllable load if separately scheduled.
            if (isset($controllable[(int)$iv['start_utc']])) {
                $kwh = max(0.0, $kwh - (float)$controllable[(int)$iv['start_utc']]);
            }

            $row = $iv;
            $row['load_kwh'] = $kwh;
            $out[] = $row;
        }

        return array(
            'intervals' => $out,
            'quality' => $usedManual ? 'manual_assumption' : 'from_history',
        );
    }

    private function buildBuckets(array $history)
    {
        $buckets = array();
        $tz = new \DateTimeZone($this->tz);
        $now = time();
        foreach ($history as $s) {
            if (isset($s['valid']) && !$s['valid']) {
                continue; // exclude bad intervals
            }
            $dt = (new \DateTime('@' . (int)$s['utc']))->setTimezone($tz);
            $hour = (int)$dt->format('G');
            $isWeekend = in_array((int)$dt->format('N'), array(6, 7), true) ? 1 : 0;
            $key = $isWeekend . ':' . $hour;
            // Recency weight: last 7 days weigh more.
            $ageDays = max(0, ($now - (int)$s['utc']) / 86400.0);
            $weight = $ageDays <= 7 ? 2.0 : 1.0;
            $buckets[$key][] = array('kw' => (float)$s['load_kw'], 'w' => $weight);
        }
        return $buckets;
    }

    private function weightedMedian(array $samples)
    {
        // Expand by integer weight-ish via sorting on value then picking the
        // weighted midpoint — robust and simple.
        usort($samples, function ($a, $b) {
            return $a['kw'] <=> $b['kw'];
        });
        $total = 0.0;
        foreach ($samples as $s) {
            $total += $s['w'];
        }
        $half = $total / 2.0;
        $acc = 0.0;
        foreach ($samples as $s) {
            $acc += $s['w'];
            if ($acc >= $half) {
                return $s['kw'];
            }
        }
        return $samples[count($samples) - 1]['kw'];
    }
}
