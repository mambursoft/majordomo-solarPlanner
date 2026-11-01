# Backtesting

`Simulation/BacktestRunner` compares strategies on historical data at a fair
cutoff, so a plan for a given evening only uses information available then.

## Strategies compared

- fixed night SOC,
- charge to 100% overnight,
- a simple rule based on daily PV,
- an interval plan with a single provider,
- an interval plan with the ensemble,
- the plan after calibration,
- the plan after TOU compilation.

All run under identical initial states, reserves, tariffs, hardware limits,
export assumptions and the forecasts available at the cutoff.

## Metrics

Cost, night import, day import, reserve breaches, full-target achievement,
avoidable curtailment, number of writes, extra battery throughput.

## Honesty rules (enforced by design)

- Separate the math-simulation result, the shadow-mode result and the actually
  measured result after applying.
- If the station clipped PV in history, the alternative-policy backtest is
  partial for those intervals — the runner reports the count and a note.
- Do not promise a fixed savings percentage without real verification. Lower MAE
  alone does not prove a provider gives the best control economy.

## No-peeking guarantee

Both `FileForecastStore::loadRunsBefore` and `SqlForecastStore::loadRunsBefore`
filter by `retrieved_at_utc <= cutoff`, so a backtest cannot accidentally use a
forecast refined later in the day, nor substitute actual weather for a historical
forecast run.
