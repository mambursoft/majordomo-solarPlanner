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

`PV_ARRAYS_JSON` is a JSON array; each element:

```json
{"array_id":"roof_south","kwp":3.22,"tilt_deg":40,"azimuth_module_deg":180,
 "dc_loss":0.14,"temp_coeff_pct_per_c":-0.004}
```

Azimuth uses the module convention: **0 = North, 90 = East, 180 = South,
270 = West**. Conversion to each provider happens inside its adapter.

## Providers

| Key | Meaning |
|---|---|
| `OPEN_METEO_ENABLED` | enable Open-Meteo (no key; non-commercial free API) |
| `FORECAST_SOLAR_ENABLED` / `FORECAST_SOLAR_API_KEY` | Forecast.Solar |
| `SOLCAST_ENABLED` / `SOLCAST_API_KEY` / `SOLCAST_RESOURCE_IDS` | Solcast home |
| `SOLCAST_RESERVED_UNITS` | daily calls reserved for the critical pre-07:00 run |

See `providers.md` for quota handling and licensing.

## Property map (private)

`PROPERTY_MAP_JSON` maps logical telemetry fields to the MajorDoMo object
properties your existing inverter/MQTT module already populates. A missing field
is treated as `null`, never a silent zero.

## Blocking parameters

Until these are set, only `observe`/`shadow` is allowed: battery count,
capacity, reserve (> 0), tariff prices, transport, firmware, property map, PV
arrays, and a verified device profile. The UI lists whatever is still missing.
