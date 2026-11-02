<?php
/**
 * SqlForecastStore — MajorDoMo SQL-backed forecast/plan/quota store.
 *
 * Uses MajorDoMo's SQL helpers (SQLInsert/SQLSelect/DBSafe). Append-only:
 * forecast runs are never overwritten. No API keys are stored in snapshots.
 *
 * Kept out of the pure-logic lib so the engine stays testable without the DB;
 * this class is only loaded inside MajorDoMo where the helpers exist.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\MajorDoMo;

use SolarPlanner\Storage\ForecastStore;

class SqlForecastStore implements ForecastStore
{
    const ALGO_VERSION = '1.0.0';

    public function saveForecastRun(array $run)
    {
        $run = $this->stripSecrets($run);
        $rec = array(
            'PROVIDER_ID' => isset($run['provider_id']) ? $run['provider_id'] : '',
            'FORECAST_RUN_ID' => isset($run['forecast_run_id']) ? $run['forecast_run_id'] : '',
            'RETRIEVED_AT_UTC' => isset($run['retrieved_at_utc']) ? (int)$run['retrieved_at_utc'] : 0,
            'VALID_FROM_UTC' => isset($run['valid_from_utc']) ? (int)$run['valid_from_utc'] : 0,
            'VALID_TO_UTC' => isset($run['valid_to_utc']) ? (int)$run['valid_to_utc'] : 0,
            'ENERGY_BASIS' => isset($run['energy_basis']) ? $run['energy_basis'] : '',
            'RAW_RESPONSE_HASH' => isset($run['raw_response_hash']) ? $run['raw_response_hash'] : '',
            'PAYLOAD' => json_encode($run),
            'CREATED' => date('Y-m-d H:i:s'),
        );
        $id = SQLInsert('solarplanner_forecast_runs', $rec);
        if (!empty($run['intervals'])) {
            foreach ($run['intervals'] as $iv) {
                // SQLInsert takes the record by reference -> must be a variable.
                $ivRec = array(
                    'RUN_ID' => $id,
                    'INTERVAL_START_UTC' => (int)$iv['interval_start_utc'],
                    'INTERVAL_END_UTC' => (int)$iv['interval_end_utc'],
                    'PV_ENERGY_KWH' => $iv['pv_energy_kwh'] === null ? 0 : (float)$iv['pv_energy_kwh'],
                    'IS_MISSING' => !empty($iv['is_missing']) ? 1 : 0,
                    'IS_INTERPOLATED' => !empty($iv['is_interpolated']) ? 1 : 0,
                );
                SQLInsert('solarplanner_forecast_intervals', $ivRec);
            }
        }
        return $id;
    }

    public function loadRunsBefore($cutoffUtc, $providerId = null)
    {
        $where = "RETRIEVED_AT_UTC <= " . (int)$cutoffUtc;
        if ($providerId !== null) {
            $where .= " AND PROVIDER_ID='" . DBSafe($providerId) . "'";
        }
        $rows = SQLSelect("SELECT * FROM solarplanner_forecast_runs WHERE $where ORDER BY RETRIEVED_AT_UTC ASC");
        $out = array();
        foreach ($rows as $r) {
            $payload = json_decode($r['PAYLOAD'], true);
            if (is_array($payload)) {
                $out[] = $payload;
            }
        }
        return $out;
    }

    public function savePlanningRun(array $plan)
    {
        $plan = $this->stripSecrets($plan);
        $rec = array(
            'PLAN_UTC' => time(),
            'STATUS' => isset($plan['status']) ? $plan['status'] : '',
            'TARGET_SOC_AT_07' => isset($plan['target_soc_at_07']) ? (float)$plan['target_soc_at_07'] : null,
            'NIGHT_IMPORT_KWH' => isset($plan['planned_night_import_kwh']) ? (float)$plan['planned_night_import_kwh'] : null,
            'DAY_IMPORT_KWH' => isset($plan['expected_day_import_kwh']) ? (float)$plan['expected_day_import_kwh'] : null,
            'PV_TOMORROW_KWH' => isset($plan['pv_tomorrow_kwh']) ? (float)$plan['pv_tomorrow_kwh'] : null,
            'LOAD_TOMORROW_KWH' => isset($plan['load_tomorrow_kwh']) ? (float)$plan['load_tomorrow_kwh'] : null,
            'ALGORITHM_VERSION' => self::ALGO_VERSION,
            'PAYLOAD' => json_encode($plan),
            'CREATED' => date('Y-m-d H:i:s'),
        );
        return SQLInsert('solarplanner_planning_runs', $rec);
    }

    public function loadQuotaState()
    {
        $row = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='solarplanner'");
        // quota state is kept in module config, not here; return empty.
        return array();
    }

    public function saveQuotaState(array $state)
    {
        // Persisted via module config (getConfig/saveConfig) by the cycle.
        return true;
    }

    public function audit($event, array $detail)
    {
        $rec = array(
            'TS_UTC' => time(),
            'EVENT' => substr($event, 0, 48),
            'DETAIL' => json_encode($this->stripSecrets($detail)),
        );
        SQLInsert('solarplanner_command_audit', $rec);
    }

    private function stripSecrets(array $a)
    {
        foreach (array('api_key', 'apikey', 'token', 'password', 'secret',
                     'solcast_api_key', 'forecast_solar_api_key', 'lat', 'lon',
                     'latitude', 'longitude') as $k) {
            if (isset($a[$k])) {
                unset($a[$k]);
            }
        }
        return $a;
    }
}
