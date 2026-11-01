<?php
/**
 * Config — load module configuration and report blocking parameters.
 *
 * With an incomplete configuration, only observe/shadow is allowed and the list
 * of blocking parameters is shown. A zero reserve is never a silent production
 * default.
 *
 * Secrets (API keys, coordinates, IPs, serials) are kept private and masked in
 * any output.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\MajorDoMo;

class Config
{
    /** Parameters that block armed mode until confirmed. */
    public static function blockingParameters(array $cfg)
    {
        $blocking = array();
        // These gate ARMED (real inverter writes). With the shipped defaults most
        // are already satisfied, so a fresh install runs in shadow immediately;
        // only the genuinely site/hardware-specific items remain to confirm.
        $need = array(
            'battery_count' => 'Кількість батарей (підтвердити 2/3)',
            'battery_capacity_kwh' => 'Доступна ємність (підтвердити за паспортом/BMS)',
            'reserve_soc_pct' => 'Аварійний резерв',
            'tariff_cheap_price' => 'Ціна пільгового тарифу',
            'tariff_day_price' => 'Ціна денного тарифу',
            'transport' => 'Транспорт Deye',
            'firmware' => 'Прошивка інвертора (для device profile)',
            'property_map' => 'Мапінг властивостей MajorDoMo',
            'pv_arrays' => 'PV-масиви (kWp, нахил, азимут)',
            'profile_verified' => 'Перевірений device profile (обовʼязково для armed)',
        );
        foreach ($need as $key => $label) {
            if (!isset($cfg[$key]) || $cfg[$key] === '' || $cfg[$key] === null
                || ($key === 'pv_arrays' && empty($cfg[$key]))
                || ($key === 'profile_verified' && empty($cfg[$key]))) {
                $blocking[$key] = $label;
            }
        }
        // Reserve must be explicitly set and > 0 (no silent zero default).
        if (isset($cfg['reserve_soc_pct']) && (float)$cfg['reserve_soc_pct'] <= 0) {
            $blocking['reserve_soc_pct'] = 'Аварійний резерв не може бути 0 за замовчуванням';
        }
        return $blocking;
    }

    /** Highest control mode allowed by the current configuration. */
    public static function allowedControlMode(array $cfg)
    {
        $blocking = self::blockingParameters($cfg);
        if (!empty($blocking)) {
            return 'shadow';
        }
        return 'armed';
    }

    /** Mask secret-ish values for display/logging. */
    public static function maskSecrets(array $cfg)
    {
        $masked = $cfg;
        $secretKeys = array('solcast_api_key', 'forecast_solar_api_key', 'lat', 'lon',
            'latitude', 'longitude', 'inverter_ip', 'inverter_serial', 'telegram_token');
        foreach ($secretKeys as $k) {
            if (isset($masked[$k]) && $masked[$k] !== '') {
                $masked[$k] = '***';
            }
        }
        return $masked;
    }
}
