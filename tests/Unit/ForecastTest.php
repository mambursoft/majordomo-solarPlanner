<?php
/** Forecast normalization, horizon completeness, ensemble, quota, calibration. */

use SolarPlanner\Forecast\ForecastRun;
use SolarPlanner\Forecast\EnsembleBuilder;
use SolarPlanner\Forecast\QuotaManager;
use SolarPlanner\Forecast\AccuracyMetrics;

T::group('ForecastRun normalization');

$run = new ForecastRun('open_meteo');
$run->issuedAtUtc = null;
$run->addInterval(1000, 1000 + 1800, 1.5);
$run->addInterval(1000 + 1800, 1000 + 3600, null); // missing
T::eq($run->intervals[1]['is_missing'], 1, 'null energy marked missing (not zero)');
T::ok($run->issuedAtUtc === null, 'unknown issue time stays null (not invented)');
T::approx($run->totalPvKwh(), 1.5, 1e-9, 'total ignores missing interval');

// Horizon completeness: a run with a missing interval does not cover horizon.
T::ok(!$run->coversHorizon(1000, 1000 + 3600), 'incomplete horizon not accepted as complete');
$full = new ForecastRun('x');
$full->addInterval(0, 1800, 1.0);
$full->addInterval(1800, 3600, 1.0);
T::ok($full->coversHorizon(0, 3600), 'complete horizon accepted');

T::group('EnsembleBuilder (median, spread band)');
$a = new ForecastRun('a');
$a->addInterval(0, 1800, 2.0);
$b = new ForecastRun('b');
$b->addInterval(0, 1800, 4.0);
$c = new ForecastRun('c');
$c->addInterval(0, 1800, 3.0);
$eb = new EnsembleBuilder();
$merged = $eb->build(array(array('run' => $a), array('run' => $b), array('run' => $c)), 'median');
T::approx($merged['intervals'][0]['pv_kwh'], 3.0, 1e-9, 'median of 2,4,3 = 3');
T::approx($merged['band'][0]['low_kwh'], 2.0, 1e-9, 'band low = 2 (heuristic, not calibrated P10)');
T::approx($merged['band'][0]['high_kwh'], 4.0, 1e-9, 'band high = 4');

// Weighted falls back to median without enough observations.
$mw = $eb->build(array(
    array('run' => $a, 'mae' => 1.0, 'obs' => 2),
    array('run' => $b, 'mae' => 2.0, 'obs' => 2),
), 'weighted');
T::eq($mw['method'], 'median', 'weighted falls back to median with too few observations');

T::group('QuotaManager');
$q = new QuotaManager();
$q->configure('solcast', array('daily_limit_units' => 10, 'reserved_units' => 2));
for ($i = 0; $i < 8; $i++) {
    $q->recordSuccess('solcast', 1.0);
}
$nonCritical = $q->canRequest('solcast', 1.0, false);
T::ok(!$nonCritical['allowed'], 'reserved units protected from non-critical calls');
$critical = $q->canRequest('solcast', 1.0, true);
T::ok($critical['allowed'], 'critical call may use reserved units');

$q->recordFailure('bad', 401);
$snap = $q->snapshot('bad');
T::ok($snap['breaker_open_until_utc'] > time(), '401 opens circuit breaker');

T::group('AccuracyMetrics');
$m = AccuracyMetrics::compute(array(
    array('forecast' => 2, 'actual' => 1),
    array('forecast' => 0, 'actual' => 1),
));
T::approx($m['mae'], 1.0, 1e-9, 'MAE = 1');
T::approx($m['bias'], 0.0, 1e-9, 'bias = 0 (symmetric errors)');
$mWinter = AccuracyMetrics::compute(array(array('forecast' => 0.1, 'actual' => 0.05)), 0.5);
T::ok($mWinter['mape'] === null, 'MAPE suppressed for tiny winter values');
