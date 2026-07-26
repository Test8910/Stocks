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
      <h1>Price, low, then high — SOXL &amp; QQQ</h1>
      <p class="sub">See price with time, dollar-move timings (up or down), and low → high paths (Mon–Fri, 09:30–16:00 ET)</p>
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
    <h2>Pattern windows (≥ 60% probability)</h2>
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
