<?php
/** TimeGrid: half-open intervals, DST, cheap window real duration. */

use SolarPlanner\Common\TimeGrid;

T::group('TimeGrid intervals & cheap window');

$g = new TimeGrid('Europe/Kyiv', 30);

// A plain 2-hour span -> four 30-min half-open intervals.
$start = gmmktime(0, 0, 0, 6, 1, 2026); // summer, no DST change inside
$ivs = $g->intervals($start, $start + 2 * 3600);
T::eq(count($ivs), 4, '2h at 30-min step -> 4 intervals');
T::eq($ivs[0]['start_utc'], $start, 'first interval starts at span start');
T::eq($ivs[0]['end_utc'], $ivs[1]['start_utc'], 'half-open: interval end == next start');
T::approx($ivs[0]['hours'], 0.5, 1e-9, 'interval length 0.5 h');

// Cheap window 23:00 -> 07:00 crosses midnight; in a normal (non-DST) night it
// is 8 real hours. Base at local evening.
$eve = $g->localTimeToUtc($start, 20, 0, 0); // 20:00 local
$w = $g->cheapWindow($eve, 23, 7);
T::approx($w['hours'], 8.0, 1e-6, 'cheap window 23->07 is 8 real hours (non-DST night)');
T::ok($w['end_utc'] > $w['start_utc'], 'cheap window end after start (crosses midnight)');

// DST "spring forward" night in Europe/Kyiv (last Sunday of March 2026 = 29th):
// the local 23:00->07:00 window loses one hour -> 7 real hours.
$dstBase = gmmktime(12, 0, 0, 3, 28, 2026); // 28 Mar 2026, the evening before
$wd = $g->cheapWindow($dstBase, 23, 7);
T::approx($wd['hours'], 7.0, 1e-6, 'DST spring-forward night: cheap window is 7 real hours, not assumed 8');
