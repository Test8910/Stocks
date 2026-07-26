# Stocks Intraday Pattern Plan

Goal: learn **Mon–Fri** time-of-day patterns for **SOXL** and **QQQ**, including when price tends to be **low** vs **high**, using 1-minute RTH bars.

## Confirmed decisions

| Item | Choice |
|------|--------|
| Symbols | **SOXL**, **QQQ** |
| Session | Weekdays **09:30–16:00 America/New_York** |
| Heatmap | **1-minute** cells |
| Pattern threshold | **≥ 60%** up/down probability (`min_samples` starts at 2; raise to 5 as history grows) |
| Database | SQLite locally; MySQL-compatible schema for production |

## What you will see

1. **Weekday heatmaps** — Mon–Fri × each minute: avg move and up%
2. **Uptrend / downtrend times** — minutes that historically move up or down ≥ 60%
3. **Low → high windows** — typical times price is near the session low vs session high, so you can spot “buy earlier / sell later” tendencies per weekday

## Next: deep scenario patterns

See **[PATTERN_SCENARIOS_PLAN.md](PATTERN_SCENARIOS_PLAN.md)** — “If price goes up 09:30→10:00, what usually happens next?” conditional analysis (plan only until approved).

## Build phases

1. Config + Yahoo 1m client + RTH filter  
2. Historical ingest (request up to ~30 calendar days of 1m; Yahoo usually has ~2–3 weeks)  
3. MySQL/SQLite schema (`prices_1m`)  
4. Cron sync every 5 minutes  
5. PHP API (aggregates, probabilities, low/high profiles)  
6. Chart.js dashboard  
7. Pattern engine (60% rules + low/high windows)  
8. Test and tune  

## Repo layout

```text
config/  database/  bin/  cron/  src/  public/  storage/
```

See `README.md` for setup commands.
