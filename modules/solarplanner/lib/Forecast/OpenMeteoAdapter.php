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
 * Since 1.2.0: arrays with different tilt/azimuth get their OWN GTI request.
 * Applying the first array's POA irradiance to every array is physically wrong
 * for multi-orientation sites. Arrays sharing one orientation share a request.
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
        $groups = $this->orientationGroups();
        $needUnits = count($groups);
        $can = $this->quota->canRequest($this->accountId, $needUnits, $critical);
        if (!$can['allowed']) {
            return array('run' => null, 'status' => 'missing', 'detail' => 'quota: ' . $can['reason']);
        }

        $responses = array();
        $okAny = false;
        $failedKwp = 0.0;
        $totalKwp = 0.0;
        foreach ($this->site['arrays'] as $a) {
            $totalKwp += isset($a['kwp']) ? (float)$a['kwp'] : 0.0;
        }
        foreach ($groups as $key => $g) {
            $url = $this->buildUrl($fromUtc, $toUtc, $g['sample']);
            $guard = $this->http->guardUrl($url);
            if ($guard !== true) {
                $this->quota->recordFailure($this->accountId, 0);
            } else {
                $res = $this->http->get($url);
                if ($res['status'] !== 200) {
                    $this->quota->recordFailure($this->accountId, $res['status']);
                } else {
                    $data = json_decode($res['body'], true);
                    if (is_array($data) && isset($data['hourly']['time'])) {
                        $this->quota->recordSuccess($this->accountId, 1.0);
                        $okAny = true;
                        $responses[$key] = array('data' => $data, 'raw' => $res['body'], 'arrays' => $g['arrays']);
                        continue;
                    }
                    $this->quota->recordFailure($this->accountId, 0);
                }
            }
            // A failed orientation must not silently become zero generation.
            foreach ($g['arrays'] as $a) {
                $failedKwp += isset($a['kwp']) ? (float)$a['kwp'] : 0.0;
            }
        }

        if (!$okAny) {
            return array('run' => null, 'status' => 'unusable', 'detail' => 'no orientation request succeeded');
        }
        // Compensate aggregate energy for the arrays whose request failed and
        // flag the run as partial. This is a first-order estimate, not a claim
        // of accuracy — but far safer than treating the missing kWp as zero.
        $coverageFactor = ($totalKwp > 0 && $failedKwp > 0)
            ? min(2.0, $totalKwp / max(0.001, $totalKwp - $failedKwp)) : 1.0;
        return $this->parse($responses, $fromUtc, $toUtc, $coverageFactor, $failedKwp);
    }

    /** Group arrays by identical (tilt, azimuth) so one GTI request serves each group. */
    private function orientationGroups()
    {
        $groups = array();
        foreach ($this->site['arrays'] as $a) {
            $tilt = isset($a['tilt_deg']) ? round((float)$a['tilt_deg'], 1) : 0.0;
            $az = isset($a['azimuth_module_deg']) ? round((float)$a['azimuth_module_deg'], 1) : 180.0;
            $key = $tilt . '|' . $az;
            if (!isset($groups[$key])) {
                $sample = $a;
                $sample['tilt_deg'] = $tilt;
                $sample['azimuth_module_deg'] = $az;
                $groups[$key] = array('sample' => $sample, 'arrays' => array());
            }
            $aid = isset($a['array_id']) ? $a['array_id'] : (isset($a['id']) ? $a['id'] : 'array');
            $groups[$key]['arrays'][$aid] = $a;
        }
        return $groups;
    }

    private function buildUrl($fromUtc, $toUtc, $arr)
    {
        $lat = (float)$this->site['lat'];
        $lon = (float)$this->site['lon'];
        $tilt = (float)$arr['tilt_deg'];
        $omAz = PvModel::azimuthModuleToOpenMeteo((float)$arr['azimuth_module_deg']);

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

    private function parse(array $responses, $fromUtc, $toUtc, $coverageFactor = 1.0, $failedKwp = 0.0)
    {
        $run = new ForecastRun('open_meteo');
        $run->energyBasis = 'interval';
        $run->modelId = 'open-meteo';
        $run->issuedAtUtc = null; // generationtime_ms is NOT the model issue time
        $run->validFromUtc = (int)$fromUtc;
        $run->validToUtc = (int)$toUtc;
        $run->quotaState = $this->quota->snapshot($this->accountId);
        $run->locationConfigHash = isset($this->site['location_hash']) ? $this->site['location_hash'] : '';
        $run->pvConfigHash = isset($this->site['pv_hash']) ? $this->site['pv_hash'] : '';
        $run->qualityFlags[] = 'attribution:open-meteo CC BY 4.0';
        if ($failedKwp > 0) {
            $run->qualityFlags[] = 'partial_orientation_missing_kwp:' . round($failedKwp, 3);
        }

        // Index each orientation response by timestamp.
        $byTime = array();
        $rawConcat = '';
        $tempFallback = false;
        $fallbackC = isset($this->site['om_default_temp_c']) && is_numeric($this->site['om_default_temp_c'])
            ? (float)$this->site['om_default_temp_c'] : 10.0;
        foreach ($responses as $key => $resp) {
            $rawConcat .= $resp['raw'];
            $times = $resp['data']['hourly']['time'];
            $gti = isset($resp['data']['hourly']['global_tilted_irradiance'])
                ? $resp['data']['hourly']['global_tilted_irradiance'] : array();
            $temp = isset($resp['data']['hourly']['temperature_2m'])
                ? $resp['data']['hourly']['temperature_2m'] : array();
            $n = count($times);
            for ($i = 0; $i < $n; $i++) {
                $t = (int)$times[$i];
                if (!isset($byTime[$t])) {
                    $byTime[$t] = array('poa' => array(), 'gti' => array(), 'temp' => null,
                        'temp_fallback' => false);
                }
                $meanWm2 = isset($gti[$i]) && $gti[$i] !== null ? (float)$gti[$i] : null;
                $tC = isset($temp[$i]) && $temp[$i] !== null ? (float)$temp[$i] : null;
                if ($tC === null && $byTime[$t]['temp'] === null) {
                    $byTime[$t]['temp'] = $fallbackC;
                    $byTime[$t]['temp_fallback'] = true;
                    $tempFallback = true;
                } elseif ($tC !== null) {
                    // A later orientation with a real temperature wins; the
                    // per-interval flag must reflect the value actually used.
                    $byTime[$t]['temp'] = $tC;
                    $byTime[$t]['temp_fallback'] = false;
                }
                $byTime[$t]['gti'][$key] = $meanWm2;
            }
        }
        $run->rawResponseHash = hash('sha256', $rawConcat);
        if ($tempFallback) {
            $run->qualityFlags[] = 'temperature_fallback_used:' . $fallbackC;
        }

        ksort($byTime);
        foreach ($byTime as $endUtc => $row) {
            // Open-Meteo hourly radiation is the MEAN over the PRECEDING hour.
            $startUtc = $endUtc - 3600;
            if ($endUtc <= $fromUtc || $startUtc >= $toUtc) {
                continue;
            }
            $hours = ($endUtc - $startUtc) / 3600.0;
            $poaByArray = array();
            $any = false;
            foreach ($responses as $key => $resp) {
                $meanWm2 = isset($row['gti'][$key]) ? $row['gti'][$key] : null;
                if ($meanWm2 === null) {
                    continue;
                }
                $any = true;
                $poaWhm2 = $meanWm2 * $hours; // mean power * hours -> Wh/m2
                foreach ($resp['arrays'] as $aid => $a) {
                    $poaByArray[$aid] = $poaWhm2;
                }
            }
            if (!$any) {
                $run->addInterval($startUtc, $endUtc, null, array('is_missing' => true));
                continue;
            }
            $est = $this->pv->intervalEnergy($poaByArray, $row['temp'], $hours);
            $kwh = $est['total_kwh'] * $coverageFactor;
            $run->addInterval($startUtc, $endUtc, $kwh, array(
                'pv_mean_power_kw' => $hours > 0 ? $kwh / $hours : 0.0,
                'array_id' => 'aggregate',
                'temp_fallback' => !empty($row['temp_fallback']) ? 1 : 0,
            ));
        }

        $status = ($failedKwp <= 0 && $run->coversHorizon($fromUtc, $toUtc)) ? 'fresh' : 'partial';
        return array('run' => $run, 'status' => $status, 'detail' => 'ok');
    }
}
