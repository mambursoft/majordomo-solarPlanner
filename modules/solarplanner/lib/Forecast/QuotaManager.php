<?php
/**
 * QuotaManager — per-provider quota, backoff and circuit-breaker state.
 *
 * Spec requirements honoured:
 *  - track HTTP requests AND estimated quota units separately,
 *  - persist state across restarts (caller supplies load/save callbacks),
 *  - next-allowed-request time, circuit breaker, reserve part of the daily
 *    allowance for a critical pre-07:00 refinement,
 *  - a shared counter per account (the same key covers all resources of one
 *    account), and surface the uncertainty when a key may be used elsewhere.
 *
 * This class holds NO API keys. It keys state by an opaque account id.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Forecast;

class QuotaManager
{
    /** @var array state keyed by account id */
    private $state;

    public function __construct(array $state = array())
    {
        $this->state = $state;
    }

    public function getState()
    {
        return $this->state;
    }

    private function acct($accountId)
    {
        if (!isset($this->state[$accountId])) {
            $this->state[$accountId] = array(
                'day' => gmdate('Y-m-d'),
                'http_requests' => 0,
                'quota_units' => 0.0,
                'daily_limit_units' => 0,     // 0 = unknown/unlimited
                'reserved_units' => 0.0,      // kept for critical night refinement
                'next_allowed_utc' => 0,
                'breaker_open_until_utc' => 0,
                'consecutive_failures' => 0,
                'shared_externally' => false, // e.g. HA uses the same key
                'last_ok_utc' => 0,
            );
        }
        // Daily roll-over (UTC day; provider-specific resets can override).
        if ($this->state[$accountId]['day'] !== gmdate('Y-m-d')) {
            $this->state[$accountId]['day'] = gmdate('Y-m-d');
            $this->state[$accountId]['http_requests'] = 0;
            $this->state[$accountId]['quota_units'] = 0.0;
        }
        return $this->state[$accountId];
    }

    public function configure($accountId, array $cfg)
    {
        $a = $this->acct($accountId);
        foreach (array('daily_limit_units', 'reserved_units', 'shared_externally') as $k) {
            if (isset($cfg[$k])) {
                $a[$k] = $cfg[$k];
            }
        }
        $this->state[$accountId] = $a;
    }

    /**
     * Decide whether a request is allowed right now.
     *
     * @param string $accountId
     * @param float $estUnits estimated quota units this call will cost
     * @param bool $critical critical pre-07:00 refinement may use reserved units
     * @param int $nowUtc
     * @return array ['allowed'=>bool,'reason'=>string]
     */
    public function canRequest($accountId, $estUnits = 1.0, $critical = false, $nowUtc = null)
    {
        $nowUtc = $nowUtc === null ? time() : (int)$nowUtc;
        $a = $this->acct($accountId);

        if ($nowUtc < $a['breaker_open_until_utc']) {
            return array('allowed' => false, 'reason' => 'circuit_breaker_open');
        }
        if ($nowUtc < $a['next_allowed_utc']) {
            return array('allowed' => false, 'reason' => 'backoff_wait');
        }
        if ($a['daily_limit_units'] > 0) {
            $budget = $a['daily_limit_units'] - (float)$a['quota_units'];
            if (!$critical) {
                $budget -= (float)$a['reserved_units'];
            }
            if ($budget < $estUnits) {
                return array('allowed' => false, 'reason' => $critical ? 'quota_exhausted' : 'quota_reserved_for_critical');
            }
        }
        return array('allowed' => true, 'reason' => 'ok');
    }

    public function recordSuccess($accountId, $units = 1.0, $nowUtc = null)
    {
        $nowUtc = $nowUtc === null ? time() : (int)$nowUtc;
        $a = $this->acct($accountId);
        $a['http_requests'] += 1;
        $a['quota_units'] += (float)$units;
        $a['consecutive_failures'] = 0;
        $a['next_allowed_utc'] = 0;
        $a['breaker_open_until_utc'] = 0;
        $a['last_ok_utc'] = $nowUtc;
        $this->state[$accountId] = $a;
    }

    /**
     * Record a failure. Applies backoff; opens the breaker after repeated
     * failures. 401/403 open a longer breaker (bad credentials).
     */
    public function recordFailure($accountId, $httpStatus = 0, $nowUtc = null)
    {
        $nowUtc = $nowUtc === null ? time() : (int)$nowUtc;
        $a = $this->acct($accountId);
        $a['http_requests'] += 1;
        $a['consecutive_failures'] += 1;

        $backoff = min(3600, 30 * (2 ** min(7, $a['consecutive_failures'])));
        $a['next_allowed_utc'] = $nowUtc + $backoff;

        if ($httpStatus === 401 || $httpStatus === 403) {
            $a['breaker_open_until_utc'] = $nowUtc + 3600; // auth problem — stop hammering
        } elseif ($a['consecutive_failures'] >= 5) {
            $a['breaker_open_until_utc'] = $nowUtc + 900;
        }
        if ($httpStatus === 429) {
            $a['breaker_open_until_utc'] = max($a['breaker_open_until_utc'], $nowUtc + 600);
        }
        $this->state[$accountId] = $a;
    }

    /** Snapshot of quota state for a ForecastRun (no secrets). */
    public function snapshot($accountId)
    {
        $a = $this->acct($accountId);
        return array(
            'day' => $a['day'],
            'http_requests' => $a['http_requests'],
            'quota_units' => $a['quota_units'],
            'daily_limit_units' => $a['daily_limit_units'],
            'reserved_units' => $a['reserved_units'],
            'next_allowed_utc' => $a['next_allowed_utc'],
            'breaker_open_until_utc' => $a['breaker_open_until_utc'],
            'shared_externally' => $a['shared_externally'],
        );
    }
}
