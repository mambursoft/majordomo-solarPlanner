<?php
/**
 * Solar Planner background cycle for MajorDoMo.
 *
 * Pure PHP 7.3+ (compatible with PHP 8.x). Zero external dependencies.
 *
 * Responsibilities:
 *  - periodically collect telemetry into history,
 *  - fetch forecasts respecting per-provider quotas and backoff,
 *  - build a plan (TargetSocAt07) and compile TOU,
 *  - in observe/shadow mode: compute and store only (NEVER write to inverter),
 *  - react to control flags: 'restart', 'plan_now'.
 *
 * RPi4 notes:
 *  - long sleep between iterations (default 60s loop; heavy work every N min)
 *    to spare the CPU and the microSD card,
 *  - a single flock guard prevents overlapping runs,
 *  - telemetry and forecast writes are batched; debug log is append-only and
 *    best kept on a tmpfs-mounted path (see docs/installation.md).
 *
 * @package solarplanner
 * @author  mambursoft
 * @license MIT
 */

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

require_once(dirname(__FILE__) . '/../modules/solarplanner/lib/autoload.php');
require_once(dirname(__FILE__) . '/../modules/solarplanner/lib/MajorDoMo/SqlForecastStore.php');

use SolarPlanner\Common\TimeGrid;
use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Forecast\HttpClient;
use SolarPlanner\Forecast\QuotaManager;
use SolarPlanner\Forecast\PvModel;
use SolarPlanner\Forecast\OpenMeteoAdapter;
use SolarPlanner\Forecast\ForecastSolarAdapter;
use SolarPlanner\Forecast\SolcastAdapter;
use SolarPlanner\MajorDoMo\PlannerService;
use SolarPlanner\MajorDoMo\SqlForecastStore;
use SolarPlanner\MajorDoMo\Config as SpConfig;
use SolarPlanner\Telemetry\TelemetryCollector;
use SolarPlanner\Inverter\MdmPropertyInverterAdapter;

set_time_limit(0);

$lockFile = sys_get_temp_dir() . '/cycle_solarplanner.lock';
$lockFp   = @fopen($lockFile, 'c+');
if ($lockFp) {
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        echo date("H:i:s") . " [SolarPlanner ERROR] Another instance is already running. Exiting.\n";
        exit(0);
    }
    ftruncate($lockFp, 0);
    fwrite($lockFp, (string)getmypid() . "\n");
    fflush($lockFp);
}

echo date("H:i:s") . " [SolarPlanner] cycle starting...\n";

$cycleVar = 'cycle_solarplannerRun';
$ctrlVar  = 'cycle_solarplannerControl';
setGlobal($ctrlVar, '', 1);

function sp_cfg($key, $default = null)
{
    $raw = getGlobal('ModuleConfig_solarplanner'); // fallback if present
    static $cfg = null;
    if ($cfg === null) {
        // Load module config the same way MajorDoMo modules do.
        $cfg = array();
        $rec = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='solarplanner'");
        // project_modules.DATA is params, config lives in saved_params; use the
        // module's getConfig by including the class if needed. For the cycle we
        // read the serialized config file that saveConfig() writes.
        $file = ROOT . 'cms/solarplanner_config.json';
        if (file_exists($file)) {
            $d = json_decode(file_get_contents($file), true);
            if (is_array($d)) {
                $cfg = $d;
            }
        }
    }
    return isset($cfg[$key]) ? $cfg[$key] : $default;
}

/**
 * Read module config via the module object (authoritative), falling back to
 * defaults. Using the module keeps us aligned with getConfig()/saveConfig().
 */
function sp_load_config()
{
    $cfg = array();
    if (class_exists('solarplanner')) {
        $m = new solarplanner();
        if (method_exists($m, 'getConfig')) {
            $m->getConfig();
            if (is_array($m->config)) {
                $cfg = $m->config;
            }
        }
    }
    return $cfg;
}

