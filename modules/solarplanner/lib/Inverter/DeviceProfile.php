<?php
/**
 * DeviceProfile — verified description of an inverter's TOU / control surface.
 *
 * IMPORTANT: register addresses are NOT shipped as fact. A profile must be
 * explicitly marked `verified` by the owner after validation against the real
 * device, firmware and on-screen behaviour. Until then, control is refused and
 * only observe/shadow is allowed.
 *
 * The bundled "deye_sg05lp3" profile encodes only what the public datasheet
 * confirms (SIX TOU periods for the SUN-14/15/16/18/20K-SG05LP3-EU-SM2 series)
 * and leaves register addresses empty, to be filled from a verified source
 * (e.g. the owner's validated map, deye-inverter-mqtt or ha-solarman with a
 * pinned commit SHA). No addresses are invented here.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Inverter;

class DeviceProfile
{
    /** @var array */
    private $profile;

    public function __construct(array $profile)
    {
        $this->profile = $profile;
    }

    public static function deyeSg05lp3Template()
    {
        return array(
            'id' => 'deye_sg05lp3',
            'model_series' => 'SUN-14/15/16/18/20K-SG05LP3-EU-SM2',
            'verified' => false,            // owner must set true after validation
            'firmware' => null,             // fill from the real device
            'tou_slots' => 6,               // confirmed by datasheet
            'soc_mode' => null,             // 'soc' | 'voltage' — verify
            'time_step_min' => 1,
            'soc_step_pct' => 1,
            'power_unit' => null,           // 'W' | 'A' — verify
            'transport' => null,            // 'modbus_tcp' | 'rs485' | 'logger' | 'mdm_property'
            // Register addresses deliberately empty — fill from a verified map.
            'registers' => array(
                'grid_charge_enable' => null,
                'tou_time' => array(),      // per-slot time registers
                'tou_soc' => array(),       // per-slot SOC registers
                'tou_power' => array(),     // per-slot power registers
                'tou_enable_bits' => null,  // packed enable bits (if applicable)
            ),
            'notes' => 'TCP 8899 (logger) and Modbus TCP 502 are NOT the same '
                . 'protocol. Do not send a plain Modbus frame to the logger port. '
                . 'Validate packed bits and shared registers before any write.',
        );
    }

    public function isVerified()
    {
        return !empty($this->profile['verified']);
    }

    public function touSlots()
    {
        return isset($this->profile['tou_slots']) ? (int)$this->profile['tou_slots'] : 6;
    }

    public function get($key, $default = null)
    {
        return isset($this->profile[$key]) ? $this->profile[$key] : $default;
    }

    public function toArray()
    {
        return $this->profile;
    }

    /**
     * Read-modify-write helper for PACKED registers: set only the target bits,
     * preserving all foreign bits exactly. Used so a TOU enable write never
     * clobbers unrelated bits in a shared register.
     *
     * @param int $current current 16-bit register value
     * @param int $mask bits this write owns
     * @param int $value new value for the owned bits (already shifted into mask)
     * @return int new register value
     */
    public static function packBits($current, $mask, $value)
    {
        $current &= 0xFFFF;
        $mask &= 0xFFFF;
        $value &= $mask;
        return (($current & ~$mask) | $value) & 0xFFFF;
    }
}
