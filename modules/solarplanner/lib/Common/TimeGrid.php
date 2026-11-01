<?php
/**
 * TimeGrid — half-open interval grid in UTC with DST-aware local mapping.
 *
 * Rules enforced from the spec:
 *  - Timestamps stored in UTC (unix seconds internally).
 *  - Tariff / UI / inverter boundaries resolved via an IANA timezone, never a
 *    fixed UTC offset.
 *  - Intervals are half-open: [start, end).
 *  - A day is NOT assumed to be exactly 24h, and the cheap window is NOT assumed
 *    to be exactly 8 physical hours (DST transitions change real durations).
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Common;

class TimeGrid
{
    /** @var string IANA timezone of the installation */
    private $tz;

    /** @var int grid step in minutes (15, 30 or 60) */
    private $stepMinutes;

    public function __construct($ianaTimezone = 'Europe/Kyiv', $stepMinutes = 30)
    {
        $this->tz = $ianaTimezone;
        $this->setStep($stepMinutes);
    }

    public function setStep($stepMinutes)
    {
        $stepMinutes = (int)$stepMinutes;
        if (!in_array($stepMinutes, array(15, 30, 60), true)) {
            throw new \InvalidArgumentException('Grid step must be 15, 30 or 60 minutes.');
        }
        $this->stepMinutes = $stepMinutes;
    }

    public function getStepMinutes()
    {
        return $this->stepMinutes;
    }

    public function getStepHours()
    {
        return $this->stepMinutes / 60.0;
    }

    public function getTimezone()
    {
        return $this->tz;
    }

    /**
     * Build a list of half-open intervals covering [startUtc, endUtc).
     * Each element: ['start_utc' => int, 'end_utc' => int, 'hours' => float].
     *
     * DST-correct: steps are computed on the wall clock of the timezone so that
     * a 30-minute grid stays aligned to local half hours across DST changes,
     * while 'hours' reflects the real physical duration of each interval.
     *
     * @param int $startUtc unix seconds (UTC)
     * @param int $endUtc   unix seconds (UTC), exclusive
     * @return array
     */
    public function intervals($startUtc, $endUtc)
    {
        $startUtc = (int)$startUtc;
        $endUtc = (int)$endUtc;
        if ($endUtc <= $startUtc) {
            return array();
        }

        $tz = new \DateTimeZone($this->tz);
        $cursor = (new \DateTime('@' . $startUtc))->setTimezone($tz);
        $out = array();
        $stepSpec = 'PT' . $this->stepMinutes . 'M';

        $curUnix = (int)$cursor->format('U');
        $guard = 0;
        while ($curUnix < $endUtc) {
            $next = clone $cursor;
            $next->add(new \DateInterval($stepSpec));
            $nextUnix = (int)$next->format('U');
            if ($nextUnix > $endUtc) {
                $nextUnix = $endUtc;
            }
            $hours = ($nextUnix - $curUnix) / 3600.0;
            if ($hours > 0) {
                $out[] = array(
                    'start_utc' => $curUnix,
                    'end_utc' => $nextUnix,
                    'hours' => $hours,
                );
            }
            $cursor = $next;
            $curUnix = $nextUnix;
            if (++$guard > 100000) {
                break; // safety against pathological input
            }
        }
        return $out;
    }

    /**
     * Resolve a local wall-clock time (HH:MM) on a given local date to a UTC
     * unix timestamp. If $baseUtc falls on a given local calendar day, that day
     * is used; $dayOffset shifts by whole local days.
     *
     * @param int $baseUtc reference unix seconds
     * @param int $hour local hour
     * @param int $minute local minute
     * @param int $dayOffset local-day offset (0 = same local day as base)
     * @return int unix seconds (UTC)
     */
    public function localTimeToUtc($baseUtc, $hour, $minute = 0, $dayOffset = 0)
    {
        $tz = new \DateTimeZone($this->tz);
        $dt = (new \DateTime('@' . (int)$baseUtc))->setTimezone($tz);
        if ($dayOffset !== 0) {
            $dt->modify(($dayOffset > 0 ? '+' : '-') . abs((int)$dayOffset) . ' day');
        }
        // Re-create at the requested wall clock on that local date.
        $local = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $dt->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', (int)$hour, (int)$minute),
            $tz
        );
        return (int)$local->format('U');
    }

    /**
     * Build the real cheap-tariff window [start, end) in UTC for the night that
     * starts on the local day of $baseUtc. Handles windows that cross midnight
     * (e.g. 23:00 -> 07:00 next local day) and DST-shifted real durations.
     *
     * @param int $baseUtc reference unix seconds (the evening before the night)
     * @param int $startHour local start hour (e.g. 23)
     * @param int $endHour   local end hour (e.g. 7)
     * @return array ['start_utc' => int, 'end_utc' => int, 'hours' => float]
     */
    public function cheapWindow($baseUtc, $startHour = 23, $endHour = 7, $startMin = 0, $endMin = 0)
    {
        $start = $this->localTimeToUtc($baseUtc, $startHour, $startMin, 0);
        // If the window crosses midnight the end is on the next local day.
        $endDayOffset = ($endHour * 60 + $endMin) <= ($startHour * 60 + $startMin) ? 1 : 0;
        $end = $this->localTimeToUtc($baseUtc, $endHour, $endMin, $endDayOffset);
        return array(
            'start_utc' => $start,
            'end_utc' => $end,
            'hours' => ($end - $start) / 3600.0,
        );
    }

    /** Format a UTC unix timestamp as local ISO string with offset. */
    public function formatLocal($utc, $format = 'Y-m-d H:i')
    {
        $tz = new \DateTimeZone($this->tz);
        return (new \DateTime('@' . (int)$utc))->setTimezone($tz)->format($format);
    }
}
