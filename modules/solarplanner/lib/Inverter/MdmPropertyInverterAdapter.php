<?php
/**
 * MdmPropertyInverterAdapter — read inverter telemetry (and optionally write
 * TOU) through existing MajorDoMo object properties.
 *
 * Preferred first-release transport: reuse properties that an already-working
 * MajorDoMo module populates, rather than writing a new Modbus/logger driver.
 *
 * It depends on two injected callables so the core stays testable without
 * MajorDoMo globals:
 *   $getter(string $propertyName): mixed   — e.g. wraps gg()
 *   $setter(string $propertyName, $value): bool — e.g. wraps setGlobal() / callMethod
 *
 * The property map (which MajorDoMo object/property holds SOC, PV power, etc.)
 * is private configuration, not hard-coded.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Inverter;

class MdmPropertyInverterAdapter implements InverterAdapter
{
    /** @var callable */
    private $getter;
    /** @var callable|null */
    private $setter;
    /** @var array property map: logical_field => mdm property name */
    private $map;
    /** @var bool whether writing is enabled (shadow by default = false) */
    private $writeEnabled;

    public function __construct(callable $getter, array $map, $setter = null, $writeEnabled = false)
    {
        $this->getter = $getter;
        $this->map = $map;
        $this->setter = $setter;
        $this->writeEnabled = (bool)$writeEnabled;
    }

    private function g($field)
    {
        if (!isset($this->map[$field])) {
            return null; // missing -> null, never a silent zero
        }
        return call_user_func($this->getter, $this->map[$field]);
    }

    public function readTelemetry()
    {
        $soc = $this->g('battery_soc');
        return array(
            'battery_soc_pct' => $soc === null ? null : (float)$soc,
            'soc_updated_utc' => $this->g('soc_updated_utc'),
            'pv_power_kw' => $this->numOrNull('pv_power_kw'),
            'load_power_kw' => $this->numOrNull('load_power_kw'),
            'grid_power_kw' => $this->numOrNull('grid_power_kw'),
            'battery_power_kw' => $this->numOrNull('battery_power_kw'),
            'grid_connected' => $this->g('grid_connected'),
            'inverter_alarm' => $this->g('inverter_alarm'),
            'bms_charge_limit_a' => $this->numOrNull('bms_charge_limit_a'),
            'bms_discharge_limit_a' => $this->numOrNull('bms_discharge_limit_a'),
            'grid_charge_enabled' => $this->g('grid_charge_enabled'),
        );
    }

    private function numOrNull($field)
    {
        $v = $this->g($field);
        return $v === null || $v === '' ? null : (float)$v;
    }

    public function readTou()
    {
        // When TOU is mirrored into MajorDoMo properties, read it back; otherwise
        // return an empty set so the supervisor refuses writes (cannot read TOU).
        $raw = $this->g('tou_schedule_json');
        if ($raw === null || $raw === '') {
            return array('readable' => false, 'slots' => array());
        }
        $decoded = json_decode($raw, true);
        return array('readable' => is_array($decoded), 'slots' => is_array($decoded) ? $decoded : array());
    }

    public function writeTou(array $slots, array $opts = array())
    {
        if (!$this->writeEnabled || $this->setter === null) {
            return array('ok' => false, 'error' => 'writes disabled (shadow/observe)');
        }
        if (!isset($this->map['tou_schedule_json'])) {
            return array('ok' => false, 'error' => 'no tou_schedule_json property mapped');
        }
        $ok = call_user_func($this->setter, $this->map['tou_schedule_json'], json_encode($slots));
        return array('ok' => (bool)$ok, 'error' => $ok ? null : 'setter failed');
    }
}
