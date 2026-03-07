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

        // ── Mode buttons ─────────────────────────────────────────────────────
        document.querySelectorAll('#jordvarme-section .hp-mode-btn').forEach(btn => {
            btn.addEventListener("click", function () {
                document.querySelectorAll('#jordvarme-section .hp-mode-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentMode = this.dataset.mode;
                const isChronological = currentMode === "compare" || currentMode === "ytd";
                periodNav.style.display = isChronological ? "none" : "";
                if (!isChronological && yearContainer) yearContainer.style.display = "none";
                fetchAndRender();
            });
        });

        // ── Period navigation ────────────────────────────────────────────────
        prevBtn.addEventListener("click", () => shiftPeriod(-1));
        nextBtn.addEventListener("click", () => shiftPeriod(1));

        function shiftPeriod(direction) {
            if (currentMode === "daily") {
                const [y, m] = currentMonth.split("-").map(Number);
                const d = new Date(y, m - 1 + direction, 1);
                currentMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
            } else if (currentMode === "monthly") {
                currentYear = String(Number(currentYear) + direction);
            }
            fetchAndRender();
        }

        function updatePeriodLabel() {
            if (currentMode === "daily") {
                const [y, m] = currentMonth.split("-").map(Number);
                const name = new Date(y, m - 1, 1).toLocaleString("da-DK", { month: "long", year: "numeric" });
                periodLabel.textContent = name.charAt(0).toUpperCase() + name.slice(1);
            } else if (currentMode === "monthly") {
                periodLabel.textContent = currentYear;
            } else {
                periodLabel.textContent = "";
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
                        renderStats([]);
                        return;
                    }
                    renderChart(data);
                    renderStats(data);
                    setChartVisible(true);
                })
                .catch(() => {
                    errorEl.style.display = "";
                    statsContent.innerHTML = "<p class='text-muted'>Kunne ikke hente data.</p>";
                });
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
                        tooltip: { callbacks: { label: c => `${c.parsed.y.toFixed(2)} kWh` } }
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

            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <tr><td>Total</td><td class="text-end fw-bold">${total.toFixed(1)} kWh</td></tr>
                    <tr><td>Gennemsnit</td><td class="text-end">${avg.toFixed(1)} kWh</td></tr>
                    <tr><td>Højeste</td><td class="text-end">${max.toFixed(1)} kWh (${maxLabel})</td></tr>
                    <tr><td>Perioder</td><td class="text-end">${data.length}</td></tr>
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

            const years = [...yearTotals.keys()].sort();
            const rows  = years.map((year, i) => {
                const color   = YEAR_COLORS[i % YEAR_COLORS.length];
                const total   = yearTotals.get(year);
                const months  = yearCounts.get(year);
                return `<tr>
                    <td><span class="year-dot" style="background:${color}"></span>${year}</td>
                    <td class="text-end fw-bold">${total.toFixed(1)} kWh</td>
                    <td class="text-end text-muted" style="font-size:11px">${months} mdr.</td>
                </tr>`;
            }).join("");

            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>År</th><th class="text-end">Total</th><th class="text-end">Mdr.</th>
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
            const rows = years.map((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                const total = labels.reduce((sum, md) => sum + (yearDayMap.get(year).get(md) || 0), 0);
                return `<tr>
                    <td><span class="year-dot" style="background:${color}"></span>${year}</td>
                    <td class="text-end fw-bold">${total.toFixed(1)} kWh</td>
                </tr>`;
            }).join("");
            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>År</th><th class="text-end">Akkumuleret</th>
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
        fetchSyncStatus();
    }

    return { init };
})();

window.jordvarmeApp = jordvarmeApp;
