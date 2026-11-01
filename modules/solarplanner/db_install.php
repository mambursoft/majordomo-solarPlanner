<?php
/**
 * db_install.php for Solar Planner module.
 * Mirrors dbInstall() in solarplanner.class.php. Re-safe: parent::dbInstall
 * only adds missing tables/columns and never drops history.
 */

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
