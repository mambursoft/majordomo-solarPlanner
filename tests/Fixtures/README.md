# Test fixtures

All fixtures here are **synthetic** and explicitly labelled as such. They do not
represent real measured accuracy or real savings for any installation.

- Do **not** commit real provider responses (Open-Meteo / Forecast.Solar /
  Solcast) as fixtures without verified permission. Solcast in particular
  forbids sharing data with third parties without consent.
- Do **not** commit real coordinates, API keys, serials or private site data.
- `fixtures/real/` is git-ignored for your own private, local backtesting data.

Unit and integration tests build their inputs programmatically (see
`tests/Unit` and `tests/Integration`) so no captured responses are required.
