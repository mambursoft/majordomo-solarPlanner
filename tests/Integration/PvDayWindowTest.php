<?php
/**
 * Regression: "PV tomorrow" must describe ONE local calendar day even when
 * the planning horizon spans ~44 hours (tomorrow + the day after).
 * The old code summed the whole horizon and roughly doubled the headline.
 */

use SolarPlanner\Common\TimeGrid;
use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Forecast\ForecastRun;
use SolarPlanner\MajorDoMo\PlannerService;

T::group('PV tomorrow is one local day (44h horizon)');

$tz = 'Europe/Kyiv';
$grid = new TimeGrid($tz, 30);
$batt = new BatteryModel(array(
    'capacity_kwh' => 20.0, 'min_soc_pct' => 10, 'reserve_soc_pct' => 20,
    'max_soc_pct' => 100, 'max_charge_kw' => 6, 'max_discharge_kw' => 6,
    'eta_charge' => 0.95, 'eta_discharge' => 0.95, 'taper_start_soc_pct' => 95,
));

// Evening plan: the 44h horizon necessarily reaches into the day after.
$from = $grid->localTimeToUtc(gmmktime(12, 0, 0, 11, 3, 2026), 21, 0, 0);
$to = $from + 44 * 3600;
$ivs = $grid->intervals($from, $to);
$run = new ForecastRun('open_meteo');
foreach ($ivs as $iv) {
    $localH = (float)(new DateTime('@' . $iv['start_utc']))->setTimezone(new DateTimeZone($tz))->format('G');
    // A bell curve every day, so every local day contributes real energy.
    $pv = max(0.0, 1.0 - abs($localH - 13.0) / 5.0);
    $run->addInterval($iv['start_utc'], $iv['end_utc'], $pv);
}

$cfg = array(
    'tariff_cheap_price' => 2.0, 'tariff_day_price' => 4.0,
    'cheap_start_hour' => 23, 'cheap_end_hour' => 7,
    'mode' => 'reach_full', 'soc_step_pct' => 10, 'allow_export' => false,
    'ensemble_method' => 'median', 'tou_slots' => 6, 'load_min_samples' => 3,
    'pv_scale' => 0.8,
);
$svc = new PlannerService($batt, $grid, $cfg);
$plan = $svc->makePlan(40, array(array('run' => $run, 'weather_model' => 'open-meteo')),
    array(), $from, $to, array());

T::ok($plan['pv_tomorrow_kwh'] > 0, 'tomorrow PV is positive');
T::ok($plan['pv_tomorrow_kwh'] < $plan['pv_horizon_kwh'] * 0.8,
    'tomorrow PV is strictly smaller than the whole-horizon total (no 2-day sum)');
T::ok($plan['pv_day_after_kwh'] > 0, 'day-after PV is reported separately');
T::approx($plan['pv_tomorrow_kwh'], round($plan['pv_tomorrow_raw_kwh'] * 0.8, 3), 1e-6,
    'published PV = raw model PV x manual scale');
T::ok($plan['pv_tomorrow_low_kwh'] <= $plan['pv_tomorrow_kwh']
    && $plan['pv_tomorrow_kwh'] <= $plan['pv_tomorrow_high_kwh'],
    'central value lies inside the low/high band');
$expectedDate = (new DateTime('@' . $from))->setTimezone(new DateTimeZone($tz))
    ->modify('+1 day')->format('Y-m-d');
T::eq($plan['tomorrow_local_date'], $expectedDate, 'tomorrow_local_date is the next local date');

$parts = $plan['pv_today_remaining_kwh'] + $plan['pv_tomorrow_kwh'] + $plan['pv_day_after_kwh'];
T::ok(abs($parts - $plan['pv_horizon_kwh']) < 0.15,
    'per-day figures together account for the whole horizon');
