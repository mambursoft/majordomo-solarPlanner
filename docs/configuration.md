# Configuration

All settings live in the module configuration (MajorDoMo `getConfig`/`saveConfig`).
Secrets (coordinates, API keys) are kept private and masked in the UI and logs.
See `examples/config.example.json` and `examples/property-map.example.json` for
synthetic templates.

## Core

| Key | Meaning | Default |
|---|---|---|
| `CONTROL_MODE` | observe / shadow / armed | `shadow` |
| `TIMEZONE` | IANA timezone of the installation | `Europe/Kyiv` (confirm!) |
| `GRID_STEP_MIN` | calculation grid: 15 / 30 / 60 | 30 |
| `MODE` | `reach_full` or `minimum_cost` | `reach_full` |
| `CHEAP_START_HOUR` / `CHEAP_END_HOUR` | cheap tariff window (local) | 23 / 7 |
| `CHEAP_START_MIN` / `CHEAP_END_MIN` | minute precision within the cheap window | 0 / 0 |
| `CURTAILMENT_PENALTY_PER_KWH` | cost assigned to surplus PV in the optimizer | 0.05 |
| `TARIFF_CHEAP_PRICE` / `TARIFF_DAY_PRICE` | per-kWh prices | 0 (must set) |
| `ALLOW_EXPORT` | permit export in the model | 0 |
| `SOC_STEP_PCT` | candidate SOC granularity | 5 |
| `ENSEMBLE_METHOD` | median / mean / weighted / best | median |

## Battery

| Key | Meaning |
|---|---|
| `BATTERY_COUNT` | confirmed number of batteries (2 or 3 — confirm) |
| `BATTERY_CAPACITY_KWH` | total available capacity on the SOC scale |
| `MIN_SOC_PCT` / `MAX_SOC_PCT` | hardware floor / ceiling |
| `RESERVE_SOC_PCT` | emergency reserve (must be > 0; never 0 by default) |
| `MAX_CHARGE_KW` / `MAX_DISCHARGE_KW` | power limits |
| `ETA_CHARGE` / `ETA_DISCHARGE` | path efficiencies |

> Do not derive capacity from the battery model name alone. Use the confirmed
> datasheet / BMS / a separately verified calibration.

## PV arrays (private)

Use the **Solar arrays builder** on the Settings tab: add one row per group of
panels (name, peak kWp, tilt, compass direction). The module assembles the
JSON for you, so you never edit it by hand. Direction maps to the internal
module azimuth convention: **0 = North, 90 = East, 180 = South, 270 = West**;
conversion to each provider happens inside its adapter.

Stored as `PV_ARRAYS_JSON`; each element looks like:

```json
{"array_id":"roof_south","kwp":3.22,"tilt_deg":40,"azimuth_module_deg":180,
 "dc_loss":0.14,"temp_coeff_pct_per_c":-0.004}
```

## Deye access and network scanning

All inverter access parameters are set by hand on the **Deye / TOU** tab
(transport, address/IP, port, serial, firmware) and in the private config. The
module **does not scan the network** and does not auto-discover the inverter —
a deliberate choice for a 24/7 production home. Transport options:

- `mdm_property` — read via existing MajorDoMo object properties (recommended
  to start; no direct inverter connection),
- `modbus_tcp` — Modbus TCP (port 502),
- `logger_tcp` — Solarman logger (port 8899) — a different protocol from Modbus,
- `rs485` — serial.

Register addresses come only from a verified device profile (see
`deye-profile-validation.md`). Writing stays off until armed mode.

## Providers

| Key | Meaning |
|---|---|
| `OPEN_METEO_ENABLED` | enable Open-Meteo (no key; non-commercial free API) |
| `FORECAST_SOLAR_ENABLED` / `FORECAST_SOLAR_API_KEY` | Forecast.Solar |
| `SOLCAST_ENABLED` / `SOLCAST_API_KEY` / `SOLCAST_RESOURCE_IDS` | Solcast home |
| `SOLCAST_RESERVED_UNITS` | daily calls reserved for the critical pre-07:00 run (default 2) |
| `SOLCAST_DAILY_LIMIT_UNITS` | daily quota of Solcast requests; `0` = unknown/unlimited (default 0) |
| `SOLCAST_SHARED_EXTERNALLY` | flag when Home Assistant or another client uses the same key |

See `providers.md` for quota handling and licensing. Quota state (backoff and
circuit breaker) is now persisted in `solarplanner_quota_state` and restored on
every run. Cached provider runs are discarded when they no longer cover the
current horizon or were fetched with different coordinates/PV arrays.

## Forecast accuracy and calibration (new in 1.2.0)

| Key | Meaning | Default |
|---|---|---|
| `PV_SCALE` | manual multiplier applied to modelled PV | 1.0 |
| `PV_AUTO_CALIBRATE` | learn a correction from measured vs forecast daily PV | 1 (on) |
| `PV_CALIBRATION_ALPHA` | smoothing: 0 = never change, 1 = full correction at once | 0.25 |
| `CONTROLLABLE_HOURLY_KW_JSON` | optional 24-value kW profile of loads scheduled separately (subtracted from the base load forecast) | empty |
| `OM_DEFAULT_TEMP_C` | fallback ambient temperature when Open-Meteo omits it (flagged in diagnostics) | 10 |
| `CACHE_HORIZON_SLACK_MIN` | tolerated horizon shortfall when reusing a cached run | 360 |

The module compares the **unscaled** ensemble forecast for yesterday
(`PV_TOMORROW_RAW_KWH`, published as `SolarPlanner.ForecastPvTomorrowRawKwh`)
with the PV energy integrated from `solarplanner_telemetry.PV_POWER_KW`, stores
MAE / bias / RMSE / MAPE in `solarplanner_calibration` (one row per provider and
local day), and applies a clamped, smoothed scale (0.35–1.60) after at least 3
verified days. The manual `PV_SCALE` is divided out before learning, so the loop
converges to the true correction instead of `sqrt(bias)`. Samples recorded with a full battery
or a clipped inverter are excluded from learning. Accuracy metrics and a
forecast-vs-actual chart are shown on the Overview tab. Disable auto-calibration
only if you deliberately manage `PV_SCALE` by hand.

## Property map (private)

Use the **property-map picker** on the Settings tab: for each reading (SOC, PV
power, load, grid, battery power, …) choose a MajorDoMo object and one of its
properties from dropdowns. If you have no suitable objects yet, click **Create
SolarPlanner objects** to provision a `SolarPlanner` mirror object with the
needed properties, then use **Use mirror objects** to map against it in one
click. A missing field is treated as `null`, never a silent zero.

Stored as `PROPERTY_MAP_JSON` (logical field → `Object.property`).

## Blocking parameters

Until these are set, only `observe`/`shadow` is allowed: battery count,
capacity, reserve (> 0), tariff prices, transport, firmware, property map, PV
arrays, and a verified device profile. The UI lists whatever is still missing.
