<?php
/** Units and Wh/kWh scaling tests. */

use SolarPlanner\Common\Units;

T::group('Units (Wh/kWh, power/energy)');

T::approx(Units::wToKw(1500), 1.5, 1e-9, 'W -> kW');
T::approx(Units::whToKwh(2500), 2.5, 1e-9, 'Wh -> kWh');
T::approx(Units::kwhToWh(2.5), 2500, 1e-9, 'kWh -> Wh');

// Energy from mean power over 30 min.
T::approx(Units::powerToEnergyKwh(2.0, 0.5), 1.0, 1e-9, '2 kW over 0.5 h = 1 kWh (not 2 kWh/day confusion)');
T::approx(Units::energyToMeanPowerKw(1.0, 0.5), 2.0, 1e-9, '1 kWh over 0.5 h = 2 kW');
T::eq(Units::energyToMeanPowerKw(1.0, 0.0), 0.0, 'zero duration -> 0 power (no divide by zero)');

T::approx(Units::clamp(120, 0, 100), 100, 1e-9, 'clamp upper');
T::approx(Units::clamp(-5, 0, 100), 0, 1e-9, 'clamp lower');
