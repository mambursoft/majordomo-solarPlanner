# Changelog

All notable changes to this project are documented here. The format is based on
Keep a Changelog; this project uses semantic versioning.

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
