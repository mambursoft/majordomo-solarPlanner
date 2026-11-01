<?php
/**
 * SafetySupervisor — pre-write and post-write safety checks.
 *
 * Control states: observe, shadow, approval_required, armed, applying,
 * verifying, active, degraded, blocked, manual_override.
 *
 * By default the module is in observe/shadow and NEVER writes to the inverter.
 * A write is permitted only when every precondition passes AND the state is
 * armed with a valid approval.
 *
 * This component is pure logic (no network). The InverterAdapter performs the
 * actual read-modify-write; the supervisor decides whether that is allowed.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Safety;

class SafetySupervisor
{
    const S_OBSERVE = 'observe';
    const S_SHADOW = 'shadow';
    const S_APPROVAL = 'approval_required';
    const S_ARMED = 'armed';
    const S_APPLYING = 'applying';
    const S_VERIFYING = 'verifying';
    const S_ACTIVE = 'active';
    const S_DEGRADED = 'degraded';
    const S_BLOCKED = 'blocked';
    const S_MANUAL = 'manual_override';

    /** Fields the module is ever allowed to write (allowlist). */
    public static function writeAllowlist()
    {
        return array(
            'tou_slots',            // the six TOU periods
            'grid_charge_enable',   // global grid-charge enable
        );
    }

    /**
     * Decide whether a write is permitted.
     *
     * @param array $ctx keys:
     *   state (string), approved (bool), approval_until_utc (int),
     *   telemetry_age_s (int), max_telemetry_age_s (int),
     *   bms_alarm (bool), inverter_alarm (bool),
     *   profile_verified (bool),
     *   current_tou_readable (bool),
     *   foreign_change_detected (bool),
     *   fields (array of field names to write),
     *   now_utc (int)
     * @return array ['allowed'=>bool,'reason'=>string,'next_state'=>string]
     */
    public static function canApply(array $ctx)
    {
        $now = isset($ctx['now_utc']) ? (int)$ctx['now_utc'] : time();
        $state = isset($ctx['state']) ? $ctx['state'] : self::S_OBSERVE;

        if (in_array($state, array(self::S_OBSERVE, self::S_SHADOW), true)) {
            return self::deny('state is ' . $state . ' (no writes by design)', self::S_SHADOW);
        }
        if ($state === self::S_MANUAL) {
            return self::deny('manual_override active', self::S_MANUAL);
        }
        if ($state === self::S_BLOCKED) {
            return self::deny('blocked', self::S_BLOCKED);
        }
        if (empty($ctx['approved'])) {
            return self::deny('no valid approval', self::S_APPROVAL);
        }
        if (isset($ctx['approval_until_utc']) && $now > (int)$ctx['approval_until_utc']) {
            return self::deny('approval expired', self::S_APPROVAL);
        }
        $age = isset($ctx['telemetry_age_s']) ? (int)$ctx['telemetry_age_s'] : PHP_INT_MAX;
        $maxAge = isset($ctx['max_telemetry_age_s']) ? (int)$ctx['max_telemetry_age_s'] : 300;
        if ($age > $maxAge) {
            return self::deny('telemetry stale (' . $age . 's > ' . $maxAge . 's)', self::S_DEGRADED);
        }
        if (!empty($ctx['bms_alarm'])) {
            return self::deny('BMS alarm active', self::S_BLOCKED);
        }
        if (!empty($ctx['inverter_alarm'])) {
            return self::deny('inverter alarm active', self::S_BLOCKED);
        }
        if (empty($ctx['profile_verified'])) {
            return self::deny('device profile not verified', self::S_BLOCKED);
        }
        if (empty($ctx['current_tou_readable'])) {
            return self::deny('cannot read current TOU', self::S_DEGRADED);
        }
        if (!empty($ctx['foreign_change_detected'])) {
            return self::deny('foreign TOU change detected (conflict)', self::S_MANUAL);
        }
        // Validate requested fields against the allowlist.
        $allow = self::writeAllowlist();
        foreach ((isset($ctx['fields']) ? $ctx['fields'] : array()) as $f) {
            if (!in_array($f, $allow, true)) {
                return self::deny('field not in write allowlist: ' . $f, self::S_BLOCKED);
            }
        }
        return array('allowed' => true, 'reason' => 'ok', 'next_state' => self::S_APPLYING);
    }

    private static function deny($reason, $nextState)
    {
        return array('allowed' => false, 'reason' => $reason, 'next_state' => $nextState);
    }

    /**
     * Post-write verification: a write is only a success if readback matches
     * the expected result. Absence of a TCP error is NOT success.
     *
     * @param array $expected field => value
     * @param array $readback field => value
     * @return array ['verified'=>bool,'mismatches'=>array,'next_state'=>string]
     */
    public static function verifyReadback(array $expected, array $readback)
    {
        $mismatch = array();
        foreach ($expected as $k => $v) {
            if (!array_key_exists($k, $readback)) {
                $mismatch[$k] = array('expected' => $v, 'got' => null);
                continue;
            }
            if (self::normalize($readback[$k]) !== self::normalize($v)) {
                $mismatch[$k] = array('expected' => $v, 'got' => $readback[$k]);
            }
        }
        $ok = empty($mismatch);
        return array(
            'verified' => $ok,
            'mismatches' => $mismatch,
            'next_state' => $ok ? self::S_ACTIVE : self::S_DEGRADED,
        );
    }

    private static function normalize($v)
    {
        if (is_array($v)) {
            return json_encode($v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v) || is_int($v)) {
            return (string)(0 + $v);
        }
        return (string)$v;
    }
}
