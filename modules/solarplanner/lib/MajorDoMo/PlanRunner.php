<?php
/**
 * PlanRunner — the single, shared implementation of "build a plan now".
 *
 * Both the background cycle and the UI "Run plan / Diagnose" button call this,
 * so they always behave identically and produce the same diagnostics. It wraps
 * DiagnosticsService (forecast collection + environment self-check), the
 * PlannerService (ensemble/load/simulate/plan/TOU) and attaches a structured
 * 'diagnostics' block to every result — especially when there is no forecast.
 *
 * It takes small callables for the platform-specific bits (current SOC, load
 * history, property publishing, audit) so the core stays testable.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\MajorDoMo;

use SolarPlanner\Common\TimeGrid;
use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Analytics\PvCalibrationService;

class PlanRunner
{
    /**
     * @param array $cfg module config
     * @param object|null $store SqlForecastStore (or compatible) or null
     * @param array $hooks optional callables:
     *   'get_soc'      => function(): ?float
     *   'get_history'  => function(): array
     *   'publish'      => function(array $plan, array $cfg): void
     *   'audit'        => function(string $event, array $detail): void
     *   'say'          => function(string $text): void
     * @return array plan result with a 'diagnostics' block
     */
    /**
     * @param array $hooks optional callables; additionally:
     *   'cache'       => ForecastCache for quota-saving reuse (optional)
     *   'prop_getter' => function(string $prop): mixed (gg wrapper, for sun times)
     *   'force_fetch' => bool, bypass the schedule (manual "Run plan")
     */
    public static function run(array $cfg, $store = null, array $hooks = array())
    {
        $tz = !empty($cfg['TIMEZONE']) ? $cfg['TIMEZONE'] : 'Europe/Kyiv';
        $step = isset($cfg['GRID_STEP_MIN']) ? (int)$cfg['GRID_STEP_MIN'] : 30;
        $grid = new TimeGrid($tz, in_array($step, array(15, 30, 60), true) ? $step : 30);
        $batt = self::buildBattery($cfg);

        $now = time();
        $from = $now;
        $to = $now + 44 * 3600; // 36-48h horizon

        // 1) Collect forecasts + environment diagnostics (cache-aware).
        $cache = isset($hooks['cache']) ? $hooks['cache'] : null;
        $propGetter = isset($hooks['prop_getter']) ? $hooks['prop_getter'] : null;
        $force = !empty($hooks['force_fetch']);
        $diag = new DiagnosticsService($cfg, $cache, $propGetter);
        $collected = $diag->collect($from, $to, $store, $force);
        $providerRuns = $collected['runs'];

        // --- Closed feedback loop: compare yesterday's published forecast with
        // the PV energy actually measured, and derive a conservative correction.
        $calibration = self::calibrate($cfg, $store, $tz, $from);
        if ($store) {
            foreach ($providerRuns as &$runRow) {
                $pid = $runRow['run']->providerId;
                if (method_exists($store, 'loadCalibration')) {
                    $stats = PvCalibrationService::providerStats($store->loadCalibration($pid, 'tomorrow', 30));
                    if (isset($stats[$pid])) {
                        $runRow['mae'] = $stats[$pid]['mae'];
                        $runRow['obs'] = $stats[$pid]['obs'];
                    }
                }
            }
            unset($runRow);
        }

        $unavailable = array();
        foreach ($collected['providers'] as $code => $p) {
            if ($p['status'] !== 'fresh' && $p['status'] !== 'partial') {
                $unavailable[] = $code . ': ' . $p['detail'];
            }
        }

        $diagnostics = array(
            'generated_at' => date('Y-m-d H:i:s'),
            'providers' => $collected['providers'],
            'environment' => $collected['env'],
            'site' => array(
                'coordinates_source' => $collected['env']['coordinates_source'],
                'pv_arrays_source' => $collected['site']['arrays_source'],
                'timezone' => $tz,
            ),
            'horizon_utc' => array('from' => $from, 'to' => $to),
        );

        // 2) No forecast -> safe fallback WITH a specific reason.
        if (empty($providerRuns)) {
            $plan = array(
                'status' => 'no_forecast',
                'reason' => self::noForecastReason($collected),
                'unavailable_providers' => $unavailable,
                'diagnostics' => $diagnostics,
            );
            self::persist($plan, $cfg, $store, $hooks);
            return $plan;
        }

        // 3) Current SOC + load history via hooks.
        $startSoc = null;
        if (isset($hooks['get_soc']) && is_callable($hooks['get_soc'])) {
            $startSoc = call_user_func($hooks['get_soc']);
        }
        if ($startSoc === null) {
            $startSoc = $batt->reserveSocPct;
            $diagnostics['soc_source'] = 'fallback (reserve)';
        } else {
            $diagnostics['soc_source'] = 'telemetry';
        }
        $history = array();
        if (isset($hooks['get_history']) && is_callable($hooks['get_history'])) {
            $history = (array)call_user_func($hooks['get_history']);
        }
        $diagnostics['load_history_samples'] = count($history);

        // 4) Build the plan. Manual PV_SCALE and the measured-data auto
        // correction are multiplied (both are legitimate, independent factors).
        $svcCfg = self::svcCfg($cfg);
        $manualScale = isset($svcCfg['pv_scale']) ? (float)$svcCfg['pv_scale'] : 1.0;
        $svcCfg['pv_scale'] = max(0.1, min(2.0, $manualScale * (float)$calibration['scale']));
        $svc = new PlannerService($batt, $grid, $svcCfg);
        $plan = $svc->makePlan($startSoc, $providerRuns, $history, $from, $to, array(
            'unavailable_providers' => $unavailable,
            'calibration' => $calibration,
        ));
        $plan['calibration'] = $calibration;
        $plan['pv_by_provider_tomorrow_kwh'] = self::providerDailyKwh($providerRuns, $tz, $from, 1);
        $compiled = $svc->compileAndVerify($plan);
        $plan['tou_compiled'] = $compiled;
        $plan['diagnostics'] = $diagnostics;

        self::persist($plan, $cfg, $store, $hooks);

        // TTS announcement if enabled.
        if (!empty($cfg['ANNOUNCE_PLAN']) && isset($plan['explanation_obj']['text'])
            && isset($hooks['say']) && is_callable($hooks['say'])) {
            call_user_func($hooks['say'], $plan['explanation_obj']['text']);
        }
        return $plan;
    }

    /**
     * Compare the forecast published for the previous local day with the PV
     * energy actually measured on that day, then persist the accuracy row and
     * return a smoothed correction factor. Safe no-op when data is missing.
     */
    private static function calibrate(array $cfg, $store, $tz, $nowUtc)
    {
        $empty = array('applied' => false, 'n' => 0, 'mae' => null, 'bias' => null,
            'rmse' => null, 'mape' => null, 'scale' => 1.0,
            'recommended_scale' => 1.0, 'date_local' => null, 'forecast_kwh' => null,
            'actual_kwh' => null);
        if (!$store || !method_exists($store, 'loadCalibration')
            || !method_exists($store, 'loadForecastForLocalDay')
            || !method_exists($store, 'loadPvActualKwhByLocalDay')) {
            return $empty;
        }
        // Enabled by default; can be disabled explicitly for a manual PV_SCALE.
        if (array_key_exists('PV_AUTO_CALIBRATE', $cfg) && empty($cfg['PV_AUTO_CALIBRATE'])) {
            $empty['disabled'] = true;
            return $empty;
        }
        try {
            $zone = new \DateTimeZone($tz);
            $date = (new \DateTime('@' . (int)$nowUtc))->setTimezone($zone)
                ->modify('-1 day')->format('Y-m-d');
            $fc = $store->loadForecastForLocalDay($date);
            $ac = $store->loadPvActualKwhByLocalDay($date, $tz);
            if (!$fc || empty($ac['kwh'])) {
                return $empty;
            }
            $manualScale = isset($cfg['PV_SCALE']) && is_numeric($cfg['PV_SCALE'])
                ? max(0.1, min(2.0, (float)$cfg['PV_SCALE'])) : 1.0;
            $alpha = isset($cfg['PV_CALIBRATION_ALPHA']) ? (float)$cfg['PV_CALIBRATION_ALPHA'] : 0.25;
            $rows = $store->loadCalibration('ensemble', 'tomorrow', 1);
            $prev = !empty($rows[0]['SCALE']) ? (float)$rows[0]['SCALE'] : 1.0;
            $res = PvCalibrationService::evaluate(
                array(array('forecast' => (float)$fc['forecast_kwh'], 'actual' => (float)$ac['kwh'], 'date' => $date)),
                $prev, $alpha, 0.35, 1.60, $manualScale
            );
            $res['date_local'] = $date;
            // Stored forecast is the RAW model output, not the published value.
            $res['forecast_kwh'] = (float)$fc['forecast_kwh'];
            $res['actual_kwh'] = (float)$ac['kwh'];
            if (method_exists($store, 'saveCalibration')) {
                // One pair per distinct local day. Limit 30 rows now means 30
                // real days, not 30 runs of the same day.
                $pairs = array($date => array('forecast' => $res['forecast_kwh'],
                    'actual' => $res['actual_kwh'], 'date' => $date));
                foreach ($store->loadCalibration('ensemble', 'tomorrow', 30) as $h) {
                    $d = isset($h['DATE_LOCAL']) ? $h['DATE_LOCAL'] : '';
                    if ($d === '' || isset($pairs[$d])
                        || empty($h['FORECAST_KWH']) || empty($h['ACTUAL_KWH'])) {
                        continue;
                    }
                    $pairs[$d] = array('forecast' => (float)$h['FORECAST_KWH'],
                        'actual' => (float)$h['ACTUAL_KWH'], 'date' => $d);
                }
                $combined = PvCalibrationService::evaluate($pairs, $prev, $alpha, 0.35, 1.60, $manualScale);
                $res['n'] = $combined['n'];
                $res['mae'] = $combined['mae'];
                $res['bias'] = $combined['bias'];
                $res['rmse'] = $combined['rmse'];
                $res['mape'] = $combined['mape'];
                $res['applied'] = $combined['applied'];
                $res['recommended_scale'] = $combined['recommended_scale'];
                $res['scale'] = $combined['scale'];
                $store->saveCalibration(array_merge($res, array(
                    'provider_id' => 'ensemble', 'horizon' => 'tomorrow',
                    'n_samples' => $res['n'],
                )));

                // Per-provider accuracy statistics for EnsembleBuilder's
                // weighted/best modes. The archived plan carries each
                // provider's raw contribution for the target day.
                $byProvider = isset($fc['payload']['pv_by_provider_tomorrow_kwh'])
                    ? $fc['payload']['pv_by_provider_tomorrow_kwh'] : array();
                if (is_array($byProvider)) {
                    foreach ($byProvider as $pid => $kwh) {
                        $pf = (float)$kwh;
                        if ($pf <= 0) {
                            continue;
                        }
                        $store->saveCalibration(array(
                            'provider_id' => $pid, 'horizon' => 'tomorrow',
                            'date_local' => $date,
                            'forecast_kwh' => $pf, 'actual_kwh' => (float)$ac['kwh'],
                            'mae' => abs($pf - (float)$ac['kwh']),
                            'bias' => $pf - (float)$ac['kwh'],
                            'n_samples' => 1,
                            'scale' => null,
                        ));
                    }
                }
            }
            return $res;
        } catch (\Throwable $e) {
            $empty['error'] = $e->getMessage();
            return $empty;
        }
    }

    /** Sum each provider's PV energy for a local day (keyed by provider id). */
    private static function providerDailyKwh(array $runs, $tz, $refUtc, $dayOffset)
    {
        try {
            $zone = new \DateTimeZone($tz);
            $d = (new \DateTime('@' . (int)$refUtc))->setTimezone($zone);
            $d->setTime(0, 0, 0);
            if ($dayOffset !== 0) {
                $d->modify(($dayOffset > 0 ? '+' : '-') . abs((int)$dayOffset) . ' day');
            }
            $start = (int)$d->format('U');
            $end = (int)(clone $d)->modify('+1 day')->format('U');
        } catch (\Throwable $e) {
            return array();
        }
        $out = array();
        foreach ($runs as $row) {
            if (empty($row['run'])) {
                continue;
            }
            $total = 0.0;
            foreach ($row['run']->intervals as $iv) {
                if (!empty($iv['is_missing']) || $iv['pv_energy_kwh'] === null) {
                    continue;
                }
                $mid = (int)(((int)$iv['interval_start_utc'] + (int)$iv['interval_end_utc']) / 2);
                if ($mid >= $start && $mid < $end) {
                    $total += (float)$iv['pv_energy_kwh'];
                }
            }
            $out[$row['run']->providerId] = round($total, 3);
        }
        return $out;
    }

    /** Human-readable, specific reason why no forecast was produced. */
    private static function noForecastReason(array $collected)
    {
        $env = $collected['env'];
        if (empty($env['config_loaded'])) {
            return 'Не вдалося завантажити конфігурацію модуля у фоновому циклі.';
        }
        if (empty($env['any_provider_enabled'])) {
            return 'Жоден провайдер прогнозу не увімкнений у налаштуваннях.';
        }
        if (empty($env['outbound_https'])) {
            return 'Сервер не має вихідного доступу до інтернету (HTTPS): ' . $env['outbound_https_detail'];
        }
        // Providers enabled and internet works, but each failed -> list them.
        $parts = array();
        foreach ($collected['providers'] as $code => $p) {
            if ($p['status'] !== 'fresh' && $p['status'] !== 'partial' && $p['status'] !== 'disabled') {
                $parts[] = $code . ' (' . $p['detail'] . ')';
            }
        }
        if (!empty($parts)) {
            return 'Усі увімкнені провайдери не повернули прогноз: ' . implode('; ', $parts);
        }
        return 'Прогноз недоступний з невідомої причини — див. діагностику.';
    }

    private static function persist(array $plan, array $cfg, $store, array $hooks)
    {
        if ($store && method_exists($store, 'savePlanningRun')) {
            try { $store->savePlanningRun($plan); } catch (\Throwable $e) {}
        }
        if ($store && method_exists($store, 'audit')) {
            try {
                $store->audit('plan_run', array(
                    'status' => isset($plan['status']) ? $plan['status'] : '?',
                    'mode' => isset($cfg['CONTROL_MODE']) ? $cfg['CONTROL_MODE'] : 'shadow',
                ));
            } catch (\Throwable $e) {}
        }
        if (isset($hooks['publish']) && is_callable($hooks['publish'])) {
            call_user_func($hooks['publish'], $plan, $cfg);
        }
    }

    public static function buildBattery(array $cfg)
    {
        $perUnit = isset($cfg['BATTERY_CAPACITY_KWH']) ? (float)$cfg['BATTERY_CAPACITY_KWH'] : 0;
        $count = isset($cfg['BATTERY_COUNT']) ? (float)$cfg['BATTERY_COUNT'] : 0;
        $capacity = $perUnit > 0 ? $perUnit : max(1.0, $count * 10.0);
        return new BatteryModel(array(
            'capacity_kwh' => $capacity,
            'min_soc_pct' => isset($cfg['MIN_SOC_PCT']) ? (float)$cfg['MIN_SOC_PCT'] : 10,
            'reserve_soc_pct' => isset($cfg['RESERVE_SOC_PCT']) ? (float)$cfg['RESERVE_SOC_PCT'] : 20,
            'max_soc_pct' => isset($cfg['MAX_SOC_PCT']) ? (float)$cfg['MAX_SOC_PCT'] : 100,
            'max_charge_kw' => isset($cfg['MAX_CHARGE_KW']) ? (float)$cfg['MAX_CHARGE_KW'] : 5,
            'max_discharge_kw' => isset($cfg['MAX_DISCHARGE_KW']) ? (float)$cfg['MAX_DISCHARGE_KW'] : 5,
            'eta_charge' => isset($cfg['ETA_CHARGE']) ? (float)$cfg['ETA_CHARGE'] : 0.95,
            'eta_discharge' => isset($cfg['ETA_DISCHARGE']) ? (float)$cfg['ETA_DISCHARGE'] : 0.95,
        ));
    }

    private static function svcCfg(array $cfg)
    {
        return array(
            'tariff_cheap_price' => isset($cfg['TARIFF_CHEAP_PRICE']) ? (float)$cfg['TARIFF_CHEAP_PRICE'] : 0,
            'tariff_day_price' => isset($cfg['TARIFF_DAY_PRICE']) ? (float)$cfg['TARIFF_DAY_PRICE'] : 0,
            'cheap_start_hour' => isset($cfg['CHEAP_START_HOUR']) ? (int)$cfg['CHEAP_START_HOUR'] : 23,
            'cheap_end_hour' => isset($cfg['CHEAP_END_HOUR']) ? (int)$cfg['CHEAP_END_HOUR'] : 7,
            'cheap_start_min' => isset($cfg['CHEAP_START_MIN']) ? (int)$cfg['CHEAP_START_MIN'] : 0,
            'cheap_end_min' => isset($cfg['CHEAP_END_MIN']) ? (int)$cfg['CHEAP_END_MIN'] : 0,
            'mode' => isset($cfg['MODE']) ? $cfg['MODE'] : 'reach_full',
            'soc_step_pct' => isset($cfg['SOC_STEP_PCT']) ? (float)$cfg['SOC_STEP_PCT'] : 5,
            'allow_export' => !empty($cfg['ALLOW_EXPORT']),
            'ensemble_method' => isset($cfg['ENSEMBLE_METHOD']) ? $cfg['ENSEMBLE_METHOD'] : 'median',
            'tou_slots' => 6,
            // PV arrays so the planner can report total kWp and flag an
            // implausible per-kWp forecast.
            'pv_arrays' => self::pvArrays($cfg),
            // Manual PV forecast correction factor (1.0 = unchanged).
            'pv_scale' => isset($cfg['PV_SCALE']) && is_numeric($cfg['PV_SCALE']) ? (float)$cfg['PV_SCALE'] : 1.0,
            'curtailment_penalty_per_kwh' => isset($cfg['CURTAILMENT_PENALTY_PER_KWH'])
                && is_numeric($cfg['CURTAILMENT_PENALTY_PER_KWH'])
                ? max(0.0, (float)$cfg['CURTAILMENT_PENALTY_PER_KWH']) : 0.05,
            'controllable_hourly_kw' => self::controllableProfile($cfg),
        );
    }

    /** Optional 24-value kW profile of loads scheduled separately. */
    private static function controllableProfile(array $cfg)
    {
        $raw = isset($cfg['CONTROLLABLE_HOURLY_KW_JSON']) ? $cfg['CONTROLLABLE_HOURLY_KW_JSON'] : '';
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d) && count($d) === 24) {
                return array_values($d);
            }
        }
        return array();
    }

    /** Decode the configured PV arrays (PV_ARRAYS_JSON) into an array. */
    private static function pvArrays(array $cfg)
    {
        $raw = isset($cfg['PV_ARRAYS_JSON']) ? $cfg['PV_ARRAYS_JSON'] : '';
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) {
                return $d;
            }
        }
        return array();
    }
}
