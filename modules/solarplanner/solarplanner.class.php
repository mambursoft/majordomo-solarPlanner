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
 * @version    1.0.0
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
                    'CURRENT_VERSION' => '1.0.0',
                    'LATEST_VERSION'  => '1.0.0',
                    'IS_INSTALLED'    => 1,
                    'LATEST_UPDATE'   => date('Y-m-d H:i:s'),
                );
                SQLInsert('plugins', $pRec);
            } else {
                $pRec['TITLE']          = $this->title;
                $pRec['AUTHOR']         = 'mambursoft';
                $pRec['DESCRIPTION_RU'] = $descRu;
                $pRec['DESCRIPTION_EN'] = $descEn;
                $pRec['CURRENT_VERSION']= '1.0.0';
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
        // Safe defaults. Reserve is intentionally NOT 0; armed stays blocked
        // until the owner confirms the open parameters.
        $defaults = array(
            'CONTROL_MODE'         => 'shadow',   // observe | shadow | armed (never armed by default)
            'TIMEZONE'             => 'Europe/Kyiv',
            'GRID_STEP_MIN'        => 30,
            'MODE'                 => 'reach_full',
            'CHEAP_START_HOUR'     => 23,
            'CHEAP_END_HOUR'       => 7,
            'RESERVE_SOC_PCT'      => 20,
            'BATTERY_COUNT'        => 0,
            'BATTERY_CAPACITY_KWH' => 0,
            'MAX_SOC_PCT'          => 100,
            'MIN_SOC_PCT'          => 10,
            'MAX_CHARGE_KW'        => 5,
            'MAX_DISCHARGE_KW'     => 5,
            'ETA_CHARGE'           => 0.95,
            'ETA_DISCHARGE'        => 0.95,
            'TARIFF_CHEAP_PRICE'   => 0,
            'TARIFF_DAY_PRICE'     => 0,
            'ALLOW_EXPORT'         => 0,
            'SOC_STEP_PCT'         => 5,
            'ENSEMBLE_METHOD'      => 'median',
            'PROFILE_VERIFIED'     => 0,
            'TRANSPORT'            => '',
            'FIRMWARE'             => '',
            'LATITUDE'             => '',
            'LONGITUDE'            => '',
            'OPEN_METEO_ENABLED'   => 1,
            'FORECAST_SOLAR_ENABLED' => 0,
            'SOLCAST_ENABLED'      => 0,
            'SOLCAST_API_KEY'      => '',
            'FORECAST_SOLAR_API_KEY' => '',
            'SOLCAST_RESERVED_UNITS' => 2,
            'DATA_DIR'             => '',
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
            'SOLCAST_API_KEY' => 'solcast_api_key', 'FORECAST_SOLAR_API_KEY' => 'forecast_solar_api_key',
        );
        foreach ($map as $cfgKey => $postKey) {
            if (isset($_POST[$postKey])) {
                $this->config[$cfgKey] = trim($_POST[$postKey]);
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
            'property_map' => SQLSelectOne("SHOW TABLES LIKE 'solarplanner_telemetry'") ? 'db' : '',
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
     * Trigger a planning run (shadow calculation; never writes in shadow mode).
     * Delegates heavy work to the cycle via a control flag, and also computes a
     * quick plan inline for the UI when enough config exists.
     */
    private function triggerPlan()
    {
        setGlobal('cycle_solarplannerControl', 'plan_now');
        return array('success' => true, 'message' => 'Запит на розрахунок плану відправлено циклу.');
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

        $out['CONTROL_MODE']   = isset($this->config['CONTROL_MODE']) ? $this->config['CONTROL_MODE'] : 'shadow';
        $out['ALLOWED_MODE']   = $allowed;
        $out['ARMED_BLOCKED']  = ($allowed !== 'armed') ? 1 : 0;

        // Settings (secrets masked for display).
        foreach ($this->config as $k => $v) {
            $out['CFG_' . $k] = is_scalar($v) ? htmlspecialchars((string)$v) : '';
        }
        $out['CFG_SOLCAST_API_KEY'] = !empty($this->config['SOLCAST_API_KEY']) ? '********' : '';
        $out['CFG_FORECAST_SOLAR_API_KEY'] = !empty($this->config['FORECAST_SOLAR_API_KEY']) ? '********' : '';
        $out['CFG_LATITUDE'] = !empty($this->config['LATITUDE']) ? '***' : '';
        $out['CFG_LONGITUDE'] = !empty($this->config['LONGITUDE']) ? '***' : '';

        // Blocking parameters list for the UI.
        $blkList = array();
        foreach ($blocking as $key => $label) {
            $blkList[] = array('KEY' => $key, 'LABEL' => htmlspecialchars($label));
        }
        $out['BLOCKING_PARAMS'] = $blkList;
        $out['HAS_BLOCKING'] = empty($blkList) ? 0 : 1;

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
    }
}
