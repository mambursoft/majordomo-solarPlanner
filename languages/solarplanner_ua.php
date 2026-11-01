<?php
/**
 * Ukrainian language file for Solar Planner module.
 */

$dictionary = array(
    'SP_TITLE'            => 'Планувальник сонця',
    'SP_OVERVIEW'         => 'Огляд',
    'SP_PLAN'             => 'План',
    'SP_PROVIDERS'        => 'Провайдери',
    'SP_INSTALLATION'     => 'Установка',
    'SP_DEYE'             => 'Deye / TOU',
    'SP_SAFETY'           => 'Безпека',
    'SP_SETTINGS'         => 'Налаштування',
    'SP_CONTROL_MODE'     => 'Режим керування',
    'SP_ALLOWED_MODE'     => 'Дозволений режим',
    'SP_ARMED_BLOCKED'    => 'Режим armed заблоковано: не всі обов\'язкові параметри підтверджені',
    'SP_CYCLE'            => 'Цикл',
    'SP_BLOCKING_TITLE'   => 'Обов\'язкові параметри не заповнені',
    'SP_BLOCKING_NOTE'    => 'До їх підтвердження доступні лише observe/shadow. Резерв не може бути 0 за замовчуванням.',
    'SP_TARGET_SOC'       => 'TargetSocAt07',
    'SP_PV_TOMORROW'      => 'Прогноз PV на завтра',
    'SP_LOAD_TOMORROW'    => 'Прогноз навантаження',
    'SP_NIGHT_IMPORT'     => 'Нічний імпорт',
    'SP_DAY_IMPORT'       => 'Денний імпорт',
    'SP_EXPLANATION'      => 'Пояснення плану',
    'SP_RUN_PLAN'         => 'Розрахувати план',
    'SP_SHADOW_HINT'      => 'У shadow mode план лише розраховується; запис в інвертор не виконується.',
    'SP_LIVE'             => 'Поточні дані',
    'SP_LOAD'             => 'Навантаження',
    'SP_PROVIDERS_NOTE'   => 'Кожен провайдер незалежний. Відмова одного не блокує планувальник. Квоти враховуються окремо.',
    'SP_DEYE_NOTE'        => 'Інтеграція з Deye вимагає перевіреного device profile. За замовчуванням запис заборонено.',
    'SP_DEYE_SLOTS'       => 'Серія SG05LP3 підтверджено має 6 TOU-періодів (не 7).',
    'SP_DEYE_TRANSPORT'   => 'TCP 8899 (логер) і Modbus TCP 502 — різні протоколи.',
    'SP_DEYE_VERIFY'      => 'Адреси регістрів заповнюються лише з перевіреного джерела.',
    'SP_SAFETY_NOTE'      => 'Стани: observe, shadow, approval_required, armed, applying, verifying, active, degraded, blocked, manual_override. Armed потребує підтвердження та перевіреного профілю.',
    'SP_SET_MODE'         => 'Встановити режим',
    'SP_INSTALL_NOTE'     => 'Див. docs/installation.md. Для RPi4 винести лог і сесії в tmpfs, запускати цикл під supervisor.',
    'SP_TIMEZONE'         => 'Часовий пояс (IANA)',
    'SP_GRID_STEP'        => 'Крок сітки (хв)',
    'SP_MODE'             => 'Режим планувальника',
    'SP_ENSEMBLE'         => 'Метод ансамблю',
    'SP_CHEAP_START'      => 'Початок пільги (год)',
    'SP_CHEAP_END'        => 'Кінець пільги (год)',
    'SP_CHEAP_PRICE'      => 'Ціна пільги',
    'SP_DAY_PRICE'        => 'Ціна дня',
    'SP_RESERVE'          => 'Резерв SOC %',
    'SP_SOC_STEP'         => 'Крок SOC %',
    'SP_BAT_COUNT'        => 'К-сть батарей',
    'SP_BAT_CAP'          => 'Ємність кВт·год',
    'SP_MIN_SOC'          => 'Мін SOC %',
    'SP_MAX_SOC'          => 'Макс SOC %',
    'SP_MAX_CH'           => 'Макс заряд кВт',
    'SP_MAX_DIS'          => 'Макс розряд кВт',
    'SP_ALLOW_EXPORT'     => 'Дозволити експорт',
    'SP_PROFILE_VERIFIED' => 'Device profile перевірено',
    'SP_SAVE'             => 'Зберегти',
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
