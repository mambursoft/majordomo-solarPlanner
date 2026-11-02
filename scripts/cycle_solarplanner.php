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
// Make the module class available to the cycle so getConfig() works here too.
// Without this, sp_load_config() could not read the saved settings and every
// plan degraded to "no_forecast" with no providers attempted.
@include_once(dirname(__FILE__) . '/../modules/solarplanner/solarplanner.class.php');

use SolarPlanner\Telemetry\TelemetryCollector;
use SolarPlanner\Inverter\MdmPropertyInverterAdapter;
use SolarPlanner\MajorDoMo\PlanRunner;

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
    // Primary: the module object (same getConfig/saveConfig as the UI).
    if (class_exists('solarplanner')) {
        try {
            $m = new solarplanner();
            if (method_exists($m, 'getConfig')) {
                $m->getConfig();
                if (is_array($m->config)) {
                    $cfg = $m->config;
                }
            }
        } catch (\Throwable $e) {
            echo date("H:i:s") . " [SolarPlanner] config via class failed: " . $e->getMessage() . "\n";
        }
    } else {
        echo date("H:i:s") . " [SolarPlanner] WARNING: solarplanner class not loaded in cycle context.\n";
    }

    // Fallback: read the module's saved settings directly from the DB so the
    // cycle still works even if the class could not be instantiated.
    if (empty($cfg)) {
        $cfg = sp_load_config_fallback();
    }
    return is_array($cfg) ? $cfg : array();
}

/**
 * Read module settings straight from MajorDoMo's settings storage, independent
 * of the module class. MajorDoMo stores module config in the 'settings' table
 * under key "moduleConfig_<name>" (serialized) in most builds; we also probe a
 * couple of known variants to be resilient across versions.
 */
function sp_load_config_fallback()
{
    $candidates = array();
    $has = SQLSelectOne("SHOW TABLES LIKE 'settings'");
    if ($has) {
        foreach (array('moduleConfig_solarplanner', 'solarplanner_config', 'ModuleConfig_solarplanner') as $key) {
            $row = SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='" . DBSafe($key) . "'");
            if ($row && isset($row['VALUE']) && $row['VALUE'] !== '') {
                $candidates[] = $row['VALUE'];
            }
        }
    }
    foreach ($candidates as $raw) {
        $data = @unserialize($raw);
        if (is_array($data) && !empty($data)) {
            echo date("H:i:s") . " [SolarPlanner] config loaded via settings fallback.\n";
            return $data;
        }
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json)) {
            return $json;
        }
    }
    echo date("H:i:s") . " [SolarPlanner] WARNING: no saved config found; using defaults.\n";
    return array();
}

/** Durable KV read from the `settings` table (forecast cache transport). */
function sp_kv_get($key)
{
    if (!SQLSelectOne("SHOW TABLES LIKE 'settings'")) {
        return null;
    }
    $row = SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='" . DBSafe($key) . "'");
    return ($row && isset($row['VALUE'])) ? $row['VALUE'] : null;
}

/** Durable KV write to the `settings` table. */
function sp_kv_set($key, $value)
{
    if (!SQLSelectOne("SHOW TABLES LIKE 'settings'")) {
        return;
    }
    $row = SQLSelectOne("SELECT ID FROM settings WHERE NAME='" . DBSafe($key) . "'");
    // SQLInsert() takes the record by reference -> use a variable (PHP 7.3).
    if ($row && isset($row['ID'])) {
        $rec = array('ID' => $row['ID'], 'NAME' => $key, 'VALUE' => $value);
        SQLUpdate('settings', $rec);
    } else {
        $rec = array('NAME' => $key, 'VALUE' => $value);
        SQLInsert('settings', $rec);
    }
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
        $rec = array(
            'SAMPLE_UTC' => $v['sample']['utc'],
            'BATTERY_SOC_PCT' => $v['sample']['battery_soc_pct'],
            'PV_POWER_KW' => $v['sample']['pv_power_kw'],
            'LOAD_POWER_KW' => $v['sample']['load_power_kw'],
            'GRID_POWER_KW' => $v['sample']['grid_power_kw'],
            'BATTERY_POWER_KW' => $v['sample']['battery_power_kw'],
            'FLAGS' => implode(',', $v['flags']),
        );
        SQLInsert('solarplanner_telemetry', $rec);
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
    $store = new SqlForecastStore();

    // Delegate to the shared PlanRunner so the cycle and the UI "Run plan"
    // button behave identically and produce the same diagnostics.
    // Cache-aware: the cycle respects the fetch schedule (force_fetch=false) so
    // provider quota is spread across the day/night instead of burned every run.
    require_once(dirname(__FILE__) . '/../modules/solarplanner/lib/Forecast/ForecastCache.php');
    $cache = new \SolarPlanner\Forecast\ForecastCache(
        function ($key) { return sp_kv_get($key); },
        function ($key, $val) { sp_kv_set($key, $val); },
        (isset($cfg['CACHE_MAX_AGE_MIN']) ? (int)$cfg['CACHE_MAX_AGE_MIN'] * 60 : 6 * 3600)
    );

    $plan = PlanRunner::run($cfg, $store, array(
        'cache' => $cache,
        'prop_getter' => function ($prop) { return function_exists('gg') ? gg($prop) : null; },
        'force_fetch' => false,
        'get_soc' => 'sp_current_soc',
        'get_history' => 'sp_load_history',
        'publish' => 'sp_publish_properties',
        'say' => function ($text) { if (function_exists('say')) { @say($text, 1); } },
    ));

    // Persist compiled TOU slots for the history table (UI consumes this).
    if (isset($plan['tou_compiled']['compile']['slots'])) {
        $touRec = array(
            'PLAN_RUN_ID' => 0,
            'SLOTS_JSON' => json_encode($plan['tou_compiled']['compile']['slots']),
            'COMPILED_OK' => !empty($plan['tou_compiled']['accurate']) ? 1 : 0,
            'CREATED' => date('Y-m-d H:i:s'),
        );
        SQLInsert('solarplanner_tou_plans', $touRec);
    }
    return $plan;
}

/** Latest battery SOC from telemetry, or null if none (never a fake 0). */
function sp_current_soc()
{
    $row = SQLSelectOne("SELECT BATTERY_SOC_PCT FROM solarplanner_telemetry WHERE BATTERY_SOC_PCT IS NOT NULL ORDER BY SAMPLE_UTC DESC LIMIT 1");
    return ($row && $row['BATTERY_SOC_PCT'] !== null) ? (float)$row['BATTERY_SOC_PCT'] : null;
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
