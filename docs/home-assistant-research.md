# Home Assistant research

Home Assistant is treated as a source of proven approaches, data models and
integrations — **not** a required dependency. The final module runs as a
MajorDoMo module without HA installed. An optional HA bridge is a future
extension for owners who already run HA.

The following were studied as references. No code was copied verbatim; ideas
were reimplemented. Verify the license of each specific file and its
attribution/NOTICE obligations before reusing any code. (Content rephrased for
licensing compliance.)

## Open-Meteo Solar Forecast — [rany2/ha-open-meteo-solar-forecast](https://github.com/rany2/ha-open-meteo-solar-forecast)

- License declared: Apache-2.0.
- Useful ideas: splitting site vs per-array parameters, weather→PV conversion,
  panel temperature handling, DC losses separate from cell efficiency, morning
  and evening shading, multi-array aggregation, shared inverter limits, hourly /
  interval formats, and unit/azimuth conversion **tests**.
- Azimuth there is 0 = North, 90 = East, 180 = South, 270 = West — the same
  convention this module uses internally.
- Lesson from its issue history: temperature-calc and azimuth conversions are
  where bugs hide, which is why this module has mandatory physical-model tests
  (`tests/Unit/AzimuthTest.php`, `PvModel`).
- Do not copy README numeric coefficients as fact for this installation.

## HA Solarman — [davidrapan/ha-solarman](https://github.com/davidrapan/ha-solarman)

- Audit target for logger transport, model detection, telemetry, value scaling,
  sign handling, register profiles, writable controls and write verification.
- Note: an **open** PR for Deye TOU slot controls / packed-register handling is
  not proof the feature exists in an installed release. Pin a commit SHA and
  check license + status before reusing anything.
- Do not carry register addresses from another Deye series without verification.

## deye-inverter-mqtt — [kbialek/deye-inverter-mqtt](https://github.com/kbialek/deye-inverter-mqtt)

- Reference for Deye transport and register handling. TCP 8899 (logger) and
  Modbus TCP 502 are different protocols — never send a plain Modbus frame to
  the logger port.

## EMHASS — [docs](https://emhass.readthedocs.io/)

- Architectural reference for separating data collection, forecasting,
  optimization and execution; forecasting load alongside generation; replanning
  from actual state; explicit energy balance; separate import/export/charge/
  discharge limits; publishing expected SOC and plan status, not just a command;
  accounting for controllable loads; optional external forecast.
- EMHASS is **not** a required MVP component. A heavier physical model could be
  an optional sidecar later, but the PHP module, UI, planning and safe fallback
  must never silently depend on an undocumented Python process.

## Further audit (future work)

Forecast.Solar official HA integration, the current supported HA Solcast
integration, Predbat (battery planning approaches) and pvlib (physical PV
reference). These are follow-up research tasks; this module does not claim
Predbat directly supports the target Deye model or is license-compatible.
