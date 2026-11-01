# Provider setup

Each provider is an independent adapter. An outage or exhausted quota in one
must not block the planner; the others continue and the plan is built from
whatever is available (with reduced confidence surfaced in the UI).

## Open-Meteo

- No API key required for the free endpoint.
- The free API is **non-commercial**; there is no uptime guarantee. Weather data
  is CC BY 4.0 (attribution is shown in the UI and `NOTICE.md`).
- Standard hourly radiation values are **means over the preceding hour**; the
  adapter maps each timestamp to the correct interval and converts mean power to
  interval energy. It uses `timeformat=unixtime` for DST-safe handling.
- `retrieved_at_utc` is **not** the model issue time; `generationtime_ms` is the
  response build time, not a model run timestamp. Unknown issue time stays null.
- Track HTTP requests and estimated quota units separately. Do not re-fetch an
  unchanged forecast; weather models update far less often than every few
  minutes, and 15-minute data may be interpolated hourly data.

## Forecast.Solar

- Verify the current official docs before trusting any numeric quota; limits are
  configurable values, not hard-coded. 429 and backoff are handled by the
  `QuotaManager`.
- The adapter consumes `watt_hours_period` (per-interval energy), never the
  cumulative daily series, to avoid mixing cumulative with interval energy.
- One request per PV plane; arrays are summed onto a common grid.

## Solcast (home / hobbyist)

- Strictly limited: non-commercial, one location, up to two tilt/azimuth
  combinations, a small number of requests per day.
- Key stays in private config. One shared counter per account across all rooftop
  resources. Part of the daily allowance is reserved for a critical pre-07:00
  refinement (`SOLCAST_RESERVED_UNITS`).
- If the same key is used by Home Assistant elsewhere, the remaining quota is
  uncertain; the module flags this rather than guaranteeing a balance from its
  own counter.
- Solcast's `pv_estimate10`/`pv_estimate90` are its own percentile estimates;
  the module keeps their definition and does **not** relabel them as calibrated
  P10/P90 of your site. Do not commit real Solcast responses as fixtures.

## PVGIS / TMY (sanity check only)

PVGIS / typical-meteorological-year data is for seasonal plausibility and rough
checks, not a forecast of tomorrow's actual weather. It is not used as a precise
next-day forecast.

## Fallback order when all online forecasts are unavailable

1. Use a still-valid cache with a visible age flag.
2. Fall back to a conservative local forecast.
3. If history is insufficient, apply an agreed fallback plan.
4. Show reduced confidence.
5. Do **not** lower the reserve on the basis of a climatic average.
