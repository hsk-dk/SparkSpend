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

        // ── Settings (server config via .env → window.sparkConfig) ───────────
        // All previously localStorage-stored settings are now server-side only.
        // Clean up any stale client-side values on first load.
        ['sparkspend_elpris', 'sparkspend_priszone', 'sparkspend_gln',
         'sparkspend_netselskab', 'sparkspend_hp_lat', 'sparkspend_hp_lon']
            .forEach(k => localStorage.removeItem(k));

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

        function getWeatherSettings() {
            return {
                lat: window.sparkConfig?.weatherLat || '',
                lon: window.sparkConfig?.weatherLon || '',
            };
        }
        function weatherSettingsReady() {
            const s = getWeatherSettings();
            return s.lat !== '' && s.lon !== '';
        }

        // Cost state: date string (YYYY-MM-DD) → kr/kWh, populated by fetchElCosts()
        let activeCostMap    = {};
        // Representative component breakdown for the period (from getElspotPrices.php response)
        let activeComponents = null;
        // Weather state: date string (YYYY-MM-DD) → HDD (max(0, 17 - mean_temp)), from Open-Meteo
        let activeWeatherMap = {};
        // Temperature state: date string (YYYY-MM-DD) → mean_temp_c, parallel to activeWeatherMap
        let activeTemperatureMap = {};
        // Whether the compare chart is showing kWh/GD instead of raw kWh
        let compareNormalized = false;
        // Baseline kWh/GD computed from compare-mode data; used for expected-vs-actual in stats
        let baselineKwhPerHdd = null;
        // Last kWh dataset received from fetchAndRender, used to re-render stats after costs arrive
        let lastKwhData      = null;

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
                activeWeatherMap = {}; activeTemperatureMap = {}; compareNormalized = false;
                fetchAndRender();
                // compare/ytd: fetchElCosts is triggered inside fetchAndRender once
                // data arrives, so the actual year range is known (avoids fetching
                // 6+ years of hourly spot data for a fixed 2020→today window).
                if (!isChronological) fetchElCosts();
                if (!isChronological) fetchWeatherData();
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
            activeWeatherMap = {}; activeTemperatureMap = {};
            fetchAndRender();
            fetchElCosts();
            fetchWeatherData();
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
            // Do NOT hide the canvas here. Hiding it with display:none causes Chart.js
            // to measure 0px dimensions when the chart is created in the .then() below.
            // Leaving the old chart visible during loading is fine (stale-while-revalidate).
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
                        // Hide canvas (may show a stale chart) and clean up
                        setChartVisible(false);
                        if (heatpumpChart) { heatpumpChart.destroy(); heatpumpChart = null; }
                        lastKwhData = null;
                        renderStats([]);
                        return;
                    }
                    // Canvas is already visible — chart is created at correct dimensions.
                    setChartVisible(true);
                    lastKwhData = data;
                    renderChart(data);
                    renderStats(data);
                    // For compare/ytd the date range for costs depends on which years
                    // are present in the data — trigger the fetch here with the data
                    // so fetchElCosts can compute the correct start year.
                    if (currentMode === 'compare' || currentMode === 'ytd') {
                        activeCostMap = {}; activeComponents = null;
                        fetchElCosts(data);
                        activeWeatherMap = {}; activeTemperatureMap = {};
                        fetchWeatherData(data);
                    }
                })
                .catch(err => {
                    console.error('Jordvarme fetch error:', err);
                    errorEl.style.display = "";
                    setChartVisible(false);
                    if (heatpumpChart) { heatpumpChart.destroy(); heatpumpChart = null; }
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

        // Inject or update the HDD line overlay on an existing daily/monthly chart without
        // destroying it — avoids the destroy→recreate blink when weather data arrives.
        function _updateHddOverlay() {
            if (!heatpumpChart) return;
            if (currentMode !== 'daily' && currentMode !== 'monthly') return;
            const hasWeather = weatherSettingsReady() && Object.keys(activeWeatherMap).length > 0;
            const hddValues = hasWeather
                ? (currentMode === 'daily'
                    ? lastKwhData.map(d => activeWeatherMap[d.day] ?? null)
                    : lastKwhData.map(d => {
                        const s = Object.entries(activeWeatherMap)
                            .filter(([date]) => date.startsWith(d.month))
                            .reduce((sum, [, h]) => sum + h, 0);
                        return s > 0 ? parseFloat(s.toFixed(1)) : null;
                    }))
                : null;
            const existingIdx = heatpumpChart.data.datasets.findIndex(d => d.label === 'Gradedage (GD)');
            if (hddValues && existingIdx === -1) {
                heatpumpChart.data.datasets.push({
                    type: 'line', label: 'Gradedage (GD)', data: hddValues,
                    borderColor: 'rgba(148, 163, 184, 0.85)', backgroundColor: 'transparent',
                    borderWidth: 2, pointRadius: 2, pointHoverRadius: 4,
                    tension: 0.3, spanGaps: false, yAxisID: 'y2',
                });
                heatpumpChart.options.scales.y2 = {
                    type: 'linear', position: 'right', beginAtZero: true,
                    title: { display: true, text: 'GD' }, grid: { drawOnChartArea: false },
                };
            } else if (hddValues && existingIdx !== -1) {
                heatpumpChart.data.datasets[existingIdx].data = hddValues;
            } else if (!hddValues && existingIdx !== -1) {
                heatpumpChart.data.datasets.splice(existingIdx, 1);
                heatpumpChart.options.scales.y2 = { display: false };
            }
            heatpumpChart.update('none'); // no animation — smooth in-place update
        }

        function _rerenderChart() {
            if (!lastKwhData) return;
            if (currentMode === 'daily' || currentMode === 'monthly') {
                // If a chart already exists, update the HDD overlay in-place to avoid blink
                if (heatpumpChart) { _updateHddOverlay(); return; }
                renderChart(lastKwhData);
                return;
            }
            if (currentMode === 'compare') renderCompareChart(lastKwhData);
        }

        // ── Background baseline initialisation ────────────────────────────────
        // Silently fetches compare + weather data on init (both server-side cached)
        // to seed baselineKwhPerHdd so expected-vs-actual appears in daily/monthly
        // stats without requiring the user to visit compare mode first.
        function initBaseline() {
            if (!weatherSettingsReady()) return;
            const { lat, lon } = getWeatherSettings();
            const today = new Date().toISOString().slice(0, 10);
            fetch('getHeatpumpData.php?mode=compare')
                .then(r => r.json())
                .then(compareData => {
                    if (!Array.isArray(compareData) || compareData.length === 0) return;
                    const years   = compareData.map(d => parseInt(d.year)).filter(y => !isNaN(y));
                    const minYear = Math.min(...years);
                    const params  = new URLSearchParams({ start: minYear + '-01-01', end: today, lat, lon });
                    return fetch('getWeatherData.php?' + params)
                        .then(r => r.json())
                        .then(weatherData => {
                            const wMap = {};
                            (weatherData.records || []).forEach(r => { wMap[r.date] = r.hdd; });
                            let totalKwh = 0, totalHdd = 0;
                            compareData.forEach(d => {
                                const mp   = String(d.month || '').padStart(2, '0');
                                const mHdd = Object.entries(wMap)
                                    .filter(([date]) => date.startsWith(`${d.year}-${mp}`))
                                    .reduce((s, [, h]) => s + h, 0);
                                if (mHdd >= 5) { totalKwh += parseFloat(d.total_kwh); totalHdd += mHdd; }
                            });
                            if (totalHdd > 0 && baselineKwhPerHdd === null) {
                                baselineKwhPerHdd = totalKwh / totalHdd;
                                _rerenderStats();
                            }
                        });
                })
                .catch(() => {}); // silent — best-effort
        }

        // ── Weather / degree-day fetch ────────────────────────────────────────
        // Calls getWeatherData.php which fetches from Open-Meteo and caches the
        // result server-side (24-hour TTL, same pattern as getElspotPrices.php).
        // kwhData: optional, passed for compare/ytd so the year range is derived
        // from actual measurements (same pattern as fetchElCosts).
        function fetchWeatherData(kwhData) {
            if (!weatherSettingsReady()) return;
            const { lat, lon } = getWeatherSettings();
            const today = new Date().toISOString().slice(0, 10);

            let start, end;
            if (currentMode === 'daily') {
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
                const srcData = kwhData || lastKwhData;
                let minYear = new Date().getFullYear() - 2;
                if (srcData && srcData.length > 0) {
                    const years = srcData.map(d => parseInt(d.year)).filter(y => !isNaN(y));
                    if (years.length > 0) minYear = Math.min(...years);
                }
                start = minYear + '-01-01';
                end   = today;
            }

            const params = new URLSearchParams({ start, end, lat, lon });
            fetch('getWeatherData.php?' + params)
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(data => {
                    activeWeatherMap = {};
                    activeTemperatureMap = {};
                    (data.records || []).forEach(r => {
                        activeWeatherMap[r.date]     = r.hdd;
                        activeTemperatureMap[r.date] = r.mean_temp_c;
                    });
                    _rerenderStats();
                    _rerenderChart();
                })
                .catch(err => console.error('fetchWeatherData:', err));
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

            // Build HDD overlay values (one per bar)
            const hasWeather = weatherSettingsReady() && Object.keys(activeWeatherMap).length > 0;
            const hddValues  = hasWeather
                ? (currentMode === 'daily'
                    ? data.map(d => activeWeatherMap[d.day] ?? null)
                    : data.map(d => {
                        const sum = Object.entries(activeWeatherMap)
                            .filter(([date]) => date.startsWith(d.month))
                            .reduce((s, [, hdd]) => s + hdd, 0);
                        return sum > 0 ? parseFloat(sum.toFixed(1)) : null;
                    }))
                : null;

            const datasets = [{
                label: "Forbrug (kWh)",
                data: values,
                backgroundColor: "rgba(34, 197, 94, 0.65)",
                borderColor: "rgba(34, 197, 94, 1)",
                borderWidth: 1,
                borderRadius: 4,
                yAxisID: 'y',
            }];

            if (hddValues) {
                datasets.push({
                    type: 'line',
                    label: 'Gradedage (GD)',
                    data: hddValues,
                    borderColor: 'rgba(148, 163, 184, 0.85)',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    tension: 0.3,
                    spanGaps: false,
                    yAxisID: 'y2',
                });
            }

            heatpumpChart = new Chart(ctx, {
                type: "bar",
                data: { labels, datasets },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            filter: item => item.datasetIndex === 0,
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
                                    // Read activeWeatherMap live so tooltip reflects HDD overlay
                                    // injected after initial render (avoids stale closure over hddValues)
                                    if (weatherSettingsReady()) {
                                        let hdd = null;
                                        if (currentMode === 'daily') {
                                            const date = data[c.dataIndex]?.day;
                                            hdd = date ? (activeWeatherMap[date] ?? null) : null;
                                        } else {
                                            const month = data[c.dataIndex]?.month;
                                            if (month) {
                                                const s = Object.entries(activeWeatherMap)
                                                    .filter(([d]) => d.startsWith(month))
                                                    .reduce((sum, [, h]) => sum + h, 0);
                                                hdd = s > 0 ? s : null;
                                            }
                                        }
                                        if (hdd !== null && hdd > 0)
                                            lines.push(`${hdd.toFixed(1)} GD · ${(kwh / hdd).toFixed(2)} kWh/GD`);
                                    }
                                    return lines;
                                }
                            }
                        }
                    },
                    scales: {
                        y:  { beginAtZero: true, title: { display: true, text: 'kWh' } },
                        y2: hddValues
                            ? { type: 'linear', position: 'right', beginAtZero: true,
                                title: { display: true, text: 'GD' },
                                grid: { drawOnChartArea: false } }
                            : { display: false },
                    }
                }
            });
        }

        function renderCompareChart(data) {
            // Capture which datasets were hidden before rebuilding, then destroy old chart
            const hiddenLabels = new Set();
            if (heatpumpChart) {
                heatpumpChart.data.datasets.forEach((ds, i) => {
                    if (!heatpumpChart.isDatasetVisible(i)) hiddenLabels.add(ds.label);
                });
                heatpumpChart.destroy();
                heatpumpChart = null;
            }

            // Pivot: Map<year, Array(12)> — null for months with no data
            const yearMap = new Map();
            data.forEach(d => {
                if (!yearMap.has(d.year)) yearMap.set(d.year, new Array(12).fill(null));
                yearMap.get(d.year)[parseInt(d.month, 10) - 1] = parseFloat(d.total_kwh);
            });

            const years = [...yearMap.keys()].sort();

            // Monthly HDD lookup helper (sum of daily HDD within a year-month)
            const hasWeather = weatherSettingsReady() && Object.keys(activeWeatherMap).length > 0;
            const monthlyHdd = (year, monthIdx) => {
                const prefix = `${year}-${String(monthIdx + 1).padStart(2, '0')}`;
                return Object.entries(activeWeatherMap)
                    .filter(([d]) => d.startsWith(prefix))
                    .reduce((s, [, h]) => s + h, 0);
            };

            // Normalize kWh → kWh/GD when toggle is active and weather data is available
            const normalizing = compareNormalized && hasWeather;
            const normalize = (kwh, year, monthIdx) => {
                if (kwh === null) return null;
                const hdd = monthlyHdd(year, monthIdx);
                return hdd >= 5 ? parseFloat((kwh / hdd).toFixed(3)) : null; // < 5 GD (summer) → show as gap
            };

            const datasets = years.map((year, i) => {
                const color = YEAR_COLORS[i % YEAR_COLORS.length];
                return {
                    label: year,
                    data: normalizing
                        ? yearMap.get(year).map((kwh, m) => normalize(kwh, year, m))
                        : yearMap.get(year),
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
                const vals = [...yearMap.values()]
                    .map((arr, yi) => normalizing ? normalize(arr[m], years[yi], m) : arr[m])
                    .filter(v => v !== null);
                return vals.length > 0
                    ? parseFloat((vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(normalizing ? 3 : 1))
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

            const yLabel = normalizing ? 'kWh/GD' : 'kWh';

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
                                label: c => {
                                    if (c.parsed.y === null) return `${c.dataset.label}: ingen data`;
                                    return `${c.dataset.label}: ${c.parsed.y.toFixed(normalizing ? 2 : 1)} ${yLabel}`;
                                }
                            }
                        }
                    },
                    scales: { y: { beginAtZero: true, title: { display: true, text: yLabel } } }
                }
            });

            buildYearChips(years, true, hiddenLabels);

            // kWh/GD normalization toggle chip — only shown when weather settings configured
            if (hasWeather && yearContainer) {
                const gdChip = document.createElement('button');
                gdChip.type = 'button';
                gdChip.className = 'year-chip' + (compareNormalized ? ' active' : '');
                gdChip.textContent = 'kWh/GD';
                gdChip.style.setProperty('--chip-color', '#f59e0b');
                gdChip.addEventListener('click', () => {
                    compareNormalized = !compareNormalized;
                    renderCompareChart(lastKwhData);
                });
                yearContainer.appendChild(gdChip);
            }
        }

        // ── Year toggle chips ────────────────────────────────────────────────
        function buildYearChips(years, hasAvg = false, hiddenLabels = new Set()) {
            if (!yearContainer) return;
            yearContainer.innerHTML = "";
            let needsUpdate = false;

            years.forEach((year, i) => {
                const color    = YEAR_COLORS[i % YEAR_COLORS.length];
                const chip     = document.createElement("button");
                const isHidden = hiddenLabels.has(year);
                chip.type = "button";
                chip.className = 'year-chip' + (isHidden ? '' : ' active');
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
                if (isHidden && heatpumpChart) {
                    heatpumpChart.setDatasetVisibility(i, false);
                    needsUpdate = true;
                }
            });

            if (hasAvg) {
                const avgIdx   = years.length;
                const chip     = document.createElement("button");
                const isHidden = hiddenLabels.has("Gns.");
                chip.type = "button";
                chip.className = 'year-chip' + (isHidden ? '' : ' active');
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
                if (isHidden && heatpumpChart) {
                    heatpumpChart.setDatasetVisibility(avgIdx, false);
                    needsUpdate = true;
                }
            }

            if (needsUpdate && heatpumpChart) heatpumpChart.update();
            yearContainer.style.display = "flex";
        }

        // ── COP estimation ────────────────────────────────────────────────────
        // Ground source heat pump model (jordvarme / brine-water GSHP).
        // Ground loop temperature is buffered by the earth's thermal mass —
        //   groundTempC ≈ outdoor * 0.3 + 7, clamped [2°C, 14°C]
        // Supply temperature default 40°C (typical Danish underfloor heating).
        // Practical COP ≈ 0.5 × Carnot (50% efficiency factor, EN 14825 typical).
        function _estimateCop(meanOutdoorTempC, supplyTempC = 40) {
            const groundTempC = Math.min(14, Math.max(2, meanOutdoorTempC * 0.3 + 7));
            const dT = supplyTempC - groundTempC;
            if (dT <= 0) return null;
            const carnot = (supplyTempC + 273.15) / dT;
            return Math.min(8, Math.round(carnot * 0.5 * 10) / 10);
        }

        // Compute the kWh-weighted mean outdoor temperature for a period using
        // activeTemperatureMap. Returns null when temperature data is unavailable.
        function _periodMeanTemp(data) {
            if (Object.keys(activeTemperatureMap).length === 0) return null;
            let sumWeightedTemp = 0, sumKwh = 0;
            if (currentMode === 'daily') {
                data.forEach(d => {
                    const t = activeTemperatureMap[d.day];
                    if (t == null) return;
                    const kwh = parseFloat(d.total_kwh);
                    sumWeightedTemp += t * kwh;
                    sumKwh += kwh;
                });
            } else {
                // Monthly: average daily temps within the month, weight by monthly kWh
                data.forEach(d => {
                    const temps = Object.entries(activeTemperatureMap)
                        .filter(([date]) => date.startsWith(d.month))
                        .map(([, t]) => t);
                    if (temps.length === 0) return;
                    const avgTemp = temps.reduce((a, b) => a + b, 0) / temps.length;
                    const kwh    = parseFloat(d.total_kwh);
                    sumWeightedTemp += avgTemp * kwh;
                    sumKwh += kwh;
                });
            }
            return sumKwh > 0 ? sumWeightedTemp / sumKwh : null;
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

            // Degree-day rows (populated by fetchWeatherData via activeWeatherMap)
            let weatherRows = '';
            if (weatherSettingsReady()) {
                const weatherReady = Object.keys(activeWeatherMap).length > 0;
                if (weatherReady) {
                    let totalHDD = 0;
                    if (currentMode === 'daily') {
                        data.forEach(d => { totalHDD += activeWeatherMap[d.day] || 0; });
                    } else {
                        data.forEach(d => {
                            Object.entries(activeWeatherMap)
                                .filter(([date]) => date.startsWith(d.month))
                                .forEach(([, hdd]) => { totalHDD += hdd; });
                        });
                    }
                    const kwhPerHdd = totalHDD > 0 ? total / totalHDD : 0;
                    weatherRows =
                        `<tr><td>Gradedage (HDD 17°C)</td><td class="text-end">${totalHDD.toFixed(1)}</td></tr>` +
                        `<tr><td>Forbrug / graddag</td><td class="text-end">${kwhPerHdd.toFixed(2)} kWh/GD</td></tr>`;

                    // Expected vs. actual — only when a baseline has been computed from compare mode
                    if (baselineKwhPerHdd !== null && totalHDD > 0) {
                        const expectedKwh = baselineKwhPerHdd * totalHDD;
                        const diffPct     = (total - expectedKwh) / expectedKwh * 100;
                        const sign        = diffPct >= 0 ? '+' : '';
                        const diffClass   = diffPct >  5 ? 'text-danger'
                                          : diffPct < -5 ? 'text-success' : '';
                        weatherRows +=
                            `<tr><td>Forventet (vejrbaseret)</td><td class="text-end">${expectedKwh.toFixed(1)} kWh</td></tr>` +
                            `<tr><td>Faktisk vs. forventet</td><td class="text-end ${diffClass}">${sign}${diffPct.toFixed(1)}%</td></tr>`;
                    }
                } else {
                    weatherRows =
                        `<tr><td>Gradedage (HDD 17°C)</td>` +
                        `<td class="text-end text-muted"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></td></tr>` +
                        `<tr><td>Forbrug / graddag</td><td class="text-end text-muted">—</td></tr>`;
                }
            }

            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <tr><td>Total</td><td class="text-end fw-bold">${total.toFixed(1)} kWh</td></tr>
                    <tr><td>Gennemsnit</td><td class="text-end">${avg.toFixed(1)} kWh</td></tr>
                    <tr><td>Højeste</td><td class="text-end">${max.toFixed(1)} kWh (${maxLabel})</td></tr>
                    <tr><td>Perioder</td><td class="text-end">${data.length}</td></tr>
                    ${costRows}
                    ${weatherRows}
                    ${_renderCopRows(data, total)}
                </table>`;
        }

        // Render COP estimate rows for daily/monthly stats.
        // Only shown when temperature data is available (weatherSettingsReady + populated map).
        function _renderCopRows(data, totalKwh) {
            if (!weatherSettingsReady()) return '';
            const hasTempData = Object.keys(activeTemperatureMap).length > 0;
            if (!hasTempData) {
                // Placeholder — same row count as loaded state to avoid layout shift
                return `<tr><td>Estimeret COP</td>` +
                    `<td class="text-end text-muted"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></td></tr>` +
                    `<tr><td>Estimeret leveret varme</td><td class="text-end text-muted">—</td></tr>`;
            }
            const meanTemp = _periodMeanTemp(data);
            if (meanTemp === null) return '';
            const cop = _estimateCop(meanTemp);
            if (cop === null) return '';
            const deliveredKwh = totalKwh * cop;
            const fmt = v => new Intl.NumberFormat('da-DK', { maximumFractionDigits: 1 }).format(v);
            return `<tr><td>Estimeret COP</td><td class="text-end">${cop.toFixed(1)}</td></tr>` +
                   `<tr><td>Estimeret leveret varme</td><td class="text-end">${fmt(deliveredKwh)}\u00a0kWh</td></tr>`;
        }

        function renderCompareStats(data) {
            // Build per-year totals
            const years = [...new Set(data.map(d => d.year))].sort();
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

            // Per-year COP estimate (requires activeTemperatureMap)
            const hasTempData = weatherSettingsReady() && Object.keys(activeTemperatureMap).length > 0;
            const yearCops = new Map();
            if (hasTempData) {
                years.forEach(year => {
                    // Collect all daily temps for this year from activeTemperatureMap
                    const temps = Object.entries(activeTemperatureMap)
                        .filter(([date]) => date.startsWith(year + '-'))
                        .map(([, t]) => t);
                    if (temps.length === 0) return;
                    const meanTemp = temps.reduce((a, b) => a + b, 0) / temps.length;
                    const cop = _estimateCop(meanTemp);
                    if (cop !== null) yearCops.set(year, cop);
                });
            }
            const hasCop = yearCops.size > 0;

            const rows  = years.map((year, i) => {
                const color   = YEAR_COLORS[i % YEAR_COLORS.length];
                const total   = yearTotals.get(year);
                const months  = yearCounts.get(year);
                const costCell = hasCosts && yearCosts.get(year) > 0
                    ? `<td class="text-end text-muted" style="font-size:11px">${appUtils.formatCurrency(yearCosts.get(year))}</td>`
                    : (hasCosts ? '<td></td>' : '');
                const cop    = yearCops.get(year);
                const copCell = hasCop
                    ? `<td class="text-end text-muted" style="font-size:11px">${cop != null ? 'COP ' + cop.toFixed(1) : '—'}</td>`
                    : '';
                return `<tr>
                    <td><span class="year-dot" style="background:${color}"></span>${year}</td>
                    <td class="text-end fw-bold">${total.toFixed(1)} kWh</td>
                    <td class="text-end text-muted" style="font-size:11px">${months} mdr.</td>
                    ${costCell}${copCell}
                </tr>`;
            }).join("");

            const costHeader = hasCosts ? '<th class="text-end">Est. kr</th>' : '';
            const copHeader  = hasCop   ? '<th class="text-end">COP</th>'     : '';
            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>År</th><th class="text-end">Total</th><th class="text-end">Mdr.</th>${costHeader}${copHeader}
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;

            // Compute baseline kWh/GD from compare data for use in expected-vs-actual (daily/monthly stats)
            if (weatherSettingsReady() && Object.keys(activeWeatherMap).length > 0) {
                let totalBaseKwh = 0, totalBaseHdd = 0;
                data.forEach(d => {
                    const monthPad = String(d.month || '').padStart(2, '0');
                    const prefix   = `${d.year}-${monthPad}`;
                    const mHdd = Object.entries(activeWeatherMap)
                        .filter(([date]) => date.startsWith(prefix))
                        .reduce((s, [, hdd]) => s + hdd, 0);
                    if (mHdd >= 5) { // exclude near-zero summer months (consistent with normalize())
                        totalBaseKwh += parseFloat(d.total_kwh);
                        totalBaseHdd += mHdd;
                    }
                });
                if (totalBaseHdd > 0) baselineKwhPerHdd = totalBaseKwh / totalBaseHdd;
            }
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
        fetchWeatherData();
        fetchSyncStatus();
        initBaseline(); // seeds kWh/GD baseline from compare data without user needing to visit compare mode
    }

    return { init };
})();

window.jordvarmeApp = jordvarmeApp;
