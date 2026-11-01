# Architecture

The module separates data collection, forecasting, optimization and execution,
so that the core simulator and planner can be tested without network, API keys
or hardware (an architectural idea borrowed from EMHASS; content rephrased for
licensing compliance).

```
                +---------------------+
   providers -->| ForecastAdapter(s)  |--+
                +---------------------+  |
                                         v
                +---------------------+  |   +------------------+
   telemetry -->| TelemetryCollector  |  +-->| EnsembleBuilder  |
                +---------------------+      +------------------+
                         |                           |
                         v                           v
                +---------------------+     +------------------+
                | LoadForecaster      |     | (calibration)    |
                +---------------------+     +------------------+
                         \                         /
                          v                       v
                        +-----------------------------+
                        | PlannerService (orchestrator)|
                        +-----------------------------+
                                     |
             +-----------------------+------------------------+
             v                       v                        v
    +----------------+     +------------------+      +------------------+
    | EnergySimulator|<----| NightChargePlanner|     | ExplanationBuilder|
    +----------------+     +------------------+      +------------------+
                                     |
                                     v
                             +--------------+
                             | TouCompiler  |
                             +--------------+
                                     |
                                     v
                    +-----------------------------------+
                    | SafetySupervisor -> PlanExecutor  |
                    |      -> InverterAdapter           |  (armed only)
                    +-----------------------------------+
```

## Layering rules

- `lib/Common`, `lib/Simulation`, `lib/Planning`, `lib/Forecast`, `lib/Safety`,
  `lib/Inverter`, `lib/Telemetry`, `lib/Storage` are pure PHP with no MajorDoMo
  globals.
- `lib/MajorDoMo` holds the glue (`Config`, `PlannerService`, `SqlForecastStore`)
  and may call MajorDoMo helpers.
- `solarplanner.class.php` and `scripts/cycle_solarplanner.php` are the only
  files allowed to use MajorDoMo globals directly.

## Data flow on the RPi4

The cycle samples telemetry every 60 s and runs a full plan every 15 min (both
configurable). Heavy work is throttled to spare the CPU and the microSD card.
Forecast fetches respect per-provider quotas; a local re-plan does not spend API
quota.
