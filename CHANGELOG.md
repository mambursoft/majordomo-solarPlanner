# Changelog

All notable changes to this project are documented here. The format is based on
Keep a Changelog; this project uses semantic versioning.

## [1.1.1] - 2026-11-03

### Fixed
- **"PV tomorrow" was ~2× too high because it summed the whole ~44h horizon.**
  `PlannerService::makePlan` set `pv_tomorrow_kwh = sumPv(all ensemble intervals)`,
  so the figure added tomorrow **plus** the day after (and any remaining today),
  e.g. 4.29 + 4.29 = 8.58 kWh reported as "tomorrow". The headline PV figure now
  sums only the next local day (midnight→midnight in the installation timezone,
  by interval midpoint). `load_tomorrow_kwh` had the same horizon-wide bug and is
  likewise restricted to tomorrow. The full horizon is still used by the
  simulation and night-charge planner — only the UI totals are per-day. Verified
  on the live host: 8.58 → 3.7–4.3 kWh for a single day. The ensemble (median,
  not sum) and the overlap PV→grid allocation (energy-conserving, ratio 1.0)
  were confirmed correct and are not the cause.
- Exposed per-day context so the horizon is still visible without mislabeling:
  `pv_today_remaining_kwh`, `pv_day_after_kwh`, `pv_horizon_kwh`, and
  `tomorrow_local_date` (shown next to the PV figure in the overview).

### Added
- **PV plausibility guardrail (diagnostic, non-intrusive).** The plan now
  computes modelled PV per installed kWp for tomorrow and flags
  `pv_forecast_implausible` when it exceeds a generous physical ceiling
  (~6.5 kWh/kWp/day), surfacing a warning in the overview instead of silently
  driving the night-charge plan with an obviously wrong number. Total installed
  kWp is read from the configured PV arrays (passed through `PlanRunner::svcCfg`).
  Note: a secondary, smaller single-day optimism remains in the forecast/PvModel
  (modelled peak ~1.38 kW vs measured ~996 W on an overcast day); calibrating the
  irradiance→energy conversion is tracked separately and does not affect the
  per-day summing fix above.

## [1.1.0] - 2026-11-02

### Fixed
- **PV forecast never reached the simulator (plan was effectively PV-blind).**
  `EnsembleBuilder` keys intervals by the providers' own grid (Open-Meteo is
  hourly) while `PlannerService` looked them up by exact 30-min grid start, so
  `pv_kwh` was 0 in every simulated interval even though `pv_tomorrow` was
  non-zero. PV energy is now distributed onto the simulation grid by time
  overlap, preserving total energy. Verified on the live host: ensemble PV sum
  now equals the simulated PV sum (was 0).
- **Plan was "infeasible / 0%" because of a bogus start SOC.** The telemetry SOC
  read by "Run plan" was 0 (mirror objects not yet fed by a real inverter), i.e.
  below the reserve, so every candidate breached the reserve and the plan looked
  infeasible with a misleading 0% target. The SOC hook now rejects an
  implausible/stale reading (≤0, >100, or older than 6h) and falls back to a
  safe default (reserve), which is stated in the explanation. Verified: the same
  plan now returns `feasible`, TargetSocAt07 = 100%.
- **Plan explanation was raw English, unformatted and unreadable.** The
  infeasibility reason is now a machine code localized at the display layer
  (UA/RU/EN) with a plain-language meaning for a homeowner, rendered as separate
  lines instead of one run-on paragraph. On an infeasible plan the UI now shows
  the suggested safe compromise SOC (clearly marked as advice) instead of a
  misleading 0%.
- **PV-array and property-map editors intermittently broke / "disappeared".**
  Their saved JSON was injected into an HTML `value="..."` attribute; the
  embedded double quotes closed the attribute and corrupted the markup. Both
  hidden fields are now filled by JS from the injected JS data, so quotes in the
  data can no longer break the form.

### Added
- **Auto-detect for the property map.** A new "Auto-detect" button scans the
  installation's objects/properties by name keywords (SOC, PV power, load, grid,
  battery power, alarms, BMS limits, TOU) and fills confident matches, leaving
  the rest as "—" (null) for confirmation; the SolarPlanner.* mirror is used as
  a fallback for core telemetry. Added guidance text explaining what to pick and
  that "—" means "do not use (null)". New server action `auto_map_properties`.

## [1.0.9] - 2026-11-02

