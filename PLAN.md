# Stocks Intraday Pattern Plan

Goal: track selected US stocks on a 1-minute grid during regular hours, learn weekday/time-of-day tendencies, and surface them on a simple PHP + Chart.js dashboard.

## Stack

| Piece | Choice | Why |
|-------|--------|-----|
| Language | PHP 8.1+ | Matches your other trading dashboard repo |
| Data source | Yahoo Finance chart API (`interval=1m`) | Free, already used elsewhere |
| Database | MySQL 8+ (SQLite ok for local demo) | Your request; easy upserts |
| Charts | Chart.js | Line charts + heatmap-style matrices |
| Scheduler | Cron (every 5 min) + optional GitHub Action | Keeps data fresh |

---

## Phase 1 — Symbols & API access

**Deliverable:** confirmed symbol list + working Yahoo fetch.

Proposed starter symbols (easy to change in config):

| Symbol | Name | Yahoo |
|--------|------|-------|
| QQQ | Nasdaq-100 ETF | `QQQ` |
| SPY | S&P 500 ETF | `SPY` |
| AAPL | Apple | `AAPL` |
| MSFT | Microsoft | `MSFT` |
| NVDA | Nvidia | `NVDA` |

Tasks:
1. Put symbols in `config/config.php` (not hardcoded in scripts).
2. Prove Yahoo 1m history works for ~2 weeks (`range=5d` or `range=15d` with `interval=1m` — Yahoo caps 1m history; use the max available and document it).
3. Note rate limits: small delay between symbol requests.

**Exit criteria:** one CLI command prints filtered 1m bars for a symbol.

---

## Phase 2 — Historical ingest (2 weeks, RTH only)

**Deliverable:** `bin/ingest_intraday.php`

Rules for each bar:
- Interval: `1m`
- Session: weekdays only
- Hours: **09:30–16:00 America/New_York** (drop pre/post market)
- Fields: `symbol`, `timestamp` (ET or UTC stored consistently), `price` (close), `volume`

Implementation notes:
- Convert Yahoo unix timestamps → America/New_York.
- Skip Saturday/Sunday.
- Skip bars outside `[09:30, 16:00)`.
- Upsert on `(symbol, timestamp)` so re-runs are safe.

**Exit criteria:** DB has ~2 weeks of RTH 1m bars for all symbols.

---

## Phase 3 — Database schema

**Deliverable:** `database/schema.sql`

```sql
-- Core tick / minute bars
CREATE TABLE prices_1m (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(20) NOT NULL,
  ts_utc DATETIME NOT NULL,
  ts_et DATETIME NOT NULL,
  weekday TINYINT NOT NULL,          -- 1=Mon … 5=Fri
  minute_of_day SMALLINT NOT NULL,   -- minutes since 09:30 (0–389)
  price DECIMAL(12, 4) NOT NULL,
  volume BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_symbol_ts (symbol, ts_utc),
  KEY idx_symbol_weekday_minute (symbol, weekday, minute_of_day)
);

CREATE TABLE symbols (
  symbol VARCHAR(20) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  yahoo_symbol VARCHAR(30) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
);
```

Optional later: materialized aggregate table for faster dashboard loads.

**Exit criteria:** `setup_db.php` creates schema + seeds symbols.

---

## Phase 4 — Cron (every 5 minutes)

**Deliverable:** `bin/sync_intraday.php` + `cron/stocks-intraday`

Behavior:
1. Run only on weekdays during ~09:25–16:05 ET (no-op otherwise).
2. Fetch recent 1m bars (e.g. last 1–2 days) for active symbols.
3. Filter to RTH and upsert into `prices_1m`.
4. Log success/failure counts.

Example cron:

```cron
*/5 * * * * cd /path/to/Stocks && php bin/sync_intraday.php >> storage/sync.log 2>&1
```

Optional: GitHub Actions scheduled workflow for environments without a VPS cron.

**Exit criteria:** re-running sync adds new bars without duplicates.

---

## Phase 5 — Backend API (aggregates & probabilities)

**Deliverable:** `public/api.php` (JSON)

Endpoints (simple query params):

