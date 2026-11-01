# Specification (implemented subset)

This document summarises what the module implements against the owner's
technical specification (`majordomo-solarPlanner.md`). It is a map from
requirements to code, not a restatement of the full research document.

## Goal

Turn a forecast of solar generation and house consumption into an explainable,
safe and feasible Deye night-charge plan that minimises unnecessary purchase and
leaves room for solar energy — without hard seasonal rules and without silently
breaching the reserve. The headline output is **TargetSocAt07**.

## Requirement → component map

| Requirement | Component |
|---|---|
| Multi-provider PV forecast | `Forecast/OpenMeteoAdapter`, `ForecastSolarAdapter`, `SolcastAdapter` |
| Independent adapters, one outage does not block | `ForecastAdapter` contract + `QuotaManager` |
| Quotas, backoff, circuit breaker, reserved critical units | `Forecast/QuotaManager` |
| Weather → PV with azimuth/temperature handling | `Forecast/PvModel` |
| Normalized immutable forecast snapshot | `Forecast/ForecastRun`, `Storage/*Store` |
| Ensemble / median / weighted with caps | `Forecast/EnsembleBuilder` |
| Accuracy metrics (MAE/Bias/RMSE, MAPE guard) | `Forecast/AccuracyMetrics` |
| Load forecast (statistical, manual fallback) | `Planning/LoadForecaster` |
| Interval energy balance + invariants | `Simulation/EnergySimulator` |
| Battery model (taper, efficiencies, reserve) | `Simulation/BatteryModel` |
| TargetSocAt07 search + scenarios + feasibility | `Planning/NightChargePlanner` |
| TOU compiler (≤ 6 slots, no gaps, midnight wrap) | `Planning/TouCompiler` |
| Explanations (reproducible, no fake confidence) | `Planning/ExplanationBuilder` |
| Safety states, pre/post-write checks, readback | `Safety/SafetySupervisor` |
| Verified device profile, packed registers | `Inverter/DeviceProfile` |
| Read telemetry / allowed writes | `Inverter/InverterAdapter`, `MdmPropertyInverterAdapter` |
| Apply + readback + audit, idempotency | `Inverter/PlanExecutor` |
| Telemetry quality, potential/deliverable/measured PV | `Telemetry/TelemetryCollector` |
| Backtest strategies at a fair cutoff | `Simulation/BacktestRunner` |
| MajorDoMo module lifecycle, UI, properties | `solarplanner.class.php`, templates, cycle |

## Explicitly NOT done by default

- No write to the inverter. Default mode is `shadow`.
- No invented register addresses. The Deye profile ships `verified=false`.
- No Home Assistant dependency. An optional HA bridge is a future extension.
- No hard seasonal rules; seasonality emerges from the energy balance.
