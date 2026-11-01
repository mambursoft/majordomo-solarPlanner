<?php
/**
 * Integration: full pipeline forecast -> load -> simulate -> plan -> TOU,
 * with synthetic forecast runs (no network, no hardware, no API keys).
 */

use SolarPlanner\Common\TimeGrid;
use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Forecast\ForecastRun;
use SolarPlanner\MajorDoMo\PlannerService;

T::group('PlannerService end-to-end (synthetic)');

$tz = 'Europe/Kyiv';
$grid = new TimeGrid($tz, 30);
$batt = new BatteryModel(array(
    'capacity_kwh' => 20.0, 'min_soc_pct' => 10, 'reserve_soc_pct' => 20,
    'max_soc_pct' => 100, 'max_charge_kw' => 6, 'max_discharge_kw' => 6,
    'eta_charge' => 0.95, 'eta_discharge' => 0.95, 'taper_start_soc_pct' => 95,
));

$base = gmmktime(12, 0, 0, 6, 1, 2026);
$from = $grid->localTimeToUtc($base, 20, 0, 0);
$to = $from + 24 * 3600;
$ivs = $grid->intervals($from, $to);

// Two synthetic providers with a summer-ish midday PV curve.
function spMakeRun($id, array $ivs, $scale)
{
    $run = new ForecastRun($id);
    foreach ($ivs as $iv) {
        $localH = (float)(new DateTime('@' . $iv['start_utc']))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('G');
        $pv = max(0.0, 1.0 - abs($localH - 13.0) / 5.0) * $scale;
        $run->addInterval($iv['start_utc'], $iv['end_utc'], $pv);
    }
    return $run;
}
$runA = spMakeRun('open_meteo', $ivs, 2.0);
$runB = spMakeRun('forecast_solar', $ivs, 2.2);

// Synthetic load history: ~0.3 kW baseline over the past 10 days.
$history = array();
for ($d = 1; $d <= 10; $d++) {
    for ($h = 0; $h < 24; $h++) {
        $history[] = array('utc' => $base - $d * 86400 + $h * 3600, 'load_kw' => 0.3, 'valid' => true);
    }
}

$cfg = array(
    'tariff_cheap_price' => 2.0, 'tariff_day_price' => 4.0,
    'cheap_start_hour' => 23, 'cheap_end_hour' => 7,
    'mode' => 'reach_full', 'soc_step_pct' => 10, 'allow_export' => false,
    'ensemble_method' => 'median', 'tou_slots' => 6, 'load_min_samples' => 3,
);
$svc = new PlannerService($batt, $grid, $cfg);
$plan = $svc->makePlan(40, array(
    array('run' => $runA, 'weather_model' => 'open-meteo'),
    array('run' => $runB, 'weather_model' => 'forecast_solar'),
), $history, $from, $to, array('unavailable_providers' => array()));

T::ok(isset($plan['status']), 'plan produced a status');
T::ok($plan['pv_tomorrow_kwh'] > 0, 'PV tomorrow computed from ensemble');
T::ok($plan['load_tomorrow_kwh'] > 0, 'load tomorrow computed from history');
T::ok(isset($plan['explanation_obj']['text']) && strlen($plan['explanation_obj']['text']) > 0, 'explanation produced');

if ($plan['status'] === 'feasible') {
    $compiled = $svc->compileAndVerify($plan);
    T::ok(count($compiled['compile']['slots']) <= 6, 'compiled schedule <= 6 TOU slots');
    T::ok($compiled['compile']['ok'], 'compiled TOU schedule is valid (covers 24h, no gaps)');
}
