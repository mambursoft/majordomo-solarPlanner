<?php
/**
 * SqlForecastStore — MajorDoMo SQL-backed forecast/plan/quota/calibration store.
 *
 * Uses MajorDoMo's SQL helpers (SQLInsert/SQLSelect/SQLSelectOne/SQLUpdate/DBSafe).
 * Append-only: forecast runs are never overwritten. No API keys are stored in
 * snapshots.
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
    const ALGO_VERSION = '1.2.1';

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
            'PV_TOMORROW_RAW_KWH' => isset($plan['pv_tomorrow_raw_kwh']) ? (float)$plan['pv_tomorrow_raw_kwh'] : null,
            'LOAD_TOMORROW_KWH' => isset($plan['load_tomorrow_kwh']) ? (float)$plan['load_tomorrow_kwh'] : null,
            'TOMORROW_LOCAL_DATE' => isset($plan['tomorrow_local_date']) ? (string)$plan['tomorrow_local_date'] : '',
            'ALGORITHM_VERSION' => self::ALGO_VERSION,
            'PAYLOAD' => json_encode($plan),
            'CREATED' => date('Y-m-d H:i:s'),
        );
        return SQLInsert('solarplanner_planning_runs', $rec);
    }

    /** Forecast headline that was published for a given local date. */
    public function loadForecastForLocalDay($dateLocal)
    {
        $row = SQLSelectOne("SELECT PAYLOAD, PV_TOMORROW_RAW_KWH, PV_TOMORROW_KWH FROM solarplanner_planning_runs "
            . "WHERE TOMORROW_LOCAL_DATE='" . DBSafe($dateLocal) . "' AND PV_TOMORROW_KWH IS NOT NULL "
            . "ORDER BY PLAN_UTC DESC LIMIT 1");
        if (!$row) {
            return null;
        }
        $payload = json_decode($row['PAYLOAD'], true);
        // Prefer the raw (unscaled) ensemble value: calibration measures the
        // model bias, not the bias remaining after a previous correction.
        $raw = isset($row['PV_TOMORROW_RAW_KWH']) && $row['PV_TOMORROW_RAW_KWH'] !== null
            ? (float)$row['PV_TOMORROW_RAW_KWH'] : (float)$row['PV_TOMORROW_KWH'];
        if ($raw <= 0 && isset($payload['pv_tomorrow_raw_kwh'])) {
            $raw = (float)$payload['pv_tomorrow_raw_kwh'];
        }
        return array(
            'forecast_kwh' => $raw,
            'published_kwh' => (float)$row['PV_TOMORROW_KWH'],
            'payload' => is_array($payload) ? $payload : array(),
        );
    }

    /**
     * Measured PV energy (kWh) for one local calendar day, integrated from the
     * 60-second PV_POWER_KW telemetry samples by the trapezoidal rule.
     * Nulls/spikes are skipped so bad telemetry cannot poison calibration.
     */
    public function loadPvActualKwhByLocalDay($dateLocal, $tz = 'Europe/Kyiv')
    {
        try {
            $zone = new \DateTimeZone($tz);
            $start = (new \DateTime($dateLocal . ' 00:00:00', $zone))->format('U');
            $end = (new \DateTime($dateLocal . ' 00:00:00', $zone))->modify('+1 day')->format('U');
        } catch (\Exception $e) {
            return array('kwh' => null, 'samples' => 0);
        }
        $rows = SQLSelect("SELECT SAMPLE_UTC, PV_POWER_KW, FLAGS FROM solarplanner_telemetry "
            . "WHERE SAMPLE_UTC >= " . (int)$start . " AND SAMPLE_UTC < " . (int)$end
            . " AND PV_POWER_KW IS NOT NULL ORDER BY SAMPLE_UTC ASC");
        if (count($rows) < 10) {
            return array('kwh' => null, 'samples' => count($rows));
        }
        $prevT = null;
        $prevP = null;
        $kwh = 0.0;
        $used = 0;
        foreach ($rows as $r) {
            $flags = isset($r['FLAGS']) ? (string)$r['FLAGS'] : '';
            // Measured PV is not potential PV when the battery was full or the
            // inverter was clipping: those samples must not teach the model.
            if (strpos($flags, 'pv_battery_full') !== false
                || strpos($flags, 'pv_clipped') !== false) {
                $prevT = null;
                continue;
            }
            $t = (int)$r['SAMPLE_UTC'];
            $p = (float)$r['PV_POWER_KW'];
            // Reject impossible readings for a residential array.
            if ($p < 0 || $p > 100) {
                $prevT = null;
                continue;
            }
            if ($prevT !== null && $t > $prevT && ($t - $prevT) <= 600) {
                $kwh += (($prevP + $p) / 2.0) * (($t - $prevT) / 3600.0);
                $used++;
            }
            $prevT = $t;
            $prevP = $p;
        }
        return array('kwh' => $used > 0 ? round($kwh, 3) : null, 'samples' => $used);
    }

    public function saveCalibration(array $row)
    {
        $provider = isset($row['provider_id']) ? substr((string)$row['provider_id'], 0, 32) : 'ensemble';
        $horizon = isset($row['horizon']) ? substr((string)$row['horizon'], 0, 24) : 'tomorrow';
        $date = isset($row['date_local']) ? substr((string)$row['date_local'], 0, 10) : '';
        $rec = array(
            'MAE' => isset($row['mae']) && $row['mae'] !== null ? (float)$row['mae'] : null,
            'BIAS' => isset($row['bias']) && $row['bias'] !== null ? (float)$row['bias'] : null,
            'RMSE' => isset($row['rmse']) && $row['rmse'] !== null ? (float)$row['rmse'] : null,
            'MAPE' => isset($row['mape']) && $row['mape'] !== null ? (float)$row['mape'] : null,
            'SCALE' => isset($row['scale']) ? (float)$row['scale'] : null,
            'FORECAST_KWH' => isset($row['forecast_kwh']) ? (float)$row['forecast_kwh'] : null,
            'ACTUAL_KWH' => isset($row['actual_kwh']) ? (float)$row['actual_kwh'] : null,
            'N_SAMPLES' => isset($row['n_samples']) ? (int)$row['n_samples']
                : (isset($row['n']) ? (int)$row['n'] : 0),
            'UPDATED' => date('Y-m-d H:i:s'),
        );
        // One row per provider/horizon/local-day. The cycle builds a plan every
        // 15 min; inserting on every run used to create ~96 duplicates a day.
        $existing = SQLSelectOne("SELECT ID FROM solarplanner_calibration WHERE PROVIDER_ID='"
            . DBSafe($provider) . "' AND HORIZON='" . DBSafe($horizon)
            . "' AND DATE_LOCAL='" . DBSafe($date) . "' ORDER BY ID DESC LIMIT 1");
        if ($existing && !empty($existing['ID'])) {
            $rec['ID'] = $existing['ID'];
            return SQLUpdate('solarplanner_calibration', $rec);
        }
        $rec['PROVIDER_ID'] = $provider;
        $rec['HORIZON'] = $horizon;
        $rec['DATE_LOCAL'] = $date;
        return SQLInsert('solarplanner_calibration', $rec);
    }

    public function loadCalibration($providerId = 'ensemble', $horizon = 'tomorrow', $limit = 30)
    {
        $rows = SQLSelect("SELECT * FROM solarplanner_calibration WHERE PROVIDER_ID='"
            . DBSafe($providerId) . "' AND HORIZON='" . DBSafe($horizon)
            . "' ORDER BY ID DESC LIMIT " . max(1, min(365, (int)$limit)));
        return $rows ? $rows : array();
    }

    public function loadQuotaState()
    {
        $row = SQLSelectOne("SELECT PAYLOAD FROM solarplanner_quota_state WHERE STATE_KEY='default' ORDER BY ID DESC LIMIT 1");
        if (!$row || empty($row['PAYLOAD'])) {
            return array();
        }
        $state = json_decode($row['PAYLOAD'], true);
        return is_array($state) ? $state : array();
    }

    public function saveQuotaState(array $state)
    {
        $payload = json_encode($state);
        $row = SQLSelectOne("SELECT ID FROM solarplanner_quota_state WHERE STATE_KEY='default' ORDER BY ID DESC LIMIT 1");
        if ($row) {
            $rec = array('ID' => $row['ID'], 'PAYLOAD' => $payload, 'UPDATED' => date('Y-m-d H:i:s'));
            return SQLUpdate('solarplanner_quota_state', $rec);
        }
        return SQLInsert('solarplanner_quota_state', array(
            'STATE_KEY' => 'default', 'PAYLOAD' => $payload, 'UPDATED' => date('Y-m-d H:i:s'),
        ));
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
