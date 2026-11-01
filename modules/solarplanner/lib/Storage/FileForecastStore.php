<?php
/**
 * FileForecastStore — append-only JSON-lines store for tests and a lightweight
 * fallback. On the RPi4 this keeps disk writes minimal and avoids heavy DB load.
 *
 * Snapshots are append-only: saveForecastRun never rewrites an existing run.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Storage;

class FileForecastStore implements ForecastStore
{
    private $dir;

    public function __construct($dir)
    {
        $this->dir = rtrim($dir, '/\\');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function saveForecastRun(array $runArray)
    {
        $id = isset($runArray['forecast_run_id']) ? $runArray['forecast_run_id'] : uniqid('run', true);
        $runArray = $this->stripSecrets($runArray);
        $line = json_encode(array('id' => $id, 'saved_utc' => time(), 'run' => $runArray));
        file_put_contents($this->dir . '/forecast_runs.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        return $id;
    }

    public function loadRunsBefore($cutoffUtc, $providerId = null)
    {
        $path = $this->dir . '/forecast_runs.jsonl';
        if (!file_exists($path)) {
            return array();
        }
        $out = array();
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $rec = json_decode($line, true);
            if (!$rec || !isset($rec['run'])) {
                continue;
            }
            $r = $rec['run'];
            $retrieved = isset($r['retrieved_at_utc']) ? (int)$r['retrieved_at_utc'] : 0;
            if ($retrieved > $cutoffUtc) {
                continue; // no peeking at forecasts retrieved after the cutoff
            }
            if ($providerId !== null && isset($r['provider_id']) && $r['provider_id'] !== $providerId) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    public function savePlanningRun(array $planArray)
    {
        $planArray = $this->stripSecrets($planArray);
        $line = json_encode(array('saved_utc' => time(), 'plan' => $planArray));
        file_put_contents($this->dir . '/planning_runs.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        return true;
    }

    public function loadQuotaState()
    {
        $path = $this->dir . '/quota_state.json';
        if (!file_exists($path)) {
            return array();
        }
        $d = json_decode(file_get_contents($path), true);
        return is_array($d) ? $d : array();
    }

    public function saveQuotaState(array $state)
    {
        file_put_contents($this->dir . '/quota_state.json', json_encode($state), LOCK_EX);
        return true;
    }

    private function stripSecrets(array $a)
    {
        foreach (array('api_key', 'apikey', 'token', 'password', 'secret') as $k) {
            unset($a[$k]);
        }
        return $a;
    }
}
