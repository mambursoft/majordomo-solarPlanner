<?php
/**
 * SolcastAdapter — ready-made PV forecast from Solcast home (hobbyist) API.
 *
 * Home access is strictly limited (non-commercial, one location, up to two
 * tilt/azimuth combinations, a small number of requests per day). This adapter:
 *  - keeps the API key only in private config (never in ForecastRun),
 *  - uses one shared quota counter per account across all rooftop resources,
 *  - reserves part of the daily allowance for a critical pre-07:00 refinement,
 *  - caches responses (caller supplies caching via ForecastStore),
 *  - surfaces that the remaining quota is uncertain if the same key may be used
 *    by Home Assistant or elsewhere (shared_externally flag).
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class SolcastAdapter implements ForecastAdapter
{
    /** @var HttpClient */
    private $http;
    /** @var QuotaManager */
    private $quota;
    /** @var array resource ids (rooftop sites) */
    private $resourceIds;
    /** @var string api key (private) */
    private $apiKey;
    private $accountId;

    public function __construct(HttpClient $http, QuotaManager $quota, array $resourceIds, $apiKey, $accountId = 'solcast')
    {
        $this->http = $http;
        $this->quota = $quota;
        $this->resourceIds = $resourceIds;
        $this->apiKey = $apiKey;
        $this->accountId = $accountId;
    }

    public function providerId()
    {
        return 'solcast';
    }

    public function fetch($fromUtc, $toUtc, $critical = false)
    {
        if (empty($this->apiKey) || empty($this->resourceIds)) {
            return array('run' => null, 'status' => 'missing', 'detail' => 'no api key / resource configured');
        }
        $needUnits = count($this->resourceIds);
        $can = $this->quota->canRequest($this->accountId, $needUnits, $critical);
        if (!$can['allowed']) {
            return array('run' => null, 'status' => 'missing', 'detail' => 'quota: ' . $can['reason']);
        }

        $run = new ForecastRun('solcast');
        $run->energyBasis = 'interval';
        $run->validFromUtc = (int)$fromUtc;
        $run->validToUtc = (int)$toUtc;
        // Solcast forecasts describe the configured rooftop resources, not our
        // local coordinates. Hashing the resource ids lets the cache reject a
        // run produced with a different resource set.
        $run->locationConfigHash = '';
        $run->pvConfigHash = md5(json_encode($this->resourceIds));

        $byInterval = array();
        $rawConcat = '';
        $okAny = false;
        $okResources = 0;

        foreach ($this->resourceIds as $rid) {
            $url = 'https://api.solcast.com.au/rooftop_sites/' . rawurlencode($rid)
                . '/forecasts?format=json&hours=48';
            $guard = $this->http->guardUrl($url);
            if ($guard !== true) {
                continue;
            }
            $res = $this->http->get($url, array(
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ));
            if ($res['status'] !== 200) {
                $this->quota->recordFailure($this->accountId, $res['status']);
                continue;
            }
            $this->quota->recordSuccess($this->accountId, 1.0);
            $okAny = true;
            $okResources++;
            $rawConcat .= $res['body'];
            $data = json_decode($res['body'], true);
            if (!isset($data['forecasts'])) {
                continue;
            }
            foreach ($data['forecasts'] as $f) {
                // period_end is ISO8601 UTC; period like "PT30M".
                $end = isset($f['period_end']) ? (int)strtotime($f['period_end']) : 0;
                $periodMin = isset($f['period']) ? $this->parseIso8601Minutes($f['period']) : 30;
                $start = $end - $periodMin * 60;
                if ($end <= $fromUtc || $start >= $toUtc) {
                    continue;
                }
                $hours = $periodMin / 60.0;
                // pv_estimate is kW (mean power over the period).
                $kwh = isset($f['pv_estimate']) ? (float)$f['pv_estimate'] * $hours : 0.0;
                $lowKwh = isset($f['pv_estimate10']) ? (float)$f['pv_estimate10'] * $hours : null;
                $highKwh = isset($f['pv_estimate90']) ? (float)$f['pv_estimate90'] * $hours : null;
                if (!isset($byInterval[$start])) {
                    $byInterval[$start] = array('end' => $end, 'kwh' => 0.0, 'low' => 0.0,
                        'high' => 0.0, 'has_q' => false, 'partial_q' => false);
                }
                $byInterval[$start]['kwh'] += $kwh;
                // Only treat the band as a quantile pair when BOTH P10 and P90
                // are present. Substituting the median for a missing P90 used
                // to silently narrow the reported uncertainty.
                if ($lowKwh !== null && $highKwh !== null) {
                    $byInterval[$start]['low'] += $lowKwh;
                    $byInterval[$start]['high'] += $highKwh;
                    $byInterval[$start]['has_q'] = true;
                } elseif ($lowKwh !== null || $highKwh !== null) {
                    $byInterval[$start]['partial_q'] = true;
                }
            }
        }

        if (!$okAny) {
            return array('run' => null, 'status' => 'unusable', 'detail' => 'all resource requests failed');
        }

        // A failed resource must not silently become zero generation. Scale the
        // aggregate by the missing share and flag the run as partial.
        $expected = count($this->resourceIds);
        $coverageFactor = ($okResources > 0 && $okResources < $expected)
            ? min(2.0, $expected / $okResources) : 1.0;
        if ($coverageFactor > 1.0) {
            $run->qualityFlags[] = 'partial_resources:' . ($expected - $okResources) . '/' . $expected;
        }
        ksort($byInterval);
        foreach ($byInterval as $start => $row) {
            $kwh = $row['kwh'] * $coverageFactor;
            $extra = array(
                'pv_mean_power_kw' => ($row['end'] - $start) > 0 ? $kwh / (($row['end'] - $start) / 3600.0) : 0.0,
                'array_id' => 'aggregate',
            );
            if ($row['has_q']) {
                // Solcast documents these as percentile estimates; keep their
                // definition, do not relabel as calibrated P10/P90 of our site.
                $extra['quantile_low_kwh'] = $row['low'] * $coverageFactor;
                $extra['quantile_median_kwh'] = $kwh;
                $extra['quantile_high_kwh'] = $row['high'] * $coverageFactor;
            } elseif ($row['partial_q']) {
                $extra['quantile_incomplete'] = 1;
            }
            $run->addInterval($start, $row['end'], $kwh, $extra);
        }

        $run->rawResponseHash = hash('sha256', $rawConcat);
        $run->quotaState = $this->quota->snapshot($this->accountId);
        if (!empty($run->quotaState['shared_externally'])) {
            $run->qualityFlags[] = 'quota_uncertain:key_may_be_shared';
        }
        $run->qualityFlags[] = 'quantiles:solcast_percentile_not_site_calibrated';

        $status = ($okResources >= $expected && $run->coversHorizon($fromUtc, $toUtc)) ? 'fresh' : 'partial';
        return array('run' => $run, 'status' => $status, 'detail' => 'ok');
    }

    private function parseIso8601Minutes($period)
    {
        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?/', $period, $m)) {
            $h = isset($m[1]) ? (int)$m[1] : 0;
            $min = isset($m[2]) ? (int)$m[2] : 0;
            return $h * 60 + $min ?: 30;
        }
        return 30;
    }
}