function sp_build_battery(array $cfg)
{
    $count = isset($cfg['BATTERY_COUNT']) ? (float)$cfg['BATTERY_COUNT'] : 0;
    $perUnit = isset($cfg['BATTERY_CAPACITY_KWH']) ? (float)$cfg['BATTERY_CAPACITY_KWH'] : 0;
    // BATTERY_CAPACITY_KWH is treated as total available capacity here.
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

function sp_collect_telemetry()
{
    // Map logical fields to MajorDoMo property names from private config.
    $cfg = sp_load_config();
    $mapJson = isset($cfg['PROPERTY_MAP_JSON']) ? $cfg['PROPERTY_MAP_JSON'] : '';
    $map = $mapJson ? json_decode($mapJson, true) : array();
    if (!is_array($map) || empty($map)) {
        // Default to SolarPlanner.* mirror properties.
        $map = array(
            'battery_soc' => 'SolarPlanner.CurrentSoc',
            'pv_power_kw' => 'SolarPlanner.PvPower',
            'load_power_kw' => 'SolarPlanner.LoadPower',
            'grid_power_kw' => 'SolarPlanner.GridPower',
            'battery_power_kw' => 'SolarPlanner.BatteryPower',
        );
    }
    $adapter = new MdmPropertyInverterAdapter(function ($prop) {
        return gg($prop);
    }, $map);
    $raw = $adapter->readTelemetry();
    $v = TelemetryCollector::validate($raw, time(), 300);
    if ($v['sample']['battery_soc_pct'] !== null) {
        SQLInsert('solarplanner_telemetry', array(
            'SAMPLE_UTC' => $v['sample']['utc'],
            'BATTERY_SOC_PCT' => $v['sample']['battery_soc_pct'],
            'PV_POWER_KW' => $v['sample']['pv_power_kw'],
            'LOAD_POWER_KW' => $v['sample']['load_power_kw'],
            'GRID_POWER_KW' => $v['sample']['grid_power_kw'],
            'BATTERY_POWER_KW' => $v['sample']['battery_power_kw'],
            'FLAGS' => implode(',', $v['flags']),
        ));
    }
    return $v;
}

function sp_load_history($hours = 336)
{
    $since = time() - $hours * 3600;
    $rows = SQLSelect("SELECT SAMPLE_UTC, LOAD_POWER_KW FROM solarplanner_telemetry WHERE SAMPLE_UTC >= $since AND LOAD_POWER_KW IS NOT NULL ORDER BY SAMPLE_UTC ASC");
    $hist = array();
    foreach ($rows as $r) {
        $hist[] = array('utc' => (int)$r['SAMPLE_UTC'], 'load_kw' => (float)$r['LOAD_POWER_KW'], 'valid' => true);
    }
    return $hist;
}

function sp_run_plan()
{
    $cfg = sp_load_config();
    $tz = isset($cfg['TIMEZONE']) ? $cfg['TIMEZONE'] : 'Europe/Kyiv';
    $step = isset($cfg['GRID_STEP_MIN']) ? (int)$cfg['GRID_STEP_MIN'] : 30;
    $grid = new TimeGrid($tz, in_array($step, array(15, 30, 60), true) ? $step : 30);
    $batt = sp_build_battery($cfg);

    $store = new SqlForecastStore();
    $quota = new QuotaManager();
    $http = new HttpClient(15, 2, false);

    // PV arrays from private config.
    $arrays = array();
    if (!empty($cfg['PV_ARRAYS_JSON'])) {
        $decoded = json_decode($cfg['PV_ARRAYS_JSON'], true);
        if (is_array($decoded)) {
            $arrays = $decoded;
        }
    }
    if (empty($arrays)) {
        // Conservative placeholder so the cycle can run read-only; real arrays
        // must be configured before trusting PV numbers.
        $arrays = array(array('array_id' => 'a1', 'kwp' => 3.2, 'tilt_deg' => 40, 'azimuth_module_deg' => 180));
    }

    $site = array(
        'lat' => isset($cfg['LATITUDE']) ? (float)$cfg['LATITUDE'] : 50.45,
        'lon' => isset($cfg['LONGITUDE']) ? (float)$cfg['LONGITUDE'] : 30.52,
        'arrays' => $arrays,
        'tz' => $tz,
    );
    $pv = new PvModel($arrays, 0.0);

    $now = time();
    // Horizon: from now until the day-after-tomorrow cheap window end (36-48h).
    $from = $now;
    $to = $now + 44 * 3600;

    $providerRuns = array();
    $unavailable = array();

    if (!empty($cfg['OPEN_METEO_ENABLED'])) {
        $a = new OpenMeteoAdapter($http, $quota, $pv, $site, 'open_meteo');
        $r = $a->fetch($from, $to, false);
        if ($r['run']) {
            $store->saveForecastRun($r['run']->toArray());
            $providerRuns[] = array('run' => $r['run'], 'mae' => null, 'obs' => 0, 'weather_model' => 'open-meteo');
        } else {
            $unavailable[] = 'open_meteo:' . $r['detail'];
        }
    }
    if (!empty($cfg['FORECAST_SOLAR_ENABLED'])) {
        $key = isset($cfg['FORECAST_SOLAR_API_KEY']) ? $cfg['FORECAST_SOLAR_API_KEY'] : null;
        $a = new ForecastSolarAdapter($http, $quota, $site, $key ?: null, 'forecast_solar');
        $r = $a->fetch($from, $to, false);
        if ($r['run']) {
            $store->saveForecastRun($r['run']->toArray());
            $providerRuns[] = array('run' => $r['run'], 'mae' => null, 'obs' => 0, 'weather_model' => 'forecast_solar');
        } else {
            $unavailable[] = 'forecast_solar:' . $r['detail'];
        }
    }
    if (!empty($cfg['SOLCAST_ENABLED']) && !empty($cfg['SOLCAST_API_KEY']) && !empty($cfg['SOLCAST_RESOURCE_IDS'])) {
        $ids = array_filter(array_map('trim', explode(',', $cfg['SOLCAST_RESOURCE_IDS'])));
        $a = new SolcastAdapter($http, $quota, $ids, $cfg['SOLCAST_API_KEY'], 'solcast');
        $r = $a->fetch($from, $to, false);
        if ($r['run']) {
            $store->saveForecastRun($r['run']->toArray());
            $providerRuns[] = array('run' => $r['run'], 'mae' => null, 'obs' => 0, 'weather_model' => 'solcast');
        } else {
            $unavailable[] = 'solcast:' . $r['detail'];
        }
    }

    // Current SOC from telemetry.
    $socRow = SQLSelectOne("SELECT BATTERY_SOC_PCT FROM solarplanner_telemetry WHERE BATTERY_SOC_PCT IS NOT NULL ORDER BY SAMPLE_UTC DESC LIMIT 1");
    $startSoc = ($socRow && $socRow['BATTERY_SOC_PCT'] !== null) ? (float)$socRow['BATTERY_SOC_PCT'] : $batt->reserveSocPct;

    $history = sp_load_history();

    $svcCfg = array(
        'tariff_cheap_price' => isset($cfg['TARIFF_CHEAP_PRICE']) ? (float)$cfg['TARIFF_CHEAP_PRICE'] : 0,
        'tariff_day_price' => isset($cfg['TARIFF_DAY_PRICE']) ? (float)$cfg['TARIFF_DAY_PRICE'] : 0,
        'cheap_start_hour' => isset($cfg['CHEAP_START_HOUR']) ? (int)$cfg['CHEAP_START_HOUR'] : 23,
        'cheap_end_hour' => isset($cfg['CHEAP_END_HOUR']) ? (int)$cfg['CHEAP_END_HOUR'] : 7,
        'mode' => isset($cfg['MODE']) ? $cfg['MODE'] : 'reach_full',
        'soc_step_pct' => isset($cfg['SOC_STEP_PCT']) ? (float)$cfg['SOC_STEP_PCT'] : 5,
        'allow_export' => !empty($cfg['ALLOW_EXPORT']),
        'ensemble_method' => isset($cfg['ENSEMBLE_METHOD']) ? $cfg['ENSEMBLE_METHOD'] : 'median',
        'tou_slots' => 6,
    );
    $svc = new PlannerService($batt, $grid, $svcCfg);

    if (empty($providerRuns)) {
        // Safe fallback: no forecast available. Do not invent accuracy.
        $plan = array('status' => 'no_forecast', 'reason' => 'all providers unavailable',
            'unavailable_providers' => $unavailable);
        $store->savePlanningRun($plan);
        sp_publish_properties($plan, $cfg);
        return $plan;
    }

    $plan = $svc->makePlan($startSoc, $providerRuns, $history, $from, $to, array(
        'unavailable_providers' => $unavailable,
    ));
    $compiled = $svc->compileAndVerify($plan);
    $plan['tou_compiled'] = $compiled;

    $store->savePlanningRun($plan);
    if (isset($compiled['compile']['slots'])) {
        SQLInsert('solarplanner_tou_plans', array(
            'PLAN_RUN_ID' => 0,
            'SLOTS_JSON' => json_encode($compiled['compile']['slots']),
            'COMPILED_OK' => !empty($compiled['accurate']) ? 1 : 0,
            'CREATED' => date('Y-m-d H:i:s'),
        ));
    }

    sp_publish_properties($plan, $cfg);

    // NEVER write to the inverter unless armed with valid approval. Shadow mode
    // computes and stores only. Explain the plan over TTS if configured.
    $controlMode = isset($cfg['CONTROL_MODE']) ? $cfg['CONTROL_MODE'] : 'shadow';
    if ($controlMode !== 'armed') {
        $store->audit('shadow_plan', array('mode' => $controlMode, 'status' => $plan['status']));
    }

    if (function_exists('say') && isset($plan['explanation_obj']['text']) && !empty($cfg['ANNOUNCE_PLAN'])) {
        @say($plan['explanation_obj']['text'], 1);
    }

    return $plan;
}

function sp_publish_properties(array $plan, array $cfg)
{
    $set = function ($prop, $val) {
        if (function_exists('setGlobal')) {
            setGlobal($prop, $val);
        }
    };
    $set('SolarPlanner.Enabled', 1);
    $set('SolarPlanner.ControlMode', isset($cfg['CONTROL_MODE']) ? $cfg['CONTROL_MODE'] : 'shadow');
    $set('SolarPlanner.Status', isset($plan['status']) ? $plan['status'] : 'unknown');
    $set('SolarPlanner.LastPlanAt', date('Y-m-d H:i:s'));
    if (isset($plan['target_soc_at_07'])) {
        $set('SolarPlanner.TargetSocAt07', $plan['target_soc_at_07']);
    }
    if (isset($plan['planned_grid_charge_kwh'])) {
        $set('SolarPlanner.PlannedGridChargeKwh', $plan['planned_grid_charge_kwh']);
    }
    if (isset($plan['planned_night_import_kwh'])) {
        $set('SolarPlanner.PlannedNightImportKwh', $plan['planned_night_import_kwh']);
    }
    if (isset($plan['expected_day_import_kwh'])) {
        $set('SolarPlanner.ExpectedDayImportKwh', $plan['expected_day_import_kwh']);
    }
    if (isset($plan['pv_tomorrow_kwh'])) {
        $set('SolarPlanner.ForecastPvTomorrowKwh', $plan['pv_tomorrow_kwh']);
    }
    if (isset($plan['pv_tomorrow_low_kwh'])) {
        $set('SolarPlanner.ForecastPvTomorrowLowKwh', $plan['pv_tomorrow_low_kwh']);
    }
    if (isset($plan['pv_tomorrow_high_kwh'])) {
        $set('SolarPlanner.ForecastPvTomorrowHighKwh', $plan['pv_tomorrow_high_kwh']);
    }
    if (isset($plan['load_tomorrow_kwh'])) {
        $set('SolarPlanner.ForecastLoadTomorrowKwh', $plan['load_tomorrow_kwh']);
    }
    if (isset($plan['explanation_obj']['text'])) {
        $set('SolarPlanner.PlanExplanation', $plan['explanation_obj']['text']);
    }
}

// ---------------------------------------------------------------------------
// Main loop. Telemetry every loop (60s); heavy planning every PLAN_INTERVAL.
// ---------------------------------------------------------------------------
$loopSleep = 60;                 // seconds between telemetry samples
$planInterval = 15 * 60;         // seconds between planning runs (RPi4-friendly)
$lastPlan = 0;

while (true) {
    setGlobal($cycleVar, time(), 1);

    $ctrl = (string)getGlobal($ctrlVar);
    if ($ctrl === 'restart') {
        echo date("H:i:s") . " [SolarPlanner] restart requested; exiting for supervisor to relaunch.\n";
        setGlobal($ctrlVar, '', 1);
        break;
    }

    try {
        sp_collect_telemetry();
    } catch (\Throwable $e) {
        echo date("H:i:s") . " [SolarPlanner telemetry error] " . $e->getMessage() . "\n";
    }

    $planNow = ($ctrl === 'plan_now');
    if ($planNow || (time() - $lastPlan) >= $planInterval) {
        if ($planNow) {
            setGlobal($ctrlVar, '', 1);
        }
        try {
            $plan = sp_run_plan();
            $lastPlan = time();
            echo date("H:i:s") . " [SolarPlanner] plan status=" . (isset($plan['status']) ? $plan['status'] : '?') . "\n";
        } catch (\Throwable $e) {
            echo date("H:i:s") . " [SolarPlanner plan error] " . $e->getMessage() . "\n";
        }
    }

    sleep($loopSleep);
}

echo date("H:i:s") . " [SolarPlanner] cycle stopped.\n";
