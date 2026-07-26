# Deep Pattern Plan — Scenario Analysis (If → Then)

## Goal

Go beyond “average up/down at this minute.”

Answer questions like:

> **If SOXL goes up from 09:30 → 10:00, what usually happens next?**  
> (continue up / pull back / make the day high / reverse into a down day)

Same for QQQ, and for down opens, flat opens, big $ moves, etc.

---

## What we have today

| Already built | Gap |
|---------------|-----|
| 1m RTH bars for SOXL/QQQ (~15 sessions) | Too little history for strong stats |
| Unconditional weekday heatmaps | No **conditional** “if A then B” |
| $5/$7/$9/$11 swing timings | Not tied to morning scenario |
| Week-over-week overlays | Visual only, not probability rules |

**Constraint:** Yahoo 1m history ≈ 2–3 weeks. Scenario stats will be thin until we accumulate more days via cron (or add 5m bars for longer history).

---

## Core idea: Scenario → Outcome

### 1) Define a **setup window** (the “if”)

Examples:

| Setup ID | Window | Condition |
|----------|--------|-----------|
| `OPEN_UP_30` | 09:30–10:00 | return ≥ +0.5% (or ≥ +$X) |
| `OPEN_UP_STRONG` | 09:30–10:00 | return ≥ +1.0% or ≥ +$5 |
| `OPEN_DOWN_30` | 09:30–10:00 | return ≤ −0.5% |
| `OPEN_FLAT_30` | 09:30–10:00 | \|return\| < 0.3% |
| `OPEN_UP_THEN_FADE` | 09:30–10:00 up, then 10:00–10:30 down | combo setup |

Configurable:

- Window start/end (default 09:30–10:00)
- Threshold in **%** and/or **$**
- Symbol, weekday filter (All / Mon / … / Fri)
- Interval used to measure (1 / 2 / 5 / 15 min)

### 2) Define **outcomes** (the “then”)

For each day that matches the setup, measure later in the session:

| Outcome | Definition |
|---------|------------|
| `CONT_UP_TO_11` | 10:00→11:00 still up |
| `PULLBACK_TO_11` | 10:00→11:00 down ≥ threshold |
| `DAY_HIGH_AFTER` | session high prints **after** 10:00 |
| `DAY_LOW_AFTER` | session low prints **after** 10:00 |
| `CLOSE_UP` | close > open |
| `CLOSE_DOWN` | close < open |
| `REACH_PLUS_1` | price reaches +1% from open after 10:00 |
| `GIVEBACK_50` | gives back ≥ 50% of 09:30–10:00 gain by 11:00 / by close |
| `BIG_MOVE_AFTER` | ≥ $N swing (using selected move size) after 10:00 |

### 3) Report probabilities

For each setup:

```text
Setup matched on N days
P(continue up to 11:00) = …
P(pullback by 11:00) = …
P(day high after 10:00) = …
P(close green) = …
Median path after 10:00 (indexed to 10:00 = 100)
```

Split by **weekday** when enough samples exist.

---

## Deep analysis modules

### A. Opening-drive scenarios (priority #1)

Focus windows:

1. **09:30–10:00** (first 30m) — your example  
2. **09:30–09:45** (first 15m)  
3. **09:30–10:30** (first hour)

For each: Up / Down / Flat → next 30m, next 60m, rest of day.

### B. Continuation vs reversal map

Build a matrix:

```text
Rows: morning direction (Up / Down / Flat)
Cols: afternoon result (Close Up / Close Down / High after / Low after)
Cell: probability + sample count
```

One matrix per weekday + one “All days.”

### C. Path clusters (shape patterns)

Normalize each day to open = 100.

Cluster morning shapes (09:30–10:00):

- Smooth grind up  
- Spike then fade  
- Dip then reclaim  
- Straight dump  

Then for each cluster, show the **average afternoon path**.

(Start simple: rule-based shapes; later optional k-means.)

### D. Time-of-day conditional heat

Not just “avg return at 11:05,” but:

