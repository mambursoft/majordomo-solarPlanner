<?php
/**
 * InverterAdapter — contract for reading telemetry and writing allowed controls.
 *
 * Implementations: MdmPropertyAdapter (read/write via MajorDoMo properties),
 * and (after verification) ModbusTcp / SolarmanLogger adapters.
 *
 * Writes are restricted to the SafetySupervisor allowlist and must support
 * readback. Addresses/transport/serial come from a verified DeviceProfile and
 * private configuration — never hard-coded here.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Inverter;

interface InverterAdapter
{
    /** Read the current telemetry snapshot (associative array). */
    public function readTelemetry();

    /** Read the current TOU schedule as a normalized array of slots. */
    public function readTou();

    /**
     * Write a validated TOU schedule. MUST perform read-modify-write for packed
     * registers and MUST NOT touch any field outside the allowlist.
     *
     * @param array $slots normalized slots
     * @param array $opts
     * @return array ['ok'=>bool,'error'=>string|null]
     */
    public function writeTou(array $slots, array $opts = array());
}
