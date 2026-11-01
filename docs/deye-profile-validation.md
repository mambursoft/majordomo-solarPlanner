# Deye device profile validation

**Nothing is written to the inverter until a device profile is verified and the
module is explicitly armed with approval.** The bundled profile ships
`verified=false` and with empty register addresses on purpose.

## Confirmed facts

- The series datasheet for **SUN-14/15/16/18/20K-SG05LP3-EU-SM2** lists **six**
  charge/discharge time periods. Never assume a seventh TOU slot.
  Source: official Deye datasheet (series PDF).
- A TOU row's SOC can be used as a charge target or a discharge threshold, and
  grid charge is governed by a separate enable. Do not treat a daytime
  `Batt = 100%` as a plain "top up from sun to 100%" — it may forbid the desired
  discharge. Source: Deye technical partner TOU guide (series-specific; confirm
  for SG05 firmware).

## Mandatory audit before any write

Verify on the real device and firmware:

- model and firmware version,
- SOC mode vs voltage mode,
- global grid-charge enable,
- per-TOU-slot grid-charge enable,
- time boundaries and active weekdays,
- power/current units (W vs A),
- behaviour across midnight,
- minimum time step,
- packed bits and shared registers,
- behaviour on a partial write,
- compatibility with generator and export bits,
- the real on-screen reflection of each change.

## How to fill the profile

1. Copy `examples/device-profile.deye-sg05lp3.example.json` to a private file.
2. Fill register addresses **only** from a verified source: your own validated
   read of the device, or `deye-inverter-mqtt` / `ha-solarman` with a pinned
   commit SHA whose license and status you checked.
3. Record the transport (`modbus_tcp` / `rs485` / `logger` / `mdm_property`).
   TCP 8899 (logger) and Modbus TCP 502 are different protocols.
4. Set `verified: true` only after the on-screen behaviour matches.

## Packed / shared registers

Use `DeviceProfile::packBits($current, $mask, $value)` for a read-modify-write
that sets only the owned bits and preserves all foreign bits. A test in
`tests/Unit/SafetyTest.php` proves foreign bits survive.

## Forbidden automatic changes

Without a separate, explicit task the module must never change grid code,
voltage/frequency protections, anti-islanding, battery type, BMS protocol,
shutdown/restart thresholds, critical voltage settings, BMS-bypass parameters,
the physical export mode, or powers above agreed limits. Access to TOU is not
permission to change every register.
