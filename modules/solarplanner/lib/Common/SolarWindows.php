<?php
/**
 * SolarWindows — resolve today's daylight window (sunrise..sunset) and the
 * cheap-tariff night window, in UTC unix seconds, for the FetchScheduler.
 *
 * Sunrise/sunset come from MajorDoMo's ThisComputer.SunRiseTime /
 * ThisComputer.SunSetTime via an injected getter, parsed robustly (accepts
 * "HH:MM", "HH:MM:SS", or a full "Y-m-d H:i[:s]" local string). If they are not
 * available, a coarse latitude/day-of-year fallback is used so the scheduler
 * still behaves sensibly.
 *
 * Pure logic aside from the injected getter; "now" is passed in for tests.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Common;

class SolarWindows
{
    /** @var string IANA tz */
    private $tz;
    /** @var callable|null function(string $prop): mixed  (e.g. wraps gg()) */
    private $getter;

    public function __construct($tz = 'Europe/Kyiv', $getter = null)
    {
        $this->tz = $tz;
        $this->getter = $getter;
    }

    /**
     * Resolve sunrise/sunset/night context around $nowUtc.
     *
     * @param int $nowUtc
     * @param array $cfg config for cheap window + critical-refine lead time:
     *   cheap_start_hour, cheap_end_hour (local ints),
     *   critical_refine_lead_min (minutes before cheap end; default 20)
     * @param float|null $lat latitude for the fallback estimate
     * @return array sunrise_utc, sunset_utc, night_start_utc, night_end_utc,
     *               critical_refine_utc, sun_source ('properties'|'fallback')
     */
    public function resolve($nowUtc, array $cfg = array(), $lat = 50.0)
    {
        $nowUtc = (int)$nowUtc;
        $grid = new TimeGrid($this->tz, 30);

        // --- sunrise / sunset ---
        $sunrise = $this->readSunProp('SunRiseTime', $nowUtc);
        $sunset = $this->readSunProp('SunSetTime', $nowUtc);
        $source = 'properties';
        if ($sunrise === null || $sunset === null || $sunset <= $sunrise) {
            $est = $this->estimateSun($nowUtc, $lat);
            $sunrise = $est['sunrise'];
            $sunset = $est['sunset'];
            $source = 'fallback';
        }

        // --- cheap-tariff night window (the one that is active now, or next) ---
        $startHour = isset($cfg['cheap_start_hour']) ? (int)$cfg['cheap_start_hour'] : 23;
        $endHour = isset($cfg['cheap_end_hour']) ? (int)$cfg['cheap_end_hour'] : 7;
        // Build windows for yesterday/today so the one covering "now" (or the
        // upcoming one) is chosen.
        $best = null;
        for ($off = -1; $off <= 1; $off++) {
            $w = $grid->cheapWindow($nowUtc + $off * 86400, $startHour, $endHour);
            if ($nowUtc >= $w['start_utc'] && $nowUtc < $w['end_utc']) {
                $best = $w; // active now
                break;
            }
            if ($w['start_utc'] > $nowUtc && ($best === null || $w['start_utc'] < $best['start_utc'])) {
                $best = $w; // nearest upcoming
            }
        }
        if ($best === null) {
            $best = $grid->cheapWindow($nowUtc, $startHour, $endHour);
        }

        $lead = isset($cfg['critical_refine_lead_min']) ? (int)$cfg['critical_refine_lead_min'] : 20;
        $critical = $best['end_utc'] - $lead * 60;

        return array(
            'sunrise_utc' => $sunrise,
            'sunset_utc' => $sunset,
            'night_start_utc' => $best['start_utc'],
            'night_end_utc' => $best['end_utc'],
            'critical_refine_utc' => $critical,
            'sun_source' => $source,
        );
    }

    /** Read and parse a sun property to a UTC timestamp near $nowUtc, or null. */
    private function readSunProp($prop, $nowUtc)
    {
        if (!is_callable($this->getter)) {
            return null;
        }
        $raw = call_user_func($this->getter, 'ThisComputer.' . $prop);
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        $raw = trim((string)$raw);
        $tz = new \DateTimeZone($this->tz);

        // Full datetime?
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}/', $raw)) {
            try {
                $dt = new \DateTime($raw, $tz);
                return (int)$dt->format('U');
            } catch (\Exception $e) {
                return null;
            }
        }
        // HH:MM[:SS] -> apply to today's local date.
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            $today = (new \DateTime('@' . $nowUtc))->setTimezone($tz)->format('Y-m-d');
            try {
                $dt = new \DateTime($today . ' ' . sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0), $tz);
                return (int)$dt->format('U');
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Coarse sunrise/sunset estimate (NOAA-free approximation) for the fallback.
     * Good enough to drive a fetch schedule; not used for energy math.
     */
    private function estimateSun($nowUtc, $lat)
    {
        $tz = new \DateTimeZone($this->tz);
        $local = (new \DateTime('@' . (int)$nowUtc))->setTimezone($tz);
        $doy = (int)$local->format('z') + 1;

        // Solar declination (deg) and hour angle of sunrise.
        $decl = 23.44 * sin(deg2rad(360.0 / 365.0 * ($doy - 81)));
        $latR = deg2rad((float)$lat);
        $declR = deg2rad($decl);
        $cosH = -tan($latR) * tan($declR);
        $cosH = max(-1.0, min(1.0, $cosH));
        $H = rad2deg(acos($cosH)); // half-day length in degrees
        $dayHours = 2.0 * $H / 15.0;
        $noonHour = 12.0; // local solar noon approx (ignores eq. of time/longitude)
        $sunriseHour = $noonHour - $dayHours / 2.0;
        $sunsetHour = $noonHour + $dayHours / 2.0;

        $dateStr = $local->format('Y-m-d');
        $mk = function ($hourFloat) use ($dateStr, $tz) {
            $h = (int)floor($hourFloat);
            $mnt = (int)round(($hourFloat - $h) * 60);
            if ($mnt >= 60) { $h++; $mnt -= 60; }
            $h = max(0, min(23, $h));
            $dt = new \DateTime($dateStr . ' ' . sprintf('%02d:%02d:00', $h, $mnt), $tz);
            return (int)$dt->format('U');
        };
        return array('sunrise' => $mk($sunriseHour), 'sunset' => $mk($sunsetHour));
    }
}
