# Testing

The core simulator and planner are tested without network, API keys or hardware.
The harness is dependency-free (no PHPUnit required) so it runs on PHP 7.3 / the
RPi4 without extra tooling.

## Run

```bash
php tests/run.php
```

Exit code is non-zero if any assertion fails. The runner includes every file in
`tests/Unit` and `tests/Integration`.

## Coverage of the spec's required scenarios

| Scenario | Test |
|---|---|
| Winter day, small PV — realistic night charge, no invented surplus | `Unit/PlannerTest` |
| Summer day, large PV — smaller night charge when morning allows | `Unit/PlannerTest` |
| Battery already charged — no excess night purchase | `Unit/PlannerTest` |
| No feasible plan — returns a reason, not a dangerous command | `Unit/PlannerTest` |
| Reserve never auto-lowered | `Unit/PlannerTest` |
| Six TOU slots, no hidden seventh | `Unit/TouCompilerTest` |
| Midnight crossing — full, correct schedule | `Unit/TouCompilerTest` |
| Gap/overlap rejected | `Unit/TouCompilerTest` |
| DST — real durations and tariff boundaries | `Unit/TimeGridTest` |
| Wh/kWh — no scale error | `Unit/UnitsTest` |
| Azimuth N/E/S/W converted correctly | `Unit/AzimuthTest` |
| Missing data ≠ zero | `Unit/SimulatorTest`, `Unit/ForecastTest` |
| No simultaneous charge/discharge or import/export | `Unit/SimulatorTest` |
| Powers within profile; load above battery power → import not masked | `Unit/SimulatorTest` |
| SOC within physical bounds; excess PV → curtailment | `Unit/SimulatorTest` |
| Forecast horizon incomplete not accepted as complete | `Unit/ForecastTest` |
| Solcast quota reserved for critical run; 401 opens breaker | `Unit/ForecastTest` |
| SOC stale → write blocked; readback mismatch not success | `Unit/SafetyTest` |
| Field outside allowlist blocked; packed bits preserved | `Unit/SafetyTest` |
| End-to-end forecast→load→plan→TOU | `Integration/PlannerServiceTest` |

## Simulator invariants checked per interval

Energy is not created from nothing; SOC stays within bounds; reserve breaches
are flagged; no simultaneous charge+discharge or import+export; powers respect
the profile; interval sums match aggregates; resampling conserves energy;
missing data never becomes a real zero.

## Test data policy

Synthetic data is allowed for unit tests and is explicitly labelled synthetic. A
synthetic test result is never presented as real savings or proven accuracy.
Real data for backtests requires the owner's permission and must not leak
private details. See `tests/Fixtures/README.md`.
