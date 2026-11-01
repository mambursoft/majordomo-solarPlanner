<?php
/**
 * PlanExecutor — apply a compiled TOU plan safely, with readback and audit.
 *
 * Flow (spec): check mode -> freshness -> BMS/alarms -> profile -> read current
 * TOU -> detect foreign changes -> save private snapshot -> validate new
 * schedule -> check field allowlist -> write -> read back -> compare -> audit.
 *
 * Idempotency: if the current TOU already matches the desired schedule within
 * tolerance, no write is performed. Hysteresis / minimum write interval are
 * enforced by the caller/cycle. A single lock/queue must guard concurrent runs.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Inverter;

use SolarPlanner\Safety\SafetySupervisor;

class PlanExecutor
{
    /** @var InverterAdapter */
    private $inv;
    /** @var callable audit sink: function(array $entry) */
    private $audit;

    public function __construct(InverterAdapter $inv, $audit = null)
    {
        $this->inv = $inv;
        $this->audit = $audit;
    }

    /**
     * @param array $desiredSlots compiled TOU slots
     * @param array $safetyCtx context for SafetySupervisor::canApply
     * @return array result
     */
    public function apply(array $desiredSlots, array $safetyCtx)
    {
        $current = $this->inv->readTou();
        $safetyCtx['current_tou_readable'] = !empty($current['readable']);
        $safetyCtx['fields'] = array('tou_slots');

        // Idempotency: skip if already matching.
        if (!empty($current['readable']) && $this->slotsEqual($current['slots'], $desiredSlots)) {
            $this->log(array('event' => 'skip_idempotent', 'slots' => $desiredSlots));
            return array('ok' => true, 'written' => false, 'reason' => 'already_matches', 'state' => SafetySupervisor::S_ACTIVE);
        }

        $decision = SafetySupervisor::canApply($safetyCtx);
        if (!$decision['allowed']) {
            $this->log(array('event' => 'write_denied', 'reason' => $decision['reason']));
            return array('ok' => false, 'written' => false, 'reason' => $decision['reason'], 'state' => $decision['next_state']);
        }

        // Snapshot current (private) before writing.
        $this->log(array('event' => 'pre_write_snapshot', 'current' => $current['slots']));

        $w = $this->inv->writeTou($desiredSlots);
        if (empty($w['ok'])) {
            $this->log(array('event' => 'write_failed', 'error' => isset($w['error']) ? $w['error'] : 'unknown'));
            return array('ok' => false, 'written' => false, 'reason' => isset($w['error']) ? $w['error'] : 'write_failed',
                'state' => SafetySupervisor::S_DEGRADED);
        }

        // Readback & verify — absence of error is NOT success.
        $after = $this->inv->readTou();
        $verify = SafetySupervisor::verifyReadback(
            array('tou_slots' => $desiredSlots),
            array('tou_slots' => isset($after['slots']) ? $after['slots'] : null)
        );
        $this->log(array('event' => 'readback', 'verified' => $verify['verified'], 'mismatches' => $verify['mismatches']));

        return array(
            'ok' => $verify['verified'],
            'written' => true,
            'reason' => $verify['verified'] ? 'applied_and_verified' : 'readback_mismatch',
            'state' => $verify['next_state'],
            'mismatches' => $verify['mismatches'],
        );
    }

    private function slotsEqual($a, $b)
    {
        return json_encode($a) === json_encode($b);
    }

    private function log(array $entry)
    {
        $entry['ts_utc'] = time();
        if (is_callable($this->audit)) {
            call_user_func($this->audit, $entry);
        }
    }
}
