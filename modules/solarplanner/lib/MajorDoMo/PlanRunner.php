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

        // 4) Build the plan.
        $svc = new PlannerService($batt, $grid, self::svcCfg($cfg));
        $plan = $svc->makePlan($startSoc, $providerRuns, $history, $from, $to, array(
            'unavailable_providers' => $unavailable,
        ));
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
        );
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
