<?php
/** FetchScheduler, ForecastCache and SolarWindows behaviour. */

use SolarPlanner\Forecast\FetchScheduler;
use SolarPlanner\Forecast\ForecastCache;
use SolarPlanner\Common\SolarWindows;

T::group('FetchScheduler');

$sched = new FetchScheduler(array(
    'solcast' => array('min_interval_sec' => 1800, 'day_fetches' => 3, 'night_fetches' => 4, 'night_only' => false),
    'open_meteo' => array('min_interval_sec' => 1800, 'day_fetches' => 4, 'night_fetches' => 4),
));

// A day window 06:00..18:00 and a night window 23:00..07:00 (next day).
$day0 = gmmktime(6, 0, 0, 6, 1, 2026);
$dayEnd = gmmktime(18, 0, 0, 6, 1, 2026);
$nightStart = gmmktime(23, 0, 0, 6, 1, 2026);
$nightEnd = gmmktime(7, 0, 0, 6, 2, 2026);
$ctx = array(
    'sunrise_utc' => $day0, 'sunset_utc' => $dayEnd,
    'night_start_utc' => $nightStart, 'night_end_utc' => $nightEnd,
    'critical_refine_utc' => $nightEnd - 20 * 60,
);

// Never fetched -> bootstrap fetch.
$r = $sched->shouldFetch('solcast', $day0 + 3600, null, $ctx);
T::ok($r['fetch'], 'bootstrap fetch when never fetched');

// Just fetched 5 min ago -> blocked by min interval.
$now = $day0 + 3600;
$r = $sched->shouldFetch('solcast', $now, $now - 300, $ctx);
T::ok(!$r['fetch'], 'min interval blocks a too-soon fetch');

// Daytime, last fetch long ago -> due.
$r = $sched->shouldFetch('solcast', $day0 + 5 * 3600, $day0, $ctx);
T::ok($r['fetch'], 'daytime refresh due after a long gap');

// Night interval should be denser than day interval for solcast
// (night_fetches across an 8h window vs day_fetches across 12h). Verify by the
// computed next time gap: feed a recent fetch and check "not due yet" boundary.
$nightNow = $nightStart + 1800;
$rNight = $sched->shouldFetch('solcast', $nightNow, $nightNow - 1700, $ctx);
T::ok(!$rNight['fetch'], 'night: not due right after a fetch');

// Night base interval = 8h / 4 = 7200s; within 2h of critical it halves to 3600s.
// A 4000s gap is: NOT due far from critical (4000 < 7200), but DUE near critical
// (4000 > 3600). This proves the cadence densifies toward the decision point.
$farFromCritical = $ctx['critical_refine_utc'] - 5 * 3600; // >2h before critical
$rFar = $sched->shouldFetch('solcast', $farFromCritical, $farFromCritical - 4000, $ctx);
T::ok(!$rFar['fetch'], 'far from critical: 4000s gap not yet due (base cadence)');

$nearCritical = $ctx['critical_refine_utc'] - 1800; // 30 min before critical
$rNear = $sched->shouldFetch('solcast', $nearCritical, $nearCritical - 4000, $ctx);
T::ok($rNear['fetch'], 'near critical: same 4000s gap becomes due (densified cadence)');

T::group('ForecastCache');
$kv = array();
$cache = new ForecastCache(
    function ($k) use (&$kv) { return isset($kv[$k]) ? $kv[$k] : null; },
    function ($k, $v) use (&$kv) { $kv[$k] = $v; },
    3600
);
$run = array('provider_id' => 'solcast', 'intervals' => array(
    array('interval_start_utc' => 100, 'interval_end_utc' => 1900, 'pv_energy_kwh' => 1.5, 'is_missing' => 0),
));
$cache->put('solcast', $run, 1000);
$e = $cache->get('solcast', 1000 + 600);
T::ok($e !== null, 'cache returns stored entry');
T::ok($e['fresh'], 'entry fresh within max age');
T::eq($cache->lastFetchUtc('solcast', 2000), 1000, 'lastFetchUtc returns cached_at');
$stale = $cache->get('solcast', 1000 + 7200);
T::ok(!$stale['fresh'], 'entry stale beyond max age');

