<?php
/** NightChargePlanner: winter vs summer behaviour, already-charged, infeasible. */

use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Planning\NightChargePlanner;
use SolarPlanner\Common\TimeGrid;

T::group('NightChargePlanner (TargetSocAt07)');

function spPlannerBatt()
{
    return new BatteryModel(array(
        'capacity_kwh' => 20.0, 'min_soc_pct' => 10, 'reserve_soc_pct' => 20,
        'max_soc_pct' => 100, 'max_charge_kw' => 6, 'max_discharge_kw' => 6,
        'eta_charge' => 0.95, 'eta_discharge' => 0.95, 'taper_start_soc_pct' => 95,
    ));
}

/**
 * Build a 24h horizon of 30-min intervals starting at local 20:00, with a cheap
 * window 23:00-07:00 and a configurable PV day curve (kWh total spread midday).
 */
function spHorizon($pvDayKwh, $loadPerIntervalKwh)
{
    $g = new TimeGrid('Europe/Kyiv', 30);
    $base = gmmktime(12, 0, 0, 6, 1, 2026);
    $startEvening = $g->localTimeToUtc($base, 20, 0, 0);
    $ivs = $g->intervals($startEvening, $startEvening + 24 * 3600);
    $cheap = $g->cheapWindow($startEvening, 23, 7);

    // Distribute PV across daytime 08:00-18:00 local (triangular around noon).
    $out = array();
    $dayIntervals = array();
    foreach ($ivs as $i => $iv) {
        $localH = (int)(new DateTime('@' . $iv['start_utc']))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('G');
        if ($localH >= 8 && $localH < 18) {
            $dayIntervals[] = $i;
        }
    }
    $weights = array();
    $wsum = 0;
    foreach ($dayIntervals as $i) {
        $localH = (float)(new DateTime('@' . $ivs[$i]['start_utc']))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('G');
        $w = max(0.0, 1.0 - abs($localH - 13.0) / 5.0);
        $weights[$i] = $w;
        $wsum += $w;
    }
    foreach ($ivs as $i => $iv) {
        $pv = 0.0;
        if (isset($weights[$i]) && $wsum > 0) {
            $pv = $pvDayKwh * ($weights[$i] / $wsum);
        }
        $isCheap = ($iv['start_utc'] >= $cheap['start_utc'] && $iv['start_utc'] < $cheap['end_utc']);
        $out[] = array(
            'start_utc' => $iv['start_utc'], 'end_utc' => $iv['end_utc'], 'hours' => $iv['hours'],
            'pv_kwh' => $pv, 'load_kwh' => $loadPerIntervalKwh,
            'is_cheap' => $isCheap, 'price' => $isCheap ? 2.0 : 4.0,
        );
    }
    return $out;
}

// Winter: tiny PV (1 kWh/day), moderate load -> expect meaningful night charge,
// no invented solar surplus.
$winter = spHorizon(1.0, 0.3);
$plannerW = new NightChargePlanner(spPlannerBatt(), array('mode' => 'reach_full', 'soc_step_pct' => 10));
$pw = $plannerW->plan(30, $winter);
T::ok(in_array($pw['status'], array('feasible', 'infeasible'), true), 'winter plan returns a status');
if ($pw['status'] === 'feasible') {
    T::ok($pw['planned_night_import_kwh'] >= 0, 'winter: night import computed');
    T::ok($pw['expected_curtailment_kwh'] < 1.0, 'winter: no large invented surplus');
}

// Summer: big PV (25 kWh/day) -> night charge should be smaller than winter if
// morning deficit allows, and surplus/full reachable.
$summer = spHorizon(25.0, 0.3);
$plannerS = new NightChargePlanner(spPlannerBatt(), array('mode' => 'reach_full', 'soc_step_pct' => 10));
$ps = $plannerS->plan(30, $summer);
T::ok($ps['status'] === 'feasible', 'summer plan feasible');
if ($ps['status'] === 'feasible' && $pw['status'] === 'feasible') {
    T::ok($ps['planned_night_import_kwh'] <= $pw['planned_night_import_kwh'] + 1e-6,
        'summer night import <= winter (seasonality emerges from energy balance)');
}

// Already charged: starting near target, extra night purchase should be minimal.
$ps2 = $plannerS->plan(95, $summer);
T::ok($ps2['status'] === 'feasible', 'already-charged plan feasible');
T::ok($ps2['planned_grid_charge_kwh'] <= 2.0, 'already charged -> little/no extra night charge');

// Reserve is never auto-lowered: an impossible reserve (very high) with weak PV
// and heavy load should report infeasible rather than breach reserve silently.
$hardBatt = new BatteryModel(array(
    'capacity_kwh' => 5.0, 'min_soc_pct' => 10, 'reserve_soc_pct' => 90,
    'max_soc_pct' => 100, 'max_charge_kw' => 1, 'max_discharge_kw' => 10,
    'eta_charge' => 0.95, 'eta_discharge' => 0.95,
));
$heavy = spHorizon(0.5, 2.0); // huge load, tiny PV
$plannerH = new NightChargePlanner($hardBatt, array('mode' => 'reach_full', 'soc_step_pct' => 20));
$ph = $plannerH->plan(30, $heavy);
T::ok($ph['status'] === 'infeasible', 'impossible goal -> infeasible status (no reserve auto-lowering)');
if ($ph['status'] === 'infeasible') {
    T::ok(!empty($ph['requires_manual_confirmation']), 'infeasible requires manual confirmation');
}
