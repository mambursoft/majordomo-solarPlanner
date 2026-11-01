<?php
/**
 * OpenMeteoAdapter — weather -> local PV model.
 *
 * Uses Open-Meteo global tilted irradiance (GTI) per array via the local
 * PvModel. Key correctness points from the spec:
 *  - azimuth converted module->OpenMeteo convention in the adapter only;
 *  - standard radiation variables are MEANS over the preceding hour; the
 *    adapter maps each timestamp to its correct interval and converts mean
 *    power to interval energy, not instantaneous-as-mean;
 *  - uses Unix time (timeformat=unixtime) for DST-safe handling;
 *  - retrieved_at is NOT the model issue time; generationtime_ms is response
 *    build time, not a model run timestamp;
 *  - Open-Meteo free API is non-commercial; data is CC BY 4.0 (attribution in
 *    NOTICE.md / UI footer).
 *
 * No API key is required for the free Open-Meteo endpoint. Coordinates come
 * from private config and are never logged to the public repo.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class OpenMeteoAdapter implements ForecastAdapter
{
    const BASE = 'https://api.open-meteo.com/v1/forecast';

    /** @var HttpClient */
    private $http;
    /** @var QuotaManager */
    private $quota;
    /** @var PvModel */
    private $pv;
    /** @var array site: lat, lon, arrays[], inverter_ac_kw */
    private $site;
    /** @var string account id for quota accounting */
    private $accountId;

    public function __construct(HttpClient $http, QuotaManager $quota, PvModel $pv, array $site, $accountId = 'open_meteo')
    {
        $this->http = $http;
        $this->quota = $quota;
        $this->pv = $pv;
        $this->site = $site;
        $this->accountId = $accountId;
    }

    public function providerId()
    {
        return 'open_meteo';
    }

    public function fetch($fromUtc, $toUtc, $critical = false)
    {
        $can = $this->quota->canRequest($this->accountId, 1.0, $critical);
        if (!$can['allowed']) {
            return array('run' => null, 'status' => 'missing', 'detail' => 'quota: ' . $can['reason']);
        }

        $url = $this->buildUrl($fromUtc, $toUtc);
        $guard = $this->http->guardUrl($url);
        if ($guard !== true) {
            return array('run' => null, 'status' => 'unusable', 'detail' => 'ssrf: ' . $guard);
        }

        $res = $this->http->get($url);
        if ($res['status'] !== 200) {
            $this->quota->recordFailure($this->accountId, $res['status']);
            return array('run' => null, 'status' => 'unusable', 'detail' => 'http ' . $res['status']);
        }
        $this->quota->recordSuccess($this->accountId, 1.0);

        $data = json_decode($res['body'], true);
        if (!is_array($data) || !isset($data['hourly']['time'])) {
            return array('run' => null, 'status' => 'unusable', 'detail' => 'schema');
        }

        return $this->parse($data, $res['body'], $fromUtc, $toUtc);
    }

    private function buildUrl($fromUtc, $toUtc)
    {
        $lat = (float)$this->site['lat'];
        $lon = (float)$this->site['lon'];

        // Request GTI for the first array orientation; multiple arrays with
        // differing tilt/azimuth would normally need multiple requests. For the
        // base model we request one representative GTI plus temperature and
        // apply per-array scaling in PvModel. Arrays sharing orientation reuse it.
        $arr0 = $this->site['arrays'][0];
        $tilt = (float)$arr0['tilt_deg'];
        $omAz = PvModel::azimuthModuleToOpenMeteo((float)$arr0['azimuth_module_deg']);

        $params = array(
            'latitude' => $lat,
            'longitude' => $lon,
            'hourly' => 'temperature_2m,global_tilted_irradiance,shortwave_radiation',
            'tilt' => round($tilt, 1),
            'azimuth' => round($omAz, 1),
            'timeformat' => 'unixtime',
            'timezone' => 'UTC',
            'start_hour' => gmdate('Y-m-d\TH:00', (int)$fromUtc),
            'end_hour' => gmdate('Y-m-d\TH:00', (int)$toUtc),
        );
        return self::BASE . '?' . http_build_query($params);
    }

    private function parse(array $data, $rawBody, $fromUtc, $toUtc)
    {
        $run = new ForecastRun('open_meteo');
        $run->energyBasis = 'interval';
        $run->modelId = isset($data['hourly']['model']) ? 'open-meteo' : 'open-meteo';
        $run->issuedAtUtc = null; // generationtime_ms is NOT the model issue time
        $run->validFromUtc = (int)$fromUtc;
        $run->validToUtc = (int)$toUtc;
        $run->rawResponseHash = hash('sha256', $rawBody);
        $run->quotaState = $this->quota->snapshot($this->accountId);
        $run->locationConfigHash = isset($this->site['location_hash']) ? $this->site['location_hash'] : '';
        $run->pvConfigHash = isset($this->site['pv_hash']) ? $this->site['pv_hash'] : '';
        $run->qualityFlags[] = 'attribution:open-meteo CC BY 4.0';

        $times = $data['hourly']['time'];
        $gti = isset($data['hourly']['global_tilted_irradiance']) ? $data['hourly']['global_tilted_irradiance'] : array();
        $temp = isset($data['hourly']['temperature_2m']) ? $data['hourly']['temperature_2m'] : array();

        $n = count($times);
        for ($i = 0; $i < $n; $i++) {
            // Open-Meteo hourly radiation is the MEAN over the PRECEDING hour.
            // The timestamp labels the end of its averaging hour here; interval
            // is [t-1h, t). We convert mean W/m2 to Wh/m2 over the hour.
            $endUtc = (int)$times[$i];
            $startUtc = $endUtc - 3600;
            if ($endUtc <= $fromUtc || $startUtc >= $toUtc) {
                continue;
            }
            $meanWm2 = isset($gti[$i]) && $gti[$i] !== null ? (float)$gti[$i] : null;
            $tC = isset($temp[$i]) && $temp[$i] !== null ? (float)$temp[$i] : null;

            if ($meanWm2 === null) {
                $run->addInterval($startUtc, $endUtc, null, array('is_missing' => true));
                continue;
            }
            $hours = ($endUtc - $startUtc) / 3600.0;
            $poaWhm2 = $meanWm2 * $hours; // mean power * hours -> energy per m2
            $poaByArray = array();
            foreach ($this->site['arrays'] as $a) {
                $aid = isset($a['array_id']) ? $a['array_id'] : (isset($a['id']) ? $a['id'] : 'array');
                $poaByArray[$aid] = $poaWhm2;
            }
            $est = $this->pv->intervalEnergy($poaByArray, $tC === null ? 10.0 : $tC, $hours);
            $run->addInterval($startUtc, $endUtc, $est['total_kwh'], array(
                'pv_mean_power_kw' => $hours > 0 ? $est['total_kwh'] / $hours : 0.0,
                'array_id' => 'aggregate',
            ));
        }

        $status = $run->coversHorizon($fromUtc, $toUtc) ? 'fresh' : 'partial';
        return array('run' => $run, 'status' => $status, 'detail' => 'ok');
    }
}