| Endpoint | Purpose |
|----------|---------|
| `?action=symbols` | List tracked symbols |
| `?action=series&symbol=QQQ&weekday=1` | Avg price / avg return by minute for Monday |
| `?action=heatmap&symbol=QQQ` | Weekday × minute matrix of avg move / up% |
| `?action=patterns&symbol=QQQ` | Classified uptrend/downtrend windows |

For each `(symbol, weekday, minute_of_day)` compute:
- sample count `n`
- average return vs previous minute: `avg_ret`
- average absolute move
- **up probability** = count(ret > 0) / n
- **down probability** = count(ret < 0) / n

Return JSON only (dashboard consumes it).

**Exit criteria:** curl returns heatmap JSON for one symbol.

---

## Phase 6 — Dashboard (Chart.js)

**Deliverable:** `public/index.php` (+ small CSS/JS)

UI sections (one job each):
1. **Symbol picker** — choose QQQ / SPY / …
2. **Weekday line chart** — avg cumulative or per-minute return for selected weekday
3. **Heatmap** — rows = Mon–Fri, columns = time buckets (e.g. every 5 or 15 min), color = avg move or up%
4. **Pattern callouts** — list of “uptrend time” / “downtrend time” windows from Phase 7

No card clutter in a hero; keep it a working analytics page.

**Exit criteria:** local `php -S` shows charts from live API data.

---

## Phase 7 — Pattern rules

**Deliverable:** `src/PatternEngine.php`

Default thresholds (tunable in config):

| Label | Rule |
|-------|------|
| Uptrend time | `up_prob >= 0.60` AND `avg_ret > 0` AND `n >= 5` |
| Downtrend time | `down_prob >= 0.60` AND `avg_ret < 0` AND `n >= 5` |
| Neutral | everything else |

Also merge consecutive qualifying minutes into windows (e.g. “Mon 10:05–10:25 Uptrend”).

**Exit criteria:** patterns endpoint returns named windows per weekday.

---

## Phase 8 — Test, iterate, adjust

Checklist:
1. Ingest 2 weeks → verify bar counts roughly match RTH minutes × trading days.
2. Spot-check Yahoo vs DB for one morning session.
3. Confirm weekend / premarket bars are absent.
4. Tune probability thresholds if too noisy or too sparse.
5. Optionally bucket heatmap to 5-minute cells if 1m is noisy.
6. Document how to add/remove symbols.

---

## Suggested repo layout

```text
/
├── PLAN.md                 ← this file
├── README.md
├── index.html              ← simple static hello (existing)
├── .github/workflows/
│   └── pages.yml           ← existing static Pages deploy
├── config/
│   ├── config.example.php
│   └── config.php          (gitignored)
├── database/
│   └── schema.sql
├── bin/
│   ├── setup_db.php
│   ├── ingest_intraday.php
│   └── sync_intraday.php
├── cron/
│   └── stocks-intraday
├── src/
│   ├── bootstrap.php
│   ├── Database.php
│   ├── YahooFinanceClient.php
│   ├── PriceRepository.php
│   ├── SessionFilter.php   ← weekday + 9:30–16:00 ET
│   ├── StatsService.php    ← aggregates / probabilities
│   └── PatternEngine.php
├── public/
│   ├── index.php           ← dashboard
│   ├── api.php
│   └── assets/
└── storage/                ← logs, local sqlite if used
```

---

## Implementation order

1. Config + schema + Yahoo 1m client + session filter  
2. Historical ingest  
3. Sync cron  
4. Stats API  
5. Dashboard charts  
6. Pattern engine  
7. Threshold tuning + README  

## Out of scope (for later)

- Options / IV / Greeks  
- Live websocket ticks  
- Auth / multi-user accounts  
- Broker order placement  

---

## Open decisions (confirm before coding)

1. **Symbols:** keep QQQ/SPY/AAPL/MSFT/NVDA, or a different list?  
2. **DB:** MySQL in production only, SQLite for local demo — OK?  
3. **Heatmap grain:** 1-minute cells or 5-minute buckets?  
4. **Thresholds:** start at 60% up/down probability with `n >= 5`?
