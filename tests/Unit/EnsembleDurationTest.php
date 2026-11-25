<?php
/** Regression tests for the 1.2.0 forecast-accuracy fixes. */

use SolarPlanner\Forecast\ForecastRun;
use SolarPlanner\Forecast\EnsembleBuilder;
use SolarPlanner\Forecast\QuotaManager;
use SolarPlanner\Analytics\PvCalibrationService;

T::group('EnsembleBuilder: mixed 1h / 30min intervals');

$base = strtotime('2026-11-04 00:00:00 UTC');
$hourly = array();
for ($i = 0; $i < 24; $i++) {
    $hourly[$i] = ($i >= 7 && $i < 17) ? round(sin((($i - 7) / 10) * M_PI), 4) : 0.0;
}

// Open-Meteo: hourly, [t-1h,t), energy for a FULL hour.
$om = new ForecastRun('open_meteo');
for ($i = 1; $i <= 24; $i++) {
    $om->addInterval($base + ($i - 1) * 3600, $base + $i * 3600, $hourly[$i - 1]);
}
// Forecast.Solar: hourly, slightly lower.
$fs = new ForecastRun('forecast_solar');
for ($i = 0; $i < 24; $i++) {
    $fs->addInterval($base + $i * 3600, $base + ($i + 1) * 3600, $hourly[$i] * 0.9);
}
// Solcast: 30-minute slots on :00 and :30, energy for HALF an hour.
$sol = new ForecastRun('solcast');
for ($i = 0; $i < 24; $i++) {
    foreach (array(0, 1800) as $off) {
        $sol->addInterval($base + $i * 3600 + $off, $base + $i * 3600 + $off + 1800, $hourly[$i] * 0.5);
    }
}

$runs = array(
    array('run' => $om, 'mae' => null, 'obs' => 0, 'weather_model' => 'open-meteo'),
    array('run' => $fs, 'mae' => null, 'obs' => 0, 'weather_model' => 'forecast_solar'),
    array('run' => $sol, 'mae' => null, 'obs' => 0, 'weather_model' => 'solcast'),
);
$ens = (new EnsembleBuilder())->build($runs, 'median');
$sum = 0.0;
foreach ($ens['intervals'] as $iv) {
    $sum += $iv['pv_kwh'];
}
$providerTotals = array($om->totalPvKwh(), $fs->totalPvKwh(), $sol->totalPvKwh());
$minP = min($providerTotals);
$maxP = max($providerTotals);

T::eq($ens['step_sec'], 1800, 'finest provider cadence becomes the common grid step');
T::eq(count($ens['intervals']), 48, 'one day produces 48 half-hour slots, not 24');
T::ok($sum <= $maxP + 1e-6, 'ensemble total is NOT inflated above the best provider (no 1.45x mixing bug)');
T::ok($sum >= $minP - 1e-6, 'ensemble median stays within the provider range');
T::approx($ens['intervals'][$base + 12 * 3600]['pv_kwh'], 0.5, 1e-6,
    'hourly energy is split evenly over the two half-hour slots');
T::approx($ens['intervals'][$base + 12 * 3600 + 1800]['pv_kwh'], 0.5, 1e-6,
    'the :30 slot is not counted twice');

$bandSum = 0.0;
foreach ($ens['band'] as $b) {
    $bandSum += $b['high_kwh'];
}
T::ok($bandSum <= $maxP + 1e-6, 'band high uses the same duration-safe placement as the central value');

T::group('EnsembleBuilder: weighted mode becomes reachable with real statistics');
$a = new ForecastRun('a');
$b = new ForecastRun('b');
$a->addInterval(0, 1800, 1.0);
$b->addInterval(0, 1800, 9.0);
$weighted = (new EnsembleBuilder())->build(array(
    array('run' => $a, 'mae' => 0.2, 'obs' => 10),
    array('run' => $b, 'mae' => 1.0, 'obs' => 10),
), 'weighted');
T::eq($weighted['method'], 'weighted', 'weighted mode activates once MAE/obs are supplied');
T::ok($weighted['intervals'][0]['pv_kwh'] < 5.0, 'lower-error provider gets the larger weight');
T::ok(count($weighted['weights']) === 2, 'weights are reported for transparency');
foreach ($weighted['weights'] as $w) {
    T::ok($w <= 0.7 + 1e-9, 'maxWeight=0.7 is enforced after renormalisation');
}

