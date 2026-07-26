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
      <h1>Intraday patterns — SOXL &amp; QQQ</h1>
      <p class="sub">Mon–Fri · 09:30–16:00 ET · 1-minute heatmap · learn when price is typically low vs high</p>
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
        Weekday chart
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
    <h2>Typical low → high (by weekday)</h2>
    <p class="hint">Average clock time when the session low and session high printed over recent days.</p>
    <div id="lowHigh" class="lowhigh"></div>
  </section>

  <section class="section">
    <h2>Pattern windows (≥ 60% probability)</h2>
    <div id="patterns" class="patterns"></div>
  </section>

  <section class="section">
    <h2>Average per-minute return — selected weekday</h2>
    <div class="chart-wrap">
      <canvas id="lineChart" height="110"></canvas>
    </div>
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