### Fixed
- **«Розрахувати план» не запускав розрахунок.** The button's AJAX call used a
  bare relative URL (`?action=run_plan`), which replaces the whole query string
  and drops `md=solarplanner`. MajorDoMo routes module pages by `md` (`admin.php`:
  `if ($md != $app->name) restoreParams()`), so without it the request never
  reached the module's `admin()` handler and the plan was never computed. The
  button now builds the request from the current page URL, preserving `md` (and
  `pd`/`inst`/...), sends `X-Requested-With`, disables itself while running, and
  reloads so the fresh plan shows. The same fix is applied to the property-map
  picker's `list_properties` XHR. Verified end-to-end on the live host: the
  module's `triggerPlan()` returns a real JSON result (`success:true`).

### Added
- **Use `DarknessMode.active` as the authoritative "is it dark now?" signal.**
  When MajorDoMo exposes `DarknessMode.active`, the fetch scheduler trusts it
  over its own sunrise/sunset estimate: when the host reports daylight is gone,
  daytime refreshes stop; when it reports light, the night cadence is not used.
  Night forecasts for *tomorrow* are still fetched during the cheap-tariff
  window (they decide how many kWh to buy), so battery-charge sizing is
  unaffected. The flag is surfaced on the Diagnostics tab's sun/schedule line.
  Falls back to the computed sun windows when the property is absent.

## [1.0.8] - 2026-11-02

### Fixed
- **Background cycle produced a permanent `no_forecast` and could hang.** On the
  live RPi4 the plan run blocked for 90s+ and never finished, so the module
  showed "Цикл остановлен" / no forecast even though the cycle process and its
  heartbeat were alive. Root cause: forecast collection had no overall time
  budget and the HTTP client *retried* HTTP 429 (rate limit) responses. When a
  provider throttled hard (Solcast home tier, and Forecast.Solar's ~12 req/h per
  IP), each retry waited out the full per-request timeout, stacking up to several
  minutes per plan — longer than a cycle iteration, so nothing was ever cached.
  - `HttpClient` no longer retries a 429 by default (a rate limit is a definitive
    answer, not a transient glitch); it is surfaced immediately. Retrying 429 only
    wasted time and burned quota. Added an explicit `$retryOn429` opt-in and a
    configurable connect timeout.
  - Provider fetches now use a tight budget (connect ≤ 5s, total ≤ 8s, one retry);
    the outbound internet probe uses an even tighter, retry-free budget (≤ 6s).
  - `DiagnosticsService::collect()` now enforces an overall wall-clock deadline
    (default 20s, `FETCH_BUDGET_SEC`). A provider still pending when the budget is
    spent is skipped with a clear reason and the plan is built from whatever
    forecasts did arrive — one working provider is enough. Verified on the live
    PHP 7.3.31 host: a full plan run now completes in ~1.3s and returns
    `status=feasible` instead of hanging.

## [1.0.7] - 2026-11-02

### Fixed
- **500 Internal Server Error during installation on PHP 7.3.** MajorDoMo's
  `SQLInsert($table, &$record)` takes the record by reference; passing an inline
  `array(...)` literal is a fatal "Only variables can be passed by reference" on
  PHP 7.3 (only a deprecation on PHP 8.x, so it did not surface in local testing
  on PHP 8.2). All six such calls (settings KV in the class and cycle, telemetry
  insert, TOU-plan insert, forecast-intervals insert, audit insert) now assign
  the array to a variable first. Verified on the live PHP 7.3.31 host:
  construct + getConfig + the previously-fatal kvSet path all succeed.

## [1.0.6] - 2026-11-02

### Added
- **Forecast caching + a sunrise/sunset-aware fetch schedule** so provider
  quota is spread across the day instead of burned every cycle:
  - `ForecastCache` stores the latest successful run per provider (in the
    `settings` table) and serves it while fresh; plan recomputes reuse it with
    no HTTP call. On a fetch failure the module falls back to a still-usable
    cached run.
  - `FetchScheduler` spaces fetches using `ThisComputer.SunRiseTime` /
    `ThisComputer.SunSetTime` (via `SolarWindows`, with a latitude fallback):
    sparse daytime refreshes, several night refreshes because tomorrow's
    forecast decides how many kWh to buy cheaply, and a denser cadence as the
    critical last-top-up time approaches.
  - Per-provider minimum intervals protect Forecast.Solar's hourly IP limit and
    Solcast's small daily quota. The cycle respects the schedule; the manual
    "Run plan" and the new "Refresh now" button force a live fetch.
