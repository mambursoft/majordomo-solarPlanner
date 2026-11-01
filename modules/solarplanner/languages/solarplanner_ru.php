<?php
/**
 * Russian language file for Solar Planner module.
 */

$dictionary = array(
    'SP_TITLE'            => 'Планировщик солнца',
    'SP_OVERVIEW'         => 'Обзор',
    'SP_PLAN'             => 'План',
    'SP_PROVIDERS'        => 'Провайдеры',
    'SP_INSTALLATION'     => 'Установка',
    'SP_DEYE'             => 'Deye / TOU',
    'SP_SAFETY'           => 'Безопасность',
    'SP_SETTINGS'         => 'Настройки',
    'SP_CONTROL_MODE'     => 'Режим управления',
    'SP_ALLOWED_MODE'     => 'Разрешённый режим',
    'SP_ARMED_BLOCKED'    => 'Режим armed заблокирован: не все обязательные параметры подтверждены',
    'SP_CYCLE'            => 'Цикл',
    'SP_BLOCKING_TITLE'   => 'Обязательные параметры не заполнены',
    'SP_BLOCKING_NOTE'    => 'До подтверждения доступны только observe/shadow. Резерв не может быть 0 по умолчанию.',
    'SP_TARGET_SOC'       => 'TargetSocAt07',
    'SP_PV_TOMORROW'      => 'Прогноз PV на завтра',
    'SP_LOAD_TOMORROW'    => 'Прогноз нагрузки',
    'SP_NIGHT_IMPORT'     => 'Ночной импорт',
    'SP_DAY_IMPORT'       => 'Дневной импорт',
    'SP_EXPLANATION'      => 'Объяснение плана',
    'SP_RUN_PLAN'         => 'Рассчитать план',
    'SP_SHADOW_HINT'      => 'В shadow mode план только рассчитывается; запись в инвертор не выполняется.',
    'SP_LIVE'             => 'Текущие данные',
    'SP_LOAD'             => 'Нагрузка',
    'SP_PROVIDERS_NOTE'   => 'Каждый провайдер независим. Отказ одного не блокирует планировщик. Квоты учитываются отдельно.',
    'SP_DEYE_NOTE'        => 'Интеграция с Deye требует проверенного device profile. Запись по умолчанию запрещена.',
    'SP_DEYE_SLOTS'       => 'Серия SG05LP3 подтверждённо имеет 6 TOU-периодов (не 7).',
    'SP_DEYE_TRANSPORT'   => 'TCP 8899 (логгер) и Modbus TCP 502 — разные протоколы.',
    'SP_DEYE_VERIFY'      => 'Адреса регистров заполняются только из проверенного источника.',
    'SP_SAFETY_NOTE'      => 'Состояния: observe, shadow, approval_required, armed, applying, verifying, active, degraded, blocked, manual_override. Armed требует подтверждения и проверенного профиля.',
    'SP_SET_MODE'         => 'Установить режим',
    'SP_INSTALL_NOTE'     => 'См. docs/installation.md. На RPi4 вынесите логи и сессии в tmpfs и запускайте цикл под supervisor.',
    'SP_TIMEZONE'         => 'Часовой пояс (IANA)',
    'SP_GRID_STEP'        => 'Шаг сетки (мин)',
    'SP_MODE'             => 'Режим планировщика',
    'SP_ENSEMBLE'         => 'Метод ансамбля',
    'SP_CHEAP_START'      => 'Начало льготы (ч)',
    'SP_CHEAP_END'        => 'Конец льготы (ч)',
    'SP_CHEAP_PRICE'      => 'Цена льготы',
    'SP_DAY_PRICE'        => 'Цена дня',
    'SP_RESERVE'          => 'Резерв SOC %',
    'SP_SOC_STEP'         => 'Шаг SOC %',
    'SP_BAT_COUNT'        => 'Кол-во батарей',
    'SP_BAT_CAP'          => 'Ёмкость кВт·ч',
    'SP_MIN_SOC'          => 'Мин SOC %',
    'SP_MAX_SOC'          => 'Макс SOC %',
    'SP_MAX_CH'           => 'Макс заряд кВт',
    'SP_MAX_DIS'          => 'Макс разряд кВт',
    'SP_ALLOW_EXPORT'     => 'Разрешить экспорт',
    'SP_PROFILE_VERIFIED' => 'Device profile проверен',
    'SP_SAVE'             => 'Сохранить',
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
