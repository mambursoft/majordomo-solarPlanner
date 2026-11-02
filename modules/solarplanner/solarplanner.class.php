<?php
/**
 * Solar Planner module for MajorDoMo.
 *
 * Solar generation and night battery charge planner. Multi-provider PV forecast,
 * load forecast, interval energy simulator, TargetSocAt07, Deye TOU compiler and
 * a safe shadow mode. Does NOT write to the inverter by default.
 *
 * @package    solarplanner
 * @author     mambursoft
 * @copyright  2026 mambursoft
 * @license    MIT
 * @version    1.0.7
 */

require_once(dirname(__FILE__) . '/lib/autoload.php');

use SolarPlanner\MajorDoMo\Config;

class solarplanner extends module
{
    function solarplanner()
    {
        $this->name            = 'solarplanner';
        $this->title           = 'Solar Planner';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
    }

    function __construct()
    {
        $this->solarplanner();
    }

    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id))          $p['id']          = $this->id;
        if (isset($this->view_mode))   $p['view_mode']   = $this->view_mode;
        if (isset($this->edit_mode))   $p['edit_mode']   = $this->edit_mode;
        if (isset($this->data_source)) $p['data_source'] = $this->data_source;
        if (isset($this->tab))         $p['tab']         = $this->tab;
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $data_source, $tab;
        if (isset($id))          $this->id          = $id;
        if (isset($mode))        $this->mode        = $mode;
        if (isset($view_mode))   $this->view_mode   = $view_mode;
        if (isset($edit_mode))   $this->edit_mode   = $edit_mode;
        if (isset($tab))         $this->tab         = $tab;
        if (isset($data_source)) $this->data_source = $data_source;
    }

    function run()
    {
        global $session;
        $out = array();

        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }

        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }

        $out['VIEW_MODE']   = $this->view_mode;
        $out['EDIT_MODE']   = $this->edit_mode;
        $out['MODE']        = $this->mode;
        $out['ACTION']      = $this->action;
        $out['DATA_SOURCE'] = $this->data_source;
        $out['TAB']         = $this->tab;

        $this->data = $out;

        $templateFile = DIR_TEMPLATES . $this->name . '/' . $this->name . '.html';
        if (!file_exists($templateFile) && defined('ROOT')) {
            $templateFile = ROOT . 'templates/' . $this->name . '/' . $this->name . '.html';
        }
        if (!file_exists($templateFile)) {
            $templateFile = DIR_MODULES . $this->name . '/templates/' . $this->name . '.html';
        }
        if (!file_exists($templateFile) && defined('ROOT')) {
            $templateFile = ROOT . 'modules/' . $this->name . '/templates/' . $this->name . '.html';
        }

        $p = new parser($templateFile, $this->data, $this);
        $this->result = $p->result;
    }

    function checkInstalled()
    {
        parent::checkInstalled();

        $rec = SQLSelectOne("SELECT ID, CATEGORY FROM project_modules WHERE NAME='" . $this->name . "'");
        if (!$rec['ID']) {
            $rec = array(
                'NAME'     => $this->name,
                'TITLE'    => $this->title,
                'CATEGORY' => $this->module_category,
                'DATA'     => '',
            );
            SQLInsert('project_modules', $rec);
        } elseif ($rec['CATEGORY'] != $this->module_category) {
            $rec['CATEGORY'] = $this->module_category;
            SQLUpdate('project_modules', $rec);
        }

        if (defined('ROOT') && is_dir(ROOT . 'cms/modules_installed')) {
            $flagFile = ROOT . 'cms/modules_installed/' . $this->name . '.installed';
            if (!file_exists($flagFile)) {
                @file_put_contents($flagFile, date("H:i d.m.Y"));
            }
        }

        // Provision the module icon into the web root /img/modules/ so the UI
        // header and module list can show it even if the package extractor did
        // not place it there directly.
        if (defined('ROOT')) {
            $dstDir = ROOT . 'img/modules/';
            if (!is_dir($dstDir)) {
                @mkdir($dstDir, 0775, true);
            }
            $srcIcon = DIR_MODULES . $this->name . '/../../img/modules/' . $this->name . '.png';
            $srcIconAlt = ROOT . 'modules/' . $this->name . '/img/' . $this->name . '.png';
            $dstIcon = $dstDir . $this->name . '.png';
            if (!file_exists($dstIcon)) {
                if (file_exists($srcIcon)) {
                    @copy($srcIcon, $dstIcon);
                } elseif (file_exists($srcIconAlt)) {
                    @copy($srcIconAlt, $dstIcon);
                }
            }
        }

        // Register in plugins table for Marketplace & Installed extensions.
        $hasPlugins = SQLSelectOne("SHOW TABLES LIKE 'plugins'");
        if ($hasPlugins) {
            $col = SQLSelectOne("SHOW COLUMNS FROM plugins LIKE 'MODULE_NAME'");
            $keyField = ($col && isset($col['Field'])) ? 'MODULE_NAME' : 'NAME';

            $pRec = SQLSelectOne("SELECT * FROM plugins WHERE " . $keyField . "='" . $this->name . "'");
            $descRu = 'Планировщик солнечной генерации и ночного заряда батарей: мультипровайдерный прогноз PV (Open-Meteo, Forecast.Solar, Solcast), прогноз нагрузки, интервальный симулятор, расчёт TargetSocAt07, компилятор Deye Time of Use, безопасный shadow mode. По умолчанию не пишет в инвертор.';
            $descEn = 'Solar generation and night battery charge planner: multi-provider PV forecast (Open-Meteo, Forecast.Solar, Solcast), load forecast, interval simulator, TargetSocAt07, Deye Time of Use compiler, safe shadow mode. Does not write to the inverter by default.';

            if (!$pRec['ID']) {
                $pRec = array(
                    $keyField         => $this->name,
                    'TITLE'           => $this->title,
                    'AUTHOR'          => 'mambursoft',
                    'SUPPORT_URL'     => 'https://github.com/mambursoft/majordomo-solarPlanner',
                    'REPOSITORY_URL'  => 'https://github.com/mambursoft/majordomo-solarPlanner',
                    'DESCRIPTION_RU'  => $descRu,
                    'DESCRIPTION_EN'  => $descEn,
                    'CURRENT_VERSION' => '1.0.7',
                    'LATEST_VERSION'  => '1.0.7',
                    'IS_INSTALLED'    => 1,
                    'LATEST_UPDATE'   => date('Y-m-d H:i:s'),
                );
                SQLInsert('plugins', $pRec);
            } else {
                $pRec['TITLE']          = $this->title;
                $pRec['AUTHOR']         = 'mambursoft';
                $pRec['DESCRIPTION_RU'] = $descRu;
                $pRec['DESCRIPTION_EN'] = $descEn;
                $pRec['CURRENT_VERSION']= '1.0.7';
                $pRec['IS_INSTALLED']   = 1;
                SQLUpdate('plugins', $pRec);
            }
        }
    }

    function install($data = '')
    {
        $this->dbInstall();
        parent::install();

        $this->getConfig();
        // Realistic, safe defaults so the module is usable out-of-the-box in
        // observe/shadow. These are typical values for the documented reference
        // installation (Deye SUN-15K-SG05LP3, 3x RW-F10.6, ~3.2 kWp south@40).
        // They are starting points to review, NOT confirmed site parameters.
        // Reserve is intentionally NOT 0. 'armed' still stays blocked until the
        // device profile is verified (see Config::blockingParameters()).
        $defaults = array(
            'CONTROL_MODE'         => 'shadow',   // observe | shadow | armed (never armed by default)
            'TIMEZONE'             => 'Europe/Kyiv',
            'GRID_STEP_MIN'        => 30,
            'MODE'                 => 'reach_full',
            'CHEAP_START_HOUR'     => 23,
            'CHEAP_END_HOUR'       => 7,
            'RESERVE_SOC_PCT'      => 20,
            'BATTERY_COUNT'        => 3,          // 3x Deye RW-F10.6 (confirm!)
            'BATTERY_CAPACITY_KWH' => 30.0,       // ~3 x ~10 kWh usable (confirm!)
            'MAX_SOC_PCT'          => 100,
            'MIN_SOC_PCT'          => 10,
            'MAX_CHARGE_KW'        => 7.0,         // conservative for a 15K hybrid
            'MAX_DISCHARGE_KW'     => 7.0,
            'ETA_CHARGE'           => 0.95,
            'ETA_DISCHARGE'        => 0.95,
            'TARIFF_CHEAP_PRICE'   => 2.64,       // UA night tariff example (UAH/kWh)
            'TARIFF_DAY_PRICE'     => 4.32,       // UA day tariff example (UAH/kWh)
            'ALLOW_EXPORT'         => 0,
            'SOC_STEP_PCT'         => 5,
            'ENSEMBLE_METHOD'      => 'median',
            'PROFILE_VERIFIED'     => 0,
            'TRANSPORT'            => 'mdm_property',
            'FIRMWARE'             => '',
            'DEYE_HOST'            => '',
            'DEYE_PORT'            => '',
            'DEYE_SERIAL'          => '',
            'LATITUDE'             => '',
            'LONGITUDE'            => '',
            'OPEN_METEO_ENABLED'   => 1,
            'FORECAST_SOLAR_ENABLED' => 0,
            'SOLCAST_ENABLED'      => 0,
            'SOLCAST_API_KEY'      => '',
            'FORECAST_SOLAR_API_KEY' => '',
            'SOLCAST_RESOURCE_IDS' => '',
            'SOLCAST_RESERVED_UNITS' => 2,
            'ANNOUNCE_PLAN'        => 0,
            'DATA_DIR'             => '',
            // Forecast caching / fetch schedule (save provider quota).
            'CACHE_MAX_AGE_MIN'    => 360,   // a cached forecast is usable for 6h
            'FORECAST_SOLAR_MIN_INTERVAL_MIN' => 20, // Forecast.Solar ~12/h per IP
            'SOLCAST_DAY_FETCHES'  => 3,     // daytime Solcast refreshes
            'SOLCAST_NIGHT_FETCHES'=> 4,     // night Solcast refreshes (tomorrow matters)
            // A representative single south-facing array so Open-Meteo returns a
            // plausible PV curve immediately. Replace with real arrays.
            'PV_ARRAYS_JSON'       => '[{"array_id":"roof_south","kwp":3.22,"tilt_deg":40,"azimuth_module_deg":180,"dc_loss":0.14,"temp_coeff_pct_per_c":-0.004}]',
            // Default property map points at SolarPlanner.* mirror properties so
            // telemetry has somewhere to read from until mapped to the real module.
            'PROPERTY_MAP_JSON'    => '{"battery_soc":"SolarPlanner.CurrentSoc","pv_power_kw":"SolarPlanner.PvPower","load_power_kw":"SolarPlanner.LoadPower","grid_power_kw":"SolarPlanner.GridPower","battery_power_kw":"SolarPlanner.BatteryPower"}',
        );
        foreach ($defaults as $k => $v) {
            if (!isset($this->config[$k])) {
                $this->config[$k] = $v;
            }
        }
        $this->saveConfig();
        $this->checkInstalled();
    }

    function dbInstall($data = '')
    {
        // Append-only history tables. Migrations are re-safe (dbInstall only
        // adds missing columns); updating the module must not wipe history.
        $data = <<<EOD
solarplanner_forecast_runs: ID int(10) unsigned NOT NULL auto_increment
solarplanner_forecast_runs: PROVIDER_ID varchar(32) NOT NULL DEFAULT ''
solarplanner_forecast_runs: FORECAST_RUN_ID varchar(96) NOT NULL DEFAULT ''
solarplanner_forecast_runs: RETRIEVED_AT_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_forecast_runs: VALID_FROM_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_forecast_runs: VALID_TO_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_forecast_runs: ENERGY_BASIS varchar(16) NOT NULL DEFAULT ''
solarplanner_forecast_runs: RAW_RESPONSE_HASH varchar(72) NOT NULL DEFAULT ''
solarplanner_forecast_runs: PAYLOAD mediumtext
solarplanner_forecast_runs: CREATED datetime

solarplanner_forecast_intervals: ID int(10) unsigned NOT NULL auto_increment
solarplanner_forecast_intervals: RUN_ID int(10) unsigned NOT NULL DEFAULT '0'
solarplanner_forecast_intervals: INTERVAL_START_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_forecast_intervals: INTERVAL_END_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_forecast_intervals: PV_ENERGY_KWH float NOT NULL DEFAULT '0'
solarplanner_forecast_intervals: IS_MISSING tinyint(1) NOT NULL DEFAULT '0'
solarplanner_forecast_intervals: IS_INTERPOLATED tinyint(1) NOT NULL DEFAULT '0'

solarplanner_telemetry: ID int(10) unsigned NOT NULL auto_increment
solarplanner_telemetry: SAMPLE_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_telemetry: BATTERY_SOC_PCT float
solarplanner_telemetry: PV_POWER_KW float
solarplanner_telemetry: LOAD_POWER_KW float
solarplanner_telemetry: GRID_POWER_KW float
solarplanner_telemetry: BATTERY_POWER_KW float
solarplanner_telemetry: FLAGS varchar(255) NOT NULL DEFAULT ''

solarplanner_planning_runs: ID int(10) unsigned NOT NULL auto_increment
solarplanner_planning_runs: PLAN_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_planning_runs: STATUS varchar(24) NOT NULL DEFAULT ''
solarplanner_planning_runs: TARGET_SOC_AT_07 float
solarplanner_planning_runs: NIGHT_IMPORT_KWH float
solarplanner_planning_runs: DAY_IMPORT_KWH float
solarplanner_planning_runs: PV_TOMORROW_KWH float
solarplanner_planning_runs: LOAD_TOMORROW_KWH float
solarplanner_planning_runs: ALGORITHM_VERSION varchar(16) NOT NULL DEFAULT ''
solarplanner_planning_runs: PAYLOAD mediumtext
solarplanner_planning_runs: CREATED datetime

solarplanner_tou_plans: ID int(10) unsigned NOT NULL auto_increment
solarplanner_tou_plans: PLAN_RUN_ID int(10) unsigned NOT NULL DEFAULT '0'
solarplanner_tou_plans: SLOTS_JSON text
solarplanner_tou_plans: COMPILED_OK tinyint(1) NOT NULL DEFAULT '0'
solarplanner_tou_plans: CREATED datetime

solarplanner_command_audit: ID int(10) unsigned NOT NULL auto_increment
solarplanner_command_audit: TS_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_command_audit: EVENT varchar(48) NOT NULL DEFAULT ''
solarplanner_command_audit: DETAIL text

solarplanner_manual_overrides: ID int(10) unsigned NOT NULL auto_increment
solarplanner_manual_overrides: UNTIL_UTC int(11) NOT NULL DEFAULT '0'
solarplanner_manual_overrides: TARGET_SOC float
solarplanner_manual_overrides: NOTE varchar(255) NOT NULL DEFAULT ''
solarplanner_manual_overrides: CREATED datetime

solarplanner_device_profiles: ID int(10) unsigned NOT NULL auto_increment
solarplanner_device_profiles: PROFILE_ID varchar(48) NOT NULL DEFAULT ''
solarplanner_device_profiles: VERIFIED tinyint(1) NOT NULL DEFAULT '0'
solarplanner_device_profiles: PAYLOAD mediumtext
solarplanner_device_profiles: CREATED datetime

solarplanner_calibration: ID int(10) unsigned NOT NULL auto_increment
solarplanner_calibration: PROVIDER_ID varchar(32) NOT NULL DEFAULT ''
solarplanner_calibration: HORIZON varchar(24) NOT NULL DEFAULT ''
solarplanner_calibration: MAE float
solarplanner_calibration: BIAS float
solarplanner_calibration: RMSE float
solarplanner_calibration: N_SAMPLES int(10) NOT NULL DEFAULT '0'
solarplanner_calibration: UPDATED datetime
EOD;
        parent::dbInstall($data);
    }

    function uninstall()
    {
        // Preserve history by default; only drop volatile tables. Owners who
        // want a full purge can drop the solarplanner_* tables manually.
        SQLExec("DROP TABLE IF EXISTS solarplanner_tou_plans");
        parent::uninstall();
    }

    function admin(&$out)
    {
        $this->getConfig();
        global $action, $mode;

        if ($action == 'save_settings' || $mode == 'save_settings') {
            $this->saveSettings($out);
        }

        if ($action == 'run_plan') {
            $res = $this->triggerPlan();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res);
            exit;
        }

        if ($action == 'create_objects') {
            $res = $this->provisionMirrorObject();
            if (isset($_REQUEST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($res);
                exit;
            }
            if (!empty($res['success'])) {
                $out['SUCCESS'] = $res['message'];
            } else {
                $out['ERROR'] = isset($res['error']) ? $res['error'] : 'Не вдалося створити обʼєкт.';
            }
            $this->view_mode = 'settings';
        }

        if ($action == 'run_diagnostics') {
            $force = !empty($_REQUEST['force']);
            $res = $this->runDiagnostics($force);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res);
            exit;
        }

        if ($action == 'fetch_solcast') {
            $res = $this->fetchSolcastResources();
            if (!empty($res['success'])) {
                $out['SUCCESS'] = $res['message'];
            } else {
                $out['ERROR'] = isset($res['error']) ? $res['error'] : 'Не вдалося отримати ресурси Solcast.';
            }
            $this->view_mode = 'settings';
        }

        if ($action == 'list_properties') {
            // AJAX: return properties of a given object for the mapping picker.
            global $object;
            $objName = isset($_REQUEST['object']) ? trim($_REQUEST['object']) : trim($object);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->listObjectProperties($objName));
            exit;
        }

        if ($action == 'set_mode') {
            global $control_mode;
            $cm = isset($_REQUEST['control_mode']) ? trim($_REQUEST['control_mode']) : trim($control_mode);
            // 'armed' allowed only if configuration unblocks it.
            $allowed = Config::allowedControlMode($this->configToArray());
            if ($cm === 'armed' && $allowed !== 'armed') {
                $out['ERROR'] = 'Режим armed заблоковано: не всі обов\'язкові параметри підтверджені.';
            } else {
                $this->config['CONTROL_MODE'] = in_array($cm, array('observe', 'shadow', 'armed'), true) ? $cm : 'shadow';
                $this->saveConfig();
                $out['SUCCESS'] = 'Режим керування оновлено: ' . $this->config['CONTROL_MODE'];
            }
        }

        $this->populateView($out);
    }

    function usual(&$out)
    {
        $this->admin($out);
    }

    private function saveSettings(&$out)
    {
        $map = array(
            'TIMEZONE' => 'timezone', 'GRID_STEP_MIN' => 'grid_step_min', 'MODE' => 'planner_mode',
            'CHEAP_START_HOUR' => 'cheap_start_hour', 'CHEAP_END_HOUR' => 'cheap_end_hour',
            'RESERVE_SOC_PCT' => 'reserve_soc_pct', 'BATTERY_COUNT' => 'battery_count',
            'BATTERY_CAPACITY_KWH' => 'battery_capacity_kwh', 'MAX_SOC_PCT' => 'max_soc_pct',
            'MIN_SOC_PCT' => 'min_soc_pct', 'MAX_CHARGE_KW' => 'max_charge_kw',
            'MAX_DISCHARGE_KW' => 'max_discharge_kw', 'ETA_CHARGE' => 'eta_charge',
            'ETA_DISCHARGE' => 'eta_discharge', 'TARIFF_CHEAP_PRICE' => 'tariff_cheap_price',
            'TARIFF_DAY_PRICE' => 'tariff_day_price', 'SOC_STEP_PCT' => 'soc_step_pct',
            'ENSEMBLE_METHOD' => 'ensemble_method', 'TRANSPORT' => 'transport', 'FIRMWARE' => 'firmware',
            'LATITUDE' => 'latitude', 'LONGITUDE' => 'longitude',
            'PV_ARRAYS_JSON' => 'pv_arrays_json', 'PROPERTY_MAP_JSON' => 'property_map_json',
            'DEYE_HOST' => 'deye_host', 'DEYE_PORT' => 'deye_port',
            'SOLCAST_RESOURCE_IDS' => 'solcast_resource_ids',
        );
        foreach ($map as $cfgKey => $postKey) {
            if (isset($_POST[$postKey])) {
                $this->config[$cfgKey] = trim($_POST[$postKey]);
            }
        }
        // Secret keys: only overwrite when a new non-masked value is supplied,
        // so re-saving the form with the masked placeholder does not wipe them.
        foreach (array('SOLCAST_API_KEY' => 'solcast_api_key', 'FORECAST_SOLAR_API_KEY' => 'forecast_solar_api_key', 'DEYE_SERIAL' => 'deye_serial') as $cfgKey => $postKey) {
            if (isset($_POST[$postKey])) {
                $val = trim($_POST[$postKey]);
                if ($val !== '' && strpos($val, '*') === false) {
                    $this->config[$cfgKey] = $val;
                }
            }
        }
        // Validate JSON fields; on parse error keep the previous value and warn.
        foreach (array('PV_ARRAYS_JSON', 'PROPERTY_MAP_JSON') as $jsonKey) {
            if (isset($this->config[$jsonKey]) && $this->config[$jsonKey] !== '') {
                $decoded = json_decode($this->config[$jsonKey], true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $out['ERROR'] = 'Некоректний JSON у полі ' . $jsonKey . ' — зміну не збережено.';
                    unset($this->config[$jsonKey]);
                    $this->getConfig(); // reload to drop the bad value
                }
            }
        }
        foreach (array('ALLOW_EXPORT' => 'allow_export', 'OPEN_METEO_ENABLED' => 'open_meteo_enabled',
                     'FORECAST_SOLAR_ENABLED' => 'forecast_solar_enabled', 'SOLCAST_ENABLED' => 'solcast_enabled',
                     'PROFILE_VERIFIED' => 'profile_verified') as $cfgKey => $postKey) {
            $this->config[$cfgKey] = isset($_POST[$postKey]) ? 1 : 0;
        }
        $this->saveConfig();
        setGlobal('cycle_solarplannerControl', 'restart');
        $out['SUCCESS'] = 'Налаштування збережено. Сигнал перезапуску циклу відправлено.';
        $this->view_mode = 'settings';
    }

    private function configToArray()
    {
        $c = $this->config;
        return array(
            'battery_count' => isset($c['BATTERY_COUNT']) ? (int)$c['BATTERY_COUNT'] : 0,
            'battery_capacity_kwh' => isset($c['BATTERY_CAPACITY_KWH']) ? (float)$c['BATTERY_CAPACITY_KWH'] : 0,
            'reserve_soc_pct' => isset($c['RESERVE_SOC_PCT']) ? (float)$c['RESERVE_SOC_PCT'] : 0,
            'tariff_cheap_price' => isset($c['TARIFF_CHEAP_PRICE']) ? $c['TARIFF_CHEAP_PRICE'] : '',
            'tariff_day_price' => isset($c['TARIFF_DAY_PRICE']) ? $c['TARIFF_DAY_PRICE'] : '',
            'transport' => isset($c['TRANSPORT']) ? $c['TRANSPORT'] : '',
            'firmware' => isset($c['FIRMWARE']) ? $c['FIRMWARE'] : '',
            'property_map' => !empty($c['PROPERTY_MAP_JSON']) ? $c['PROPERTY_MAP_JSON'] : '',
            'pv_arrays' => $this->loadPvArrays(),
            'profile_verified' => isset($c['PROFILE_VERIFIED']) ? (int)$c['PROFILE_VERIFIED'] : 0,
        );
    }

    private function loadPvArrays()
    {
        // PV arrays live in private config JSON; empty by default.
        if (!empty($this->config['PV_ARRAYS_JSON'])) {
            $a = json_decode($this->config['PV_ARRAYS_JSON'], true);
            return is_array($a) ? $a : array();
        }
        return array();
    }

    /**
     * Create the MajorDoMo "SolarPlanner" mirror object with the telemetry
     * properties the planner reads (CurrentSoc, PvPower, LoadPower, GridPower,
     * BatteryPower) plus the published plan properties. Idempotent.
     *
     * This gives a non-specialist owner a one-click way to get valid objects to
     * map against, without hand-writing JSON or creating objects manually.
     */
    function provisionMirrorObject()
    {
        $className = 'SolarPlanner';
        $objName = 'SolarPlanner';

        $classRec = SQLSelectOne("SELECT ID FROM classes WHERE TITLE='" . DBSafe($className) . "'");
        if (!$classRec['ID']) {
            $classRec = array('TITLE' => $className, 'DESCRIPTION' => 'Планувальник сонячної енергії');
            $classRec['ID'] = SQLInsert('classes', $classRec);
        }

        $objRec = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='" . DBSafe($objName) . "'");
        if (!$objRec['ID']) {
            $objRec = array('TITLE' => $objName, 'CLASS_ID' => $classRec['ID'], 'DESCRIPTION' => 'Solar Planner mirror');
            $objRec['ID'] = SQLInsert('objects', $objRec);
        }

        if (!function_exists('getObject')) {
            return array('success' => false, 'error' => 'getObject() недоступний у цьому середовищі.');
        }
        $obj = getObject($objName);
        if (!$obj) {
            return array('success' => false, 'error' => 'Не вдалося отримати обʼєкт ' . $objName);
        }

        // Telemetry mirror props (owner / another module fills these) + planner
        // outputs (the planner writes these).
        $props = array(
            'CurrentSoc' => '', 'PvPower' => '', 'LoadPower' => '', 'GridPower' => '', 'BatteryPower' => '',
            'Enabled' => '1', 'ControlMode' => 'shadow', 'Status' => 'observe',
            'TargetSocAt07' => '', 'PlannedGridChargeKwh' => '', 'PlannedNightImportKwh' => '',
            'ExpectedDayImportKwh' => '', 'ForecastPvTomorrowKwh' => '', 'ForecastLoadTomorrowKwh' => '',
            'PlanExplanation' => '',
        );
        $created = 0;
        foreach ($props as $pName => $pDef) {
            $cur = $obj->getProperty($pName);
            if ($cur === false || $cur === null) {
                $obj->setProperty($pName, $pDef);
                $created++;
            }
        }

        return array(
            'success' => true,
            'message' => 'Обʼєкт "SolarPlanner" готовий (' . $created . ' нових властивостей). Тепер у мапінгу можна вибрати SolarPlanner.* або натиснути «Використати дзеркальні обʼєкти».',
            'object' => $objName,
        );
    }

    /**
     * Build a full diagnostic report (environment self-check + per-provider
     * forecast attempt) without necessarily saving a plan. Used by the
     * Diagnostics tab and its "Re-check" button.
     */
    function runDiagnostics($force = false)
    {
        $this->getConfig();
        require_once(dirname(__FILE__) . '/lib/MajorDoMo/DiagnosticsService.php');
        require_once(dirname(__FILE__) . '/lib/MajorDoMo/SqlForecastStore.php');
        $cfg = is_array($this->config) ? $this->config : array();
        $diag = new \SolarPlanner\MajorDoMo\DiagnosticsService($cfg, $this->forecastCache(), $this->propGetter());
        $now = time();
        $store = new \SolarPlanner\MajorDoMo\SqlForecastStore();
        try {
            $collected = $diag->collect($now, $now + 44 * 3600, $store, $force);
        } catch (\Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage());
        }
        $usable = 0;
        foreach ($collected['providers'] as $p) {
            if ($p['status'] === 'fresh' || $p['status'] === 'partial') {
                $usable++;
            }
        }
        return array(
            'ok' => true,
            'usable_providers' => $usable,
            'providers' => $collected['providers'],
            'environment' => $collected['env'],
            'site' => $collected['site'],
        );
    }

    /**
     * Fetch the owner's Solcast rooftop resources using the saved API key and
     * auto-fill SOLCAST_RESOURCE_IDS, so the owner never has to find/paste the
     * resource id by hand. Nothing is printed except counts (no key leakage).
     */
    function fetchSolcastResources()
    {
        $this->getConfig();
        $key = isset($this->config['SOLCAST_API_KEY']) ? trim($this->config['SOLCAST_API_KEY']) : '';
        if ($key === '') {
            return array('success' => false, 'error' => 'Спочатку збережіть Solcast API key у налаштуваннях.');
        }
        require_once(dirname(__FILE__) . '/lib/Forecast/HttpClient.php');
        $http = new \SolarPlanner\Forecast\HttpClient(15, 1, false);
        $url = 'https://api.solcast.com.au/rooftop_sites?format=json';
        $guard = $http->guardUrl($url);
        if ($guard !== true) {
            return array('success' => false, 'error' => 'SSRF-захист: ' . $guard);
        }
        $res = $http->get($url, array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'));
        if ($res['status'] === 401 || $res['status'] === 403) {
            return array('success' => false, 'error' => 'Solcast відхилив ключ (HTTP ' . $res['status'] . '). Перевірте API key.');
        }
        if ($res['status'] !== 200) {
            return array('success' => false, 'error' => 'Solcast повернув HTTP ' . $res['status'] . '.');
        }
        $data = json_decode($res['body'], true);
        if (!isset($data['sites']) || !is_array($data['sites']) || empty($data['sites'])) {
            return array('success' => false, 'error' => 'За цим ключем не знайдено жодного rooftop site.');
        }
        $ids = array();
        $names = array();
        foreach ($data['sites'] as $s) {
            if (!empty($s['resource_id'])) {
                $ids[] = $s['resource_id'];
                $names[] = isset($s['name']) ? $s['name'] : $s['resource_id'];
            }
        }
        if (empty($ids)) {
            return array('success' => false, 'error' => 'Відповідь без resource_id.');
        }
        $this->config['SOLCAST_RESOURCE_IDS'] = implode(',', $ids);
        $this->config['SOLCAST_ENABLED'] = 1;
        $this->saveConfig();
        setGlobal('cycle_solarplannerControl', 'restart');
        return array(
            'success' => true,
            'message' => 'Знайдено ' . count($ids) . ' resource_id (' . htmlspecialchars(implode(', ', $names)) . '). Solcast увімкнено.',
        );
    }

    /**
     * A ForecastCache backed by MajorDoMo's `settings` table (durable KV), so
     * plan recomputes reuse the last forecast and do not spend provider quota.
     */
    public function forecastCache()
    {
        require_once(dirname(__FILE__) . '/lib/Forecast/ForecastCache.php');
        $maxAge = isset($this->config['CACHE_MAX_AGE_MIN']) ? (int)$this->config['CACHE_MAX_AGE_MIN'] * 60 : 6 * 3600;
        return new \SolarPlanner\Forecast\ForecastCache(
            function ($key) { return self::kvGet($key); },
            function ($key, $val) { self::kvSet($key, $val); },
            $maxAge
        );
    }

    /** Getter for MajorDoMo object properties (gg wrapper) for SolarWindows. */
    public function propGetter()
    {
        return function ($prop) {
            return function_exists('gg') ? gg($prop) : null;
        };
    }

    /** Durable KV read from the `settings` table (NAME/VALUE). */
    public static function kvGet($key)
    {
        if (!SQLSelectOne("SHOW TABLES LIKE 'settings'")) {
            return null;
        }
        $row = SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='" . DBSafe($key) . "'");
        return ($row && isset($row['VALUE'])) ? $row['VALUE'] : null;
    }

    /** Durable KV write to the `settings` table (NAME/VALUE). */
    public static function kvSet($key, $value)
    {
        if (!SQLSelectOne("SHOW TABLES LIKE 'settings'")) {
            return;
        }
        $row = SQLSelectOne("SELECT ID FROM settings WHERE NAME='" . DBSafe($key) . "'");
        // Note: SQLInsert() takes the record BY REFERENCE, so the array must be
        // a variable (PHP 7.3 fatals on passing a literal by reference).
        if ($row && isset($row['ID'])) {
            $rec = array('ID' => $row['ID'], 'NAME' => $key, 'VALUE' => $value);
            SQLUpdate('settings', $rec);
        } else {
            $rec = array('NAME' => $key, 'VALUE' => $value);
            SQLInsert('settings', $rec);
        }
    }

    /** List MajorDoMo objects (TITLE) for the picker dropdowns. */
    private function listObjects()
    {
        $rows = SQLSelect("SELECT TITLE FROM objects ORDER BY TITLE ASC");
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!empty($r['TITLE'])) {
                    $out[] = $r['TITLE'];
                }
            }
        }
        return $out;
    }

    /** AJAX helper: list property names of one object for the mapping picker. */
    private function listObjectProperties($objName)
    {
        if ($objName === '') {
            return array();
        }
        $objRec = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='" . DBSafe($objName) . "'");
        if (!$objRec || !$objRec['ID']) {
            return array();
        }
        $rows = SQLSelect("SELECT TITLE FROM properties WHERE OBJECT_ID=" . (int)$objRec['ID'] . " ORDER BY TITLE ASC");
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!empty($r['TITLE'])) {
                    $out[] = $r['TITLE'];
                }
            }
        }
        return $out;
    }

    /**
     * Localized, plain-language dictionary of every control/safety state for a
     * non-specialist homeowner. Text comes from the language files via LANG_*
     * constants, with safe fallbacks if a key is missing.
     *
     * Returns: code => ['name'=>..., 'desc'=>..., 'badge'=>bootstrap label class]
     */
    private function stateDictionary()
    {
        $L = function ($const, $fallback) {
            return defined($const) ? constant($const) : $fallback;
        };
        return array(
            'observe' => array(
                'name' => $L('LANG_SP_STATE_OBSERVE', 'Спостереження'),
                'desc' => $L('LANG_SP_STATE_OBSERVE_D', 'Модуль лише дивиться: читає дані лічильника й батареї, нічого не рахує для керування і нічого не змінює в інверторі. Найбезпечніший режим для першого знайомства.'),
                'badge' => 'label-default',
            ),
            'shadow' => array(
                'name' => $L('LANG_SP_STATE_SHADOW', 'Тіньовий режим'),
                'desc' => $L('LANG_SP_STATE_SHADOW_D', 'Модуль розраховує повний план (скільки докупити вночі, цільовий заряд на 07:00) і показує його вам, але НЕ надсилає жодних команд інвертору. Так можна тижнями перевіряти якість порад без ризику. Це режим за замовчуванням.'),
                'badge' => 'label-info',
            ),
            'approval_required' => array(
                'name' => $L('LANG_SP_STATE_APPROVAL', 'Потрібне підтвердження'),
                'desc' => $L('LANG_SP_STATE_APPROVAL_D', 'План готовий до застосування, але чекає вашого дозволу. Нічого не запишеться, доки ви вручну не підтвердите саме цей план.'),
                'badge' => 'label-warning',
            ),
            'armed' => array(
                'name' => $L('LANG_SP_STATE_ARMED', 'Активне керування'),
                'desc' => $L('LANG_SP_STATE_ARMED_D', 'Модулю дозволено записувати розклад Time of Use в інвертор — але лише після низки перевірок безпеки і з підтвердженим профілем пристрою. Вмикайте, коли переконались у плані в тіньовому режимі.'),
                'badge' => 'label-danger',
            ),
            'applying' => array(
                'name' => $L('LANG_SP_STATE_APPLYING', 'Застосування'),
                'desc' => $L('LANG_SP_STATE_APPLYING_D', 'Прямо зараз модуль записує новий розклад в інвертор. Короткочасний технічний стан.'),
                'badge' => 'label-primary',
            ),
            'verifying' => array(
                'name' => $L('LANG_SP_STATE_VERIFYING', 'Перевірка запису'),
                'desc' => $L('LANG_SP_STATE_VERIFYING_D', 'Модуль зчитує з інвертора те, що щойно записав, і звіряє з очікуваним. Успіхом вважається лише збіг, а не просто відсутність помилки звʼязку.'),
                'badge' => 'label-primary',
            ),
            'active' => array(
                'name' => $L('LANG_SP_STATE_ACTIVE', 'Працює'),
                'desc' => $L('LANG_SP_STATE_ACTIVE_D', 'Розклад успішно записано й підтверджено зчитуванням. Інвертор працює за планом модуля.'),
                'badge' => 'label-success',
            ),
            'degraded' => array(
                'name' => $L('LANG_SP_STATE_DEGRADED', 'Обмежена робота'),
                'desc' => $L('LANG_SP_STATE_DEGRADED_D', 'Щось пішло не так: застарілі дані, розбіжність після запису або недоступний провайдер. Модуль працює обережно й не виконує ризикованих записів, доки ситуація не виправиться.'),
                'badge' => 'label-warning',
            ),
            'blocked' => array(
                'name' => $L('LANG_SP_STATE_BLOCKED', 'Заблоковано'),
                'desc' => $L('LANG_SP_STATE_BLOCKED_D', 'Запис заборонено через безпеку: тривога BMS чи інвертора, неперевірений профіль пристрою або заборонене поле. Керування не відбуватиметься, доки причину не усунуто.'),
                'badge' => 'label-danger',
            ),
            'manual_override' => array(
                'name' => $L('LANG_SP_STATE_MANUAL', 'Ручне керування'),
                'desc' => $L('LANG_SP_STATE_MANUAL_D', 'Ви (або інший контролер) керуєте інвертором вручну. Модуль це поважає і не перезаписує ваші налаштування, доки ручний режим не завершиться.'),
                'badge' => 'label-default',
            ),
        );
    }

    /**
     * Run a planning pass synchronously, right now, so the UI can show the real
     * result (or the specific reason it failed) immediately — not just a flag
     * for the background cycle. Also nudges the cycle for the next scheduled run.
     */
    private function triggerPlan()
    {
        $this->getConfig();
        require_once(dirname(__FILE__) . '/lib/MajorDoMo/SqlForecastStore.php');
        require_once(dirname(__FILE__) . '/lib/MajorDoMo/DiagnosticsService.php');
        require_once(dirname(__FILE__) . '/lib/MajorDoMo/PlanRunner.php');

        $cfg = is_array($this->config) ? $this->config : array();
        require_once(dirname(__FILE__) . '/lib/Forecast/ForecastCache.php');
        $store = new \SolarPlanner\MajorDoMo\SqlForecastStore();
        $self = $this;

        try {
            $plan = \SolarPlanner\MajorDoMo\PlanRunner::run($cfg, $store, array(
                'cache' => $this->forecastCache(),
                'prop_getter' => $this->propGetter(),
                'force_fetch' => true, // manual "Run plan" => refresh now
                'get_soc' => function () {
                    $row = SQLSelectOne("SELECT BATTERY_SOC_PCT FROM solarplanner_telemetry WHERE BATTERY_SOC_PCT IS NOT NULL ORDER BY SAMPLE_UTC DESC LIMIT 1");
                    return ($row && $row['BATTERY_SOC_PCT'] !== null) ? (float)$row['BATTERY_SOC_PCT'] : null;
                },
                'get_history' => function () {
                    $since = time() - 336 * 3600;
                    $rows = SQLSelect("SELECT SAMPLE_UTC, LOAD_POWER_KW FROM solarplanner_telemetry WHERE SAMPLE_UTC >= $since AND LOAD_POWER_KW IS NOT NULL ORDER BY SAMPLE_UTC ASC");
                    $h = array();
                    if (is_array($rows)) {
                        foreach ($rows as $r) {
                            $h[] = array('utc' => (int)$r['SAMPLE_UTC'], 'load_kw' => (float)$r['LOAD_POWER_KW'], 'valid' => true);
                        }
                    }
                    return $h;
                },
                'publish' => function ($plan, $cfg) use ($self) {
                    $self->publishPlanProperties($plan, $cfg);
                },
            ));
        } catch (\Throwable $e) {
            return array('success' => false, 'message' => 'Помилка розрахунку: ' . $e->getMessage());
        }

        setGlobal('cycle_solarplannerControl', 'plan_now');

        $status = isset($plan['status']) ? $plan['status'] : 'unknown';
        if ($status === 'feasible') {
            $msg = 'План розраховано. TargetSocAt07 = ' . (isset($plan['target_soc_at_07']) ? $plan['target_soc_at_07'] : '?') . '%.';
        } elseif ($status === 'no_forecast') {
            $msg = 'Прогноз недоступний. Причина: ' . (isset($plan['reason']) ? $plan['reason'] : 'невідомо') . ' Деталі — на вкладці «Діагностика».';
        } elseif ($status === 'infeasible') {
            $msg = 'План нездійсненний за поточних обмежень. Деталі — на вкладці «План».';
        } else {
            $msg = 'Статус плану: ' . $status . '. Деталі — на вкладці «Діагностика».';
        }
        return array('success' => true, 'status' => $status, 'message' => $msg);
    }

    /**
     * Publish plan results to the SolarPlanner.* MajorDoMo properties (used by
     * the inline run; mirrors the cycle's publisher).
     */
    public function publishPlanProperties($plan, $cfg)
    {
        $set = function ($prop, $val) { setGlobal($prop, $val); };
        $set('SolarPlanner.Enabled', 1);
        $set('SolarPlanner.ControlMode', isset($cfg['CONTROL_MODE']) ? $cfg['CONTROL_MODE'] : 'shadow');
        $set('SolarPlanner.Status', isset($plan['status']) ? $plan['status'] : 'unknown');
        $set('SolarPlanner.LastPlanAt', date('Y-m-d H:i:s'));
        $map = array(
            'target_soc_at_07' => 'SolarPlanner.TargetSocAt07',
            'planned_grid_charge_kwh' => 'SolarPlanner.PlannedGridChargeKwh',
            'planned_night_import_kwh' => 'SolarPlanner.PlannedNightImportKwh',
            'expected_day_import_kwh' => 'SolarPlanner.ExpectedDayImportKwh',
            'pv_tomorrow_kwh' => 'SolarPlanner.ForecastPvTomorrowKwh',
            'pv_tomorrow_low_kwh' => 'SolarPlanner.ForecastPvTomorrowLowKwh',
            'pv_tomorrow_high_kwh' => 'SolarPlanner.ForecastPvTomorrowHighKwh',
            'load_tomorrow_kwh' => 'SolarPlanner.ForecastLoadTomorrowKwh',
        );
        foreach ($map as $k => $prop) {
            if (isset($plan[$k])) {
                $set($prop, $plan[$k]);
            }
        }
        if (isset($plan['explanation_obj']['text'])) {
            $set('SolarPlanner.PlanExplanation', $plan['explanation_obj']['text']);
        }
    }

    private function populateView(&$out)
    {
        $cfgArr = $this->configToArray();
        $blocking = Config::blockingParameters($cfgArr);
        $allowed = Config::allowedControlMode($cfgArr);

        $vm = $this->view_mode;
        if (isset($_GET['view_mode'])) {
            $vm = $_GET['view_mode'];
        }
        if (!$vm) {
            $vm = 'overview';
        }
        $out['VIEW_MODE'] = $vm;

        $controlMode = isset($this->config['CONTROL_MODE']) ? $this->config['CONTROL_MODE'] : 'shadow';
        $out['CONTROL_MODE']   = $controlMode;
        $out['ALLOWED_MODE']   = $allowed;
        $out['ARMED_BLOCKED']  = ($allowed !== 'armed') ? 1 : 0;

        // Friendly localized label + plain-language description for the states.
        $states = $this->stateDictionary();
        $out['CONTROL_MODE_LABEL'] = isset($states[$controlMode]) ? $states[$controlMode]['name'] : $controlMode;
        $out['CONTROL_MODE_DESC']  = isset($states[$controlMode]) ? $states[$controlMode]['desc'] : '';
        $out['ALLOWED_MODE_LABEL'] = isset($states[$allowed]) ? $states[$allowed]['name'] : $allowed;

        // Full reference table for the Safety tab. Precompute row class and the
        // "current" marker so the template needs no conditionals inside the loop.
        $nowWord = defined('LANG_SP_STATES_NOW') ? LANG_SP_STATES_NOW : 'current';
        $stateRows = array();
        foreach ($states as $code => $info) {
            $isCurrent = ($code === $controlMode);
            $stateRows[] = array(
                'CODE' => $code,
                'NAME' => htmlspecialchars($info['name']),
                'DESC' => htmlspecialchars($info['desc']),
                'BADGE' => $info['badge'],
                'ROWCLASS' => $isCurrent ? 'success' : '',
                'NOWMARK' => $isCurrent ? '<small class="text-success">&larr; ' . htmlspecialchars($nowWord) . '</small>' : '',
            );
        }
        $out['STATES'] = $stateRows;

        // Settings for the form. API keys are shown masked (never echo the real
        // key); coordinates ARE shown to the owner in their own protected
        // settings form (they are only kept out of Git/logs/snapshots).
        foreach ($this->config as $k => $v) {
            $out['CFG_' . $k] = is_scalar($v) ? htmlspecialchars((string)$v) : '';
        }
        $out['CFG_SOLCAST_API_KEY'] = !empty($this->config['SOLCAST_API_KEY']) ? '********' : '';
        $out['CFG_FORECAST_SOLAR_API_KEY'] = !empty($this->config['FORECAST_SOLAR_API_KEY']) ? '********' : '';
        $out['CFG_DEYE_SERIAL'] = !empty($this->config['DEYE_SERIAL']) ? '********' : '';

        // Blocking parameters list for the UI.
        $blkList = array();
        foreach ($blocking as $key => $label) {
            $blkList[] = array('KEY' => $key, 'LABEL' => htmlspecialchars($label));
        }
        $out['BLOCKING_PARAMS'] = $blkList;
        $out['HAS_BLOCKING'] = empty($blkList) ? 0 : 1;

        // Objects list for the property-map picker (as a JS array).
        $out['OBJECTS_JSON'] = json_encode($this->listObjects());

        // Current PV arrays (decoded) for the friendly builder, as JS data.
        $pvArrays = $this->loadPvArrays();
        $out['PV_ARRAYS_DATA_JSON'] = json_encode(array_values($pvArrays));

        // Current property map (decoded) for the picker, as JS data.
        $pmRaw = isset($this->config['PROPERTY_MAP_JSON']) ? $this->config['PROPERTY_MAP_JSON'] : '';
        $pm = $pmRaw ? json_decode($pmRaw, true) : array();
        $out['PROPERTY_MAP_DATA_JSON'] = json_encode(is_array($pm) ? $pm : array());

        // Whether the SolarPlanner mirror object exists already.
        $mirror = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='SolarPlanner'");
        $out['MIRROR_EXISTS'] = ($mirror && $mirror['ID']) ? 1 : 0;

        // Last planning run.
        if (SQLSelectOne("SHOW TABLES LIKE 'solarplanner_planning_runs'")) {
            $last = SQLSelectOne("SELECT * FROM solarplanner_planning_runs ORDER BY ID DESC LIMIT 1");
            if ($last && $last['ID']) {
                $out['LAST_PLAN_STATUS']      = htmlspecialchars($last['STATUS']);
                $out['LAST_PLAN_TARGET_SOC']  = round((float)$last['TARGET_SOC_AT_07'], 1);
                $out['LAST_PLAN_NIGHT_KWH']   = round((float)$last['NIGHT_IMPORT_KWH'], 2);
                $out['LAST_PLAN_DAY_KWH']     = round((float)$last['DAY_IMPORT_KWH'], 2);
                $out['LAST_PLAN_PV_KWH']      = round((float)$last['PV_TOMORROW_KWH'], 2);
                $out['LAST_PLAN_LOAD_KWH']    = round((float)$last['LOAD_TOMORROW_KWH'], 2);
                $out['LAST_PLAN_AT']          = date('Y-m-d H:i', (int)$last['PLAN_UTC']);
                $payload = json_decode($last['PAYLOAD'], true);
                if (isset($payload['explanation_obj']['text'])) {
                    $out['LAST_PLAN_EXPLANATION'] = htmlspecialchars($payload['explanation_obj']['text']);
                }
            }
        }

        // Current telemetry mirror (from MajorDoMo properties, if mapped).
        $out['SP_SOC']  = gg('SolarPlanner.CurrentSoc');
        $out['SP_PV']   = gg('SolarPlanner.PvPower');
        $out['SP_LOAD'] = gg('SolarPlanner.LoadPower');

        // Cycle liveness.
        $cycleRun = (int)gg('cycle_solarplannerRun');
        $out['CYCLE_ALIVE'] = (time() - $cycleRun) < 120 ? 1 : 0;
        $out['CYCLE_TIME']  = $cycleRun > 0 ? date('Y-m-d H:i:s', $cycleRun) : 'Ніколи';

        // Diagnostics tab: run the self-check and flatten it for the template.
        if ($vm === 'diagnostics') {
            $this->populateDiagnostics($out);
        }
    }

    /** Flatten the diagnostic report into template-friendly rows. */
    private function populateDiagnostics(&$out)
    {
        $force = !empty($_GET['force']);
        $rep = $this->runDiagnostics($force);
        if (empty($rep['ok'])) {
            $out['DIAG_ERROR'] = htmlspecialchars(isset($rep['error']) ? $rep['error'] : 'unknown');
            return;
        }
        $env = $rep['environment'];

        // Environment checks as a list of [label, ok(1/0), value].
        $checks = array(
            array('Конфігурацію завантажено', $env['config_loaded'], $env['config_keys'] . ' ключів'),
            array('Увімкнено провайдер(и)', $env['any_provider_enabled'], $env['any_provider_enabled'] ? 'так' : 'ні'),
            array('Координати задано', $env['coordinates_set'], $env['coordinates_source']),
            array('PV-масиви задано', $env['pv_arrays_set'], $env['pv_arrays_set'] ? 'config' : 'заглушка'),
            array('Вихідний HTTPS', $env['outbound_https'], $env['outbound_https_detail']),
            array('cURL доступний', $env['curl_available'], $env['curl_available'] ? 'так' : 'ні (fallback streams)'),
            array('OpenSSL доступний', $env['openssl_available'], $env['openssl_available'] ? 'так' : 'ні'),
            array('Device profile перевірено', $env['profile_verified'], $env['profile_verified'] ? 'так' : 'ні (armed заблоковано)'),
        );
        $rows = array();
        foreach ($checks as $c) {
            $rows[] = array(
                'LABEL' => htmlspecialchars($c[0]),
                'OK' => $c[1] ? 1 : 0,
                'BADGE' => $c[1] ? 'label-success' : 'label-warning',
                'MARK' => $c[1] ? 'OK' : 'УВАГА',
                'VALUE' => htmlspecialchars((string)$c[2]),
            );
        }
        $out['DIAG_CHECKS'] = $rows;

        // Provider rows.
        $prov = array();
        $labels = array('open_meteo' => 'Open-Meteo', 'forecast_solar' => 'Forecast.Solar', 'solcast' => 'Solcast');
        $tz = !empty($this->config['TIMEZONE']) ? $this->config['TIMEZONE'] : 'Europe/Kyiv';
        foreach ($rep['providers'] as $code => $p) {
            $ok = in_array($p['status'], array('fresh', 'partial', 'cached'), true);
            $badge = $ok ? ($p['status'] === 'cached' ? 'label-info' : 'label-success')
                : ($p['status'] === 'disabled' ? 'label-default' : 'label-danger');
            $src = isset($p['source']) ? $p['source'] : '';
            $next = '';
            if (!empty($p['next_fetch_utc'])) {
                try {
                    $next = (new DateTime('@' . (int)$p['next_fetch_utc']))->setTimezone(new DateTimeZone($tz))->format('H:i');
                } catch (\Exception $e) {
                    $next = '';
                }
            }
            $prov[] = array(
                'NAME' => isset($labels[$code]) ? $labels[$code] : $code,
                'CODE' => $code,
                'STATUS' => htmlspecialchars($p['status']),
                'BADGE' => $badge,
                'DETAIL' => htmlspecialchars($p['detail']),
                'TOTAL' => ($p['total_kwh'] === null ? '—' : $p['total_kwh'] . ' кВт·год'),
                'INTERVALS' => (int)$p['intervals'],
                'SOURCE' => htmlspecialchars($src),
                'NEXT' => htmlspecialchars($next),
            );
        }
        $out['DIAG_PROVIDERS'] = $prov;
        $out['DIAG_USABLE'] = (int)$rep['usable_providers'];

        // Sun / schedule summary line.
        if (isset($rep['environment']['sun'])) {
            $sun = $rep['environment']['sun'];
            $fmt = function ($utc) use ($tz) {
                try {
                    return (new DateTime('@' . (int)$utc))->setTimezone(new DateTimeZone($tz))->format('H:i');
                } catch (\Exception $e) {
                    return '?';
                }
            };
            $out['DIAG_SUN'] = 'Світанок ' . $fmt($sun['sunrise_utc']) . ', захід ' . $fmt($sun['sunset_utc'])
                . ' · дешеве вікно ' . $fmt($sun['night_start_utc']) . '–' . $fmt($sun['night_end_utc'])
                . ' · критичне уточнення до ' . $fmt($sun['critical_refine_utc'])
                . ' · джерело: ' . ($sun['sun_source'] === 'properties' ? 'ThisComputer.SunRise/SunSet' : 'оцінка');
        }
    }
}