- Diagnostics tab now shows each provider's **source** (live / cache) and the
  **next scheduled fetch**, plus a sunrise/sunset/cheap-window summary line.
- New configurable defaults: `CACHE_MAX_AGE_MIN` (6h), Forecast.Solar min
  interval (20 min), Solcast day/night fetch counts (3/4).

### Changed
- `PlanRunner` and `DiagnosticsService` are cache-aware and share the exact same
  collection path, so the cycle and the UI stay consistent.

## [1.0.5] - 2026-11-02

### Fixed
- Diagnostics no longer mislabels "no internet" when only Open-Meteo is down.
  The outbound probe now tries several independent hosts (Open-Meteo,
  Forecast.Solar, Cloudflare) and reports per-host results; internet is "up" if
  any host answers. This matched a real case where the host received HTTP 503
  from Open-Meteo while Forecast.Solar worked.
- A partial (short-horizon) forecast from a single provider now reliably yields
  a plan instead of degrading to no_forecast (covered by a new integration
  test). Open-Meteo 503/429 are shown as temporary and do not block the plan.

### Added
- **Solcast resource auto-fill**: a resource_id field plus a "Fetch resources"
  button that calls the Solcast rooftop_sites API with the saved key and fills
  SOLCAST_RESOURCE_IDS automatically, then enables Solcast. No manual id lookup.
- Clearer Ukrainian provider-result messages distinguishing a temporary 503/429
  from a domain being unreachable while other providers work.

## [1.0.4] - 2026-11-02

### Fixed
- Root cause of "no forecast / PV = 0" diagnosed and fixed: the background
  cycle could fail to load the saved module config (the module class was not
  available in the cycle context), so no provider was ever attempted and every
  plan degraded to `no_forecast`. The cycle now explicitly loads the module
  class and falls back to reading the saved settings directly from the DB.
- `HttpClient` now auto-detects a system CA bundle when php.ini has none
  (`curl.cainfo`/`openssl.cafile` empty), fixing silent TLS "unable to get local
  issuer certificate" failures that previously surfaced only as HTTP 0. TLS
  verification is never disabled.

### Added
- **Diagnostics tab**: an environment self-check (config loaded? providers
  enabled? coordinates set? PV arrays set? outbound HTTPS working? cURL/OpenSSL
  present? profile verified?) with a live outbound probe to Open-Meteo, plus a
  per-provider result table showing the specific reason each provider did or did
  not return a forecast. A "Re-check" button re-runs it.
- **"Run plan" now runs synchronously** from the UI and reports the real status
  and the specific reason (instead of only flagging the cycle).
- Shared `PlanRunner` + `DiagnosticsService` so the cycle and the UI produce
  identical results and diagnostics; every plan result carries a `diagnostics`
  block, and `no_forecast` now includes a specific, human-readable reason.
- Actionable Ukrainian hint for CA/SSL problems (how to install ca-certificates
  / set curl.cainfo).

## [1.0.3] - 2026-11-01

### Fixed
- The "what the modes and states mean" table rendered empty: the loop used
  dotted keys (`[#STATES.NAME#]`) and in-loop conditionals, which the MajorDoMo
  parser does not support. Switched to bare keys with row class and "current"
  marker precomputed in PHP. `observe` and all other states now show.

### Added
- **Solar arrays builder**: a friendly add/remove table (name, peak kWp, tilt,
  compass direction) that assembles the PV-arrays JSON automatically — no manual
  JSON. Direction maps to the module azimuth convention under the hood.
- **Property-map picker**: per-reading dropdowns of existing MajorDoMo objects
  and their properties (properties loaded on demand via AJAX), a one-click
  "Create SolarPlanner objects" action that provisions a `SolarPlanner` mirror
  object with the needed properties, and a "Use mirror objects" shortcut.
- **Deye tab access panel**: editable transport (mdm_property / modbus_tcp /
  logger_tcp / rs485), address/IP, port, serial (masked) and firmware, plus an
  explicit note that the module does NOT scan the network or auto-discover the
  inverter — access is configured by hand. Answers "where are the Deye access
  parameters defined?".

### Security
- Inverter serial is masked in the UI and preserved (not wiped) when re-saving.

## [1.0.2] - 2026-11-01

