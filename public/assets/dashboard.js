(() => {
  const $ = (id) => document.getElementById(id);
  let summary = null;
  let priceChart = null;
  let avgPriceChart = null;
  let compareNormChart = null;
  let comparePriceChart = null;
  let scenarioPathChart = null;
  let scenarioData = null;
  let globalData = null;
  let liveBiasData = null;
  let liveBiasTimer = null;
  let checklistTimer = null;
  let checklistRefreshSec = 60;

  function fmtDate(iso) {
    if (!iso) return "—";
    const [y, m, d] = iso.split("-");
    return `${d}-${m}-${y}`;
  }

  function compareQuery() {
    const preset = $("comparePreset")?.value || "weekday_5";
    const limit = $("compareLimit")?.value || "4";
    if (preset === "last4_days") {
      return `&compare_mode=last4_days&compare_limit=${encodeURIComponent(limit)}`;
    }
    const wd = preset.replace("weekday_", "");
    return `&compare_mode=last4_weekday&compare_weekday=${encodeURIComponent(wd)}&compare_limit=${encodeURIComponent(limit)}`;
  }

  async function load() {
    const symbol = $("symbol").value;
    const minDollars = $("minDollars").value;
    const interval = $("interval").value;
    $("meta").textContent = `Loading ${symbol} (${interval}m, ≥$${minDollars})…`;
    let url = `api.php?action=summary&symbol=${encodeURIComponent(symbol)}&min_dollars=${encodeURIComponent(minDollars)}&interval=${encodeURIComponent(interval)}`;
    url += compareQuery();
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) {
      $("meta").textContent = data.error || "Failed to load";
      return;
    }
    summary = data;
    const bm = data.big_moves?.thresholds;
    const moveCount = data.big_moves?.moves?.length ?? 0;
    const min$ = bm?.min ?? Number(minDollars);
    const iv = data.interval_minutes ?? Number(interval);
    $("bigMoveTitle").textContent = `$${min$} and up moves — when they happen`;
    $("bigMoveHint").innerHTML = `Swings of <strong>$${min$} or more</strong> on <strong>${iv}-minute</strong> bars (up or down). Use <em>Move size</em> and <em>Interval</em> above.`;
    $("patternTitle").textContent = `Pattern windows (${iv}-min · ≥$${min$} · ≥${Math.round((data.patterns?.threshold ?? 0.6) * 100)}% )`;
    $("patternHint").innerHTML = `Recalculated from your selected options: <strong>${iv}-minute</strong> interval and <strong>$${min$}+</strong> moves, plus ≥60% up/down probability windows.`;
    $("meta").textContent = `${data.symbol}: ${iv}-min interval · ${moveCount} moves ≥$${min$} · ${data.session_count} sessions`;
    fillSessionDates();
    try { renderCompare(); } catch (e) { console.error(e); $("compareSummary").innerHTML = `<p class="hint">Compare render error: ${e.message}</p>`; }
    try { renderBigMoves(); } catch (e) { console.error(e); }
    try { renderSessionPrice(); } catch (e) { console.error(e); }
    try { renderAvgPrice(); } catch (e) { console.error(e); }
    try { renderLowHigh(); } catch (e) { console.error(e); }
    try { renderPatterns(); } catch (e) { console.error(e); }
    try { renderHeat(); } catch (e) { console.error(e); }
    try { loadScenario(); } catch (e) { console.error(e); }
    try { loadGlobalLead(); } catch (e) { console.error(e); }
    try { loadLiveBias(); } catch (e) { console.error(e); }
    try { loadRsi(); } catch (e) { console.error(e); }
    try { loadChecklist(); } catch (e) { console.error(e); }
    try { loadWeekdayReturns(); } catch (e) { console.error(e); }
  }

  async function loadWeekdayReturns() {
    if ($("weekdayReturnsSummary")) $("weekdayReturnsSummary").textContent = "Loading Mon–Fri returns…";
    try {
      const res = await fetch("api.php?action=weekday_returns");
      const data = await res.json();
      if (!data.ok) {
        if ($("weekdayReturnsSummary")) $("weekdayReturnsSummary").textContent = data.error || "Failed";
        return;
      }
      renderWeekdayReturns(data);
    } catch (e) {
      if ($("weekdayReturnsSummary")) $("weekdayReturnsSummary").textContent = "Error: " + e.message;
    }
  }

  function renderWeekdayReturns(d) {
    if (!d || !$("weekdayReturnsSummary")) return;
    $("weekdayReturnsSummary").textContent = d.summary_text || "";

    const cards = [];
    (d.symbols || []).forEach((sym) => {
      const block = d.by_symbol?.[sym];
      if (!block) return;
      (block.by_weekday || []).forEach((w) => {
        const avg = w.avg_ret == null ? "—" : `${w.avg_ret >= 0 ? "+" : ""}${w.avg_ret}%`;
        const avgCls = (w.avg_ret ?? 0) >= 0 ? "up" : "down";
        cards.push(`<div class="path-card">
          <h3>${sym} · ${w.label}</h3>
          <p>Sessions: <strong>${w.n}</strong></p>
          <p class="up">Up days: <strong>${w.up_pct ?? "—"}%</strong> (${w.up_n}/${w.n})</p>
          <p class="${avgCls}">Avg open→close: <strong>${avg}</strong></p>
        </div>`);
      });
    });
    $("weekdayReturnsCards").innerHTML = cards.join("") || "<p class='hint'>No data</p>";

    const fmt = (v) => v == null ? "—" : `${v >= 0 ? "+" : ""}${v}%`;
    const rows = (d.rows || []).map((r) => {
      const soxl = r.rets?.SOXL;
      const qqq = r.rets?.QQQ;
      const sCls = soxl == null ? "" : (soxl >= 0 ? "up" : "down");
      const qCls = qqq == null ? "" : (qqq >= 0 ? "up" : "down");
      return `<tr>
        <td>${fmtDate(r.date)}</td>
        <td>${r.label || "—"}</td>
        <td class="${sCls}">${fmt(soxl)}</td>
        <td class="${qCls}">${fmt(qqq)}</td>
      </tr>`;
    }).join("");

    $("weekdayReturnsTable").innerHTML = `
      <div class="table-wrap">
        <table class="moves-table">
          <thead><tr><th>Date</th><th>Day</th><th>SOXL %</th><th>QQQ %</th></tr></thead>
          <tbody>${rows || "<tr><td colspan='4'>No sessions</td></tr>"}</tbody>
        </table>
      </div>
    `;
  }

  async function loadChecklist() {
    const uk = $("checkUk")?.value || "EQQQ";
    const us = $("checkUs")?.value || "SOXL";
    const threshold = $("checkThreshold")?.value || "0.3";
    if ($("checkSummary")) $("checkSummary").textContent = "Refreshing checklist + live bars…";
    const url = `api.php?action=checklist&uk=${encodeURIComponent(uk)}&us=${encodeURIComponent(us)}&threshold_pct=${encodeURIComponent(threshold)}&sync=1`;
    try {
      const res = await fetch(url);
      const data = await res.json();
      if (!data.ok) {
        if ($("checkSummary")) $("checkSummary").textContent = data.error || "Checklist failed";
        return;
      }
      renderChecklist(data);
      const next = Number(data.refresh_seconds) || 60;
      if (next !== checklistRefreshSec) {
        checklistRefreshSec = next;
        if (checklistTimer) clearInterval(checklistTimer);
        checklistTimer = setInterval(() => {
          try { loadChecklist(); } catch (e) { /* ignore */ }
        }, checklistRefreshSec * 1000);
      }
    } catch (e) {
      if ($("checkSummary")) $("checkSummary").textContent = "Checklist error: " + e.message;
    }
  }

  function renderChecklist(d) {
    if (!d || !$("checkSummary")) return;
    $("checkSummary").textContent = d.summary_text || "";
    if ($("checkDisclaimer")) $("checkDisclaimer").textContent = d.disclaimer || "";

    const clocks = d.markets || {};
    $("checkClocks").innerHTML = ["london", "new_york"].map((k) => {
      const c = clocks[k];
      if (!c) return "";
      const cls = c.open ? "clock-open" : "clock-closed";
      return `<div class="path-card">
        <h3>${c.label}</h3>
        <p class="${cls}"><strong>${c.open ? "OPEN" : "CLOSED"}</strong></p>
        <p>${c.local_time}</p>
        <p class="hint">Session ${c.session}</p>
      </div>`;
    }).join("") + `<div class="path-card">
      <h3>Auto refresh</h3>
      <p>Every <strong>${d.refresh_seconds || 60}s</strong></p>
      <p>UTC ${d.as_of_utc || "—"}</p>
      <p class="hint">${d.monday_ready ? "Monday-ready: syncs 1m bars on each refresh" : ""}</p>
    </div>`;

    const action = d.action || "WAIT";
    const actionCls = action.includes("LONG") ? "up" : (action.includes("SHORT") ? "down" : "high");
    const snap = d.rsi_snapshot || {};
    $("checkAction").innerHTML = `
      <div class="path-card">
        <h3>Action</h3>
        <p class="check-action ${actionCls}">${action.replaceAll("_", " ")}</p>
        <p>Pass <strong>${d.pass_count ?? 0}</strong> / ${d.total ?? 0}</p>
      </div>
      <div class="path-card">
        <h3>${d.us_symbol} RSI now</h3>
        <p>RSI(14) 5m: <strong>${snap.rsi != null ? Number(snap.rsi).toFixed(1) : "—"}</strong></p>
        <p>Vol z: <strong>${snap.vol_z != null ? Number(snap.vol_z).toFixed(2) : "—"}</strong></p>
        <p class="hint">${snap.date || ""} ${snap.time || ""} · ${snap.price ?? ""}</p>
      </div>
      <div class="path-card">
        <h3>UK lead</h3>
        <p>${d.uk_symbol}: <strong>${d.live?.lead?.pct == null ? "—" : ((d.live.lead.pct >= 0 ? "+" : "") + d.live.lead.pct + "%")}</strong></p>
        <p>Direction: <strong>${d.live?.lead?.direction || "—"}</strong></p>
        <p>Hist follow: <strong>${d.live?.odds?.follow_pct ?? "—"}%</strong></p>
      </div>
    `;

    const rows = (d.items || []).map((it) => {
      const pass = !!it.pass;
      return `<tr>
        <td>${it.label}</td>
        <td class="${pass ? "check-pass" : "check-fail"}">${pass ? "PASS" : "FAIL"}</td>
        <td>${it.value ?? "—"}</td>
        <td>${it.detail || ""}</td>
      </tr>`;
    }).join("");
    $("checkItems").innerHTML = `
      <div class="table-wrap">
        <table class="check-table">
          <thead><tr><th>Check</th><th>Result</th><th>Value</th><th>Note</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;

    $("checkQuotes").innerHTML = (d.live?.quotes || []).map((q) => {
      const cls = (q.change_pct ?? 0) >= 0 ? "up" : "down";
      const ch = q.change_pct == null ? "—" : `${q.change_pct >= 0 ? "+" : ""}${q.change_pct}%`;
      return `<div class="path-card">
        <h3>${q.symbol}</h3>
        <p><strong>${q.price}</strong> ${q.currency || ""}</p>
        <p class="${cls}">${ch}</p>
        <p class="hint">${q.exchange || ""} · ${q.as_of_utc || ""}</p>
      </div>`;
    }).join("");
  }

  async function loadRsi() {
    const symbol = $("rsiSymbol")?.value || "SOXL";
    const interval = $("rsiInterval")?.value || "5";
    const forward = $("rsiForward")?.value || "6";
    if ($("rsiSummaryText")) $("rsiSummaryText").textContent = "Computing RSI…";
    const url = `api.php?action=rsi&symbol=${encodeURIComponent(symbol)}&rsi_interval=${encodeURIComponent(interval)}&forward_bars=${encodeURIComponent(forward)}`;
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) {
      if ($("rsiSummaryText")) $("rsiSummaryText").textContent = data.error || "RSI failed";
      return;
    }
    renderRsi(data.rsi);
  }

  function renderRsi(r) {
    if (!r || !$("rsiSummaryText")) return;
    $("rsiSummaryText").textContent = r.summary_text || "";
    const s = r.signals || {};
    const os = s.rsi_oversold || {};
    const ob = s.rsi_overbought || {};
    const cu = s.rsi_cross_up_oversold || {};
    const vol = r.volume || {};
    const worksLabel = (w) => w === true ? "works" : (w === false ? "weak" : "—");
    const worksCls = (w) => w === true ? "up" : (w === false ? "down" : "high");

    $("rsiCards").innerHTML = `
      <div class="path-card">
        <h3>RSI ≤ 30 (oversold)</h3>
        <p>N=<strong>${os.n ?? 0}</strong> · <span class="${worksCls(os.works)}">${worksLabel(os.works)}</span></p>
        <p class="up">Next ${r.forward_minutes}m up: <strong>${os.up_pct ?? "—"}%</strong></p>
        <p>Avg next: <strong>${os.avg_fwd_ret == null ? "—" : ((os.avg_fwd_ret >= 0 ? "+" : "") + os.avg_fwd_ret + "%")}</strong></p>
        <p>High vol up%: ${os.high_vol_up_pct ?? "—"} · Low vol up%: ${os.low_vol_up_pct ?? "—"}</p>
      </div>
      <div class="path-card">
        <h3>RSI ≥ 70 (overbought)</h3>
        <p>N=<strong>${ob.n ?? 0}</strong> · <span class="${worksCls(ob.works)}">${worksLabel(ob.works)}</span></p>
        <p class="down">Next down: <strong>${ob.down_pct ?? "—"}%</strong></p>
        <p>Avg next: <strong>${ob.avg_fwd_ret == null ? "—" : ((ob.avg_fwd_ret >= 0 ? "+" : "") + ob.avg_fwd_ret + "%")}</strong></p>
        <p>Expect fade after overbought</p>
      </div>
      <div class="path-card">
        <h3>Cross up from oversold</h3>
        <p>N=<strong>${cu.n ?? 0}</strong> · <span class="${worksCls(cu.works)}">${worksLabel(cu.works)}</span></p>
        <p>Next up: <strong>${cu.up_pct ?? "—"}%</strong></p>
        <p>Avg next: <strong>${cu.avg_fwd_ret == null ? "—" : ((cu.avg_fwd_ret >= 0 ? "+" : "") + cu.avg_fwd_ret + "%")}</strong></p>
      </div>
      <div class="path-card">
        <h3>Volume alone</h3>
        <p>High vol bars: up <strong>${vol.high_volume?.up_pct ?? "—"}%</strong> (N=${vol.high_volume?.n ?? 0})</p>
        <p>Low vol bars: up <strong>${vol.low_volume?.up_pct ?? "—"}%</strong> (N=${vol.low_volume?.n ?? 0})</p>
        <p>${vol.note || ""}</p>
      </div>
    `;

    const recent = (os.recent || []).map((h) => {
      const cls = h.fwd_up ? "up" : "down";
      return `<tr>
        <td>${fmtDate(h.date)} ${h.time || ""}</td>
        <td>${h.rsi}</td>
        <td>${h.price}</td>
        <td>${h.vol_z ?? "—"}</td>
        <td class="${cls}">${h.fwd_ret >= 0 ? "+" : ""}${h.fwd_ret}%</td>
      </tr>`;
    }).join("");

    $("rsiRecent").innerHTML = `
      <div class="table-wrap">
        <table class="moves-table">
          <thead><tr><th>When (oversold)</th><th>RSI</th><th>Price</th><th>Vol z</th><th>Next ${r.forward_minutes}m</th></tr></thead>
          <tbody>${recent || "<tr><td colspan='5'>No oversold hits</td></tr>"}</tbody>
        </table>
      </div>
    `;
  }

  async function loadLiveBias() {
    const uk = $("liveUk")?.value || "EQQQ";
    const us = $("liveUs")?.value || "QQQ";
    const threshold = $("liveThreshold")?.value || "0.3";
    if ($("liveBiasSummary")) $("liveBiasSummary").textContent = "Fetching live quotes…";
    const url = `api.php?action=live_bias&uk=${encodeURIComponent(uk)}&us=${encodeURIComponent(us)}&threshold_pct=${encodeURIComponent(threshold)}`;
    try {
      const res = await fetch(url);
      const data = await res.json();
      if (!data.ok) {
        if ($("liveBiasSummary")) $("liveBiasSummary").textContent = data.error || "Live bias failed";
        return;
      }
      liveBiasData = data;
      renderLiveBias();
    } catch (e) {
      if ($("liveBiasSummary")) $("liveBiasSummary").textContent = "Live bias error: " + e.message;
    }
  }

  function renderLiveBias() {
    const d = liveBiasData;
    if (!d || !$("liveBiasSummary")) return;
    $("liveBiasSummary").textContent = d.summary_text || "";
    if ($("liveBiasDisclaimer")) $("liveBiasDisclaimer").textContent = d.disclaimer || "";

    const bias = d.bias || "neutral";
    const biasLabel = bias === "lean_up" ? "LEAN UP" : (bias === "lean_down" ? "LEAN DOWN" : "NEUTRAL");
    const biasCls = bias === "lean_up" ? "up" : (bias === "lean_down" ? "down" : "high");
    const lead = d.lead || {};
    const odds = d.odds || {};
    const leadPct = lead.pct == null ? "—" : `${lead.pct >= 0 ? "+" : ""}${lead.pct}%`;

    $("liveBiasCards").innerHTML = `
      <div class="path-card">
        <h3>Bias now</h3>
        <p class="${biasCls}" style="font-size:1.4rem;font-weight:600">${biasLabel}</p>
        <p>${d.uk_symbol} lead: <strong class="${lead.direction === "down" ? "down" : (lead.direction === "up" ? "up" : "")}">${leadPct}</strong> (${lead.direction || "—"})</p>
        <p>Source: ${lead.source === "session_open" ? "vs session open" : "vs previous close"}</p>
      </div>
      <div class="path-card">
        <h3>Historical odds</h3>
        <p>Similar days: <strong>${odds.n ?? 0}</strong> · <span class="${odds.strength === "usable" ? "up" : (odds.strength === "weak" ? "high" : "down")}">${odds.strength || "—"}</span></p>
        <p>Follow lead: <strong>${odds.follow_pct ?? "—"}%</strong></p>
        <p>Against lead: <strong>${odds.against_pct ?? "—"}%</strong></p>
        <p>Avg ${d.us_symbol} session: <strong>${odds.avg_us_ret == null ? "—" : ((odds.avg_us_ret >= 0 ? "+" : "") + odds.avg_us_ret + "%")}</strong></p>
      </div>
      <div class="path-card">
        <h3>Overall UK→US</h3>
        <p>Same direction (all days): <strong>${odds.same_dir_pct ?? "—"}%</strong></p>
        <p>Correlation: <strong>${odds.corr_uk_us != null ? odds.corr_uk_us : "—"}</strong></p>
        <p>Updated UTC: ${d.as_of_utc || "—"}</p>
      </div>
    `;

    $("liveQuotes").innerHTML = (d.quotes || []).map((q) => {
      const cls = (q.change_pct ?? 0) >= 0 ? "up" : "down";
      const ch = q.change_pct == null ? "—" : `${q.change_pct >= 0 ? "+" : ""}${q.change_pct}%`;
      const sess = q.session_change_pct == null ? "—" : `${q.session_change_pct >= 0 ? "+" : ""}${q.session_change_pct}%`;
      return `<div class="path-card">
        <h3>${q.symbol}</h3>
        <p><strong>${q.price}</strong> ${q.currency || ""}</p>
        <p class="${cls}">Day: ${ch}</p>
        <p>Session: ${sess}</p>
        <p class="hint">${q.exchange || ""} · ${q.market_state || ""} · ${q.as_of_utc || ""}</p>
      </div>`;
    }).join("") || "<p class='hint'>No quotes</p>";
  }

  async function loadGlobalLead() {
    const uk = $("globalUk")?.value || "EQQQ";
    const us = $("globalUs")?.value || "QQQ";
    const threshold = $("globalThreshold")?.value || "0.3";
    if ($("globalSummaryText")) $("globalSummaryText").textContent = "Comparing UK → US…";
    const url = `api.php?action=global_lead&uk=${encodeURIComponent(uk)}&us=${encodeURIComponent(us)}&threshold_pct=${encodeURIComponent(threshold)}`;
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok && data.n_days == null) {
      if ($("globalSummaryText")) $("globalSummaryText").textContent = data.error || "Global compare failed";
      return;
    }
    globalData = data;
    renderGlobalLead();
  }

  function renderGlobalLead() {
    const g = globalData;
    if (!g || !$("globalSummaryText")) return;
    $("globalSummaryText").textContent = g.summary_text || "";
    const agr = g.agreement || {};
    const strengthClass = g.strength === "usable" ? "up" : (g.strength === "weak" ? "high" : "down");

    $("globalCards").innerHTML = `
      <div class="path-card">
        <h3>Pair</h3>
        <p><strong>${g.uk_symbol}</strong> → <strong>${g.us_symbol}</strong></p>
        <p>Days: <strong>${g.n_days}</strong> · <span class="${strengthClass}">${g.strength}</span></p>
        <p>Lead move ≥ <strong>${g.threshold_pct}%</strong></p>
      </div>
      <div class="path-card">
        <h3>Same direction</h3>
        <p>UK → US: <strong>${agr.uk_us_same_dir_pct ?? "—"}%</strong></p>
        <p>N: ${agr.n ?? 0}</p>
      </div>
      <div class="path-card">
        <h3>Correlation</h3>
        <p>UK vs US: <strong>${agr.corr_uk_us != null ? agr.corr_uk_us : "—"}</strong></p>
      </div>
      <div class="path-card">
        <h3>Why EQQQ</h3>
        <p>Same Nasdaq-100 basket as QQQ</p>
        <p>Trades in London hours before US cash open</p>
      </div>
    `;

    $("globalScenarios").innerHTML = (g.scenarios || []).map((s) => {
      const cls = s.strength === "usable" ? "up" : (s.strength === "weak" ? "high" : "down");
      const avg = s.avg_us_ret == null ? "—" : `${s.avg_us_ret >= 0 ? "+" : ""}${s.avg_us_ret}%`;
      return `<div class="path-card">
        <h3>${s.id.replaceAll("_", " ")}</h3>
        <p>If <strong>${s.lead}</strong> · N=<strong>${s.n}</strong> <span class="${cls}">${s.strength}</span></p>
        <p class="up">${g.us_symbol} up: <strong>${s.us_up_pct ?? "—"}%</strong></p>
        <p class="down">${g.us_symbol} down: <strong>${s.us_down_pct ?? "—"}%</strong></p>
        <p>Avg US session: <strong>${avg}</strong></p>
      </div>`;
    }).join("");

    const rows = (g.days || []).map((d) => {
      const uCls = d.uk_ret >= 0 ? "up" : "down";
      const sCls = d.us_ret >= 0 ? "up" : "down";
      const fmt = (v) => `${v >= 0 ? "+" : ""}${v}%`;
      return `<tr>
        <td>${fmtDate(d.date)}</td>
        <td>${d.label}</td>
        <td class="${uCls}">${fmt(d.uk_ret)}</td>
        <td class="${sCls}">${fmt(d.us_ret)}</td>
      </tr>`;
    }).join("");

    $("globalDays").innerHTML = `
      <div class="table-wrap">
        <table class="moves-table">
          <thead><tr>
            <th>Date</th><th>Day</th>
            <th>${g.uk_symbol}</th><th>${g.us_symbol}</th>
          </tr></thead>
          <tbody>${rows || "<tr><td colspan='4'>No overlapping days — run ingest for EQQQ</td></tr>"}</tbody>
        </table>
      </div>
    `;
  }

  const DROP_WINDOWS = `
    <option value="0930_1000">09:30–10:00</option>
    <option value="0930_1030">09:30–10:30</option>
    <option value="1000_1100">10:00–11:00</option>
    <option value="1100_1200">11:00–12:00</option>
    <option value="1300_1400">13:00–14:00</option>
    <option value="1400_1500">14:00–15:00</option>`;
  const OPEN_WINDOWS = `
    <option value="first_15">First 15m (→09:45)</option>
    <option value="first_30" selected>First 30m (→10:00)</option>
    <option value="first_60">First 60m (→10:30)</option>`;

  function syncScenarioControls() {
    const mode = $("scenarioMode").value;
    const win = $("scenarioWindow");
    const dir = $("scenarioDirection");
    const flatOpt = dir.querySelector('option[value="flat"]');
    const isDrop = mode === "drop";
    const isOpen = mode === "open";
    const isShape = mode === "shape";
    const isCross = mode === "cross";

    $("scenarioWindowLabel").hidden = isShape;
    $("scenarioShapeLabel").hidden = !isShape;
    $("scenarioPairLabel").hidden = !isCross;
    $("scenarioDirectionLabel").hidden = isShape;
    $("scenarioMeasureLabel").hidden = !isDrop;
    $("scenarioSetupEndLabel").hidden = !isShape;
    $("scenarioThresholdLabel").hidden = false;

    if (flatOpt) flatOpt.hidden = !isOpen;
    if (!isOpen && dir.value === "flat") dir.value = "down";

    const wantOpenWindows = isOpen || isCross;
    const currentlyOpen = win.options.length === 3 && String(win.options[0].value).startsWith("first_");
    if (wantOpenWindows && !currentlyOpen) {
      win.innerHTML = OPEN_WINDOWS;
    } else if (!wantOpenWindows && currentlyOpen) {
      win.innerHTML = DROP_WINDOWS;
      win.value = "0930_1000";
    }

    const hints = {
      drop: "If price drops (or rises) a set % in a chosen window, what usually happens next?",
      open: "If the open is up / down / flat into a cutoff, what usually happens next (incl. day high/low after)?",
      shape: "Rule-based morning shapes (V, waterfall, spike-fade, grind) → afternoon path.",
      cross: "If the lead symbol moves in the open window, what does the follow symbol do next?",
    };
    $("scenarioHint").textContent = hints[mode] || hints.drop;
  }

  async function loadScenario() {
    syncScenarioControls();
    const symbol = $("symbol").value;
    const interval = $("interval").value;
    const mode = $("scenarioMode").value;
    const windowId = $("scenarioWindow").value;
    const direction = $("scenarioDirection").value;
    const threshold = $("scenarioThreshold").value;
    const measure = $("scenarioMeasure").value;
    const nextMinutes = $("scenarioNext").value;
    const weekday = $("scenarioWeekday").value;
    const shape = $("scenarioShape").value;
    const setupEnd = $("scenarioSetupEnd").value;
    const pair = $("scenarioPair").value;
    $("scenarioSummaryText").textContent = "Analyzing scenario…";
    let url = `api.php?action=scenario&mode=${encodeURIComponent(mode)}&symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&window=${encodeURIComponent(windowId)}&direction=${encodeURIComponent(direction)}&threshold_pct=${encodeURIComponent(threshold)}&measure=${encodeURIComponent(measure)}&next_minutes=${encodeURIComponent(nextMinutes)}&weekday=${encodeURIComponent(weekday)}&shape=${encodeURIComponent(shape)}&setup_end=${encodeURIComponent(setupEnd)}&pair=${encodeURIComponent(pair)}`;
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) {
      $("scenarioSummaryText").textContent = data.error || "Scenario failed";
      return;
    }
    scenarioData = data.scenario;
    renderScenario();
  }

  function renderScenario() {
    const s = scenarioData;
    if (!s) return;
    $("scenarioSummaryText").textContent = s.summary_text || "";
    const o = s.outcomes || {};
    const goodLabel = s.labels?.primary_good || "Bounce";
    const badLabel = s.labels?.primary_bad || "Continue";
    const strengthClass = s.strength === "usable" ? "up" : (s.strength === "weak" ? "high" : "down");
    const mode = s.mode || "drop";

    let setupLines = "";
    if (mode === "cross") {
      setupLines = `
        <p><strong>${s.lead_symbol}</strong> → <strong>${s.follow_symbol}</strong></p>
        <p>${s.from_time}–${s.to_time}: lead ${s.direction === "down" ? "drop" : "rise"} ≥ <strong>${s.threshold_pct}%</strong></p>`;
    } else if (mode === "shape") {
      setupLines = `
        <p><strong>${s.symbol}</strong> · shape <strong>${s.shape || s.condition}</strong></p>
        <p>Morning to ${s.to_time} · thresh <strong>${s.threshold_pct}%</strong></p>`;
    } else if (mode === "open") {
      setupLines = `
        <p><strong>${s.symbol}</strong> · open ${s.condition || s.direction}</p>
        <p>By ${s.to_time} · thresh <strong>${s.threshold_pct}%</strong></p>`;
    } else {
      setupLines = `
        <p><strong>${s.symbol}</strong> · ${s.from_time}–${s.to_time}</p>
        <p>${s.direction === "down" ? "Drop" : "Rise"} ≥ <strong>${s.threshold_pct}%</strong> (${s.measure === "maxdd" ? "max plunge" : "end of window"})</p>`;
    }

    const extraLater = [];
    if (o.high_after_pct != null) extraLater.push(`Day high after: <strong>${o.high_after_pct}%</strong>`);
    if (o.low_after_pct != null) extraLater.push(`Day low after: <strong>${o.low_after_pct}%</strong>`);
    if (o.giveback_50_pct != null) extraLater.push(`Giveback/recover ≥50%: <strong>${o.giveback_50_pct}%</strong>`);
    if (o.same_direction_setup_pct != null) extraLater.push(`Same-dir morning: <strong>${o.same_direction_setup_pct}%</strong>`);

    $("scenarioCards").innerHTML = `
      <div class="path-card">
        <h3>Setup</h3>
        ${setupLines}
        <p>Matches: <strong>${s.n}</strong> · <span class="${strengthClass}">${s.strength}</span></p>
      </div>
      <div class="path-card">
        <h3>Next ${s.next_minutes}m (to ${s.next_end_time})</h3>
        <p class="up">${goodLabel}: <strong>${o.next_bounce_or_fade_pct ?? "—"}%</strong> (${o.next_bounce_or_fade_n ?? 0})</p>
        <p class="down">${badLabel}: <strong>${o.next_continue_pct ?? "—"}%</strong> (${o.next_continue_n ?? 0})</p>
        <p>Avg next change: <strong>${o.avg_next_ret != null ? ((o.avg_next_ret >= 0 ? "+" : "") + o.avg_next_ret + "%") : "—"}</strong></p>
      </div>
      <div class="path-card">
        <h3>Later / close</h3>
        <p>Rest of day up: <strong>${o.rest_up_pct ?? "—"}%</strong></p>
        <p>Close green: <strong>${o.close_up_pct ?? "—"}%</strong></p>
        ${extraLater.map((x) => `<p>${x}</p>`).join("")}
      </div>
      <div class="path-card">
        <h3>By weekday</h3>
        <ul class="session-moves">
          ${(s.by_weekday || []).filter((w) => w.n > 0).map((w) => `<li>${w.label}: <strong>${w.n}</strong> day(s)</li>`).join("") || "<li>None</li>"}
        </ul>
      </div>
    `;

    const rows = (s.matches || []).map((m) => {
      const setupVal = m.lead_ret != null ? m.lead_ret : m.setup_ret;
      const setup = setupVal == null ? "—" : `${setupVal >= 0 ? "+" : ""}${setupVal}%`;
      const next = m.next_ret == null ? "—" : `${m.next_ret >= 0 ? "+" : ""}${m.next_ret}%`;
      const day = m.day_ret == null ? "—" : `${m.day_ret >= 0 ? "+" : ""}${m.day_ret}%`;
      const nextCls = (m.next_ret ?? 0) >= 0 ? "up" : "down";
      const setupCls = (setupVal ?? 0) >= 0 ? "up" : "down";
      return `<tr>
        <td>${fmtDate(m.date)}</td>
        <td>${m.label}</td>
        <td class="${setupCls}">${setup}</td>
        <td class="${nextCls}">${next}</td>
        <td>${day}</td>
      </tr>`;
    }).join("");

    const setupCol = mode === "cross" ? "Lead" : "Setup";
    $("scenarioMatches").innerHTML = `
      <div class="table-wrap">
        <table class="moves-table">
          <thead><tr><th>Date</th><th>Day</th><th>${setupCol}</th><th>Next ${s.next_minutes}m</th><th>Day</th></tr></thead>
          <tbody>${rows || "<tr><td colspan='5'>No matching days</td></tr>"}</tbody>
        </table>
      </div>
    `;

    if (typeof Chart === "undefined") return;
    const times = s.median_path?.times || [];
    const values = s.median_path?.values || [];
    if (scenarioPathChart) scenarioPathChart.destroy();
    const downish = ["down", "waterfall"].includes(s.direction) || s.direction === "waterfall";
    scenarioPathChart = new Chart($("scenarioPathChart").getContext("2d"), {
      type: "line",
      data: {
        labels: times,
        datasets: [{
          label: `Median path after ${s.to_time} (100 = setup end)`,
          data: values,
          borderColor: downish ? "#e06c75" : "#3dbb8b",
          backgroundColor: downish ? "rgba(224,108,117,0.12)" : "rgba(61,187,139,0.12)",
          fill: true,
          pointRadius: 0,
          borderWidth: 2,
          tension: 0.15,
        }],
      },
      options: chartOptions("Indexed price (100 = end of setup)"),
    });
  }

  function renderCompare() {
    const box = $("compareSummary");
    const label = $("compareDatesLabel");
    const cmp = summary.week_compare?.comparison;
    const dates = summary.week_compare?.dates || [];
    if (label) {
      label.textContent = dates.length
        ? `Comparing: ${dates.map(fmtDate).join(" · ")}`
        : "";
    }
    if (!cmp || !(cmp.days || []).length) {
      box.innerHTML = "<p class='hint'>Not enough sessions yet for this comparison.</p>";
      return;
    }
    const min$ = summary.min_dollars ?? 5;
    const days = cmp.days;
    box.innerHTML = days.map((d) => `
      <div class="path-card" style="border-color:${d.color}">
        <h3><span style="color:${d.color}">●</span> ${fmtDate(d.date)} · ${d.label}</h3>
        <p>Open <strong>$${d.open}</strong> → Close <strong>$${d.close}</strong>
          (<span class="${(d.day_change_pct ?? 0) >= 0 ? "up" : "down"}">${(d.day_change_pct ?? 0) >= 0 ? "+" : ""}${d.day_change_pct}%</span>)</p>
        <p class="low">Low: <strong>$${d.low_price}</strong> at <strong>${d.low_time}</strong></p>
        <p class="high">High: <strong>$${d.high_price}</strong> at <strong>${d.high_time}</strong></p>
        <p>${d.high_after_low ? `Low→High in ${d.minutes_low_to_high}m (+${d.move_pct}%)` : "High before low"}</p>
        <p>≥$${min$} moves: <strong>${d.move_count}</strong></p>
        <ul class="session-moves">
          ${(d.moves || []).slice(0, 5).map((m) => {
            const cls = m.direction === "up" ? "up" : "down";
            const sign = m.direction === "up" ? "+" : "−";
            return `<li class="${cls}">${m.direction} ${sign}$${Number(m.dollars).toFixed(2)} · ${m.start_time}→${m.end_time}</li>`;
          }).join("") || "<li>None</li>"}
        </ul>
      </div>
    `).join("");

    // Charts optional — cards above still show if Chart.js is blocked
    if (typeof Chart === "undefined") {
      if (label) label.textContent += " (charts unavailable — cards above still work)";
      return;
    }

    const labels = cmp.times;
    const tickEvery = Math.max(1, Math.round(labels.length / 12));
    const opts = chartOptions("% of open (100 = open)");
    opts.scales.x.ticks.callback = function (v, i) {
      return i % tickEvery === 0 ? labels[i] : "";
    };

    const normDatasets = (cmp.series_norm || []).map((s) => ({
      label: `${fmtDate(s.date)} (${s.label})`,
      data: s.data,
      borderColor: s.color,
      borderWidth: 2,
      pointRadius: 0,
      tension: 0.1,
      spanGaps: false,
    }));
    const priceDatasets = (cmp.series_price || []).map((s) => ({
      label: `${fmtDate(s.date)} price`,
      data: s.data,
      borderColor: s.color,
      borderWidth: 2,
      pointRadius: 0,
      tension: 0.1,
      spanGaps: false,
    }));

    if (compareNormChart) compareNormChart.destroy();
    compareNormChart = new Chart($("compareNormChart").getContext("2d"), {
      type: "line",
      data: { labels, datasets: normDatasets },
      options: opts,
    });

    if (comparePriceChart) comparePriceChart.destroy();
    comparePriceChart = new Chart($("comparePriceChart").getContext("2d"), {
      type: "line",
      data: { labels, datasets: priceDatasets },
      options: chartOptions("Price ($)"),
    });
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
    const min$ = summary.min_dollars ?? summary.big_moves?.thresholds?.min ?? 5;
    const iv = summary.interval_minutes ?? 1;
    const days = summary.patterns?.weekdays || {};

    const opts = document.createElement("div");
    opts.className = "path-card";
    opts.innerHTML = `<h3>Active options</h3>
      <p>Interval: <strong>${iv} min</strong> · Move size: <strong>≥$${min$}</strong> · Prob threshold: <strong>${Math.round((summary.patterns?.threshold ?? 0.6) * 100)}%</strong></p>`;
    root.appendChild(opts);

    Object.keys(days).forEach((wd) => {
      const d = days[wd];
      const ups = (d.uptrend_windows || [])
        .map((w) => `<li class="up">${w.start}–${w.end} (up ${(w.avg_up_prob * 100).toFixed(0)}%)</li>`)
        .join("") || "<li>None</li>";
      const downs = (d.downtrend_windows || [])
        .map((w) => `<li class="down">${w.start}–${w.end} (down ${(w.avg_down_prob * 100).toFixed(0)}%)</li>`)
        .join("") || "<li>None</li>";
      const dUp = (d.dollar_up_windows || [])
        .slice(0, 8)
        .map((w) => `<li class="up">${w.date}: ${w.start}→${w.end} (+$${Number(w.dollars).toFixed(2)})</li>`)
        .join("") || "<li>None</li>";
      const dDown = (d.dollar_down_windows || [])
        .slice(0, 8)
        .map((w) => `<li class="down">${w.date}: ${w.start}→${w.end} (−$${Number(w.dollars).toFixed(2)})</li>`)
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
        <p>Typical ≥$${min$} start — up: <strong>${d.typical_dollar_up_start ?? "—"}</strong> · down: <strong>${d.typical_dollar_down_start ?? "—"}</strong> (${d.dollar_move_count ?? 0} moves)</p>
        <p>≥$${min$} up moves</p>
        <ul>${dUp}</ul>
        <p>≥$${min$} down moves</p>
        <ul>${dDown}</ul>
        <p>${iv}-min uptrend windows (≥60%)</p>
        <ul>${ups}</ul>
        <p>${iv}-min downtrend windows (≥60%)</p>
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
    const iv = summary.interval_minutes || 1;
    const cellW = Math.max(2, Math.min(12, iv));
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
    const tickEvery = Math.max(1, Math.round(30 / iv));
    for (let c = 0; c < cols; c += tickEvery) {
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

  $("runGlobal")?.addEventListener("click", loadGlobalLead);
  ["globalUk", "globalUs", "globalThreshold"].forEach((id) => {
    $(id)?.addEventListener("change", loadGlobalLead);
  });

  $("runLiveBias")?.addEventListener("click", loadLiveBias);
  ["liveUk", "liveUs", "liveThreshold"].forEach((id) => {
    $(id)?.addEventListener("change", loadLiveBias);
  });
  if (liveBiasTimer) clearInterval(liveBiasTimer);
  liveBiasTimer = setInterval(() => {
    try { loadLiveBias(); } catch (e) { /* ignore */ }
  }, 60000);

  $("runRsi")?.addEventListener("click", loadRsi);
  ["rsiSymbol", "rsiInterval", "rsiForward"].forEach((id) => {
    $(id)?.addEventListener("change", loadRsi);
  });

  $("runChecklist")?.addEventListener("click", loadChecklist);
  ["checkUk", "checkUs", "checkThreshold"].forEach((id) => {
    $(id)?.addEventListener("change", loadChecklist);
  });
  checklistTimer = setInterval(() => {
    try { loadChecklist(); } catch (e) { /* ignore */ }
  }, checklistRefreshSec * 1000);

  $("runWeekdayReturns")?.addEventListener("click", loadWeekdayReturns);

  $("reload").addEventListener("click", load);
  $("symbol").addEventListener("change", load);
  $("minDollars").addEventListener("change", load);
  $("interval").addEventListener("change", load);
  $("sessionDate").addEventListener("change", () => summary && renderSessionPrice());
  $("weekday").addEventListener("change", () => summary && renderAvgPrice());
  $("runCompare").addEventListener("click", load);
  $("comparePreset").addEventListener("change", load);
  $("compareLimit").addEventListener("change", load);
  $("runScenario").addEventListener("click", loadScenario);
  $("scenarioMode").addEventListener("change", () => {
    syncScenarioControls();
    loadScenario();
  });
  ["scenarioWindow", "scenarioDirection", "scenarioThreshold", "scenarioMeasure", "scenarioNext", "scenarioWeekday", "scenarioShape", "scenarioSetupEnd", "scenarioPair"].forEach((id) => {
    $(id)?.addEventListener("change", loadScenario);
  });
  syncScenarioControls();
  load();
})();
