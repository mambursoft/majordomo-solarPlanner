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

use SolarPlanner\Forecast\ForecastCache;
use SolarPlanner\Forecast\FetchScheduler;
use SolarPlanner\Forecast\ForecastRun;
use SolarPlanner\Common\SolarWindows;

class DiagnosticsService
{
    /** @var array module config (as stored) */
    private $cfg;
    /** @var ForecastCache|null */
    private $cache;
    /** @var callable|null getter for MajorDoMo properties (gg wrapper) */
    private $propGetter;
    /** @var float|null wall-clock deadline (epoch seconds) for a collect pass */
    private $deadline = null;

    /**
     * @param array $cfg module config
     * @param ForecastCache|null $cache optional forecast cache
     * @param callable|null $propGetter function(string $prop): mixed (gg wrapper)
     */
    public function __construct(array $cfg, $cache = null, $propGetter = null)
    {
        $this->cfg = $cfg;
        $this->cache = $cache;
        $this->propGetter = $propGetter;
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
    public function collect($fromUtc, $toUtc, $store = null, $force = false)
    {
        $cfg = $this->cfg;
        $site = $this->resolveSite();
        $env = $this->environment($fromUtc, $toUtc, $site);

        // Tight, bounded HTTP budget so a single slow / rate-limited provider
        // cannot stall the background cycle. connect<=5s, total<=8s, one retry,
        // and 429 is surfaced immediately (never retried). A whole collect pass
        // is additionally capped by a wall-clock deadline below.
        $http = new HttpClient(8, 1, false, 5, false);

        // Overall deadline for the entire collection. Providers still pending
        // when the budget is spent are skipped with a clear reason; the plan is
        // then built from whatever forecasts did arrive (partial is fine).
        $collectBudgetSec = isset($cfg['FETCH_BUDGET_SEC']) ? max(8, (int)$cfg['FETCH_BUDGET_SEC']) : 20;
        $deadline = microtime(true) + $collectBudgetSec;
        $this->deadline = $deadline;

        $quota = new QuotaManager();
        $pv = new PvModel($site['arrays'], 0.0);

        // Sun/night context drives the fetch schedule.
        $now = time();
        $sun = (new SolarWindows($site['tz'], $this->propGetter))->resolve($now, array(
            'cheap_start_hour' => isset($cfg['CHEAP_START_HOUR']) ? (int)$cfg['CHEAP_START_HOUR'] : 23,
            'cheap_end_hour' => isset($cfg['CHEAP_END_HOUR']) ? (int)$cfg['CHEAP_END_HOUR'] : 7,
        ), $site['lat']);
        $scheduler = new FetchScheduler($this->schedulerPolicy());

        $runs = array();
        $providers = array();

        // ---- Open-Meteo ----
        if (!empty($cfg['OPEN_METEO_ENABLED'])) {
            $providers['open_meteo'] = $this->fetchProvider(
                'open_meteo',
                function () use ($http, $quota, $pv, $site, $fromUtc, $toUtc) {
                    $a = new OpenMeteoAdapter($http, $quota, $pv, $site, 'open_meteo');
                    return $a->fetch($fromUtc, $toUtc, false);
                },
                'open-meteo', $store, $runs, $scheduler, $sun, $now, $force
            );
        } else {
            $providers['open_meteo'] = $this->disabledRow();
        }

        // ---- Forecast.Solar ----
        if (!empty($cfg['FORECAST_SOLAR_ENABLED'])) {
            $key = isset($cfg['FORECAST_SOLAR_API_KEY']) ? $cfg['FORECAST_SOLAR_API_KEY'] : null;
            $providers['forecast_solar'] = $this->fetchProvider(
                'forecast_solar',
                function () use ($http, $quota, $site, $key, $fromUtc, $toUtc) {
                    $a = new ForecastSolarAdapter($http, $quota, $site, $key ?: null, 'forecast_solar');
                    return $a->fetch($fromUtc, $toUtc, false);
                },
                'forecast_solar', $store, $runs, $scheduler, $sun, $now, $force
            );
        } else {
            $providers['forecast_solar'] = $this->disabledRow();
        }

        // ---- Solcast ----
        if (!empty($cfg['SOLCAST_ENABLED'])) {
            $keyOk = !empty($cfg['SOLCAST_API_KEY']);
            $idsOk = !empty($cfg['SOLCAST_RESOURCE_IDS']);
            if (!$keyOk || !$idsOk) {
                $miss = array();
                if (!$keyOk) { $miss[] = 'API-ключ'; }
                if (!$idsOk) { $miss[] = 'resource id'; }
                $providers['solcast'] = array('enabled' => 1, 'status' => 'misconfigured',
                    'detail' => 'Увімкнено, але не задано: ' . implode(', ', $miss),
                    'total_kwh' => null, 'intervals' => 0, 'source' => 'config');
            } else {
                $ids = array_filter(array_map('trim', explode(',', $cfg['SOLCAST_RESOURCE_IDS'])));
                $skey = $cfg['SOLCAST_API_KEY'];
                $providers['solcast'] = $this->fetchProvider(
                    'solcast',
                    function () use ($http, $quota, $ids, $skey, $fromUtc, $toUtc) {
                        $a = new SolcastAdapter($http, $quota, $ids, $skey, 'solcast');
                        return $a->fetch($fromUtc, $toUtc, false);
                    },
                    'solcast', $store, $runs, $scheduler, $sun, $now, $force
                );
            }
        } else {
            $providers['solcast'] = $this->disabledRow();
        }

        $env['sun'] = $sun;

        return array(
            'runs' => $runs,
            'providers' => $providers,
            'site' => $site,
            'env' => $env,
        );
    }

    private function disabledRow()
    {
        return array('enabled' => 0, 'status' => 'disabled',
            'detail' => 'Провайдер вимкнено в налаштуваннях', 'total_kwh' => null, 'intervals' => 0, 'source' => '-');
    }

    /** Scheduler policy per provider (configurable later; sensible defaults). */
    private function schedulerPolicy()
    {
        $cfg = $this->cfg;
        $fsMin = isset($cfg['FORECAST_SOLAR_MIN_INTERVAL_MIN']) ? (int)$cfg['FORECAST_SOLAR_MIN_INTERVAL_MIN'] : 20;
        $scDay = isset($cfg['SOLCAST_DAY_FETCHES']) ? (int)$cfg['SOLCAST_DAY_FETCHES'] : 3;
        $scNight = isset($cfg['SOLCAST_NIGHT_FETCHES']) ? (int)$cfg['SOLCAST_NIGHT_FETCHES'] : 4;
        return array(
            // Open-Meteo: effectively unlimited; refresh sparingly (model updates
            // are infrequent). Day and night a handful of times.
            'open_meteo' => array('min_interval_sec' => 1800, 'day_fetches' => 4, 'night_fetches' => 4),
            // Forecast.Solar: ~12 req/hour per IP -> keep a safe min interval.
            'forecast_solar' => array('min_interval_sec' => $fsMin * 60, 'day_fetches' => 4, 'night_fetches' => 4),
            // Solcast: scarce daily quota. Spend MOST of it at night (tomorrow's
            // forecast decides how much to buy), a little during the day.
            'solcast' => array('min_interval_sec' => 1800, 'day_fetches' => $scDay, 'night_fetches' => $scNight),
        );
    }

    /**
     * Fetch a provider honouring cache + schedule. On a non-due schedule with a
     * fresh cache, no HTTP is made (quota saved). On a fetch failure, falls back
     * to a still-usable cached run. On success, updates the cache.
     *
     * @param string $providerId
     * @param callable $fetchFn returns ['run'=>?ForecastRun,'status'=>,'detail'=>]
     */
    private function fetchProvider($providerId, callable $fetchFn, $weatherModel, $store, array &$runs, FetchScheduler $scheduler, array $sun, $now, $force)
    {
        $lastFetch = $this->cache ? $this->cache->lastFetchUtc($providerId, $now) : null;
        $sched = $scheduler->shouldFetch($providerId, $now, $lastFetch, $sun);
        $doFetch = $force || $sched['fetch'];

        // Serve from fresh cache when a fetch is not due.
        if (!$doFetch && $this->cache) {
            $entry = $this->cache->get($providerId, $now);
            if ($entry && $entry['fresh']) {
                return $this->cachedRow($providerId, $entry, $runs, $weatherModel, $sched);
            }
            // cache missing/stale but not due -> still fetch to avoid going blind.
            $doFetch = true;
        }

        // Respect the overall collection deadline: if the budget is already
        // spent (a previous provider was slow), do not start another network
        // request. Prefer a still-usable cached run; otherwise report clearly.
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            if ($this->cache) {
                $entry = $this->cache->get($providerId, $now);
                if ($entry && $entry['fresh']) {
                    $row = $this->cachedRow($providerId, $entry, $runs, $weatherModel, $sched);
                    $row['status'] = 'cached';
                    $row['detail'] = 'Пропущено (вичерпано ліміт часу збору), використано кеш (' . $this->ageText($entry['age_sec']) . ').';
                    return $row;
                }
            }
            return array(
                'enabled' => 1,
                'status' => 'skipped',
                'detail' => 'Пропущено: вичерпано ліміт часу збору прогнозів (інший провайдер відповідав надто довго).',
                'total_kwh' => null,
                'intervals' => 0,
                'source' => 'live',
                'next_fetch_utc' => $sched['next_utc'],
            );
        }

        $r = call_user_func($fetchFn);
        $status = isset($r['status']) ? $r['status'] : 'unusable';
        $detail = isset($r['detail']) ? $r['detail'] : '';

        if (!empty($r['run']) && ($status === 'fresh' || $status === 'partial')) {
            // Success: update cache and collect the run.
            if ($this->cache) {
                try { $this->cache->put($providerId, $r['run']->toArray(), $now); } catch (\Throwable $e) {}
            }
            if ($store && method_exists($store, 'saveForecastRun')) {
                try { $store->saveForecastRun($r['run']->toArray()); } catch (\Throwable $e) {}
            }
            $runs[] = array('run' => $r['run'], 'mae' => null, 'obs' => 0, 'weather_model' => $weatherModel);
            return array(
                'enabled' => 1,
                'status' => $status,
                'detail' => $this->humanDetail($status, $detail),
                'total_kwh' => round($r['run']->totalPvKwh(), 3),
                'intervals' => count($r['run']->intervals),
                'source' => 'live',
                'next_fetch_utc' => $sched['next_utc'],
            );
        }

        // Fetch failed: fall back to a still-usable cached run.
        if ($this->cache) {
            $entry = $this->cache->get($providerId, $now);
            if ($entry && $entry['fresh']) {
                $row = $this->cachedRow($providerId, $entry, $runs, $weatherModel, $sched);
                $row['detail'] = 'Live-запит не вдався (' . $this->humanDetail($status, $detail) . '), використано кеш (' . $this->ageText($entry['age_sec']) . ').';
                $row['status'] = 'cached';
                return $row;
            }
        }

        return array(
            'enabled' => 1,
            'status' => $status,
            'detail' => $this->humanDetail($status, $detail),
            'total_kwh' => null,
            'intervals' => 0,
            'source' => 'live',
            'next_fetch_utc' => $sched['next_utc'],
        );
    }

