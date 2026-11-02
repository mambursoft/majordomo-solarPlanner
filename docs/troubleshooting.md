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

**First open the Diagnostics tab.** It runs an environment self-check and a live
provider attempt and shows the specific reason. Common causes it surfaces:

- **No provider enabled** — enable at least Open-Meteo on the Settings tab.
- **No outbound HTTPS** — the server cannot reach the internet. The most common
  variant is a TLS "unable to get local issuer certificate" error, i.e. the CA
  bundle is not configured. On Raspbian: `sudo apt-get install ca-certificates`,
  or set `curl.cainfo` in php.ini. The module now auto-detects common CA paths,
  but a missing/old bundle still needs fixing at the OS level.
- **Coordinates not set** — without latitude/longitude the module falls back to
  a default location; set your real coordinates (privately) for an accurate
  forecast. The Diagnostics tab shows "coordinates: config" vs "fallback".
- **Provider error** — the per-provider row shows the exact HTTP status / reason.
  An exhausted quota or an open circuit breaker is reported per provider; the
  others keep working.

Press **Run plan** on the Overview or Diagnostics tab to recompute immediately
and see the live result (shadow mode writes nothing to the inverter).

If the background cycle cannot load the saved settings it logs a warning to its
stdout and falls back to reading them directly from the database, so a plan is
still attempted.

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
