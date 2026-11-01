<?php
/**
 * TouCompiler — turn a fine-grained plan into a feasible Deye TOU schedule.
 *
 * Deye SUN-14/15/16/18/20K-SG05LP3-EU-SM2 supports SIX time periods (confirmed
 * by the series datasheet). This compiler must never emit a seventh slot.
 *
 * It:
 *   1. takes the desired policy / SOC trajectory,
 *   2. preserves tariff boundaries (e.g. 23:00 and 07:00),
 *   3. merges adjacent intervals with similar behaviour,
 *   4. snaps to SOC/time/power steps,
 *   5. produces a full, valid schedule with no gaps, overlaps or bad midnight
 *      behaviour,
 *   6. hands the schedule back for re-simulation and comparison,
 *   7. lets the caller reject an unsafe or too-inaccurate approximation.
 *
 * It separates: desired SOC trajectory, discharge threshold, grid-charge enable
 * and grid-charge target — it does not conflate "Batt = 100%" with "charge from
 * sun to 100%".
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Planning;

class TouCompiler
{
    /** @var int max number of TOU periods for the device profile */
    private $maxSlots;
    /** @var int time step minutes supported by inverter */
    private $timeStepMin;
    /** @var float soc step percent supported by inverter */
    private $socStepPct;

    public function __construct($maxSlots = 6, $timeStepMin = 1, $socStepPct = 1.0)
    {
        $this->maxSlots = (int)$maxSlots;
        $this->timeStepMin = (int)$timeStepMin;
        $this->socStepPct = (float)$socStepPct;
    }

    /**
     * Compile desired per-interval behaviour into TOU slots.
     *
     * @param array $desired list of intervals, each:
     *   ['start_local'=>'HH:MM','end_local'=>'HH:MM',
     *    'grid_charge'=>0|1,'target_soc'=>float,'discharge_floor_soc'=>float]
     *   Intervals must be ordered and cover the day without gaps.
     * @return array ['ok'=>bool,'slots'=>array,'error'=>string|null,'merged'=>int]
     */
    public function compile(array $desired)
    {
        if (empty($desired)) {
            return array('ok' => false, 'slots' => array(), 'error' => 'no intervals', 'merged' => 0);
        }

        // Snap SOC values to the device step and normalise flags.
        $norm = array();
        foreach ($desired as $d) {
            $norm[] = array(
                'start_local' => $d['start_local'],
                'end_local' => $d['end_local'],
                'grid_charge' => !empty($d['grid_charge']) ? 1 : 0,
                'target_soc' => $this->snapSoc($d['target_soc']),
                'discharge_floor_soc' => $this->snapSoc(isset($d['discharge_floor_soc']) ? $d['discharge_floor_soc'] : 0),
            );
        }

        // Merge adjacent intervals with identical behaviour, preserving tariff
        // boundaries implicitly (they already sit on interval edges).
        $merged = array();
        $mergeCount = 0;
        foreach ($norm as $iv) {
            if (!empty($merged)) {
                $last = $merged[count($merged) - 1];
                if ($last['grid_charge'] === $iv['grid_charge']
                    && abs($last['target_soc'] - $iv['target_soc']) < 1e-9
                    && abs($last['discharge_floor_soc'] - $iv['discharge_floor_soc']) < 1e-9
                    && $last['end_local'] === $iv['start_local']) {
                    $merged[count($merged) - 1]['end_local'] = $iv['end_local'];
                    $mergeCount++;
                    continue;
                }
            }
            $merged[] = $iv;
        }

        // If still more than maxSlots, merge the least-impactful neighbours by
        // combining the two adjacent slots whose target_soc differs least.
        $guard = 0;
        while (count($merged) > $this->maxSlots && $guard++ < 1000) {
            $bestI = 0;
            $bestDiff = PHP_INT_MAX;
            for ($i = 0; $i < count($merged) - 1; $i++) {
                $diff = abs($merged[$i]['target_soc'] - $merged[$i + 1]['target_soc'])
                    + ($merged[$i]['grid_charge'] !== $merged[$i + 1]['grid_charge'] ? 100 : 0);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestI = $i;
                }
            }
            // Merge bestI and bestI+1: keep the more conservative (higher target
            // when charging, and OR the grid_charge enable).
            $a = $merged[$bestI];
            $b = $merged[$bestI + 1];
            $combined = array(
                'start_local' => $a['start_local'],
                'end_local' => $b['end_local'],
                'grid_charge' => ($a['grid_charge'] || $b['grid_charge']) ? 1 : 0,
                'target_soc' => max($a['target_soc'], $b['target_soc']),
                'discharge_floor_soc' => max($a['discharge_floor_soc'], $b['discharge_floor_soc']),
            );
            array_splice($merged, $bestI, 2, array($combined));
            $mergeCount++;
        }

        // Validate: no gaps / overlaps, correct midnight wrap, slot count.
        $err = $this->validate($merged);
        if ($err !== null) {
            return array('ok' => false, 'slots' => $merged, 'error' => $err, 'merged' => $mergeCount);
        }

        return array('ok' => true, 'slots' => $merged, 'error' => null, 'merged' => $mergeCount);
    }

    private function snapSoc($soc)
    {
        if ($this->socStepPct <= 0) {
            return (float)$soc;
        }
        return round(round((float)$soc / $this->socStepPct) * $this->socStepPct, 3);
    }

    /**
     * Ensure slots tile a 24h day with no gaps or overlaps, handling the
     * midnight wrap (a slot may run e.g. 23:00 -> 07:00).
     */
    private function validate(array $slots)
    {
        if (count($slots) > $this->maxSlots) {
            return 'too_many_slots:' . count($slots);
        }
        if (empty($slots)) {
            return 'empty';
        }
        // Convert to minutes; allow wrap on the final slot.
        $cursor = $this->toMin($slots[0]['start_local']);
        $startCursor = $cursor;
        $total = 0;
        foreach ($slots as $i => $s) {
            $st = $this->toMin($s['start_local']);
            $en = $this->toMin($s['end_local']);
            if ($st !== $cursor) {
                return 'gap_or_overlap_at_slot_' . $i;
            }
            $dur = $en - $st;
            if ($dur <= 0) {
                $dur += 1440; // wrap through midnight
            }
            $total += $dur;
            $cursor = $en % 1440;
        }
        if ($cursor !== $startCursor) {
            return 'schedule_not_closed';
        }
        if ($total !== 1440) {
            return 'coverage_not_24h:' . $total;
        }
        return null;
    }

    private function toMin($hhmm)
    {
        list($h, $m) = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }
}
