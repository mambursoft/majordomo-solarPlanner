<?php
/**
 * ForecastSolarAdapter — ready-made PV forecast from Forecast.Solar.
 *
 * Independent production adapter (no local PV model). It consumes the
 * watt_hours_period series (per-interval energy) and never the cumulative
 * daily series, to avoid mixing cumulative with interval energy.
 *
 * Numeric quota limits are NOT hard-coded: they are configured values and the
 * QuotaManager handles exhaustion / 429 / backoff. Verify the current official
 * documentation before relying on any limit.
 *
 * API key (if used) lives only in private config, never in the ForecastRun.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class ForecastSolarAdapter implements ForecastAdapter
{
    /** @var HttpClient */
    private $http;
    /** @var QuotaManager */
    private $quota;
    /** @var array site: lat, lon, arrays[] (each kwp, tilt_deg, azimuth_module_deg), tz */
    private $site;
    /** @var string|null optional api key (kept out of ForecastRun) */
    private $apiKey;
    private $accountId;

    public function __construct(HttpClient $http, QuotaManager $quota, array $site, $apiKey = null, $accountId = 'forecast_solar')
    {
        $this->http = $http;
        $this->quota = $quota;
        $this->site = $site;
        $this->apiKey = $apiKey;
        $this->accountId = $accountId;
    }

    public function providerId()
    {
        return 'forecast_solar';
    }

    public function fetch($fromUtc, $toUtc, $critical = false)
    {
        $can = $this->quota->canRequest($this->accountId, 1.0, $critical);
        if (!$can['allowed']) {
            return array('run' => null, 'status' => 'missing', 'detail' => 'quota: ' . $can['reason']);
        }

        // One request per array (Forecast.Solar is single-plane per call).
        $run = new ForecastRun('forecast_solar');
        $run->energyBasis = 'interval';
        $run->validFromUtc = (int)$fromUtc;
        $run->validToUtc = (int)$toUtc;
        $run->quotaState = $this->quota->snapshot($this->accountId);
        $run->locationConfigHash = isset($this->site['location_hash']) ? $this->site['location_hash'] : '';
        $run->pvConfigHash = isset($this->site['pv_hash']) ? $this->site['pv_hash'] : '';

        $byInterval = array(); // startUtc => ['end'=>, 'kwh'=>]
        $rawConcat = '';
        $okAny = false;

        foreach ($this->site['arrays'] as $a) {
            $url = $this->buildUrl($a);
            $guard = $this->http->guardUrl($url);
            if ($guard !== true) {
                continue;
            }
            $res = $this->http->get($url, array('Accept' => 'application/json'));
            if ($res['status'] !== 200) {
                $this->quota->recordFailure($this->accountId, $res['status']);
                continue;
            }
            $this->quota->recordSuccess($this->accountId, 1.0);
            $okAny = true;
            $rawConcat .= $res['body'];
            $data = json_decode($res['body'], true);
            if (!isset($data['result']['watt_hours_period'])) {
                continue;
            }
            $tz = isset($this->site['tz']) ? $this->site['tz'] : 'UTC';
            foreach ($data['result']['watt_hours_period'] as $ts => $wh) {
                // Forecast.Solar timestamps are local time "Y-m-d H:i:s".
                $start = $this->localToUtc($ts, $tz);
                $end = $start + 3600; // period granularity (hourly by default)
                if ($end <= $fromUtc || $start >= $toUtc) {
                    continue;
                }
                $kwh = (float)$wh / 1000.0;
                if (!isset($byInterval[$start])) {
                    $byInterval[$start] = array('end' => $end, 'kwh' => 0.0);
                }
                $byInterval[$start]['kwh'] += $kwh; // sum arrays
            }
        }

        if (!$okAny) {
            return array('run' => null, 'status' => 'unusable', 'detail' => 'all array requests failed');
        }

        ksort($byInterval);
        foreach ($byInterval as $start => $row) {
            $run->addInterval($start, $row['end'], $row['kwh'], array(
                'pv_mean_power_kw' => $row['kwh'],
                'array_id' => 'aggregate',
            ));
        }
        $run->rawResponseHash = hash('sha256', $rawConcat);
        $status = $run->coversHorizon($fromUtc, $toUtc) ? 'fresh' : 'partial';
        return array('run' => $run, 'status' => $status, 'detail' => 'ok');
    }

    private function buildUrl(array $arr)
    {
        $lat = (float)$this->site['lat'];
        $lon = (float)$this->site['lon'];
        $tilt = (float)$arr['tilt_deg'];
        // Forecast.Solar azimuth: -180..180 where 0 = south, -90 = east, 90 = west.
        // Our module convention: 180 = south. Convert module -> forecast.solar.
        $fsAz = (float)$arr['azimuth_module_deg'] - 180.0;
        while ($fsAz <= -180.0) {
            $fsAz += 360.0;
        }
        while ($fsAz > 180.0) {
            $fsAz -= 360.0;
        }
        $kwp = (float)$arr['kwp'];

        $base = 'https://api.forecast.solar';
        if ($this->apiKey) {
            $base .= '/' . rawurlencode($this->apiKey);
        }
        // /estimate/:lat/:lon/:dec(tilt)/:az/:kwp
        $path = sprintf('/estimate/%s/%s/%s/%s/%s',
            rawurlencode((string)$lat),
            rawurlencode((string)$lon),
            rawurlencode((string)round($tilt, 1)),
            rawurlencode((string)round($fsAz, 1)),
            rawurlencode((string)$kwp)
        );
        return $base . $path;
    }

    private function localToUtc($localStr, $tz)
    {
        try {
            $dt = new \DateTime($localStr, new \DateTimeZone($tz));
            return (int)$dt->format('U');
        } catch (\Exception $e) {
            return (int)strtotime($localStr . ' UTC');
        }
    }
}
