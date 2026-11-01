# Troubleshooting

## The cycle is not running

- Check `SolarPlanner` on the Overview tab: the cycle badge should be "online".
- Start it manually: `php /var/www/html/scripts/cycle_solarplanner.php`.
- If it exits immediately with "Another instance is already running", a stale
  lock may exist at `<tmp>/cycle_solarplanner.lock`; remove it only if no cycle
  process is actually running.

## Armed mode is blocked

The Overview/Safety tabs list the missing blocking parameters. Fill battery
count, capacity, reserve (> 0), tariff prices, transport, firmware, property map,
PV arrays, and verify the device profile. Until then only observe/shadow work.

## No forecast / plan status `no_forecast`

- Confirm at least one provider is enabled and reachable.
- Open-Meteo needs outbound HTTPS. The SSRF guard blocks private/reserved
  addresses by default.
- Check `solarplanner_command_audit` and the cycle stdout for provider errors
  (`quota`, `http`, `schema`). An exhausted quota or an open circuit breaker is
  reported per provider; others keep working.

## SOC looks wrong / writes refused

- A missing SOC is treated as `null`, never zero; stale SOC blocks any write.
  Check the `battery_soc` property mapping and its freshness.

## PV numbers look off

- Verify azimuth uses the module convention (0=N, 90=E, 180=S, 270=W) and the
  per-array `kwp`, `tilt_deg`, losses and temperature coefficient.
- Remember Open-Meteo hourly radiation is a mean over the preceding hour; the
  adapter already converts it to interval energy.

## TOU did not apply

- In shadow/observe mode writes are disabled by design.
- In armed mode, a readback mismatch marks the write unverified (state
  `degraded`); check `solarplanner_command_audit` for the `readback` entry and
  the mismatching fields. Restore from the pre-write snapshot if needed.

## RPi4 is sluggish

- Do not increase planning frequency. Keep logs/sessions on tmpfs.
- Do not stop Netdata (the dashboard hook depends on it; stopping it can exhaust
  PHP-FPM workers).
- Prune old `solarplanner_telemetry` rows if the table grows large.