    /** Build a provider row from a cached entry and collect its run. */
    private function cachedRow($providerId, array $entry, array &$runs, $weatherModel, array $sched)
    {
        $run = ForecastRun::fromArray($entry['run']);
        if ($run && !empty($run->intervals)) {
            $runs[] = array('run' => $run, 'mae' => null, 'obs' => 0, 'weather_model' => $weatherModel);
            $total = round($run->totalPvKwh(), 3);
            $intervals = count($run->intervals);
        } else {
            $total = null;
            $intervals = 0;
        }
        return array(
            'enabled' => 1,
            'status' => 'cached',
            'detail' => 'З кешу (' . $this->ageText($entry['age_sec']) . '), наступне оновлення: ' . $this->timeText($sched['next_utc']) . '.',
            'total_kwh' => $total,
            'intervals' => $intervals,
            'source' => 'cache',
            'next_fetch_utc' => $sched['next_utc'],
        );
    }

    private function ageText($sec)
    {
        $sec = max(0, (int)$sec);
        if ($sec < 3600) {
            return round($sec / 60) . ' хв тому';
        }
        return round($sec / 3600, 1) . ' год тому';
    }

    private function timeText($utc)
    {
        $tz = new \DateTimeZone(!empty($this->cfg['TIMEZONE']) ? $this->cfg['TIMEZONE'] : 'Europe/Kyiv');
        return (new \DateTime('@' . (int)$utc))->setTimezone($tz)->format('H:i');
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
                if ($detail === 'http 503' || $detail === 'http 429') {
                    return 'Провайдер тимчасово недоступний (' . $detail . '). Зачекайте — планувальник використає інші джерела; повтор буде автоматично.';
                }
                if ($detail === 'http 0') {
                    return 'Немає відповіді від сервера провайдера. Якщо інші провайдери працюють — проблема саме в цьому домені (можливо, фаєрвол/проксі або регіональне блокування).';
                }
                if (strpos($detail, 'http') === 0) {
                    return 'Сервер провайдера повернув помилку (' . $detail . ').';
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

    /**
     * Live outbound HTTPS probe. Tries several independent hosts so that one
     * provider being down (e.g. Open-Meteo returning 503 to this edge) is NOT
     * mistaken for "no internet". Internet is considered up if ANY host answers;
     * per-host results are reported so the real culprit is visible.
     */
    public function outboundProbe()
    {
        // Fast, bounded probe: connect<=4s, total<=6s, no retries. This only
        // needs to answer "is the network up?", so it must never block the plan.
        $http = new HttpClient(6, 0, false, 4, false);
        $targets = array(
            'open_meteo' => 'https://api.open-meteo.com/v1/forecast?latitude=50&longitude=30&hourly=temperature_2m&forecast_days=1&timeformat=unixtime',
            'forecast_solar' => 'https://api.forecast.solar/estimate/50/30/40/0/3',
            'cloudflare' => 'https://cloudflare.com/cdn-cgi/trace',
        );
        $perHost = array();
        $anyOk = false;
        $sslIssue = false;
        foreach ($targets as $name => $url) {
            $guard = $http->guardUrl($url);
            if ($guard !== true) {
                $perHost[$name] = 'SSRF: ' . $guard;
                continue;
            }
            $res = $http->get($url);
            if ($res['status'] >= 200 && $res['status'] < 500) {
                // 2xx/3xx/4xx all prove the network path works (4xx = reached it).
                $anyOk = true;
                $perHost[$name] = 'HTTP ' . $res['status'];
            } elseif ($res['status'] === 0) {
                $err = $res['error'] ? $res['error'] : 'таймаут/DNS/блокування';
                if (stripos($err, 'certificate') !== false || stripos($err, 'issuer') !== false || stripos($err, 'ssl') !== false) {
                    $sslIssue = true;
                }
                $perHost[$name] = 'нема відповіді (' . $err . ')';
            } else {
                $perHost[$name] = 'HTTP ' . $res['status'];
            }
        }

        $parts = array();
        foreach ($perHost as $n => $v) {
            $parts[] = $n . '=' . $v;
        }
        $summary = implode(', ', $parts);

        if ($anyOk) {
            return array('ok' => true, 'detail' => 'Інтернет працює. ' . $summary);
        }
        if ($sslIssue) {
            return array('ok' => false, 'detail' => 'Проблема з сертифікатами (CA). На Raspbian: sudo apt-get install ca-certificates, або вкажіть curl.cainfo у php.ini. ' . $summary);
        }
        return array('ok' => false, 'detail' => 'Немає вихідного зʼєднання до жодного хоста. ' . $summary);
    }
}
