<?php
/**
 * Default (English) language file for Solar Planner module.
 */

$dictionary = array(
    'SP_TITLE'            => 'Solar Planner',
    'SP_OVERVIEW'         => 'Overview',
    'SP_PLAN'             => 'Plan',
    'SP_PROVIDERS'        => 'Providers',
    'SP_INSTALLATION'     => 'Installation',
    'SP_DEYE'             => 'Deye / TOU',
    'SP_SAFETY'           => 'Safety',
    'SP_SETTINGS'         => 'Settings',
    'SP_CONTROL_MODE'     => 'Control mode',
    'SP_ALLOWED_MODE'     => 'Allowed mode',
    'SP_ARMED_BLOCKED'    => 'Armed mode is blocked: not all required parameters are confirmed',
    'SP_CYCLE'            => 'Cycle',
    'SP_BLOCKING_TITLE'   => 'Required parameters are missing',
    'SP_BLOCKING_NOTE'    => 'Until confirmed only observe/shadow is available. Reserve must not default to 0.',
    'SP_TARGET_SOC'       => 'TargetSocAt07',
    'SP_PV_TOMORROW'      => 'PV forecast tomorrow',
    'SP_LOAD_TOMORROW'    => 'Load forecast',
    'SP_NIGHT_IMPORT'     => 'Night import',
    'SP_DAY_IMPORT'       => 'Day import',
    'SP_EXPLANATION'      => 'Plan explanation',
    'SP_RUN_PLAN'         => 'Run plan',
    'SP_SHADOW_HINT'      => 'In shadow mode the plan is only computed; no inverter write is performed.',
    'SP_LIVE'             => 'Live data',
    'SP_LOAD'             => 'Load',
    'SP_PROVIDERS_NOTE'   => 'Each provider is independent. One outage does not block the planner. Quotas are tracked separately.',
    'SP_DEYE_NOTE'        => 'Deye integration requires a verified device profile. Writing is disabled by default.',
    'SP_DEYE_SLOTS'       => 'The SG05LP3 series is confirmed to have 6 TOU periods (not 7).',
    'SP_DEYE_TRANSPORT'   => 'TCP 8899 (logger) and Modbus TCP 502 are different protocols.',
    'SP_DEYE_VERIFY'      => 'Register addresses are filled only from a verified source.',
    'SP_SAFETY_NOTE'      => 'States: observe, shadow, approval_required, armed, applying, verifying, active, degraded, blocked, manual_override. Armed needs approval and a verified profile.',
    'SP_SET_MODE'         => 'Set mode',
    'SP_INSTALL_NOTE'     => 'See docs/installation.md. On RPi4 move logs and sessions to tmpfs and run the cycle under a supervisor.',
    'SP_TIMEZONE'         => 'Timezone (IANA)',
    'SP_GRID_STEP'        => 'Grid step (min)',
    'SP_MODE'             => 'Planner mode',
    'SP_ENSEMBLE'         => 'Ensemble method',
    'SP_CHEAP_START'      => 'Cheap start (h)',
    'SP_CHEAP_END'        => 'Cheap end (h)',
    'SP_CHEAP_PRICE'      => 'Cheap price',
    'SP_DAY_PRICE'        => 'Day price',
    'SP_RESERVE'          => 'Reserve SOC %',
    'SP_SOC_STEP'         => 'SOC step %',
    'SP_BAT_COUNT'        => 'Battery count',
    'SP_BAT_CAP'          => 'Capacity kWh',
    'SP_MIN_SOC'          => 'Min SOC %',
    'SP_MAX_SOC'          => 'Max SOC %',
    'SP_MAX_CH'           => 'Max charge kW',
    'SP_MAX_DIS'          => 'Max discharge kW',
    'SP_ALLOW_EXPORT'     => 'Allow export',
    'SP_PROFILE_VERIFIED' => 'Device profile verified',
    'SP_SAVE'             => 'Save',
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