### Added
- Localized, plain-language names and explanations for all ten control/safety
  states (observe, shadow, approval_required, armed, applying, verifying,
  active, degraded, blocked, manual_override) in UK/EN/RU, aimed at a
  non-specialist homeowner.
- Safety tab now shows a friendly mode selector and a full "what the modes and
  states mean" reference table, highlighting the current state.
- The control-mode banner shows the friendly label plus a one-line description
  alongside the technical code.

## [1.0.1] - 2026-11-01

### Fixed
- UI template now uses the correct MajorDoMo parser syntax (`[#VAR#]`,
  `[#if X=="y"#]`, `[#begin ARR#]...[#end ARR#]`). Previously raw tokens like
  `{CONTROL_MODE}` and `[#foreach#]` were shown literally on the page.

### Added
- PNG module icons (`img/modules/solarplanner.png`, `_32.png`) generated by
  `scripts/make_icons.py`; icon shown in the UI header and provisioned to the
  web root on install.
- Realistic shipped defaults (battery 3×, 30 kWh, 7 kW, reserve 20%, UA tariff
  example, one south-facing PV array, default property map) so a fresh install
  runs in shadow immediately; only genuinely site/hardware-specific items still
  gate `armed`.
- Grouped settings UI (core / tariff / battery / site-private / flags) with a
  help text under every field, in UK/EN/RU.
- Editable PV-arrays and property-map JSON fields with server-side validation;
  API-key fields no longer wiped when re-saving with the masked placeholder.

## [1.0.0] - 2026-11-01

### Added
- Initial installable MajorDoMo module `solarplanner`.
- Multi-provider PV forecasting with independent adapters:
  Open-Meteo (weather + local PV model), Forecast.Solar, Solcast (home access).
- `QuotaManager` with per-account quota units, HTTP request tracking,
  exponential backoff + jitter, circuit breaker and reserved critical units.
- `PvModel` with module→provider azimuth conversion and NOCT-style temperature
  derating; verified by unit tests for the four cardinal directions.
- Normalized immutable `ForecastRun` snapshots; append-only file and SQL stores
  with a no-peeking cutoff filter.
- `EnsembleBuilder` (median / mean / weighted / best) with weight caps,
  min-observations fallback and a heuristic spread band (not calibrated P10/P90).
- `AccuracyMetrics` (MAE, Bias, RMSE) with a MAPE guard for small winter values.
- `LoadForecaster` (weekday/weekend hourly profile, recency-weighted median,
  manual fallback, controllable-load subtraction).
- `EnergySimulator` with AC-equivalent `BatteryModel` (taper, efficiencies,
  reserve) and per-interval physical invariants; missing data is never zero.
- `NightChargePlanner` computing `TargetSocAt07` by deterministic search over
  candidates with base/low_pv/high_load/high_pv scenarios; explicit infeasible
  status; reserve never auto-lowered. Modes: reach_full and minimum_cost.
- `TouCompiler` producing ≤ 6 Deye TOU slots, no gaps/overlaps, correct midnight
  wrap, with re-simulation validation.
- `ExplanationBuilder` for reproducible, confidence-free explanations.
- `SafetySupervisor` with control states, pre/post-write checks, write allowlist
  and readback verification; `DeviceProfile` with packed-register RMW helper.
- `MdmPropertyInverterAdapter`, `InverterAdapter` contract and `PlanExecutor`
  (idempotent apply, readback, audit) — writes disabled by default.
- `TelemetryCollector` (quality flags, SOC freshness, potential/deliverable/
  measured PV separation for calibration).
- `BacktestRunner` comparing strategies at a fair cutoff with clipping notes.
- MajorDoMo integration: `solarplanner.class.php`, DB schema, UI templates
  (overview/plan/providers/installation/Deye/safety/settings), `SolarPlanner.*`
  properties, and an RPi4-friendly background cycle.
- Dependency-free PHP test harness with 80 assertions covering the spec's
  required scenarios and simulator invariants.
- Documentation set, synthetic examples, GitHub Actions CI, MIT license and
  NOTICE with licensing/privacy notes.

### Security & privacy
- No secrets, coordinates, IPs, serials or private data in the repository.
- SSRF guard on outbound HTTP; secrets masked in UI and stripped from snapshots.

### Not yet done on hardware
- No inverter writes have been performed. The Deye device profile ships
  unverified with empty register addresses, to be filled from a verified source.