> Given open was UP, what is avg return / up% at each minute after 10:00?

Second heatmap: conditioned on setup.

### E. Dollar-move scenarios

Using existing $5/$7/$9/$11 detector:

> If first ≥$5 up-move starts before 10:00, when does the next ≥$5 down-move usually start?

### F. Cross-symbol confirmation (SOXL vs QQQ)

> If QQQ 09:30–10:00 is up **and** SOXL is up, does SOXL continuation improve vs SOXL-only?

Useful for filtering false signals.

---

## Minimum samples & honesty rules

| Samples (N) | How we show it |
|-------------|----------------|
| N < 3 | “Too few — directional hint only” |
| 3 ≤ N < 8 | Show %, label **weak** |
| N ≥ 8 | Show %, label **usable** |
| N ≥ 20 | Label **stronger** |

Always show **N** next to every probability. Never hide small samples.

With ~15 sessions now, first 30m up/down splits might be ~5–8 days each → **weak but real**. Cron will improve this.

---

## Dashboard UX (after plan approval)

New section: **Scenario explorer**

Controls:

1. Symbol: SOXL / QQQ  
2. Setup window: 09:30–10:00 (presets + custom)  
3. Setup condition: Up / Down / Flat (+ threshold)  
4. Weekday: All / Mon–Fri  
5. Interval + Move size (reuse existing)

Output:

1. **Match list** — which dates hit the setup  
2. **Outcome cards** — probabilities with N  
3. **Median path chart** — after setup end, overlay matching days  
4. **Weekday breakdown** table  
5. Plain-English summary, e.g.  
   *“On 6 of 8 days when SOXL rose 09:30–10:00, price pulled back by 11:00 (75%, weak).”*

---

## Data / engineering plan

### Phase S1 — Scenario engine (backend)

- `src/ScenarioAnalyzer.php`  
- Input: session paths + setup/outcome definitions  
- Output: JSON stats (matches, probabilities, median path)

### Phase S2 — API

- `api.php?action=scenario&symbol=SOXL&from=09:30&to=10:00&dir=up&threshold_pct=0.5`

### Phase S3 — Dashboard Scenario explorer UI

### Phase S4 — Shape clusters + conditional heatmap

### Phase S5 — Longer history strategy

Options (pick later):

1. Keep 1m only + grow via cron (slow but clean)  
2. Add **5m** archive for ~60 days to boost scenario N  
3. Both: 1m for precise timing, 5m for scenario stats

---

## Suggested first scenarios to ship

1. **If up 09:30→10:00** → P(pullback by 11:00), P(close up), P(high after 10:00)  
2. **If down 09:30→10:00** → P(bounce by 11:00), P(close down), P(low after 10:00)  
3. **If strong up (≥$5 or ≥1%) 09:30→10:00** → same outcomes  
4. Split each by weekday when N allows  

---

## Success criteria

- You can pick “SOXL up 09:30–10:00” and instantly see likely next scenarios with counts  
- Paths of matching days are overlaid  
- Weak samples are clearly labeled  
- Works with current Move size + Interval controls  

---

## Open decisions (need your OK before coding)

1. **Default setup window:** 09:30–10:00 only first, or also 15m / 60m presets?  
2. **Threshold style:** percent (e.g. +0.5%), dollars (e.g. +$5), or both?  
3. **Priority symbol:** SOXL first, or SOXL+QQQ together from day one?  
4. **History:** stay on 1m only for now, or add 5m to get closer to 1 month of scenarios?  
5. **Weekday split:** show Always / only when N ≥ 3 per weekday?

---

## Recommended default answers (if you want speed)

1. Presets: 15m, 30m, 60m (default 30m)  
2. Both % and $ thresholds (default +0.5% or selected move size)  
3. SOXL + QQQ  
4. 1m now; add 5m in a follow-up  
5. Show weekday split with N, mark weak if N < 3  

---

## Out of scope for this phase

- Live trade alerts / broker orders  
- ML prediction models  
- Options/IV  

---

*No implementation until you confirm the open decisions above.*
