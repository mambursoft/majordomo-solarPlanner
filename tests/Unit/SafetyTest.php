<?php
/** SafetySupervisor: shadow refuses writes, readback must match, allowlist. */

use SolarPlanner\Safety\SafetySupervisor;
use SolarPlanner\Inverter\DeviceProfile;

T::group('SafetySupervisor');

$shadow = SafetySupervisor::canApply(array('state' => 'shadow'));
T::ok(!$shadow['allowed'], 'shadow mode refuses writes by design');

$stale = SafetySupervisor::canApply(array(
    'state' => 'armed', 'approved' => true, 'approval_until_utc' => time() + 3600,
    'telemetry_age_s' => 10000, 'max_telemetry_age_s' => 300,
    'profile_verified' => true, 'current_tou_readable' => true, 'fields' => array('tou_slots'),
));
T::ok(!$stale['allowed'], 'stale telemetry blocks write');
T::eq($stale['next_state'], SafetySupervisor::S_DEGRADED, 'stale -> degraded');

$notVerified = SafetySupervisor::canApply(array(
    'state' => 'armed', 'approved' => true, 'approval_until_utc' => time() + 3600,
    'telemetry_age_s' => 10, 'max_telemetry_age_s' => 300,
    'profile_verified' => false, 'current_tou_readable' => true, 'fields' => array('tou_slots'),
));
T::ok(!$notVerified['allowed'], 'unverified profile blocks write');

$badField = SafetySupervisor::canApply(array(
    'state' => 'armed', 'approved' => true, 'approval_until_utc' => time() + 3600,
    'telemetry_age_s' => 10, 'max_telemetry_age_s' => 300,
    'profile_verified' => true, 'current_tou_readable' => true,
    'fields' => array('grid_code'),
));
T::ok(!$badField['allowed'], 'field outside allowlist blocked');

$good = SafetySupervisor::canApply(array(
    'state' => 'armed', 'approved' => true, 'approval_until_utc' => time() + 3600,
    'telemetry_age_s' => 10, 'max_telemetry_age_s' => 300,
    'profile_verified' => true, 'current_tou_readable' => true,
    'foreign_change_detected' => false, 'fields' => array('tou_slots'),
));
T::ok($good['allowed'], 'all checks pass -> write allowed');

// Readback must match (absence of error is not success).
$v = SafetySupervisor::verifyReadback(array('tou_slots' => array('a' => 1)), array('tou_slots' => array('a' => 2)));
T::ok(!$v['verified'], 'readback mismatch is NOT a success');
$v2 = SafetySupervisor::verifyReadback(array('tou_slots' => array('a' => 1)), array('tou_slots' => array('a' => 1)));
T::ok($v2['verified'], 'matching readback verifies');

T::group('Packed register read-modify-write');
// Preserve foreign bits: set bit 0 in a register that has bits 8 and 15 set.
$current = 0b1000000100000000; // bits 15 and 8
$out = DeviceProfile::packBits($current, 0b1, 0b1);
T::eq($out & 0b1, 1, 'target bit set');
T::eq(($out >> 8) & 1, 1, 'foreign bit 8 preserved');
T::eq(($out >> 15) & 1, 1, 'foreign bit 15 preserved');
