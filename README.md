# Stocks — SOXL & QQQ intraday patterns

Learn **Monday–Friday** time-of-day patterns for **SOXL** and **QQQ**:
when price tends to be **low**, when it tends to be **high**,
when **$5 and up** swings happen (up or down),
and which minutes historically trend up/down (≥ 60%).

## Quick start

```bash
cp config/config.example.php config/config.php
php bin/setup_db.php
php bin/ingest_intraday.php

cd public
php -S 127.0.0.1:8080
# open http://127.0.0.1:8080
```

## What it does

1. Pulls Yahoo Finance **1-minute** bars (up to ~30 calendar days, chunked in 7-day requests; Yahoo only keeps ~2–3 weeks of 1m history)
2. Keeps only weekdays **09:30–16:00 ET**
3. Stores `symbol, timestamp, price, volume` in SQLite (or MySQL)
4. Cron sync every 5 minutes (`cron/stocks-intraday`)
5. API + Chart.js dashboard: 1-min heatmap, weekday line chart, low→high times, pattern windows

## API

| URL | Description |
|-----|-------------|
| `/api.php?action=symbols` | Tracked symbols + bar counts |
| `/api.php?action=heatmap&symbol=SOXL` | 1-min Mon–Fri heatmap |
| `/api.php?action=patterns&symbol=QQQ` | Uptrend/downtrend + buy/sell zones |
| `/api.php?action=summary&symbol=SOXL` | Full payload for the dashboard |

## Cron

```bash
*/5 * * * * cd /path/to/Stocks && php bin/sync_intraday.php >> storage/sync.log 2>&1
```

Use `php bin/sync_intraday.php --force` outside RTH for testing.

## MySQL

Set `db.driver` to `mysql` in `config/config.php`, create the database, then:

```bash
mysql -u root -p < database/schema.mysql.sql
php bin/setup_db.php
php bin/ingest_intraday.php
```

## Plan

See [PLAN.md](PLAN.md).