T::group('SolarWindows parsing');
// Property getter returns HH:MM strings.
$sw = new SolarWindows('Europe/Kyiv', function ($prop) {
    if (strpos($prop, 'SunRiseTime') !== false) return '06:45';
    if (strpos($prop, 'SunSetTime') !== false) return '19:10';
    return null;
});
$now = gmmktime(12, 0, 0, 6, 1, 2026);
$res = $sw->resolve($now, array('cheap_start_hour' => 23, 'cheap_end_hour' => 7));
T::eq($res['sun_source'], 'properties', 'sun source is properties when provided');
T::ok($res['sunset_utc'] > $res['sunrise_utc'], 'sunset after sunrise');
T::ok($res['night_end_utc'] > $res['night_start_utc'], 'night window valid');
T::ok($res['critical_refine_utc'] < $res['night_end_utc'], 'critical refine before night end');

// Fallback when properties missing.
$sw2 = new SolarWindows('Europe/Kyiv', function ($p) { return null; });
$res2 = $sw2->resolve($now, array(), 50.45);
T::eq($res2['sun_source'], 'fallback', 'falls back to estimate without properties');
T::ok($res2['sunset_utc'] > $res2['sunrise_utc'], 'fallback sunset after sunrise');

T::group('DarknessMode.active');
// SolarWindows reads DarknessMode.active and exposes it as is_dark.
$swDark = new SolarWindows('Europe/Kyiv', function ($prop) {
    if (strpos($prop, 'SunRiseTime') !== false) return '06:45';
    if (strpos($prop, 'SunSetTime') !== false) return '19:10';
    if (strpos($prop, 'DarknessMode.active') !== false) return '1';
    return null;
});
$rDark = $swDark->resolve($now, array('cheap_start_hour' => 23, 'cheap_end_hour' => 7));
T::ok($rDark['is_dark'] === true, 'DarknessMode.active=1 -> is_dark true');

$swLight = new SolarWindows('Europe/Kyiv', function ($prop) {
    if (strpos($prop, 'SunRiseTime') !== false) return '06:45';
    if (strpos($prop, 'SunSetTime') !== false) return '19:10';
    if (strpos($prop, 'DarknessMode.active') !== false) return '0';
    return null;
});
$rLight = $swLight->resolve($now, array('cheap_start_hour' => 23, 'cheap_end_hour' => 7));
T::ok($rLight['is_dark'] === false, 'DarknessMode.active=0 -> is_dark false');

// Property absent -> is_dark null (unknown), scheduler uses the windows.
$rNull = $sw2->resolve($now, array(), 50.45);
T::ok($rNull['is_dark'] === null, 'missing DarknessMode -> is_dark null');

// Scheduler: is_dark=false during the computed night window forces "day"
// cadence (host says it is light, trust it over our window).
$schedD = new FetchScheduler(array(
    'open_meteo' => array('min_interval_sec' => 1800, 'day_fetches' => 4, 'night_fetches' => 4),
));
$ctxLight = $ctx;
$ctxLight['is_dark'] = false;
// A point that falls inside the computed night window but host says light:
$nightMid = $nightStart + 1800;
$rLit = $schedD->shouldFetch('open_meteo', $nightMid, $nightMid - 10 * 3600, $ctxLight);
T::ok(strpos($rLit['reason'], 'night') === false, 'is_dark=false overrides night window (not a night refresh)');

// is_dark=true during daytime window forces "not day".
$ctxDark = $ctx;
$ctxDark['is_dark'] = true;
$dayMid = $day0 + 2 * 3600;
$rDk = $schedD->shouldFetch('open_meteo', $dayMid, $dayMid - 10 * 3600, $ctxDark);
T::ok(strpos($rDk['reason'], 'day refresh') === false, 'is_dark=true overrides day window (not a day refresh)');
