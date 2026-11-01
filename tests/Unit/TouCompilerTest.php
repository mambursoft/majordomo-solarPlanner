<?php
/** TouCompiler: six-slot limit, no gaps/overlaps, midnight wrap. */

use SolarPlanner\Planning\TouCompiler;

T::group('TouCompiler');

// Build a full day of 8 three-hour blocks, alternating behaviour; must compress
// to <= 6 slots and cover 24h with no gaps.
$desired = array();
for ($h = 0; $h < 24; $h += 3) {
    $start = sprintf('%02d:00', $h);
    $end = sprintf('%02d:00', ($h + 3) % 24);
    $cheap = ($h >= 23 || $h < 7) ? 1 : 0;
    $desired[] = array(
        'start_local' => $start,
        'end_local' => $end,
        'grid_charge' => $cheap,
        'target_soc' => $cheap ? 80 : 100,
        'discharge_floor_soc' => 20,
    );
}
$c = new TouCompiler(6, 1, 1.0);
$res = $c->compile($desired);
T::ok($res['ok'], 'compiles to a valid schedule');
T::ok(count($res['slots']) <= 6, 'no more than 6 TOU slots (no hidden 7th)');

// Midnight wrap: a slot 23:00 -> 07:00 is valid and the schedule closes.
$wrap = array(
    array('start_local' => '07:00', 'end_local' => '23:00', 'grid_charge' => 0, 'target_soc' => 100, 'discharge_floor_soc' => 20),
    array('start_local' => '23:00', 'end_local' => '07:00', 'grid_charge' => 1, 'target_soc' => 80, 'discharge_floor_soc' => 20),
);
$resW = $c->compile($wrap);
T::ok($resW['ok'], 'midnight-wrap schedule is valid and closed');
T::eq(count($resW['slots']), 2, 'wrap compresses to two slots');

// A schedule with a gap must be rejected.
$gap = array(
    array('start_local' => '00:00', 'end_local' => '06:00', 'grid_charge' => 1, 'target_soc' => 80, 'discharge_floor_soc' => 20),
    array('start_local' => '07:00', 'end_local' => '00:00', 'grid_charge' => 0, 'target_soc' => 100, 'discharge_floor_soc' => 20),
);
$resG = $c->compile($gap);
T::ok(!$resG['ok'], 'schedule with a gap is rejected');
