<?php
/**
 * DiagnosticsService — one place that gathers forecasts AND explains, step by
 * step, why a forecast is or is not available.
 *
 * The cycle and the UI "Run plan / Diagnose" button both use this, so the owner
 * always sees the same, specific reason instead of a bare "no_forecast".
 *
 * It is deliberately defensive: it never throws on a provider failure, it
 * records a human-readable reason for every provider, it checks the environment
 * (config present? coordinates set? PV arrays set? outbound HTTPS working?
 * curl available?) and returns a structured report.
 *
 * No secrets are placed in the report (coordinates are reported only as
 * "set"/"not set", keys as "set"/"missing").
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\MajorDoMo;

use SolarPlanner\Forecast\HttpClient;
use SolarPlanner\Forecast\QuotaManager;
use SolarPlanner\Forecast\PvModel;
use SolarPlanner\Forecast\OpenMeteoAdapter;
use SolarPlanner\Forecast\ForecastSolarAdapter;
use SolarPlanner\Forecast\SolcastAdapter;

class DiagnosticsService
{
    /** @var array module config (as stored) */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Resolve the effective site (coordinates + PV arrays) and report whether
     * they came from config or from a fallback.
     *
     * @return array ['lat','lon','arrays','tz','lat_set','lon_set','arrays_set','arrays_source']
     */
    public function resolveSite()
    {
        $cfg = $this->cfg;
        $tz = !empty($cfg['TIMEZONE']) ? $cfg['TIMEZONE'] : 'Europe/Kyiv';

        $latSet = isset($cfg['LATITUDE']) && $cfg['LATITUDE'] !== '' && is_numeric($cfg['LATITUDE']);
        $lonSet = isset($cfg['LONGITUDE']) && $cfg['LONGITUDE'] !== '' && is_numeric($cfg['LONGITUDE']);

        $arrays = array();
        if (!empty($cfg['PV_ARRAYS_JSON'])) {
            $decoded = json_decode($cfg['PV_ARRAYS_JSON'], true);
            if (is_array($decoded) && !empty($decoded)) {
                $arrays = $decoded;
            }
        }
        $arraysSet = !empty($arrays);
        if (!$arraysSet) {
            // Representative placeholder so a read-only run still produces a
            // plausible curve; flagged so the UI can warn it is not real config.
            $arrays = array(array(
                'array_id' => 'a1', 'kwp' => 3.2, 'tilt_deg' => 40,
                'azimuth_module_deg' => 180, 'dc_loss' => 0.14, 'temp_coeff_pct_per_c' => -0.004,
            ));
        }

        return array(
            'lat' => $latSet ? (float)$cfg['LATITUDE'] : 50.45,   // Kyiv fallback
            'lon' => $lonSet ? (float)$cfg['LONGITUDE'] : 30.52,
            'arrays' => $arrays,
            'tz' => $tz,
            'lat_set' => $latSet,
            'lon_set' => $lonSet,
            'arrays_set' => $arraysSet,
            'arrays_source' => $arraysSet ? 'config' : 'fallback',
        );
    }

    /**
     * Collect forecasts from every enabled provider, with a per-provider
     * diagnostic line. Also saves each successful run via the store if given.
     *
     * @param int $fromUtc
     * @param int $toUtc
     * @param object|null $store object exposing saveForecastRun(array) (optional)
     * @return array ['runs'=>[...], 'providers'=>[ code => ['enabled','status','detail','intervals','total_kwh'] ], 'site'=>..., 'env'=>...]
     */
    public function collect($fromUtc, $toUtc, $store = null)
    {
        $cfg = $this->cfg;
        $site = $this->resolveSite();
        $env = $this->environment($fromUtc, $toUtc, $site);

        $http = new HttpClient(15, 2, false);
        $quota = new QuotaManager();
        $pv = new PvModel($site['arrays'], 0.0);

        $runs = array();
        $providers = array();

        // ---- Open-Meteo ----
        $omEnabled = !empty($cfg['OPEN_METEO_ENABLED']);
        if ($omEnabled) {
            $a = new OpenMeteoAdapter($http, $quota, $pv, $site, 'open_meteo');
            $r = $a->fetch($fromUtc, $toUtc, false);
            $providers['open_meteo'] = $this->providerRow(true, $r, $store, $runs, 'open-meteo');
        } else {
            $providers['open_meteo'] = array('enabled' => 0, 'status' => 'disabled',
                'detail' => 'Провайдер вимкнено в налаштуваннях', 'total_kwh' => null, 'intervals' => 0);
        }

        // ---- Forecast.Solar ----
        $fsEnabled = !empty($cfg['FORECAST_SOLAR_ENABLED']);
        if ($fsEnabled) {
            $key = isset($cfg['FORECAST_SOLAR_API_KEY']) ? $cfg['FORECAST_SOLAR_API_KEY'] : null;
            $a = new ForecastSolarAdapter($http, $quota, $site, $key ?: null, 'forecast_solar');
            $r = $a->fetch($fromUtc, $toUtc, false);
            $providers['forecast_solar'] = $this->providerRow(true, $r, $store, $runs, 'forecast_solar');
        } else {
            $providers['forecast_solar'] = array('enabled' => 0, 'status' => 'disabled',
                'detail' => 'Провайдер вимкнено в налаштуваннях', 'total_kwh' => null, 'intervals' => 0);
        }

        // ---- Solcast ----
        $scEnabled = !empty($cfg['SOLCAST_ENABLED']);
        if ($scEnabled) {
            $keyOk = !empty($cfg['SOLCAST_API_KEY']);
            $idsOk = !empty($cfg['SOLCAST_RESOURCE_IDS']);
            if (!$keyOk || !$idsOk) {
                $miss = array();
                if (!$keyOk) { $miss[] = 'API-ключ'; }
                if (!$idsOk) { $miss[] = 'resource id'; }
                $providers['solcast'] = array('enabled' => 1, 'status' => 'misconfigured',
                    'detail' => 'Увімкнено, але не задано: ' . implode(', ', $miss),
                    'total_kwh' => null, 'intervals' => 0);
            } else {
                $ids = array_filter(array_map('trim', explode(',', $cfg['SOLCAST_RESOURCE_IDS'])));
                $a = new SolcastAdapter($http, $quota, $ids, $cfg['SOLCAST_API_KEY'], 'solcast');
                $r = $a->fetch($fromUtc, $toUtc, false);
                $providers['solcast'] = $this->providerRow(true, $r, $store, $runs, 'solcast');
            }
        } else {
            $providers['solcast'] = array('enabled' => 0, 'status' => 'disabled',
                'detail' => 'Провайдер вимкнено в налаштуваннях', 'total_kwh' => null, 'intervals' => 0);
        }

        return array(
            'runs' => $runs,
            'providers' => $providers,
            'site' => $site,
            'env' => $env,
        );
    }

    /** Build a provider diagnostic row and collect a usable run. */
    private function providerRow($enabled, array $r, $store, array &$runs, $weatherModel)
    {
        $status = isset($r['status']) ? $r['status'] : 'unusable';
        $detail = isset($r['detail']) ? $r['detail'] : '';
        $total = null;
        $intervals = 0;
        if (!empty($r['run'])) {
            $total = round($r['run']->totalPvKwh(), 3);
            $intervals = count($r['run']->intervals);
            if ($store && method_exists($store, 'saveForecastRun')) {
                try {
                    $store->saveForecastRun($r['run']->toArray());
                } catch (\Throwable $e) {
                    // storing must never break the run
                }
            }
            if ($status === 'fresh' || $status === 'partial') {
                $runs[] = array('run' => $r['run'], 'mae' => null, 'obs' => 0, 'weather_model' => $weatherModel);
            }
        }
        return array(
            'enabled' => $enabled ? 1 : 0,
            'status' => $status,
            'detail' => $this->humanDetail($status, $detail),
            'total_kwh' => $total,
            'intervals' => $intervals,
        );
    }

    /** Turn a terse status/detail into a short Ukrainian explanation. */
    private function humanDetail($status, $detail)
    {
        switch ($status) {
            case 'fresh':
                return 'Отримано повний прогноз';
            case 'partial':
                return 'Прогноз отримано, але горизонт неповний';
            case 'missing':
                return 'Пропущено: ' . $detail;
            case 'unusable':
                if (strpos($detail, 'http') === 0) {
                    return 'Сервер провайдера повернув помилку (' . $detail . '). Перевірте вихідний доступ до інтернету.';
                }
                if (strpos($detail, 'ssrf') === 0) {
                    return 'Запит заблоковано SSRF-захистом: ' . $detail;
                }
                if ($detail === 'schema') {
                    return 'Відповідь не розпізнано (несподіваний формат).';
                }
                return 'Непридатно: ' . $detail;
            default:
                return $detail;
        }
    }

    /**
     * Environment self-check: config presence, coordinates, arrays, curl, and a
     * live outbound HTTPS probe to Open-Meteo (the real network path the module
     * uses). No secrets leak.
     */
    public function environment($fromUtc, $toUtc, array $site = null)
    {
        if ($site === null) {
            $site = $this->resolveSite();
        }
        $cfg = $this->cfg;

        $configLoaded = !empty($cfg);
        $anyProvider = !empty($cfg['OPEN_METEO_ENABLED']) || !empty($cfg['FORECAST_SOLAR_ENABLED']) || !empty($cfg['SOLCAST_ENABLED']);

        // Live probe: a tiny Open-Meteo request (1 variable, 1 hour) to prove
        // the server can actually reach the internet over HTTPS.
        $probe = $this->outboundProbe();

        return array(
            'config_loaded' => $configLoaded ? 1 : 0,
            'config_keys' => $configLoaded ? count($cfg) : 0,
            'any_provider_enabled' => $anyProvider ? 1 : 0,
            'coordinates_set' => ($site['lat_set'] && $site['lon_set']) ? 1 : 0,
            'coordinates_source' => ($site['lat_set'] && $site['lon_set']) ? 'config' : 'fallback (Київ)',
            'pv_arrays_set' => $site['arrays_set'] ? 1 : 0,
            'curl_available' => function_exists('curl_init') ? 1 : 0,
            'openssl_available' => extension_loaded('openssl') ? 1 : 0,
            'outbound_https' => $probe['ok'] ? 1 : 0,
            'outbound_https_detail' => $probe['detail'],
            'solcast_key_set' => !empty($cfg['SOLCAST_API_KEY']) ? 1 : 0,
            'forecast_solar_key_set' => !empty($cfg['FORECAST_SOLAR_API_KEY']) ? 1 : 0,
            'transport' => isset($cfg['TRANSPORT']) ? $cfg['TRANSPORT'] : '',
            'profile_verified' => !empty($cfg['PROFILE_VERIFIED']) ? 1 : 0,
        );
    }

    /** Minimal live outbound HTTPS probe to Open-Meteo. */
    public function outboundProbe()
    {
        $http = new HttpClient(10, 1, false);
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=50&longitude=30&hourly=temperature_2m&forecast_days=1&timeformat=unixtime';
        $guard = $http->guardUrl($url);
        if ($guard !== true) {
            return array('ok' => false, 'detail' => 'SSRF-захист: ' . $guard);
        }
        $res = $http->get($url);
        if ($res['status'] === 200 && strpos($res['body'], 'hourly') !== false) {
            return array('ok' => true, 'detail' => 'Вихідний HTTPS працює (Open-Meteo відповів 200).');
        }
        if ($res['status'] === 0) {
            $err = $res['error'] ? $res['error'] : 'таймаут/DNS/блокування';
            if (stripos($err, 'certificate') !== false || stripos($err, 'issuer') !== false || stripos($err, 'ssl') !== false) {
                $err .= ' — не налаштовано кореневі сертифікати (CA). На Raspbian: sudo apt-get install ca-certificates, або вкажіть curl.cainfo у php.ini.';
            }
            return array('ok' => false, 'detail' => 'Немає вихідного зʼєднання: ' . $err);
        }
        return array('ok' => false, 'detail' => 'Несподівана відповідь HTTP ' . $res['status']);
    }
}
