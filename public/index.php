<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SOXL / QQQ Intraday Patterns</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500&family=IBM+Plex+Serif:wght@500&display=swap" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="assets/dashboard.css" />
</head>
<body>
  <header class="top">
    <div>
      <p class="brand">Stocks</p>
      <h1>Price, low, then high — US, UK &amp; Asia</h1>
      <p class="sub">US intraday patterns plus Asia → UK → US lead-lag (markets that open first)</p>
    </div>
    <div class="controls">
      <label>
        Symbol
        <select id="symbol">
          <option value="SOXL">SOXL</option>
          <option value="QQQ">QQQ</option>
        </select>
      </label>
      <label>
        Move size
        <select id="minDollars">
          <option value="5" selected>$5+</option>
          <option value="7">$7+</option>
          <option value="9">$9+</option>
          <option value="11">$11+</option>
        </select>
      </label>
      <label>
        Interval
        <select id="interval">
          <option value="1" selected>1 min</option>
          <option value="2">2 min</option>
          <option value="5">5 min</option>
          <option value="15">15 min</option>
        </select>
      </label>
      <label>
        Session day
        <select id="sessionDate"></select>
      </label>
      <label>
        Weekday avg
        <select id="weekday">
          <option value="1">Monday</option>
          <option value="2">Tuesday</option>
          <option value="3">Wednesday</option>
          <option value="4">Thursday</option>
          <option value="5">Friday</option>
        </select>
      </label>
      <button type="button" id="reload">Refresh</button>
    </div>
  </header>

  <p id="meta" class="meta">Loading…</p>

  <section class="section">
    <h2>Asia → UK → US lead-lag</h2>
    <p class="hint" id="globalHint">Asia and UK open before US cash. Pick QQQ-like proxies and see whether the US session usually follows the same direction.</p>
    <div class="controls compare-controls">
      <label>
        Asia (opens first)
        <select id="globalAsia">
          <option value="HSTECH" selected>HSTECH — Hang Seng TECH (~QQQ Asia)</option>
          <option value="TWII">TWII — Taiwan (semis)</option>
          <option value="N225">N225 — Nikkei 225</option>
        </select>
      </label>
      <label>
        UK / Europe
        <select id="globalUk">
          <option value="EQQQ" selected>EQQQ.L — Nasdaq-100 (London hours)</option>
          <option value="FTSE">FTSE — FTSE 100</option>
        </select>
      </label>
      <label>
        US follow
        <select id="globalUs">
          <option value="QQQ" selected>QQQ</option>
          <option value="SOXL">SOXL</option>
        </select>
      </label>
      <label>
        Lead threshold
        <select id="globalThreshold">
          <option value="0.2">0.2%</option>
          <option value="0.3" selected>0.3%</option>
          <option value="0.5">0.5%</option>
          <option value="1">1%</option>
        </select>
      </label>
      <button type="button" id="runGlobal">Compare</button>
    </div>
    <p id="globalSummaryText" class="hint"></p>
    <div id="globalCards" class="compare-grid"></div>
    <div id="globalScenarios" class="compare-grid"></div>
    <div id="globalDays" class="big-move-list"></div>
  </section>

  <section class="section">
    <h2>Scenario explorer</h2>
    <p class="hint" id="scenarioHint">If → Then patterns from history. Uses selected Symbol + Interval. Small N is labeled weak / too few.</p>
    <div class="controls compare-controls">
      <label>
        Mode
        <select id="scenarioMode">
          <option value="drop" selected>Drop / rise %</option>
          <option value="open">Open drive</option>
          <option value="shape">Morning shape</option>
          <option value="cross">QQQ ↔ SOXL</option>
        </select>
      </label>
      <label id="scenarioWindowLabel">
        Window
        <select id="scenarioWindow">
          <option value="0930_1000" selected>09:30–10:00</option>
          <option value="0930_1030">09:30–10:30</option>
          <option value="1000_1100">10:00–11:00</option>
          <option value="1100_1200">11:00–12:00</option>
          <option value="1300_1400">13:00–14:00</option>
          <option value="1400_1500">14:00–15:00</option>
        </select>
      </label>
      <label id="scenarioShapeLabel" hidden>
        Shape
        <select id="scenarioShape">
          <option value="v_reclaim" selected>V reclaim</option>
          <option value="waterfall">Waterfall dump</option>
          <option value="spike_fade">Spike then fade</option>
          <option value="grind_up">Grind up</option>
        </select>
      </label>
      <label id="scenarioPairLabel" hidden>
        Lead → follow
        <select id="scenarioPair">
          <option value="qqq_soxl" selected>QQQ → SOXL</option>
          <option value="soxl_qqq">SOXL → QQQ</option>
        </select>
      </label>
      <label id="scenarioDirectionLabel">
        Direction
        <select id="scenarioDirection">
          <option value="down" selected>Drop</option>
          <option value="up">Rise</option>
          <option value="flat" hidden>Flat</option>
        </select>
      </label>
      <label id="scenarioThresholdLabel">
        Threshold
        <select id="scenarioThreshold">
          <option value="0.3">0.3%</option>
          <option value="0.5">0.5%</option>
          <option value="1" selected>1%</option>
          <option value="1.5">1.5%</option>
          <option value="2">2%</option>
          <option value="3">3%</option>
        </select>
      </label>
      <label id="scenarioMeasureLabel">
        Measure
        <select id="scenarioMeasure">
          <option value="end" selected>End of window</option>
          <option value="maxdd">Max plunge in window</option>
        </select>
      </label>
      <label id="scenarioSetupEndLabel" hidden>
        Shape window
        <select id="scenarioSetupEnd">
          <option value="30">First 30m</option>
          <option value="60" selected>First 60m</option>
          <option value="90">First 90m</option>
        </select>
      </label>
      <label>
        Next period
        <select id="scenarioNext">
          <option value="30">Next 30m</option>
          <option value="60" selected>Next 60m</option>
          <option value="90">Next 90m</option>
          <option value="120">Next 120m</option>
        </select>
      </label>
      <label>
        Weekday
        <select id="scenarioWeekday">
          <option value="all" selected>All</option>
          <option value="1">Mon</option>
          <option value="2">Tue</option>
          <option value="3">Wed</option>
          <option value="4">Thu</option>
          <option value="5">Fri</option>
        </select>
      </label>
      <button type="button" id="runScenario">Analyze</button>
    </div>
    <p id="scenarioSummaryText" class="hint"></p>
    <div id="scenarioCards" class="compare-grid"></div>
    <div id="scenarioMatches" class="big-move-list"></div>
    <div class="chart-wrap">
      <canvas id="scenarioPathChart" height="120"></canvas>
    </div>
  </section>

  <section class="section">
    <h2>Last 4 weekdays comparison</h2>
    <p class="hint">Compare up to 4 days — last 4 trading days, or the last 4 Mondays/Tuesdays/… Uses your selected Interval and Move size.</p>
    <div class="controls compare-controls">
      <label>
        Compare
        <select id="comparePreset">
          <option value="last4_days">Last 4 trading days</option>
          <option value="weekday_5" selected>Last Fridays</option>
          <option value="weekday_1">Last Mondays</option>
          <option value="weekday_2">Last Tuesdays</option>
          <option value="weekday_3">Last Wednesdays</option>
          <option value="weekday_4">Last Thursdays</option>
        </select>
      </label>
      <label>
        How many
        <select id="compareLimit">
          <option value="2">2</option>
          <option value="3">3</option>
          <option value="4" selected>4</option>
        </select>
      </label>
      <button type="button" id="runCompare">Compare</button>
    </div>
    <p id="compareDatesLabel" class="hint"></p>
    <div id="compareSummary" class="compare-grid"></div>
    <div class="chart-wrap">
      <canvas id="compareNormChart" height="120"></canvas>
    </div>
    <div class="chart-wrap" style="margin-top:0.75rem">
      <canvas id="comparePriceChart" height="110"></canvas>
    </div>
  </section>

  <section class="section">
    <h2 id="bigMoveTitle">$5 and up moves — when they happen</h2>
    <p id="bigMoveHint" class="hint">Swings of <strong>$5 or more</strong> (up or down). Use Move size for $5 / $7 / $9 / $11.</p>
    <div id="bigMoveTiming" class="lowhigh"></div>
    <div id="bigMoveList" class="big-move-list"></div>
  </section>

  <section class="section">
    <h2>Price with time (selected session)</h2>
    <div id="pathSummary" class="path-summary"></div>
    <div class="chart-wrap">
      <canvas id="priceChart" height="120"></canvas>
    </div>
  </section>

  <section class="section">
    <h2>Average price path for weekday</h2>
    <p class="hint">Average price by minute for the selected weekday. Markers show where the average curve is lowest and highest.</p>
    <div id="avgPathSummary" class="path-summary"></div>
    <div class="chart-wrap">
      <canvas id="avgPriceChart" height="110"></canvas>
    </div>
  </section>

  <section class="section">
    <h2>Low → high by weekday</h2>
    <p class="hint">For each weekday: typical low time/price path vs high — so you can see when dips tend to happen and when highs print.</p>
    <div id="lowHigh" class="lowhigh"></div>
  </section>

  <section class="section">
    <h2 id="patternTitle">Pattern windows</h2>
    <p id="patternHint" class="hint">Analyzed with your selected Interval and Move size.</p>
    <div id="patterns" class="patterns"></div>
  </section>

  <section class="section">
    <h2>1-minute heatmap (avg return)</h2>
    <p class="hint">Rows Mon–Fri. Green = historically up that minute; red = down. Hover a cell for details.</p>
    <div class="heat-scroll">
      <canvas id="heatCanvas"></canvas>
    </div>
    <p id="heatTip" class="hint"></p>
  </section>

  <script src="assets/dashboard.js"></script>
</body>
</html>
