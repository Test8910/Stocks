# Deep Pattern Analysis Results (current data)

Analyzed: **SOXL** & **QQQ**, **15 RTH sessions** (2026-07-06 → 2026-07-24), 1-minute bars.

---

## How many patterns can be done?

| Layer | Count | Notes |
|-------|------:|-------|
| Setup types (window × direction × symbol) | **30** | 15m/30m/60m × Up/Down/Flat/StrongUp/StrongDown × SOXL/QQQ |
| Outcome metrics per setup | **7** | next-60m up/down, close up, high/low after, giveback 50%, etc. |
| With weekday split | **~1,260** | probability cells |
| + cross-symbol / shapes / $ move chains | **~115** | extra |
| **Total computable pattern slots** | **~1,375** | full catalog |

### Realistic with *current* 15 days

| Quality | Rule | Approx. now |
|---------|------|-------------|
| **Usable** | N ≥ 8 matching days | **~6–10** setups (mostly QQQ “flat open”, a few SOXL) |
| **Weak but real** | N 3–7 | **~15–20** setups (most SOXL open scenarios) |
| **Too few** | N &lt; 3 | skip or show as anecdote |

So: we can **define ~1,375 stats**, but only about **20–30 scenario patterns** are worth showing today; the rest get stronger as cron adds days.

---

## Your example: If price goes **up 09:30 → 10:00**

### SOXL (N = 7 days, **weak**)

| What happens next? | Result |
|--------------------|--------|
| Next 60 minutes (10:00–11:00) | **Down 57%** / Up 43% → slight fade bias |
| Close green? | **71%** close up |
| Day high after 10:00? | **6/7 (86%)** — high often still ahead |
| Day low after 10:00? | 4/7 |
| Give back ≥50% of morning gain by close | 29% |

**Read:** Morning up on SOXL often **still makes a later high** and **closes up**, but the **next hour frequently pulls back**. Not “buy and hold the next 60m blindly.”

### QQQ up 09:30–10:00

N = 2 only → **too few** (QQQ rarely moves ±0.5% in first 30m). For QQQ, “Flat open” is the common setup (N=12).

---

## Stronger findings (SOXL)

1. **Down in first 30m** (N=7): close up only **14%** → morning weakness often = red close.  
2. **Up in first 60m** (N=8, usable): next 60m **down 75%** → first-hour rally often fades into late morning.  
3. **Down in first 15m** (N=8, usable): close up only **25%**.  
4. **First ≥$5 move before 10:00**: Down **9** vs Up **4** (none 2) → early large red swings more common in this sample.

## QQQ note

QQQ mornings are usually **flat** (|ret| &lt; 0.5%). Better thresholds for QQQ: **±0.2%** or dollar moves, not the same % as SOXL.

---

## Idea checked: If it **drops X% in time T**, then what change next?

Rule form:

> **IF** return in window ≤ −X% (or max drawdown ≤ −X%)  
> **THEN** measure next 60m bounce/continue, rest-of-day, close

### SOXL standouts (15 days, still small N)

| Setup | N | Next 60m | Close green |
|-------|--:|----------|-------------|
| Drop ≥1% in **09:30–10:00** | 7 | bounce 43% / keep falling **57%**, avg **−1.4%** | **14%** |
| Drop ≥2% in **09:30–10:00** | 4 | keep falling **75%**, avg **−3.2%** | **0%** |
| Drop ≥0.5% in **10:00–11:00** | 8 | **bounce 62%**, avg **+0.8%** | 38% |
| Drop ≥1% in **11:00–12:00** | 4 | keep falling **75%** | 25% |
| Drop ≥1.5% in **14:00–15:00** | 4 | **bounce 75%**, avg **+1.3%** | 0% (day already damaged) |

**Read:** Early drop (first 30m) on SOXL often **keeps hurting** into the close. Mid-morning drop (10–11) more often **bounces** next hour. Late drop (14–15) can bounce into the close hour but day may stay red.

QQQ rarely hits −0.5%/−1% in short windows → need tighter % (e.g. 0.2–0.3%) or stick to SOXL for this rule family.

### Extra pattern slots from this idea alone

- Windows (~6) × drop thresholds (~5) × measure type (end-return vs maxDD) × symbols (2) ≈ **~120** more If→Then cards  
- With outcomes (next60 / rest-day / close) → **~360** stats  

---

## Pattern families we can build

1. **Opening-drive If→Then** (15/30/60m × up/down/flat) — priority  
2. **Drop X% in time T → next change** (this idea) — high priority  
3. **Continuation vs reversal matrix** (morning → close)  
4. **Conditional time heatmaps** (given open up, minute stats after 10:00)  
5. **Morning shape clusters** (grind / spike-fade / dip-reclaim / dump)  
6. **Dollar-move chains** ($5/$7/$9/$11 first move → next opposing move)  
7. **SOXL↔QQQ confirmation** (both up vs SOXL alone)  
8. **Weekday-conditioned** versions of all above  
9. **Unconditional** 1m ≥60% cells (already ~700+ up/down cells per symbol — noisy)

---

## Bottom line

- **Catalog size:** ~**1,375** pattern/stat slots possible.  
- **Worth shipping now:** ~**20–30** scenario cards with honest N labels.  
- **Best first ship:** SOXL “If up/down 09:30–10:00 → next hour / close / high-after.”  
- More days (cron or 5m history) will turn many **weak** patterns into **usable** ones.

See also: `PATTERN_SCENARIOS_PLAN.md` for build phases.
