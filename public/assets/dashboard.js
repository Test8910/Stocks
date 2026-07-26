(() => {
  const $ = (id) => document.getElementById(id);
  let summary = null;
  let lineChart = null;

  async function load() {
    const symbol = $("symbol").value;
    $("meta").textContent = `Loading ${symbol}…`;
    const res = await fetch(`api.php?action=summary&symbol=${encodeURIComponent(symbol)}`);
    const data = await res.json();
    if (!data.ok) {
      $("meta").textContent = data.error || "Failed to load";
      return;
    }
    summary = data;
    $("meta").textContent = `${data.symbol}: ${data.bar_count} RTH bars across ${data.session_count} sessions · threshold ${(data.patterns.threshold * 100).toFixed(0)}%`;
    renderLowHigh();
    renderPatterns();
    renderLine();
    renderHeat();
  }

  function renderLowHigh() {
    const root = $("lowHigh");
    root.innerHTML = "";
    (summary.low_high || []).forEach((d) => {
      const el = document.createElement("div");
      el.className = "lh-card";
      el.innerHTML = `
        <h3>${d.label}</h3>
        <p class="low">Typical low: <strong>${d.typical_low_time ?? "—"}</strong></p>
        <p class="high">Typical high: <strong>${d.typical_high_time ?? "—"}</strong></p>
        <p>${d.sessions ?? 0} sessions</p>
      `;
      root.appendChild(el);
    });
  }

  function renderPatterns() {
    const root = $("patterns");
    root.innerHTML = "";
    const days = summary.patterns?.weekdays || {};
    Object.keys(days).forEach((wd) => {
      const d = days[wd];
      const ups = (d.uptrend_windows || [])
        .map((w) => `<li class="up">${w.start}–${w.end} (up ${(w.avg_up_prob * 100).toFixed(0)}%)</li>`)
        .join("") || "<li>None</li>";
      const downs = (d.downtrend_windows || [])
        .map((w) => `<li class="down">${w.start}–${w.end} (down ${(w.avg_down_prob * 100).toFixed(0)}%)</li>`)
        .join("") || "<li>None</li>";
      const buy = d.buy_zone
        ? `<p class="up">Buy zone (near lows): ${d.buy_zone.start}–${d.buy_zone.end} (center ${d.buy_zone.center})</p>`
        : "";
      const sell = d.sell_zone
        ? `<p class="down">Sell zone (near highs): ${d.sell_zone.start}–${d.sell_zone.end} (center ${d.sell_zone.center})</p>`
        : "";
      const el = document.createElement("div");
      el.className = "pat-day";
      el.innerHTML = `
        <h3>${d.label}</h3>
        ${buy}${sell}
        <p>Uptrend times</p>
        <ul>${ups}</ul>
        <p>Downtrend times</p>
        <ul>${downs}</ul>
      `;
      root.appendChild(el);
    });
  }

  function renderLine() {
    const wd = $("weekday").value;
    const day = summary.weekdays?.[wd];
    if (!day) return;
    const labels = day.minutes.map((m) => m.time);
    const rets = day.minutes.map((m) => (m.n ? m.avg_ret * 10000 : null)); // bps-ish scale *10 for visibility → actually avg_ret*10000 = basis points-ish
    const ctx = $("lineChart").getContext("2d");
    if (lineChart) lineChart.destroy();
    lineChart = new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: [{
          label: `${summary.symbol} ${day.label} avg return (×10000)`,
          data: rets,
          borderColor: "#3dbb8b",
          backgroundColor: "rgba(61,187,139,0.12)",
          fill: true,
          pointRadius: 0,
          borderWidth: 1.5,
          tension: 0.15,
          spanGaps: false,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
          x: {
            ticks: {
              maxTicksLimit: 14,
              color: "#9aabbc",
              callback(v, i) {
                return i % 30 === 0 ? labels[i] : "";
              },
            },
            grid: { color: "#2a3644" },
          },
          y: {
            ticks: { color: "#9aabbc" },
            grid: { color: "#2a3644" },
            title: { display: true, text: "avg 1m return × 10000", color: "#9aabbc" },
          },
        },
        plugins: {
          legend: { labels: { color: "#e8eef4" } },
        },
      },
    });
  }

  function colorFor(avgRet, n) {
    if (!n) return "rgb(40,48,58)";
    const mag = Math.min(1, Math.abs(avgRet) / 0.0008);
    if (avgRet >= 0) {
      const g = Math.round(80 + 140 * mag);
      return `rgb(30,${g},90)`;
    }
    const r = Math.round(90 + 140 * mag);
    return `rgb(${r},50,60)`;
  }

  function renderHeat() {
    const heat = summary.heatmap || [];
    const times = summary.times || [];
    const rows = heat.length;
    const cols = times.length;
    const cellW = 2;
    const cellH = 28;
    const labelW = 40;
    const canvas = $("heatCanvas");
    canvas.width = labelW + cols * cellW;
    canvas.height = rows * cellH + 24;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#1a222c";
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const labels = summary.weekday_labels || ["Mon", "Tue", "Wed", "Thu", "Fri"];
    for (let r = 0; r < rows; r++) {
      ctx.fillStyle = "#9aabbc";
      ctx.font = "12px sans-serif";
      ctx.fillText(labels[r] || "", 4, r * cellH + 18);
      for (let c = 0; c < cols; c++) {
        const cell = heat[r][c];
        ctx.fillStyle = cell ? colorFor(cell.avg_ret, cell.n) : "rgb(40,48,58)";
        ctx.fillRect(labelW + c * cellW, r * cellH, cellW, cellH - 2);
      }
    }

    // time axis ticks every 30m
    ctx.fillStyle = "#9aabbc";
    ctx.font = "10px sans-serif";
    for (let c = 0; c < cols; c += 30) {
      ctx.fillText(times[c] || "", labelW + c * cellW, rows * cellH + 14);
    }

    canvas.onmousemove = (ev) => {
      const rect = canvas.getBoundingClientRect();
      const x = ev.clientX - rect.left;
      const y = ev.clientY - rect.top;
      const scaleX = canvas.width / rect.width;
      const scaleY = canvas.height / rect.height;
      const c = Math.floor(((x * scaleX) - labelW) / cellW);
      const r = Math.floor((y * scaleY) / cellH);
      if (r < 0 || r >= rows || c < 0 || c >= cols) {
        $("heatTip").textContent = "";
        return;
      }
      const cell = heat[r][c];
      if (!cell) {
        $("heatTip").textContent = `${labels[r]} ${times[c]} — no samples`;
        return;
      }
      $("heatTip").textContent = `${labels[r]} ${times[c]} · n=${cell.n} · avg ret=${(cell.avg_ret * 100).toFixed(4)}% · up=${(cell.up_prob * 100).toFixed(0)}% · near-high=${(cell.avg_rel_high * 100).toFixed(0)}%`;
    };
  }

  $("reload").addEventListener("click", load);
  $("symbol").addEventListener("change", load);
  $("weekday").addEventListener("change", () => summary && renderLine());
  load();
})();
