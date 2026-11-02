<?php
/**
 * Integration: a single provider with a PARTIAL (short-horizon) forecast must
 * still produce a usable plan — it must NOT degrade to no_forecast. This guards
 * the real-world case where Open-Meteo is down (503) and only Forecast.Solar
 * returns a partial series.
 */

use SolarPlanner\Common\TimeGrid;
use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Forecast\ForecastRun;
use SolarPlanner\MajorDoMo\PlannerService;

T::group('Plan from a single partial forecast (Open-Meteo down)');

$tz = 'Europe/Kyiv';
$grid = new TimeGrid($tz, 30);
$batt = new BatteryModel(array(
    'capacity_kwh' => 20.0, 'min_soc_pct' => 10, 'reserve_soc_pct' => 20,
    'max_soc_pct' => 100, 'max_charge_kw' => 6, 'max_discharge_kw' => 6,
    'eta_charge' => 0.95, 'eta_discharge' => 0.95, 'taper_start_soc_pct' => 95,
));

$base = gmmktime(12, 0, 0, 6, 1, 2026);
$from = $grid->localTimeToUtc($base, 20, 0, 0);
$to = $from + 44 * 3600;
$ivs = $grid->intervals($from, $to);

// Only the first ~24h have PV data (a partial, short-horizon run).
$run = new ForecastRun('forecast_solar');
$half = (int)(count($ivs) * 0.5);
foreach ($ivs as $i => $iv) {
    if ($i > $half) {
        break;
    }
    $localH = (float)(new DateTime('@' . $iv['start_utc']))->setTimezone(new DateTimeZone($tz))->format('G');
    $pv = max(0.0, 1.0 - abs($localH - 13.0) / 5.0) * 2.0;
    $run->addInterval($iv['start_utc'], $iv['end_utc'], $pv);
}

$history = array();
for ($d = 1; $d <= 10; $d++) {
    for ($h = 0; $h < 24; $h++) {
        $history[] = array('utc' => $base - $d * 86400 + $h * 3600, 'load_kw' => 0.3, 'valid' => true);
    }
}

$svc = new PlannerService($batt, $grid, array(
    'tariff_cheap_price' => 2.0, 'tariff_day_price' => 4.0,
    'cheap_start_hour' => 23, 'cheap_end_hour' => 7,
    'mode' => 'reach_full', 'soc_step_pct' => 10, 'ensemble_method' => 'median',
));
$plan = $svc->makePlan(40, array(array('run' => $run, 'weather_model' => 'forecast_solar')),
    $history, $from, $to, array());

T::ok(isset($plan['status']), 'partial forecast produced a plan status');
T::ok($plan['status'] !== 'no_forecast', 'partial forecast is NOT treated as no_forecast');
T::ok($plan['pv_tomorrow_kwh'] > 0, 'PV tomorrow computed from the partial run');
T::ok(in_array($plan['status'], array('feasible', 'infeasible'), true), 'plan reached a real decision');
