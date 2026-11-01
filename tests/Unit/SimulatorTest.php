<?php
/** EnergySimulator invariants + missing-data handling. */

use SolarPlanner\Simulation\BatteryModel;
use SolarPlanner\Simulation\EnergySimulator;

T::group('EnergySimulator invariants');

function spBatt()
{
    return new BatteryModel(array(
        'capacity_kwh' => 10.0, 'min_soc_pct' => 10, 'reserve_soc_pct' => 20,
        'max_soc_pct' => 100, 'max_charge_kw' => 5, 'max_discharge_kw' => 5,
        'eta_charge' => 1.0, 'eta_discharge' => 1.0, 'taper_start_soc_pct' => 101,
    ));
}

function spIv($start, $pv, $load, $cheap, $price = 1.0)
{
    return array('start_utc' => $start, 'end_utc' => $start + 1800, 'hours' => 0.5,
        'pv_kwh' => $pv, 'load_kwh' => $load, 'is_cheap' => $cheap, 'price' => $price);
}

// Missing pv/load must throw (not silently become zero).
$threw = false;
try {
    $sim = new EnergySimulator(spBatt(), array());
    $sim->simulate(50, array(array('start_utc' => 0, 'end_utc' => 1800, 'hours' => 0.5, 'load_kwh' => 1)));
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
T::ok($threw, 'missing pv_kwh throws (missing != zero)');

// Simple day: surplus PV charges battery; no simultaneous import/export.
$sim = new EnergySimulator(spBatt(), array('allow_export' => false, 'allow_grid_charge' => false, 'night_house_from_grid' => false));
$ivs = array(
    spIv(1000, 3.0, 1.0, false), // surplus 2 kWh over 0.5h -> within 5kW*0.5=2.5
    spIv(2800, 0.0, 2.0, false), // discharge
);
$res = $sim->simulate(50, $ivs);
T::eq($res['invariant_errors'], array(), 'no invariant errors on simple day');
foreach ($res['intervals'] as $r) {
    T::ok(!($r['batt_charge_kwh'] > 1e-9 && $r['batt_discharge_kwh'] > 1e-9), 'no simultaneous charge+discharge');
    T::ok(!($r['grid_import_kwh'] > 1e-9 && $r['grid_export_kwh'] > 1e-9), 'no simultaneous import+export');
    T::ok($r['soc_pct'] >= -1e-6 && $r['soc_pct'] <= 100 + 1e-6, 'SOC within bounds');
}

// SOC cannot exceed 100 even with large PV.
$res2 = $sim->simulate(95, array(spIv(1000, 50.0, 0.0, false)));
T::ok($res2['end_soc_pct'] <= 100 + 1e-6, 'SOC capped at 100 with huge PV');
T::ok($res2['pv_curtailed_kwh'] > 0, 'excess PV becomes curtailment when export disabled');

// Night house-from-grid must NOT also discharge the battery for the same load.
$simNight = new EnergySimulator(spBatt(), array('night_house_from_grid' => true, 'allow_grid_charge' => false));
$resN = $simNight->simulate(50, array(spIv(1000, 0.0, 2.0, true)));
T::approx($resN['intervals'][0]['grid_import_kwh'], 2.0, 1e-6, 'night load served from grid');
T::approx($resN['intervals'][0]['batt_discharge_kwh'], 0.0, 1e-6, 'battery not double-charged for night load');

T::group('Power limits');
// Discharge power cannot exceed max_discharge_kw.
$simP = new EnergySimulator(spBatt(), array('night_house_from_grid' => false));
$resP = $simP->simulate(100, array(array('start_utc' => 0, 'end_utc' => 1800, 'hours' => 0.5,
    'pv_kwh' => 0.0, 'load_kwh' => 10.0, 'is_cheap' => false, 'price' => 1.0)));
T::ok(!in_array('discharge_power_exceeded', array_keys($resP['invariant_errors']), true), 'discharge respects power limit');
T::ok($resP['intervals'][0]['grid_import_kwh'] > 0, 'load above battery power -> grid import (not masked)');
