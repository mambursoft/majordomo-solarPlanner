# MajorDoMo Solar Planner

Планувальник сонячної генерації та нічного заряду батарей для
[MajorDoMo](https://github.com/sergejey/majordomo). Модуль отримує прогнози PV з
кількох джерел, прогнозує споживання будинку, інтервально симулює енергетичний
баланс і розраховує **TargetSocAt07** — цільовий SOC батарей на кінець дешевого
тарифу — а потім формує здійсненний розклад Deye Time of Use.

> **Безпечно за замовчуванням.** Модуль за замовчуванням **не пише в інвертор**.
> Він працює в режимі `shadow`: лише розраховує й зберігає план. Реальне
> керування потребує окремого дозволу, перевіреного device profile та режиму
> `armed`.

[English summary below.](#english)

## Можливості

- Мультипровайдерний прогноз PV: **Open-Meteo** (погода + локальна PV-модель),
  **Forecast.Solar**, **Solcast** (домашній доступ) — незалежні адаптери з
  обліком квот, backoff та circuit breaker.
- Прогноз навантаження (прозорий статистичний метод + ручний fallback).
- Інтервальний енергетичний симулятор з перевіркою фізичних інваріантів.
- Розрахунок `TargetSocAt07` перебором кандидатів із сценаріями
  `base / low_pv / high_load / high_pv` і явним статусом нездійсненності.
- Компілятор TOU (максимум 6 періодів для серії Deye SG05LP3, без прогалин і
  накладень, коректний перехід через північ) з повторною симуляцією.
- Пояснення кожного рішення, що відтворюється з архівованих входів.
- Режими безпеки: observe / shadow / approval_required / armed / … з readback і
  audit log.
- UI MajorDoMo (огляд, план, провайдери, Deye/TOU, безпека, налаштування).
- Без обов'язкової залежності від Home Assistant. Сумісність PHP 7.3–8.x.

## Сезонна універсальність

Жодних жорстких правил «зима — 100%, літо — 30%». Сезонна поведінка випливає з
погодинного енергетичного балансу, фактичної ємності, навантаження, резерву та
прогнозу PV. Тести підтверджують, що влітку нічна дозарядка не більша за зимову
за інших рівних.

## Швидкий старт

```bash
sudo tar -xzf solarplanner.tgz -C /var/www/html/
php /var/www/html/scripts/cycle_solarplanner.php   # під supervisor/systemd
```

Далі відкрийте модуль у MajorDoMo, заповніть налаштування (координати та ключі —
приватні й маскуються), і працюйте в `shadow` до підтвердження всіх параметрів.
Докладніше — `docs/installation.md` і `docs/configuration.md`.

## Тести

```bash
php tests/run.php
```

Залежностей немає; підходить для PHP 7.3 і RPi4.

## Документація

`docs/`: specification, architecture, installation, configuration, providers,
home-assistant-research, deye-profile-validation, safety, testing, backtesting,
troubleshooting.

## Приватність і ліцензія

Код — MIT (`LICENSE`). Джерела та окремі ліцензії даних — `NOTICE.md`. У
репозиторії немає секретів, координат, IP, серійних номерів чи приватних даних;
усі приклади синтетичні.

---

## English

Solar generation and night battery-charge planner for MajorDoMo. It fetches PV
forecasts from several providers, forecasts house load, runs an interval energy
simulation and computes **TargetSocAt07** (the morning battery target at the end
of the cheap-tariff window), then compiles a feasible Deye Time-of-Use schedule.

**Safe by default: it does not write to the inverter.** The default `shadow`
mode only computes and stores the plan. Real control requires a separate
approval, a verified device profile and `armed` mode.

Highlights: independent multi-provider forecasting with quota/backoff/circuit
breaker, a transparent load forecast, an interval simulator with physical
invariants, a deterministic TargetSocAt07 search with uncertainty scenarios, a
≤6-slot Deye TOU compiler with re-simulation, reproducible explanations, layered
safety states with readback and audit, a MajorDoMo UI, no mandatory Home
Assistant dependency, and PHP 7.3–8.x compatibility. Seasonality emerges from the
energy balance — no hard seasonal rules.

See `docs/` for full documentation and `README.ru.md` for Russian.