T::group('EnsembleBuilder: degenerate band keeps a prudent margin');
$single = new ForecastRun('only');
$single->addInterval(0, 1800, 1.0);
$flat = (new EnsembleBuilder())->build(array(array('run' => $single)), 'median');
$factors = PvCalibrationService::bandFactors($flat['intervals']);
T::approx($factors['low_pv_factor'], 0.7, 1e-9, 'single-provider band falls back to 0.7');
T::approx($factors['high_pv_factor'], 1.3, 1e-9, 'single-provider band falls back to 1.3');

T::group('PvCalibrationService: measured-data feedback loop');
$pairs = array();
for ($i = 1; $i <= 5; $i++) {
    // Forecast is consistently 2x the measured energy.
    $pairs[] = array('forecast' => 4.0, 'actual' => 2.0, 'date' => '2026-11-0' . $i);
}
$tooFew = PvCalibrationService::evaluate(array_slice($pairs, 0, 2), 1.0);
T::eq($tooFew['applied'], false, 'no correction before 3 verified days');
T::approx($tooFew['scale'], 1.0, 1e-9, 'scale untouched while learning');

$res = PvCalibrationService::evaluate($pairs, 1.0, 0.25);
T::eq($res['applied'], true, 'correction applied after 3+ days');
T::approx($res['recommended_scale'], 0.5, 1e-9, 'energy-weighted ratio halves a 2x over-forecast');
T::ok($res['scale'] < 1.0 && $res['scale'] > 0.5, 'smoothing avoids a sudden jump');
T::approx($res['bias'], 2.0, 1e-9, 'bias is forecast minus actual');
T::approx($res['mae'], 2.0, 1e-9, 'MAE equals the constant daily error');

// Manual PV_SCALE is already included in the persisted forecast. The learned
// auto-scale must be target / manual, otherwise the loop converges to sqrt.
$withManual = PvCalibrationService::evaluate($pairs, 1.0, 1.0, 0.35, 1.60, 0.8);
T::approx($withManual['recommended_scale'], 0.625, 1e-9,
    'auto-scale divides out the manual factor (no sqrt convergence)');

$bands = array();
for ($i = 0; $i < 4; $i++) {
    $bands[$i * 1800] = array('pv_kwh' => 1.0, 'low_kwh' => 0.6, 'high_kwh' => 1.4);
}
$factors = PvCalibrationService::bandFactors($bands);
T::approx($factors['low_pv_factor'], 0.6, 1e-9, 'low scenario factor comes from the ensemble band');
T::approx($factors['high_pv_factor'], 1.4, 1e-9, 'high scenario factor comes from the ensemble band');

$stats = PvCalibrationService::providerStats(array(
    array('PROVIDER_ID' => 'solcast', 'MAE' => 0.4, 'N_SAMPLES' => 4),
    array('PROVIDER_ID' => 'solcast', 'MAE' => 0.2, 'N_SAMPLES' => 3),
));
T::eq($stats['solcast']['obs'], 7, 'observations accumulate per provider');
T::ok($stats['solcast']['mae'] > 0.2 && $stats['solcast']['mae'] < 0.4, 'MAE is smoothed over history');

T::group('QuotaManager: configured state survives a round trip');
$q1 = new QuotaManager();
$q1->configure('solcast', array('daily_limit_units' => 10, 'reserved_units' => 2));
$q1->recordSuccess('solcast', 1.0);
$state = $q1->getState();
$q2 = new QuotaManager($state);
$snap = $q2->snapshot('solcast');
T::eq($snap['daily_limit_units'], 10, 'daily limit persisted');
T::eq($snap['quota_units'], 1.0, 'used units persisted');
T::ok(!$q2->canRequest('solcast', 9.0, false)['allowed'], 'restored quota still enforces the budget');
