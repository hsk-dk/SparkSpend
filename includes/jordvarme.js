/**
 * SparkSpend Jordvarme Module
 *
 * Lazy-initialised via window.jordvarmeApp.init() called by SparkNav
 * on the first visit to the Jordvarme tab.
 */

const jordvarmeApp = (() => {
    let initialized = false;

    function init() {
        if (initialized) return;
        initialized = true;

        const YEAR_COLORS = ['#3b82f6', '#22c55e', '#f97316', '#8b5cf6', '#ef4444', '#ec4899'];
        const MONTH_NAMES  = ["Jan", "Feb", "Mar", "Apr", "Maj", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dec"];

        // State
        let currentMode  = "daily";
        let currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM
        let currentYear  = String(new Date().getFullYear());     // YYYY
        let heatpumpChart = null;

        // DOM refs
        const canvas        = document.getElementById("heatpumpChart");
        const noDataEl      = document.getElementById("heatpumpNoData");
        const errorEl       = document.getElementById("heatpumpError");
        const periodNav     = document.getElementById("heatpumpPeriodNav");
        const periodLabel   = document.getElementById("heatpumpPeriodLabel");
        const prevBtn       = document.getElementById("heatpumpPrev");
        const nextBtn       = document.getElementById("heatpumpNext");
        const statsContent  = document.getElementById("heatpumpStatsContent");
        const syncStatusEl  = document.getElementById("heatpumpSyncStatus");
        const yearContainer = document.getElementById("yearToggleContainer");

        if (!canvas || !prevBtn || !nextBtn || !periodNav || !statsContent) return;

        const ctx = canvas.getContext("2d");

        // ── Electricity cost settings (localStorage) ──────────────────────────
        // Migrates away from the legacy static-rate key if present.
        localStorage.removeItem('sparkspend_elpris');

        const LS_PRISZONE_KEY   = 'sparkspend_priszone';
        const LS_GLN_KEY        = 'sparkspend_gln';
        const LS_NETSELSKAB_KEY = 'sparkspend_netselskab';

        function getElSettings() {
            return {
                area: localStorage.getItem(LS_PRISZONE_KEY) || '',
                gln:  localStorage.getItem(LS_GLN_KEY)      || '',
            };
        }
        function elSettingsReady() {
            const s = getElSettings();
            return s.area !== '' && s.gln !== '';
        }

        // Cost state: date string (YYYY-MM-DD) → kr/kWh, populated by fetchElCosts()
        let activeCostMap    = {};
        // Representative component breakdown for the period (from getElspotPrices.php response)
        let activeComponents = null;
        // Last kWh dataset received from fetchAndRender, used to re-render stats after costs arrive
        let lastKwhData      = null;

        // Settings modal wiring
        const settingsSaveBtn      = document.getElementById('settingsSaveBtn');
        const settingsPriszoneEl   = document.getElementById('settingsPriszone');
        const settingsNetselskabEl = document.getElementById('settingsNetselskab');

        document.getElementById('settingsModal')?.addEventListener('show.bs.modal', () => {
            const s = getElSettings();
            if (s.area && settingsPriszoneEl) settingsPriszoneEl.value = s.area;
            if (settingsNetselskabEl) {
                [...settingsNetselskabEl.options].forEach(o => {
                    o.selected = (o.dataset.gln === s.gln);
                });
            }
        });

        settingsSaveBtn?.addEventListener('click', () => {
            const area   = settingsPriszoneEl?.value || '';
            const selOpt = settingsNetselskabEl?.options[settingsNetselskabEl.selectedIndex];
            const gln    = selOpt?.dataset.gln || '';
            if (area && gln) {
                localStorage.setItem(LS_PRISZONE_KEY,   area);
                localStorage.setItem(LS_GLN_KEY,        gln);
                localStorage.setItem(LS_NETSELSKAB_KEY, selOpt.value);
            } else {
                localStorage.removeItem(LS_PRISZONE_KEY);
                localStorage.removeItem(LS_GLN_KEY);
                localStorage.removeItem(LS_NETSELSKAB_KEY);
            }
            bootstrap.Modal.getInstance(document.getElementById('settingsModal')).hide();
            if (elSettingsReady()) {
                activeCostMap = {}; activeComponents = null;
                fetchElCosts();
            } else {
                activeCostMap = {}; activeComponents = null;
                if (lastKwhData) renderStats(lastKwhData);
            }
        });

        // ── Mode buttons ─────────────────────────────────────────────────────
        document.querySelectorAll('#jordvarme-section .hp-mode-btn').forEach(btn => {
            btn.addEventListener("click", function () {
                document.querySelectorAll('#jordvarme-section .hp-mode-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentMode = this.dataset.mode;
                const isChronological = currentMode === "compare" || currentMode === "ytd";
                periodNav.style.display = isChronological ? "none" : "";
                if (!isChronological && yearContainer) yearContainer.style.display = "none";
                activeCostMap = {}; activeComponents = null;
                fetchAndRender();
                // compare/ytd: fetchElCosts is triggered inside fetchAndRender once
                // data arrives, so the actual year range is known (avoids fetching
                // 6+ years of hourly spot data for a fixed 2020→today window).
                if (!isChronological) fetchElCosts();
            });
        });

        // ── Period navigation ────────────────────────────────────────────────
        prevBtn.addEventListener("click", () => shiftPeriod(-1));
        nextBtn.addEventListener("click", () => shiftPeriod(1));

        function shiftPeriod(direction) {
            const now        = new Date();
            const todayYear  = now.getFullYear();
            const todayMonth = `${todayYear}-${String(now.getMonth() + 1).padStart(2, "0")}`;
            if (currentMode === "daily") {
                const [y, m] = currentMonth.split("-").map(Number);
                const d    = new Date(y, m - 1 + direction, 1);
                const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
                if (next > todayMonth) return;
                currentMonth = next;
            } else if (currentMode === "monthly") {
                const next = Number(currentYear) + direction;
                if (next > todayYear) return;
                currentYear = String(next);
            }
            activeCostMap = {}; activeComponents = null;
            fetchAndRender();
            fetchElCosts();
        }

        function updatePeriodLabel() {
            const now        = new Date();
            const todayYear  = now.getFullYear();
            const todayMonth = `${todayYear}-${String(now.getMonth() + 1).padStart(2, "0")}`;
            if (currentMode === "daily") {
                const [y, m] = currentMonth.split("-").map(Number);
                const name = new Date(y, m - 1, 1).toLocaleString("da-DK", { month: "long", year: "numeric" });
                periodLabel.textContent = name.charAt(0).toUpperCase() + name.slice(1);
                nextBtn.disabled = currentMonth >= todayMonth;
            } else if (currentMode === "monthly") {
                periodLabel.textContent = currentYear;
                nextBtn.disabled = Number(currentYear) >= todayYear;
            } else {
                periodLabel.textContent = "";
                nextBtn.disabled = false;
            }
        }

        function buildUrl() {
            if (currentMode === "daily")   return `getHeatpumpData.php?mode=daily&month=${currentMonth}`;
            if (currentMode === "monthly") return `getHeatpumpData.php?mode=monthly&year=${currentYear}`;
            if (currentMode === "ytd")     return "getHeatpumpData.php?mode=ytd";
            return "getHeatpumpData.php?mode=compare";
        }

        function setChartVisible(visible) {
            canvas.style.display = visible ? "" : "none";
        }

        // ── Fetch & render ───────────────────────────────────────────────────
        function fetchAndRender() {
            updatePeriodLabel();
            noDataEl.style.display = "none";
            errorEl.style.display  = "none";
            setChartVisible(false);
            if (yearContainer) yearContainer.style.display = "none";
            statsContent.innerHTML = "<p>Indlæser data...</p>";

            fetch(buildUrl())
                .then(r => {
                    if (!r.ok) throw new Error("HTTP " + r.status);
                    return r.json();
                })
                .then(data => {
                    if (!Array.isArray(data) || data.length === 0) {
                        noDataEl.style.display = "";
                        lastKwhData = null;
                        renderStats([]);
                        return;
                    }
                    lastKwhData = data;
                    renderChart(data);
                    renderStats(data);
                    setChartVisible(true);
                    // For compare/ytd the date range for costs depends on which years
                    // are present in the data — trigger the fetch here with the data
                    // so fetchElCosts can compute the correct start year.
                    if (currentMode === 'compare' || currentMode === 'ytd') {
                        activeCostMap = {}; activeComponents = null;
                        fetchElCosts(data);
                    }
                })
                .catch(err => {
                    console.error('Jordvarme fetch error:', err);
                    errorEl.style.display = "";
                    statsContent.innerHTML = "<p class='text-muted'>Kunne ikke hente data.</p>";
                });
        }

        // ── Electricity cost fetch ────────────────────────────────────────────
        // kwhData: optional — passed from fetchAndRender for compare/ytd so the
        // year range can be derived from actual data without a race condition.
        function fetchElCosts(kwhData) {
            if (!elSettingsReady()) return;
            const { area, gln } = getElSettings();
            const today = new Date().toISOString().slice(0, 10);

            let start, end;
            if (currentMode === 'daily') {
                // One calendar month
                const [y, m] = currentMonth.split('-').map(Number);
                const lastDay = new Date(y, m, 0).getDate();
                start = currentMonth + '-01';
                end   = currentMonth + '-' + String(lastDay).padStart(2, '0');
                if (end > today) end = today;
            } else if (currentMode === 'monthly') {
                start = currentYear + '-01-01';
                end   = currentYear === String(new Date().getFullYear())
                            ? today : currentYear + '-12-31';
            } else {
                // compare / ytd — derive year range from actual kWh data so we only
                // fetch data for years that have measurements, not a fixed 2020→today
                // window (which would be 6+ years of hourly spot records and very slow).
                const srcData = kwhData || lastKwhData;
                let minYear = new Date().getFullYear() - 2;
                if (srcData && srcData.length > 0) {
                    const years = srcData.map(d => parseInt(d.year)).filter(y => !isNaN(y));
                    if (years.length > 0) minYear = Math.min(...years);
                }
                start = minYear + '-01-01';
                end   = today;
            }

            const params = new URLSearchParams({ start, end, area, gln });
            fetch('getElspotPrices.php?' + params)
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(data => {
                    activeCostMap    = {};
                    activeComponents = data.components || null;
                    (data.records || []).forEach(r => { activeCostMap[r.date] = parseFloat(r.kr_kwh); });
                    _rerenderStats();
                })
                .catch(err => {
                    console.error('fetchElCosts:', err);
                });
        }

        function _rerenderStats() {
            if (lastKwhData) renderStats(lastKwhData);
        }

        // ── Chart rendering ──────────────────────────────────────────────────
        function renderChart(data) {
            if (heatpumpChart) {
                heatpumpChart.destroy();
                heatpumpChart = null;
            }

            if (currentMode === "compare") {
                renderCompareChart(data);
                return;
            }

            if (currentMode === "ytd") {
                renderYtdChart(data);
                return;
            }

            let labels, values;
            if (currentMode === "daily") {
                labels = data.map(d => d.day.slice(8));
                values = data.map(d => parseFloat(d.total_kwh));
            } else {
                labels = data.map(d => MONTH_NAMES[parseInt(d.month.slice(-2), 10) - 1]);
                values = data.map(d => parseFloat(d.total_kwh));
            }

            heatpumpChart = new Chart(ctx, {
                type: "bar",
                data: {
                    labels,
                    datasets: [{
                        label: "Forbrug (kWh)",
                        data: values,
                        backgroundColor: "rgba(34, 197, 94, 0.65)",
                        borderColor: "rgba(34, 197, 94, 1)",
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                        callbacks: {
                            label: c => {
                                const kwh = c.parsed.y;
                                const lines = [`${kwh.toFixed(2)} kWh`];
                                if (Object.keys(activeCostMap).length > 0) {
                                    let rate = 0;
                                    if (currentMode === 'daily') {
                                        const date = data[c.dataIndex]?.day;
                                        rate = date ? (activeCostMap[date] || 0) : 0;
                                    } else {
                                        const month = data[c.dataIndex]?.month;
                                        if (month) {
                                            const rates = Object.entries(activeCostMap)
                                                .filter(([d]) => d.startsWith(month))
                                                .map(([, r]) => r);
                                            rate = rates.length
                                                ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
                                        }
                                    }
                                    if (rate > 0) lines.push(`ca. ${appUtils.formatCurrency(kwh * rate)}`);
                                }
                                return lines;
                            }
                        }
                    }
                    },
                    scales: { y: { beginAtZero: true, title: { display: true, text: "kWh" } } }
                }
            });
        }

        function renderCompareChart(data) {
            // Pivot: Map<year, Array(12)> — null for months with no data
            const yearMap = new Map();
            data.forEach(d => {
                if (!yearMap.has(d.year)) yearMap.set(d.year, new Array(12).fill(null));
                yearMap.get(d.year)[parseInt(d.month, 10) - 1] = parseFloat(d.total_kwh);
            });

            const years    = [...yearMap.keys()].sort();
            const datasets = years.map((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                return {
                    label: year,
                    data: yearMap.get(year),
                    borderColor: color,
                    backgroundColor: color + "20",
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.35,
                    spanGaps: false
                };
            });

            // Monthly average across all years (dashed reference line)
            const avgData = Array.from({ length: 12 }, (_, m) => {
                const vals = [...yearMap.values()].map(arr => arr[m]).filter(v => v !== null);
                return vals.length > 0
                    ? parseFloat((vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(1))
                    : null;
            });
            datasets.push({
                label: "Gns.",
                data: avgData,
                borderColor: "#94a3b8",
                backgroundColor: "transparent",
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                tension: 0.35,
                spanGaps: true
            });

            heatpumpChart = new Chart(ctx, {
                type: "line",
                data: { labels: MONTH_NAMES, datasets },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label: c => c.parsed.y !== null
                                    ? `${c.dataset.label}: ${c.parsed.y.toFixed(1)} kWh`
                                    : `${c.dataset.label}: ingen data`
                            }
                        }
                    },
                    scales: { y: { beginAtZero: true, title: { display: true, text: "kWh" } } }
                }
            });

            buildYearChips(years, true);
        }

        // ── Year toggle chips ────────────────────────────────────────────────
        function buildYearChips(years, hasAvg = false) {
            if (!yearContainer) return;
            yearContainer.innerHTML = "";

            years.forEach((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                const chip  = document.createElement("button");
                chip.type = "button";
                chip.className = "year-chip active";
                chip.textContent = year;
                chip.style.setProperty("--chip-color", color);
                chip.addEventListener("click", () => {
                    const nowActive = chip.classList.toggle("active");
                    if (heatpumpChart) {
                        heatpumpChart.setDatasetVisibility(i, nowActive);
                        heatpumpChart.update();
                    }
                });
                yearContainer.appendChild(chip);
            });

            if (hasAvg) {
                const avgIdx = years.length;
                const chip   = document.createElement("button");
                chip.type = "button";
                chip.className = "year-chip active";
                chip.textContent = "Gns.";
                chip.style.setProperty("--chip-color", "#94a3b8");
                chip.addEventListener("click", () => {
                    const nowActive = chip.classList.toggle("active");
                    if (heatpumpChart) {
                        heatpumpChart.setDatasetVisibility(avgIdx, nowActive);
                        heatpumpChart.update();
                    }
                });
                yearContainer.appendChild(chip);
            }

            yearContainer.style.display = "flex";
        }

        // ── Stats panel ──────────────────────────────────────────────────────
        function renderStats(data) {
            if (!data || data.length === 0) {
                statsContent.innerHTML = "<p class='text-muted'>Ingen data.</p>";
                return;
            }

            if (currentMode === "compare") {
                renderCompareStats(data);
                return;
            }

            if (currentMode === "ytd") return; // handled inside renderYtdChart

            const values = data.map(d => parseFloat(d.total_kwh));
            const total  = values.reduce((a, b) => a + b, 0);
            const avg    = total / values.length;
            const max    = Math.max(...values);
            const maxIdx = values.indexOf(max);

            let maxLabel = "";
            if (currentMode === "daily") {
                maxLabel = data[maxIdx].day.slice(8) + ". " +
                    new Date(data[maxIdx].day).toLocaleString("da-DK", { month: "short" });
            } else {
                maxLabel = MONTH_NAMES[parseInt(data[maxIdx].month.slice(-2), 10) - 1];
            }

            // Compute estimated cost from activeCostMap (populated by fetchElCosts)
            let totalCost = 0;
            const costsReady = elSettingsReady() && Object.keys(activeCostMap).length > 0;
            if (costsReady) {
                if (currentMode === 'daily') {
                    data.forEach(d => {
                        const rate = activeCostMap[d.day] || 0;
                        totalCost += parseFloat(d.total_kwh) * rate;
                    });
                } else {
                    // Monthly: average daily rates within the month, multiply by monthly kWh
                    data.forEach(d => {
                        const monthPrefix = d.month; // 'YYYY-MM'
                        const dayRates = Object.entries(activeCostMap)
                            .filter(([date]) => date.startsWith(monthPrefix))
                            .map(([, rate]) => rate);
                        const avgRate = dayRates.length > 0
                            ? dayRates.reduce((a, b) => a + b, 0) / dayRates.length : 0;
                        totalCost += parseFloat(d.total_kwh) * avgRate;
                    });
                }
            }

            // Always render two cost rows when electricity settings are configured so the
            // table height is identical before and after costs load (prevents layout shift).
            let costRows = '';
            if (elSettingsReady()) {
                if (costsReady && totalCost > 0) {
                    const c = activeComponents;
                    let breakdown = '';
                    if (c) {
                        const netLabel = (c.nettarif_records === 0)
                            ? `<span style="color:#dc3545">Net 0,000 ⚠ (netselskab ikke fundet)</span>`
                            : `Net ${c.nettarif_kr_kwh.toFixed(3)}`;
                        breakdown = `<tr><td colspan="2" class="text-muted" style="font-size:10px;line-height:1.4">` +
                            `Spot ${c.spot_avg_kr_kwh.toFixed(3)} · Sys ${c.systemtarif_kr_kwh.toFixed(3)} · Ela ${c.elafgift_kr_kwh.toFixed(3)} · ${netLabel} kr/kWh (ekskl. moms)</td></tr>`;
                    }
                    costRows = `<tr><td>Estimeret elomkostning</td><td class="text-end fw-bold">${appUtils.formatCurrency(totalCost)}</td></tr>${breakdown}`;
                } else {
                    // Placeholder rows — same count/height as the loaded state, no layout shift
                    costRows =
                        `<tr><td>Estimeret elomkostning</td>` +
                        `<td class="text-end text-muted"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></td></tr>` +
                        `<tr><td colspan="2" class="text-muted" style="font-size:10px;line-height:1.4">&nbsp;</td></tr>`;
                }
            }

            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <tr><td>Total</td><td class="text-end fw-bold">${total.toFixed(1)} kWh</td></tr>
                    <tr><td>Gennemsnit</td><td class="text-end">${avg.toFixed(1)} kWh</td></tr>
                    <tr><td>Højeste</td><td class="text-end">${max.toFixed(1)} kWh (${maxLabel})</td></tr>
                    <tr><td>Perioder</td><td class="text-end">${data.length}</td></tr>
                    ${costRows}
                </table>`;
        }

        function renderCompareStats(data) {
            // Build per-year totals
            const yearTotals = new Map();
            const yearCounts = new Map();
            data.forEach(d => {
                const v = parseFloat(d.total_kwh);
                yearTotals.set(d.year, (yearTotals.get(d.year) || 0) + v);
                yearCounts.set(d.year, (yearCounts.get(d.year) || 0) + 1);
            });

            // Compute per-year estimated costs from activeCostMap (daily rates)
            const hasCosts = elSettingsReady() && Object.keys(activeCostMap).length > 0;
            const yearCosts = new Map();
            if (hasCosts) {
                data.forEach(d => {
                    const monthPrefix = d.year + '-' + String(d.month || '').padStart(2, '0');
                    // average daily rates in this month from activeCostMap
                    const dayRates = Object.entries(activeCostMap)
                        .filter(([date]) => date.startsWith(monthPrefix))
                        .map(([, rate]) => rate);
                    const avgRate = dayRates.length > 0
                        ? dayRates.reduce((a, b) => a + b, 0) / dayRates.length : 0;
                    yearCosts.set(d.year, (yearCosts.get(d.year) || 0) + parseFloat(d.total_kwh) * avgRate);
                });
            }

            const years = [...yearTotals.keys()].sort();
            const rows  = years.map((year, i) => {
                const color   = YEAR_COLORS[i % YEAR_COLORS.length];
                const total   = yearTotals.get(year);
                const months  = yearCounts.get(year);
                const costCell = hasCosts && yearCosts.get(year) > 0
                    ? `<td class="text-end text-muted" style="font-size:11px">${appUtils.formatCurrency(yearCosts.get(year))}</td>`
                    : (hasCosts ? '<td></td>' : '');
                return `<tr>
                    <td><span class="year-dot" style="background:${color}"></span>${year}</td>
                    <td class="text-end fw-bold">${total.toFixed(1)} kWh</td>
                    <td class="text-end text-muted" style="font-size:11px">${months} mdr.</td>
                    ${costCell}
                </tr>`;
            }).join("");

            const costHeader = hasCosts ? '<th class="text-end">Est. kr</th>' : '';
            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>År</th><th class="text-end">Total</th><th class="text-end">Mdr.</th>${costHeader}
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
        }

        // ── YTD cumulative chart ─────────────────────────────────────────────
        function renderYtdChart(data) {
            // Generate x-axis labels: every MM-DD from 01-01 to today
            const today   = new Date();
            const todayMd = String(today.getMonth() + 1).padStart(2, "0") + "-" +
                            String(today.getDate()).padStart(2, "0");
            const labels  = [];
            const cursor  = new Date(today.getFullYear(), 0, 1);
            while (true) {
                const md = String(cursor.getMonth() + 1).padStart(2, "0") + "-" +
                           String(cursor.getDate()).padStart(2, "0");
                labels.push(md);
                if (md === todayMd) break;
                cursor.setDate(cursor.getDate() + 1);
            }

            // Group by year: Map<year, Map<MM-DD, daily_kwh>>
            const yearDayMap = new Map();
            data.forEach(row => {
                if (!yearDayMap.has(row.year)) yearDayMap.set(row.year, new Map());
                yearDayMap.get(row.year).set(row.day.slice(5), parseFloat(row.daily_kwh));
            });

            const years    = [...yearDayMap.keys()].sort();
            const datasets = years.map((year, i) => {
                const color  = YEAR_COLORS[i % YEAR_COLORS.length];
                const dayMap = yearDayMap.get(year);
                let cum = 0;
                return {
                    label: year,
                    data: labels.map(md => parseFloat((cum += (dayMap.get(md) || 0)).toFixed(2))),
                    borderColor: color,
                    backgroundColor: color + "20",
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.2,
                    fill: false
                };
            });

            if (heatpumpChart) { heatpumpChart.destroy(); heatpumpChart = null; }
            heatpumpChart = new Chart(ctx, {
                type: "line",
                data: { labels, datasets },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                title: items => {
                                    const md = items[0].label;
                                    const [m, d] = md.split("-").map(Number);
                                    return MONTH_NAMES[m - 1] + " " + d;
                                },
                                label: c => `${c.dataset.label}: ${c.parsed.y.toFixed(1)} kWh`
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                autoSkip: false,
                                maxRotation: 0,
                                callback: (val, idx) => {
                                    const md = labels[idx];
                                    return md && md.endsWith("-01")
                                        ? MONTH_NAMES[parseInt(md.split("-")[0], 10) - 1]
                                        : "";
                                }
                            }
                        },
                        y: { beginAtZero: true, title: { display: true, text: "Akkumuleret kWh" } }
                    }
                }
            });

            buildYearChips(years);
            renderYtdStats(years, yearDayMap, labels);
        }

        function renderYtdStats(years, yearDayMap, labels) {
            const hasCosts = elSettingsReady() && Object.keys(activeCostMap).length > 0;
            const rows = years.map((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                const total = labels.reduce((sum, md) => sum + (yearDayMap.get(year).get(md) || 0), 0);
                let estCost = 0;
                if (hasCosts) {
                    labels.forEach(md => {
                        const date = year + '-' + md; // 'YYYY-MM-DD'
                        const kwh  = yearDayMap.get(year).get(md) || 0;
                        estCost += kwh * (activeCostMap[date] || 0);
                    });
                }
                const costCell = hasCosts && estCost > 0
                    ? `<td class="text-end text-muted" style="font-size:11px">${appUtils.formatCurrency(estCost)}</td>`
                    : (hasCosts ? '<td></td>' : '');
                return `<tr>
                    <td><span class="year-dot" style="background:${color}"></span>${year}</td>
                    <td class="text-end fw-bold">${total.toFixed(1)} kWh</td>
                    ${costCell}
                </tr>`;
            }).join("");
            const costHeader = hasCosts ? '<th class="text-end">Est. kr</th>' : '';
            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>År</th><th class="text-end">Akkumuleret</th>${costHeader}
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
        }

        // ── Sync status ──────────────────────────────────────────────────────
        function fetchSyncStatus() {
            fetch("getHeatpumpData.php?mode=sync_status")
                .then(r => r.json())
                .then(data => {
                    if (data && data.updated_at) {
                        const ts = new Date(data.updated_at.replace(" ", "T"));
                        syncStatusEl.textContent = "Sidst synkroniseret: " + ts.toLocaleString("da-DK");
                    }
                })
                .catch(() => {});
        }

        // ── Initial load ─────────────────────────────────────────────────────
        periodNav.style.display = "";
        fetchAndRender();
        fetchElCosts();
        fetchSyncStatus();
    }

    return { init };
})();

window.jordvarmeApp = jordvarmeApp;
