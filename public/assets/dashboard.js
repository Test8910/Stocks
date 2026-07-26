(() => {
  const $ = (id) => document.getElementById(id);
  let summary = null;
  let priceChart = null;
  let avgPriceChart = null;

  async function load() {
    const symbol = $("symbol").value;
    const minDollars = $("minDollars").value;
    $("meta").textContent = `Loading ${symbol} (≥$${minDollars})…`;
    const res = await fetch(
      `api.php?action=summary&symbol=${encodeURIComponent(symbol)}&min_dollars=${encodeURIComponent(minDollars)}`
    );
    const data = await res.json();
    if (!data.ok) {
      $("meta").textContent = data.error || "Failed to load";
      return;
    }
    summary = data;
    const bm = data.big_moves?.thresholds;
    const moveCount = data.big_moves?.moves?.length ?? 0;
    const min$ = bm?.min ?? Number(minDollars);
    $("bigMoveTitle").textContent = `$${min$} and up moves — when they happen`;
    $("bigMoveHint").innerHTML = `Swings of <strong>$${min$} or more</strong> (up or down). Change “Move size” above to switch between $2–$6.`;
    $("meta").textContent = `${data.symbol}: ${data.bar_count} bars · ${moveCount} moves ≥$${min$} · ${(data.patterns.threshold * 100).toFixed(0)}% pattern threshold`;
    fillSessionDates();
    renderBigMoves();
    renderSessionPrice();
    renderAvgPrice();
    renderLowHigh();
    renderPatterns();
    renderHeat();
  }

  function renderBigMoves() {
    const timing = $("bigMoveTiming");
    const list = $("bigMoveList");
    const bm = summary.big_moves;
    if (!bm) {
      timing.innerHTML = "";
      list.innerHTML = "<p class='hint'>No big-move data.</p>";
      return;
    }

    timing.innerHTML = "";
    (bm.timing_summary || []).forEach((d) => {
      const el = document.createElement("div");
      el.className = "lh-card";
      el.innerHTML = `
        <h3>${d.label}</h3>
        <p><strong>${d.count}</strong> moves ≥$${bm.thresholds.min}</p>
        <p class="up">Up: ${d.up_count} · typical start <strong>${d.typical_up_start ?? "—"}</strong></p>
        <p class="down">Down: ${d.down_count} · typical start <strong>${d.typical_down_start ?? "—"}</strong></p>
        <p class="high">≥$${bm.thresholds.big}: ${d.big_count ?? d.count} · typical start <strong>${d.typical_big_start ?? d.typical_up_start ?? "—"}</strong></p>
      `;
      timing.appendChild(el);
    });

    const moves = bm.moves || [];
    const minLabel = `≥$${bm.thresholds.min}`;
    if (!moves.length) {
      list.innerHTML = `<p class="hint">No ${minLabel} swings found in the loaded history.</p>`;
      return;
    }

    const rows = moves.map((m) => {
      const cls = m.direction === "up" ? "up" : "down";
      const badge = `<span class="badge over3">${minLabel}</span>`;
      const sign = m.direction === "up" ? "+" : "−";
      return `<tr class="${cls}">
        <td><button type="button" class="linkish" data-date="${m.date}">${m.date}</button></td>
        <td>${m.label}</td>
        <td>${badge}</td>
        <td class="${cls}">${m.direction.toUpperCase()} ${sign}$${m.dollars.toFixed(2)}</td>
        <td><strong>${m.start_time}</strong> → ${m.end_time}</td>
        <td>$${m.start_price.toFixed(2)} → $${m.end_price.toFixed(2)}</td>
        <td>${m.duration_minutes}m</td>
      </tr>`;
    }).join("");

    list.innerHTML = `
      <div class="table-wrap">
        <table class="moves-table">
          <thead>
            <tr>
              <th>Date</th><th>Day</th><th>Size</th><th>Move</th><th>Time</th><th>Price</th><th>Dur</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;
    list.querySelectorAll("button[data-date]").forEach((btn) => {
      btn.addEventListener("click", () => {
        $("sessionDate").value = btn.getAttribute("data-date");
        renderSessionPrice();
        window.scrollTo({ top: $("pathSummary").offsetTop - 20, behavior: "smooth" });
      });
    });
  }

  function fillSessionDates() {
    const sel = $("sessionDate");
    const prev = sel.value;
    sel.innerHTML = "";
    (summary.sessions || []).forEach((s) => {
      const opt = document.createElement("option");
      opt.value = s.date;
      opt.textContent = `${s.date} (${s.label})`;
      sel.appendChild(opt);
    });
    if (prev && [...sel.options].some((o) => o.value === prev)) {
      sel.value = prev;
    }
  }

  function selectedSession() {
    const date = $("sessionDate").value;
    return (summary.sessions || []).find((s) => s.date === date) || summary.sessions?.[0] || null;
  }

  function renderSessionPrice() {
    const s = selectedSession();
    const box = $("pathSummary");
    if (!s) {
      box.innerHTML = "<p>No session data.</p>";
      return;
    }

    const min$ = summary.big_moves?.thresholds?.min ?? 5;
    const dayMoves = (summary.big_moves?.moves || []).filter((m) => m.date === s.date);
    const moveLines = dayMoves.length
      ? `<ul class="session-moves">${dayMoves.map((m) => {
          const cls = m.direction === "up" ? "up" : "down";
          const sign = m.direction === "up" ? "+" : "−";
          return `<li class="${cls}"><strong>≥$${min$}</strong> ${m.direction} ${sign}$${m.dollars.toFixed(2)} · <strong>${m.start_time}→${m.end_time}</strong> ($${m.start_price.toFixed(2)}→$${m.end_price.toFixed(2)})</li>`;
        }).join("")}</ul>`
      : `<p class="hint">No ≥$${min$} swings on this session.</p>`;

    const lowToHigh = s.high_after_low
      ? `<span class="up">Low → High in ${s.minutes_low_to_high} min (+${s.move_pct}%)</span>`
      : `<span class="down">High printed before low (down-day shape) · range ${s.move_pct ?? "—"}%</span>`;

    box.innerHTML = `
      <div class="path-card">
        <h3>${summary.symbol} · ${s.date} · ${s.label}</h3>
        <p class="low">Lower price: <strong>$${s.low_price}</strong> at <strong>${s.low_time}</strong></p>
        <p class="high">Higher price: <strong>$${s.high_price}</strong> at <strong>${s.high_time}</strong></p>
        <p>${lowToHigh}</p>
        <p><strong>≥$${min$} moves this day</strong></p>
        ${moveLines}
      </div>
    `;

    const labels = s.points.map((p) => p.time);
    const prices = s.points.map((p) => p.price);
    const lowIdx = s.points.findIndex((p) => p.time === s.low_time && p.price === s.low_price);
    const highIdx = s.points.findIndex((p) => p.time === s.high_time && p.price === s.high_price);

    const segment = prices.map((p, i) => {
      if (!s.high_after_low) return null;
      const lo = Math.min(lowIdx, highIdx);
      const hi = Math.max(lowIdx, highIdx);
      return i >= lo && i <= hi ? p : null;
    });

    // Overlay each big move stretch on the chart
    const moveDatasets = dayMoves.map((m) => {
      const isUp = m.direction === "up";
      const data = s.points.map((p) => {
        const t = p.minute_of_day;
        return t >= m.start_minute && t <= m.end_minute ? p.price : null;
      });
      return {
        label: `≥$${min$} ${m.direction} ${m.start_time}`,
        data,
        borderColor: isUp ? "#2ee6a0" : "#ff6b76",
        borderWidth: 3,
        pointRadius: 0,
        tension: 0.05,
        spanGaps: false,
        fill: false,
      };
    });

    const ctx = $("priceChart").getContext("2d");
    if (priceChart) priceChart.destroy();
    priceChart = new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: "Price",
            data: prices,
            borderColor: "#8aa4b8",
            backgroundColor: "rgba(138,164,184,0.08)",
            fill: true,
            pointRadius: 0,
            borderWidth: 1.5,
            tension: 0.05,
          },
          {
            label: "Low → High",
            data: segment,
            borderColor: "#c9a45c",
            backgroundColor: "rgba(240,198,116,0.12)",
            fill: true,
            pointRadius: 0,
            borderWidth: 1.5,
            tension: 0.05,
            spanGaps: false,
          },
          ...moveDatasets,
          {
            label: "Low",
            data: prices.map((p, i) => (i === lowIdx ? p : null)),
            borderColor: "#7ec8ff",
            backgroundColor: "#7ec8ff",
            pointRadius: 5,
            pointHoverRadius: 7,
            showLine: false,
          },
          {
            label: "High",
            data: prices.map((p, i) => (i === highIdx ? p : null)),
            borderColor: "#f0c674",
            backgroundColor: "#f0c674",
            pointRadius: 5,
            pointHoverRadius: 7,
            showLine: false,
          },
        ],
      },
      options: chartOptions("Price ($)"),
    });
  }

  function renderAvgPrice() {
    const wd = $("weekday").value;
    const avg = summary.weekday_avg_price?.[wd];
    const box = $("avgPathSummary");
    if (!avg) {
      box.innerHTML = "";
      return;
    }

    const highAfter = avg.low_time && avg.high_time && avg.high_time > avg.low_time;
    // string compare works for HH:MM
    box.innerHTML = `
      <div class="path-card">
        <h3>Average ${avg.label} path (${avg.sessions} sessions)</h3>
        <p class="low">Avg lower area: <strong>$${avg.low_price ?? "—"}</strong> near <strong>${avg.low_time ?? "—"}</strong></p>
        <p class="high">Avg higher area: <strong>$${avg.high_price ?? "—"}</strong> near <strong>${avg.high_time ?? "—"}</strong></p>
        <p>${highAfter ? '<span class="up">On average, high comes after low on this weekday</span>' : '<span class="down">On average, high comes before low on this weekday</span>'}</p>
      </div>
    `;

    const labels = avg.times;
    const prices = avg.avg_price;
    const lowIdx = labels.indexOf(avg.low_time);
    const highIdx = labels.indexOf(avg.high_time);
    const segment = prices.map((p, i) => {
      if (lowIdx < 0 || highIdx < 0 || highIdx <= lowIdx) return null;
      return i >= lowIdx && i <= highIdx ? p : null;
    });

    const ctx = $("avgPriceChart").getContext("2d");
    if (avgPriceChart) avgPriceChart.destroy();
    avgPriceChart = new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: `Avg ${avg.label} price`,
            data: prices,
            borderColor: "#8aa4b8",
            backgroundColor: "rgba(138,164,184,0.08)",
            fill: true,
            pointRadius: 0,
            borderWidth: 1.5,
            tension: 0.15,
            spanGaps: false,
          },
          {
            label: "Low → High stretch",
            data: segment,
            borderColor: "#3dbb8b",
            fill: false,
            pointRadius: 0,
            borderWidth: 2.5,
            tension: 0.15,
            spanGaps: false,
          },
          {
            label: "Low",
            data: prices.map((p, i) => (i === lowIdx ? p : null)),
            backgroundColor: "#7ec8ff",
            borderColor: "#7ec8ff",
            pointRadius: 5,
            showLine: false,
          },
          {
            label: "High",
            data: prices.map((p, i) => (i === highIdx ? p : null)),
            backgroundColor: "#f0c674",
            borderColor: "#f0c674",
            pointRadius: 5,
            showLine: false,
          },
        ],
      },
      options: chartOptions("Avg price ($)"),
    });
  }

  function chartOptions(yTitle) {
    return {
      responsive: true,
      maintainAspectRatio: true,
      interaction: { mode: "index", intersect: false },
      scales: {
        x: {
          ticks: {
            maxTicksLimit: 14,
            color: "#9aabbc",
            callback(v, i, ticks) {
              const label = this.getLabelForValue(v);
              return i % 30 === 0 ? label : "";
            },
          },
          grid: { color: "#2a3644" },
        },
        y: {
          ticks: { color: "#9aabbc" },
          grid: { color: "#2a3644" },
          title: { display: true, text: yTitle, color: "#9aabbc" },
        },
      },
      plugins: {
        legend: { labels: { color: "#e8eef4" } },
        tooltip: {
          callbacks: {
            label(ctx) {
              if (ctx.raw == null) return null;
              return `${ctx.dataset.label}: $${Number(ctx.raw).toFixed(2)}`;
            },
          },
        },
      },
    };
  }

  function renderLowHigh() {
    const root = $("lowHigh");
    root.innerHTML = "";
    const byWd = {};
    (summary.sessions || []).forEach((s) => {
      if (!byWd[s.weekday]) byWd[s.weekday] = [];
      byWd[s.weekday].push(s);
    });

    (summary.low_high || []).forEach((d) => {
      const sessions = byWd[d.weekday] || [];
      const rows = sessions
        .map((s) => {
          const arrow = s.high_after_low
            ? `${s.low_time} $${s.low_price} → ${s.high_time} $${s.high_price} (+${s.move_pct}%)`
            : `${s.high_time} high $${s.high_price}, then low ${s.low_time} $${s.low_price}`;
          return `<li><button type="button" class="linkish" data-date="${s.date}">${s.date}</button>: ${arrow}</li>`;
        })
        .join("");

      const el = document.createElement("div");
      el.className = "lh-card wide";
      el.innerHTML = `
        <h3>${d.label}</h3>
        <p class="low">Typical low time: <strong>${d.typical_low_time ?? "—"}</strong></p>
        <p class="high">Typical high time: <strong>${d.typical_high_time ?? "—"}</strong></p>
        <ul class="session-list">${rows || "<li>No sessions</li>"}</ul>
      `;
      root.appendChild(el);
    });

    root.querySelectorAll("button[data-date]").forEach((btn) => {
      btn.addEventListener("click", () => {
        $("sessionDate").value = btn.getAttribute("data-date");
        renderSessionPrice();
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
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
        ? `<p class="up">Near lows: ${d.buy_zone.start}–${d.buy_zone.end}</p>`
        : "";
      const sell = d.sell_zone
        ? `<p class="down">Near highs: ${d.sell_zone.start}–${d.sell_zone.end}</p>`
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
      $("heatTip").textContent = `${labels[r]} ${times[c]} · n=${cell.n} · avg ret=${(cell.avg_ret * 100).toFixed(4)}% · up=${(cell.up_prob * 100).toFixed(0)}%`;
    };
  }

  $("reload").addEventListener("click", load);
  $("symbol").addEventListener("change", load);
  $("minDollars").addEventListener("change", load);
  $("sessionDate").addEventListener("change", () => summary && renderSessionPrice());
  $("weekday").addEventListener("change", () => summary && renderAvgPrice());
  load();
})();
