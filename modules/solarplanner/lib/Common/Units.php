<?php
/**
 * Units — explicit, tested unit handling.
 *
 * The spec is emphatic: never confuse power (W / kW) with energy (Wh / kWh).
 * Internal model standardises on kW, kWh and hours. Drivers may use native
 * units but must convert explicitly here.
 *
 * PHP 7.3 compatible. No framework dependencies.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Common;

class Units
{
    /** Watts -> kilowatts */
    public static function wToKw($w)
    {
        return (float)$w / 1000.0;
    }

    /** Kilowatts -> watts */
    public static function kwToW($kw)
    {
        return (float)$kw * 1000.0;
    }

    /** Watt-hours -> kilowatt-hours */
    public static function whToKwh($wh)
    {
        return (float)$wh / 1000.0;
    }

    /** Kilowatt-hours -> watt-hours */
    public static function kwhToWh($kwh)
    {
        return (float)$kwh * 1000.0;
    }

    /**
     * Mean power (kW) over an interval -> energy (kWh).
     * Do NOT treat an instantaneous power sample as a mean over the interval
     * unless you know it is representative.
     *
     * @param float $meanPowerKw mean power during the interval, kW
     * @param float $durationHours interval length in hours
     * @return float energy in kWh
     */
    public static function powerToEnergyKwh($meanPowerKw, $durationHours)
    {
        return (float)$meanPowerKw * (float)$durationHours;
    }

    /**
     * Energy (kWh) over an interval -> mean power (kW).
     *
     * @param float $energyKwh
     * @param float $durationHours
     * @return float mean power kW, or 0.0 for non-positive duration
     */
    public static function energyToMeanPowerKw($energyKwh, $durationHours)
    {
        $durationHours = (float)$durationHours;
        if ($durationHours <= 0.0) {
            return 0.0;
        }
        return (float)$energyKwh / $durationHours;
    }

    /**
     * Clamp a value into [min, max].
     */
    public static function clamp($value, $min, $max)
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}
