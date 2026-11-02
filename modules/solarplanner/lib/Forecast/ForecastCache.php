<?php
/**
 * ForecastCache — the latest successful forecast per provider, with its
 * retrieval time, so plan recomputes can reuse it without spending quota.
 *
 * Backed by a tiny key/value store (two callables) so it works both against the
 * MajorDoMo settings/DB and in tests. Values are plain JSON (a ForecastRun
 * array). No secrets are stored (ForecastRun carries none).
 *
 * This complements FetchScheduler: the scheduler decides WHEN to fetch; the
 * cache holds WHAT was last fetched and how old it is.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class ForecastCache
{
    /** @var callable function(string $key): ?string */
    private $getter;
    /** @var callable function(string $key, string $value): void */
    private $setter;
    /** @var int max age (seconds) a cached run is still considered usable */
    private $maxAgeSec;

    public function __construct(callable $getter, callable $setter, $maxAgeSec = 6 * 3600)
    {
        $this->getter = $getter;
        $this->setter = $setter;
        $this->maxAgeSec = (int)$maxAgeSec;
    }

    private function key($providerId)
    {
        return 'solarplanner_cache_' . preg_replace('/[^a-z0-9_]/i', '_', $providerId);
    }

    /**
     * Store a successful run for a provider.
     * @param string $providerId
     * @param array $runArray ForecastRun::toArray()
     * @param int $nowUtc
     */
    public function put($providerId, array $runArray, $nowUtc = null)
    {
        $nowUtc = $nowUtc === null ? time() : (int)$nowUtc;
        $payload = json_encode(array(
            'provider_id' => $providerId,
            'cached_at_utc' => $nowUtc,
            'run' => $runArray,
        ));
        call_user_func($this->setter, $this->key($providerId), $payload);
    }

    /**
     * Read the cached entry (regardless of freshness).
     * @return array|null ['cached_at_utc'=>int,'age_sec'=>int,'fresh'=>bool,'run'=>array]
     */
    public function get($providerId, $nowUtc = null)
    {
        $nowUtc = $nowUtc === null ? time() : (int)$nowUtc;
        $raw = call_user_func($this->getter, $this->key($providerId));
        if (!$raw) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['run']) || !isset($data['cached_at_utc'])) {
            return null;
        }
        $age = $nowUtc - (int)$data['cached_at_utc'];
        return array(
            'cached_at_utc' => (int)$data['cached_at_utc'],
            'age_sec' => $age,
            'fresh' => ($age >= 0 && $age <= $this->maxAgeSec),
            'run' => $data['run'],
        );
    }

    public function lastFetchUtc($providerId, $nowUtc = null)
    {
        $e = $this->get($providerId, $nowUtc);
        return $e ? $e['cached_at_utc'] : null;
    }

    public function maxAge()
    {
        return $this->maxAgeSec;
    }
}
