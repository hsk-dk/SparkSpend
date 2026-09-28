/**
 * SparkSpend Hus Module
 *
 * Shows house electricity consumption (powerloghus) with a stacked bar chart
 * that breaks total kWh into:
 *   - Jordvarme (orange)  — heat pump submeter (powerlogjord)
 *   - El-bil (blue)       — EV charging (charges + ext_charges)
 *   - Restforbrug (grey)  — remainder (general household loads)
 *
 * Modes mirror jordvarme.js: daily, monthly, compare, ytd.
 * Compare and ytd show house-total only (multi-line across years).
 *
 * Lazy-initialised by SparkNav on first visit to the Hus/Forbrug sub-tab.
 */

const husApp = (() => {
    let initialized = false;

    function init() {
        if (initialized) return;
        initialized = true;

        const YEAR_COLORS = ['#3b82f6', '#22c55e', '#f97316', '#8b5cf6', '#ef4444', '#ec4899'];
        const MONTH_NAMES  = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

        // Dataset colours — match dashboard card sparkline colors for consistency
        const COLOR_HP   = '#22c55e'; // green  — Jordvarme (matches Jordvarme dashboard card)
        const COLOR_EV   = '#6BA3FF'; // blue   — El-bil    (matches Elbil dashboard card)
        const COLOR_REST = '#f59e0b'; // amber  — Restforbrug (matches Hus dashboard card)

        // State
        let currentMode  = 'daily';
        let currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM
        let currentYear  = String(new Date().getFullYear());     // YYYY
        let husChart     = null;
        let lastData     = null;

        // ── Electricity price settings & cost state ──────────────────────────
        function getElSettings() {
            return {
                area: window.sparkConfig?.elspotArea || '',
                gln:  window.sparkConfig?.elspotGln  || '',
            };
        }
        function elSettingsReady() {
            const s = getElSettings();
            return s.area !== '' && s.gln !== '';
        }
        // Cost state: date string (YYYY-MM-DD) → kr/kWh, populated by fetchElCosts()
        let activeCostMap    = {};
        // Representative component breakdown for the period (from getElspotPrices.php)
        let activeComponents = null;

        // DOM refs
        const canvas        = document.getElementById('husChart');
        const noDataEl      = document.getElementById('husNoData');
        const errorEl       = document.getElementById('husError');
        const periodNav     = document.getElementById('husPeriodNav');
        const periodLabel   = document.getElementById('husPeriodLabel');
        const prevBtn       = document.getElementById('husPrev');
        const nextBtn       = document.getElementById('husNext');
        const statsContent  = document.getElementById('husStatsContent');
        const syncStatusEl  = document.getElementById('husSyncStatus');
        const yearContainer = document.getElementById('husYearToggleContainer');

        if (!canvas || !prevBtn || !nextBtn || !periodNav || !statsContent) return;

        const ctx = canvas.getContext('2d');

        // ── Mode buttons ─────────────────────────────────────────────────────
        document.querySelectorAll('#hus-section .hp-mode-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#hus-section .hp-mode-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentMode = this.dataset.mode;
                const isChronological = currentMode === 'compare' || currentMode === 'ytd';
                periodNav.style.display = isChronological ? 'none' : '';
                if (yearContainer) yearContainer.style.display = 'none';
                fetchAndRender();
            });
        });

        // ── Period navigation ────────────────────────────────────────────────
        prevBtn.addEventListener('click', () => shiftPeriod(-1));
        nextBtn.addEventListener('click', () => shiftPeriod(1));

        function shiftPeriod(direction) {
            const now        = new Date();
            const todayYear  = now.getFullYear();
            const todayMonth = `${todayYear}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            if (currentMode === 'daily') {
                const [y, m] = currentMonth.split('-').map(Number);
                const d      = new Date(y, m - 1 + direction, 1);
                const next   = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
                if (next > todayMonth) return;
                currentMonth = next;
            } else if (currentMode === 'monthly') {
                const next = Number(currentYear) + direction;
                if (next > todayYear) return;
                currentYear = String(next);
            }
            fetchAndRender();
        }

        function updatePeriodLabel() {
            const now        = new Date();
            const todayYear  = now.getFullYear();
            const todayMonth = `${todayYear}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            if (currentMode === 'daily') {
                const [y, m] = currentMonth.split('-').map(Number);
                const name   = new Date(y, m - 1, 1).toLocaleString('da-DK', { month: 'long', year: 'numeric' });
                periodLabel.textContent = name.charAt(0).toUpperCase() + name.slice(1);
                nextBtn.disabled = currentMonth >= todayMonth;
            } else if (currentMode === 'monthly') {
                periodLabel.textContent = currentYear;
                nextBtn.disabled = Number(currentYear) >= todayYear;
            } else {
                periodLabel.textContent = '';
                nextBtn.disabled = false;
            }
        }

        function buildUrl() {
            if (currentMode === 'daily')   return `api.php?action=house-power&mode=daily&month=${currentMonth}`;
            if (currentMode === 'monthly') return `api.php?action=house-power&mode=monthly&year=${currentYear}`;
            if (currentMode === 'ytd')     return 'api.php?action=house-power&mode=ytd';
            return 'api.php?action=house-power&mode=compare';
        }

        // ── Fetch & render ───────────────────────────────────────────────────
        function fetchAndRender() {
            updatePeriodLabel();
            noDataEl.style.display  = 'none';
            errorEl.style.display   = 'none';
            canvas.style.display    = 'none';
            if (yearContainer) yearContainer.style.display = 'none';
            statsContent.innerHTML  = '<p>Indlæser data...</p>';
            // Reset cost state for the new period; fetchElCosts repopulates it.
            activeCostMap = {}; activeComponents = null;

            fetch(buildUrl())
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(resp => {
                    lastData = resp;
                    const rows = resp.days || resp.months || resp.data || [];
                    if (!Array.isArray(rows) || rows.length === 0) {
                        noDataEl.style.display = '';
                        statsContent.innerHTML = '<p class="text-muted">Ingen data for denne periode.</p>';
                        return;
                    }
                    renderChart(resp);
                    renderStats(resp);
                    canvas.style.display = '';
                    // Estimated cost enrichment (async, best-effort) — re-renders
                    // stats + chart tooltip once daily kr/kWh rates arrive.
                    fetchElCosts();
                })
                .catch(err => {
                    console.error('husApp fetch:', err);
                    errorEl.style.display = '';
                    statsContent.innerHTML = '<p class="text-muted">Kunne ikke hente data.</p>';
                });
        }

        // ── Electricity cost estimation ──────────────────────────────────────
        // Fetches all-in kr/kWh (spot + tariffs + elafgift + moms) from
        // getElspotPrices.php for the current period and populates activeCostMap.
        // Mirrors the mechanism in jordvarme.js. Note: the spot-price route uses
        // the heat-pump procesformål elafgift rate, so the Rest/El-bil part of
        // the house estimate is an approximation (boligelafgift is higher).
        function fetchElCosts() {
            if (!elSettingsReady()) return;
            const { area, gln } = getElSettings();
            const today = new Date().toISOString().slice(0, 10);

            let start, end;
            if (currentMode === 'daily') {
                const [y, m]  = currentMonth.split('-').map(Number);
                const lastDay = new Date(y, m, 0).getDate();
                start = currentMonth + '-01';
                end   = currentMonth + '-' + String(lastDay).padStart(2, '0');
                if (end > today) end = today;
            } else if (currentMode === 'monthly') {
                start = currentYear + '-01-01';
                end   = currentYear === String(new Date().getFullYear())
                            ? today : currentYear + '-12-31';
            } else {
                // compare / ytd — derive year range from the data actually present
                const data = (lastData && lastData.data) || [];
                let minYear = new Date().getFullYear() - 2;
                if (data.length > 0) {
                    const years = data.map(d => parseInt(d.year)).filter(y => !isNaN(y));
                    if (years.length > 0) minYear = Math.min(...years);
                }
                start = minYear + '-01-01';
                end   = today;
            }

            const params = new URLSearchParams({ start, end, area, gln });
            fetch('api.php?action=elspot-prices&' + params)
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(data => {
                    activeCostMap    = {};
                    activeComponents = data.components || null;
                    (data.records || []).forEach(r => { activeCostMap[r.date] = parseFloat(r.kr_kwh); });
                    if (!lastData) return;
                    // Re-render stats with cost now available. For daily/monthly
                    // also refresh the stacked chart (in-place update adds kr to
                    // the tooltip). Skip the chart for compare/ytd — cost only
                    // affects the stats table there, and re-rendering would
                    // destroy/recreate the line chart (visible blink).
                    renderStats(lastData);
                    if (currentMode === 'daily' || currentMode === 'monthly') {
                        renderChart(lastData);
                    }
                })
                .catch(err => console.error('husApp fetchElCosts:', err));
        }

        // Average kr/kWh across the days of a given YYYY-MM prefix (0 if none).
        function _avgRateForMonth(monthPrefix) {
            const rates = Object.entries(activeCostMap)
                .filter(([d]) => d.startsWith(monthPrefix))
                .map(([, r]) => r);
            return rates.length ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
        }

        // ── Sync status ──────────────────────────────────────────────────────
        function fetchSyncStatus() {
            fetch('api.php?action=house-power&mode=sync_status')
                .then(r => r.json())
                .then(s => {
                    if (!syncStatusEl) return;
                    if (s.last_sync_timestamp) {
                        const dt = new Date(s.last_sync_timestamp.replace(' ', 'T'));
                        syncStatusEl.textContent = `Sidst synkroniseret: ${dt.toLocaleString('da-DK')}`;
                    } else {
                        syncStatusEl.textContent = 'Hus-data endnu ikke synkroniseret (kør cron/sync_housepowerlog_data.php)';
                    }
                })
                .catch(() => {});
        }

        // ── Chart rendering ──────────────────────────────────────────────────
        function renderChart(resp) {
            if (currentMode === 'compare') { _renderCompare(resp.data || []); return; }
            if (currentMode === 'ytd')     { _renderYtd(resp.data || []);     return; }

            // Destroy if chart type changed (compare/ytd use line, stacked uses bar)
            if (husChart && husChart.config.type !== 'bar') { husChart.destroy(); husChart = null; }

            const rows = resp.days || resp.months || [];
            const costsReady = elSettingsReady() && Object.keys(activeCostMap).length > 0;
            // Per-period estimated cost (kr) for the total bar, or null if no rates yet
            const costVals = costsReady
                ? rows.map(d => {
                    const rate = currentMode === 'daily'
                        ? (activeCostMap[d.day] || 0)
                        : _avgRateForMonth(d.month);
                    return (d.hus ?? 0) * rate;
                })
                : null;

            if (currentMode === 'daily') {
                _renderStacked(
                    rows.map(d => d.day.slice(8)),       // labels: day of month
                    rows.map(d => d.hp   ?? 0),
                    rows.map(d => d.ev   ?? 0),
                    rows.map(d => d.rest ?? 0),
                    rows.map(d => d.hus  ?? 0),
                    costVals
                );
            } else {
                _renderStacked(
                    rows.map(d => MONTH_NAMES[parseInt(d.month.slice(-2), 10) - 1]),
                    rows.map(d => d.hp   ?? 0),
                    rows.map(d => d.ev   ?? 0),
                    rows.map(d => d.rest ?? 0),
                    rows.map(d => d.hus  ?? 0),
                    costVals
                );
            }
        }

        function _renderStacked(labels, hpVals, evVals, restVals, husVals, costVals = null) {
            const hasHus = husVals.some(v => v > 0);

            const datasets = [];

            // Bottom: Jordvarme
            datasets.push({
                label: 'Jordvarme',
                data: hpVals,
                backgroundColor: COLOR_HP,
                borderColor: COLOR_HP,
                borderWidth: 0,
                borderRadius: 0,
                stack: 'a',
            });

            // Middle: El-bil
            datasets.push({
                label: 'El-bil',
                data: evVals,
                backgroundColor: COLOR_EV,
                borderColor: COLOR_EV,
                borderWidth: 0,
                borderRadius: 0,
                stack: 'a',
            });

            // Top: Restforbrug (only if house meter data available)
            if (hasHus) {
                datasets.push({
                    label: 'Restforbrug',
                    data: restVals,
                    backgroundColor: COLOR_REST,
                    borderColor: COLOR_REST,
                    borderWidth: 0,
                    borderRadius: 4,
                    stack: 'a',
                });
            }

            // Tooltip footer: total kWh + estimated kr (when rates available)
            const footerFn = items => {
                const idx = items[0]?.dataIndex;
                if (idx === undefined) return '';
                const total = hpVals[idx] + evVals[idx] + (hasHus ? restVals[idx] : 0);
                const lines = [`Total: ${total.toFixed(1)} kWh`];
                if (costVals && costVals[idx] > 0) {
                    lines.push(`ca. ${appUtils.formatCurrency(costVals[idx])}`);
                }
                return lines;
            };

            if (husChart) {
                // In-place update — avoids destroy/recreate when same mode re-renders
                husChart.data.labels = labels;
                husChart.data.datasets.length = 0;
                datasets.forEach(ds => husChart.data.datasets.push(ds));
                husChart.options.plugins.tooltip.callbacks.footer = footerFn;
                husChart.update('none');
                return;
            }

            husChart = new Chart(ctx, {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: true, position: 'top' },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                footer: footerFn
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, title: { display: true, text: 'kWh' } },
                    }
                }
            });
        }

        function _renderCompare(data) {
            const hiddenLabels = new Set();
            if (husChart) {
                husChart.data.datasets.forEach((ds, i) => {
                    if (!husChart.isDatasetVisible(i)) hiddenLabels.add(ds.label);
                });
                husChart.destroy();
                husChart = null;
            }

            // Pivot: year → Array(12 months)
            const yearMap = new Map();
            data.forEach(d => {
                if (!yearMap.has(d.year)) yearMap.set(d.year, new Array(12).fill(null));
                yearMap.get(d.year)[parseInt(d.month, 10) - 1] = parseFloat(d.kwh);
            });
            const years = [...yearMap.keys()].sort();

            const datasets = years.map((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                return {
                    label: year,
                    data: yearMap.get(year),
                    borderColor: color,
                    backgroundColor: color + '20',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.35,
                    spanGaps: false,
                };
            });

            // Average reference line
            const avgData = Array.from({ length: 12 }, (_, m) => {
                const vals = [...yearMap.values()].map(arr => arr[m]).filter(v => v !== null);
                return vals.length ? parseFloat((vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(1)) : null;
            });
            datasets.push({
                label: 'Gns.',
                data: avgData,
                borderColor: '#94a3b8',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                tension: 0.35,
                spanGaps: true,
            });

            husChart = new Chart(ctx, {
                type: 'line',
                data: { labels: MONTH_NAMES, datasets },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label: c => {
                                    if (c.parsed.y === null) return `${c.dataset.label}: ingen data`;
                                    return `${c.dataset.label}: ${c.parsed.y.toFixed(1)} kWh`;
                                }
                            }
                        }
                    },
                    scales: { y: { beginAtZero: true, title: { display: true, text: 'kWh' } } }
                }
            });

            _buildYearChips(years, hiddenLabels);
        }

        function _renderYtd(data) {
            if (husChart) { husChart.destroy(); husChart = null; }
            if (yearContainer) { yearContainer.style.display = 'none'; yearContainer.innerHTML = ''; }

            // Pivot per year, compute cumulative sum
            const yearMap = new Map();
            data.forEach(d => {
                if (!yearMap.has(d.year)) yearMap.set(d.year, []);
                yearMap.get(d.year).push({ day: d.day, kwh: parseFloat(d.kwh) });
            });

            const years = [...yearMap.keys()].sort();
            // Max day count across years → x-axis labels (day 1, 2, …)
            const maxDays = Math.max(...[...yearMap.values()].map(a => a.length));
            const labels = Array.from({ length: maxDays }, (_, i) => String(i + 1));

            const datasets = years.map((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                const vals  = yearMap.get(year);
                // Build cumulative sums
                let cum = 0;
                const cumVals = vals.map(v => { cum += v.kwh; return parseFloat(cum.toFixed(2)); });
                // Pad to maxDays with null
                while (cumVals.length < maxDays) cumVals.push(null);
                return {
                    label: year,
                    data: cumVals,
                    borderColor: color,
                    backgroundColor: color + '20',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.35,
                    spanGaps: false,
                };
            });

            husChart = new Chart(ctx, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    animation: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label: c => `${c.dataset.label}: ${c.parsed.y?.toFixed(1) ?? '—'} kWh`,
                                title: items => `Dag ${items[0]?.label}`
                            }
                        }
                    },
                    scales: { y: { beginAtZero: true, title: { display: true, text: 'kWh akkumuleret' } } }
                }
            });

            _buildYearChips(years, new Set());
        }

        // ── Year toggle chips ────────────────────────────────────────────────
        function _buildYearChips(years, hiddenLabels) {
            if (!yearContainer) return;
            yearContainer.innerHTML = '';
            yearContainer.style.display = '';

            years.forEach((year, i) => {
                const color    = YEAR_COLORS[i % YEAR_COLORS.length];
                const isHidden = hiddenLabels.has(year);
                const chip     = document.createElement('button');
                chip.type = 'button';
                chip.className = 'year-chip' + (isHidden ? '' : ' active');
                chip.textContent = year;
                chip.style.setProperty('--chip-color', color);
                chip.addEventListener('click', () => {
                    const nowActive = chip.classList.toggle('active');
                    if (husChart) { husChart.setDatasetVisibility(i, nowActive); husChart.update(); }
                });
                yearContainer.appendChild(chip);
                if (isHidden && husChart) { husChart.setDatasetVisibility(i, false); }
            });

            // Avg chip (always last dataset)
            const avgIdx  = years.length;
            const avgChip = document.createElement('button');
            avgChip.type = 'button';
            avgChip.className = 'year-chip active';
            avgChip.textContent = 'Gns.';
            avgChip.style.setProperty('--chip-color', '#94a3b8');
            avgChip.addEventListener('click', () => {
                const nowActive = avgChip.classList.toggle('active');
                if (husChart) { husChart.setDatasetVisibility(avgIdx, nowActive); husChart.update(); }
            });
            yearContainer.appendChild(avgChip);

            if (husChart) husChart.update();
        }

        // ── Stats panel ──────────────────────────────────────────────────────
        function renderStats(resp) {
            const mode = resp.mode;

            if (mode === 'daily' || mode === 'monthly') {
                const rows   = resp.days || resp.months || [];
                const totHus = rows.reduce((s, d) => s + (d.hus  ?? 0), 0);
                const totEv  = rows.reduce((s, d) => s + (d.ev   ?? 0), 0);
                const totHp  = rows.reduce((s, d) => s + (d.hp   ?? 0), 0);
                const totRest= rows.reduce((s, d) => s + (d.rest ?? 0), 0);
                const hasHus = totHus > 0;

                const pct = (val, tot) => tot > 0 ? ` (${Math.round(val / tot * 100)} %)` : '';

                // ── Estimated cost per component (spot-price based) ──────────
                // Price each row's kWh by that period's rate: daily → the day's
                // rate; monthly → average rate across the month's days.
                const costsReady = elSettingsReady() && Object.keys(activeCostMap).length > 0;
                const rateFor = d => (mode === 'daily')
                    ? (activeCostMap[d.day] || 0)
                    : _avgRateForMonth(d.month);
                let costHus = 0, costEv = 0, costHp = 0, costRest = 0;
                if (costsReady) {
                    rows.forEach(d => {
                        const rate = rateFor(d);
                        costHus  += (d.hus  ?? 0) * rate;
                        costEv   += (d.ev   ?? 0) * rate;
                        costHp   += (d.hp   ?? 0) * rate;
                        costRest += (d.rest ?? 0) * rate;
                    });
                }
                const kr = v => appUtils.formatCurrency(v);
                // kr cell appended to a component <dd>; blank until costs load
                const krCell = (val) => {
                    if (!elSettingsReady()) return '';
                    if (!costsReady)        return ` <span class="text-muted">· …</span>`;
                    return ` <span class="text-muted">· ca. ${kr(val)}</span>`;
                };

                let html = '<dl class="stat-list">';
                if (hasHus) {
                    html += `<dt>Hus total</dt><dd>${totHus.toFixed(1)} kWh${krCell(costHus)}</dd>`;
                    html += `
                        <dt><span class="stat-dot" style="background:${COLOR_HP}"></span> Jordvarme</dt>
                        <dd>${totHp.toFixed(1)} kWh${pct(totHp, totHus)}${krCell(costHp)}</dd>
                        <dt><span class="stat-dot" style="background:${COLOR_EV}"></span> El-bil</dt>
                        <dd>${totEv.toFixed(1)} kWh${pct(totEv, totHus)}${krCell(costEv)}</dd>
                        <dt><span class="stat-dot" style="background:${COLOR_REST}"></span> Restforbrug</dt>
                        <dd>${totRest.toFixed(1)} kWh${pct(totRest, totHus)}${krCell(costRest)}</dd>
                    `;
                } else {
                    html += `
                        <dt><span class="stat-dot" style="background:${COLOR_HP}"></span> Jordvarme</dt>
                        <dd>${totHp.toFixed(1)} kWh${krCell(costHp)}</dd>
                        <dt><span class="stat-dot" style="background:${COLOR_EV}"></span> El-bil</dt>
                        <dd>${totEv.toFixed(1)} kWh${krCell(costEv)}</dd>
                        <dt colspan="2" class="text-muted small">Hus-måler ikke synkroniseret endnu</dt>
                    `;
                }
                html += '</dl>';

                // Estimated total cost + component breakdown (mirrors jordvarme)
                if (elSettingsReady()) {
                    if (costsReady && costHus > 0) {
                        const c = activeComponents;
                        let breakdown = '';
                        if (c) {
                            const netLabel = (c.nettarif_records === 0)
                                ? `<span style="color:#dc3545">Net 0,000 ⚠ (netselskab ikke fundet)</span>`
                                : `Net ${c.nettarif_kr_kwh.toFixed(3)}`;
                            breakdown = `<div class="text-muted" style="font-size:10px;line-height:1.4;margin-top:2px">` +
                                `Spot ${c.spot_avg_kr_kwh.toFixed(3)} · Sys ${c.systemtarif_kr_kwh.toFixed(3)} · ` +
                                `Ela ${c.elafgift_kr_kwh.toFixed(3)} · ${netLabel} kr/kWh (ekskl. moms)</div>`;
                        }
                        html += `<div class="hus-cost-summary" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.08)">` +
                            `<strong>Estimeret elomkostning: ${kr(costHus)}</strong>` +
                            `<div class="text-muted" style="font-size:10px">Restforbrug er en approksimation — bruger samme sats som VP; boligelafgift er højere.</div>` +
                            breakdown +
                            `</div>`;
                    } else if (!costsReady) {
                        html += `<div class="text-muted small" style="margin-top:8px">` +
                            `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Beregner elomkostning…</div>`;
                    }
                }
                statsContent.innerHTML = html;

            } else if (mode === 'compare' || mode === 'ytd') {
                // Group by year and show yearly totals
                const data = resp.data || [];
                const yearTotals = {};
                data.forEach(d => {
                    yearTotals[d.year] = (yearTotals[d.year] ?? 0) + parseFloat(d.kwh);
                });
                const years = Object.keys(yearTotals).sort().reverse();

                // Estimated cost per year (kr) from activeCostMap. ytd rows have
                // no month field, so cost is only computed for compare mode.
                const costsReady = mode === 'compare'
                    && elSettingsReady() && Object.keys(activeCostMap).length > 0;
                const yearCosts = {};
                if (costsReady) {
                    data.forEach(d => {
                        const monthPrefix = d.year + '-' + String(d.month || '').padStart(2, '0');
                        const rate = _avgRateForMonth(monthPrefix);
                        yearCosts[d.year] = (yearCosts[d.year] || 0) + parseFloat(d.kwh) * rate;
                    });
                }

                let html = '<dl class="stat-list">';
                years.forEach(y => {
                    const suffix  = mode === 'ytd' ? ' (ÅTD)' : '';
                    const costTxt = costsReady && yearCosts[y] > 0
                        ? ` <span class="text-muted">· ca. ${appUtils.formatCurrency(yearCosts[y])}</span>` : '';
                    html += `<dt>${y}${suffix}</dt><dd>${yearTotals[y].toFixed(1)} kWh${costTxt}</dd>`;
                });
                if (years.length === 0) html += '<dt colspan="2" class="text-muted">Ingen hus-data</dt>';
                html += '</dl>';
                if (data.length === 0) {
                    html = '<p class="text-muted small">Hus-måler ikke synkroniseret endnu.<br>Kør <code>cron/sync_housepowerlog_data.php</code></p>';
                }
                statsContent.innerHTML = html;
            }
        }

        // ── Initial load ─────────────────────────────────────────────────────
        fetchAndRender();
        fetchSyncStatus();
    }

    return { init };
})();

window.husApp = husApp;
