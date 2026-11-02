# Changelog

All notable changes to this project are documented here. The format is based on
Keep a Changelog; this project uses semantic versioning.

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
