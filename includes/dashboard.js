/**
 * SparkSpend Dashboard
 *
 * Loads summary data from api.php?action=dashboard and renders the two
 * overview cards (EV + Jordvarme) with mini sparkline bar charts.
 * Clicking a card navigates to the relevant section via SparkNav.
 */

let _evSparkChart  = null;
let _hpSparkChart  = null;
let _husSparkChart = null;
let _hpWeatherAbort = null;
let _costEnrichAbort = null;
let _dashRefreshInterval = null;

async function loadDashboard() {
    const cards = ['ev-dashboard-card', 'hp-dashboard-card', 'hus-dashboard-card'];
    cards.forEach(id => document.getElementById(id)?.classList.add('dash-loading'));
    try {
        const res = await fetch('api.php?action=dashboard');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        renderEvCard(data.ev);
        renderHeatpumpCard(data.heatpump);
        if (data.hus) {
            renderHusCard(data.hus);
        } else {
            const husCard = document.getElementById('hus-dashboard-card');
            if (husCard) husCard.querySelector('.dash-body').innerHTML =
                '<p class="text-muted text-center small py-3">Hus-data ikke synkroniseret endnu</p>';
        }
        // Anomaly detection runs after card data is available (async, best-effort)
        loadAnomalies();
        // Async cost enrichment for VP and Hus cards
        _enrichCardsWithCost(data.heatpump, data.hus || null);
        // Elspot day-ahead forecast widget
        loadElspotForecast();
        // Sync-status enrichment — async, non-blocking
        _enrichSyncLabels();
    } catch (e) {
        console.error('Dashboard load error:', e);
        ['ev-dashboard-card', 'hp-dashboard-card', 'hus-dashboard-card'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.querySelector('.dash-body').innerHTML =
                '<p class="text-muted text-center small py-3">Data ikke tilgængeligt</p>';
        });
    } finally {
        cards.forEach(id => document.getElementById(id)?.classList.remove('dash-loading'));
    }
}

function renderEvCard(ev) {
    const card = document.getElementById('ev-dashboard-card');
    if (!card) return;

    const now   = new Date();
    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-charges', ev.month_charges + ' ladninger');
    _setText(card, '.dash-stat-kwh', ev.month_kwh.toFixed(1) + ' kWh');
    _setText(card, '.dash-stat-cost', ev.month_cost.toFixed(0) + ' kr');
    _setText(card, '.dash-stat-cpkwh', ev.cost_per_kwh !== null ? ev.cost_per_kwh.toFixed(2) + ' kr/kWh' : '—');
    _setText(card, '.dash-projected', ev.projected_cost !== null ? 'Forventet: ' + ev.projected_cost + ' kr' : '');

    // Home / external split bar
    const splitWrap = card.querySelector('.dash-split-wrap');
    if (splitWrap && ev.month_kwh > 0) {
        const homePct = ev.home_kwh / ev.month_kwh * 100;
        const extPct  = ev.ext_kwh  / ev.month_kwh * 100;
        const homeFill = splitWrap.querySelector('.dash-split-home-fill');
        const extFill  = splitWrap.querySelector('.dash-split-ext-fill');
        if (homeFill) homeFill.style.width = homePct.toFixed(1) + '%';
        if (extFill)  extFill.style.width  = extPct.toFixed(1)  + '%';
        const homeLbl = splitWrap.querySelector('.dash-split-home-lbl');
        const extLbl  = splitWrap.querySelector('.dash-split-ext-lbl');
        if (homeLbl) homeLbl.textContent = 'Hjemme' + (ev.home_cpkwh !== null ? ' · ' + ev.home_cpkwh.toFixed(2) + '\u00a0kr/kWh' : '');
        if (extLbl)  extLbl.textContent  = ev.ext_kwh > 0 ? 'Ude' + (ev.ext_cpkwh !== null ? ' · ' + ev.ext_cpkwh.toFixed(2) + '\u00a0kr/kWh' : '') : '';
        splitWrap.style.display = '';
    }

    const showTrends = now.getDate() >= 5;
    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        let html = '';
        if (showTrends && ev.pct_change_kwh !== null) {
            const up = ev.pct_change_kwh >= 0;
            const prevKwh = Math.round(ev.month_kwh / (1 + ev.pct_change_kwh / 100)) || 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_kwh)}% kWh vs. forrige mdr (${prevKwh}→${Math.round(ev.month_kwh)})</div>`;
        }
        if (showTrends && ev.pct_change_cost !== null) {
            const up = ev.pct_change_cost >= 0;
            const prevCost = Math.round(ev.month_cost / (1 + ev.pct_change_cost / 100)) || 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_cost)}% pris vs. forrige mdr (${prevCost}→${Math.round(ev.month_cost)} kr)</div>`;
        }
        if (showTrends && ev.pct_change_year_kwh !== null) {
            const up = ev.pct_change_year_kwh >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_year_kwh)}% kWh vs. sidste år</div>`;
        }
        if (showTrends && ev.pct_change_year_cost !== null) {
            const up = ev.pct_change_year_cost >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_year_cost)}% pris vs. sidste år</div>`;
        }
        // Driver note: flag if external charging share shifted significantly vs. prev period
        if (showTrends && ev.prev_ext_kwh_pct !== null && ev.month_kwh > 0) {
            const curExtPct  = Math.round(ev.ext_kwh / ev.month_kwh * 100);
            const ppChange   = Math.round(curExtPct - ev.prev_ext_kwh_pct);
            if (Math.abs(ppChange) >= 5) {
                const up = ppChange > 0;
                html += `<div class="trend-neutral">` +
                        `Udeladning: ${curExtPct}% af kWh (${up ? '↑' : '↓'}${Math.abs(ppChange)}\u00a0pp) ` +
                        `— ${up ? 'trækker gennemsnitsprisen op' : 'trækker gennemsnitsprisen ned'}</div>`;
            }
        }
        trendEl.innerHTML = html;
    }

    const evSparkStart = new Date(); evSparkStart.setDate(evSparkStart.getDate() - 29); evSparkStart.setHours(0,0,0,0);
    _evSparkChart = _renderSparkline('ev-sparkline', ev.sparkline, '#6BA3FF', _evSparkChart, evSparkStart);
    _renderProgress('ev-budget-progress', 'ev-budget-fill', 'ev-budget-label',
        ev.month_cost, localStorage.getItem('sparkspend_budget_ev_kr'), 'kr');
}

function renderHeatpumpCard(hp) {
    const card = document.getElementById('hp-dashboard-card');
    if (!card) return;

    const now   = new Date();
    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-kwh', hp.month_kwh.toFixed(1) + ' kWh');
    _setText(card, '.dash-stat-daily', hp.daily_avg_kwh !== null ? hp.daily_avg_kwh.toFixed(1) + ' kWh' : '—');
    _setText(card, '.dash-projected', hp.projected_kwh !== null ? 'Forventet: ' + hp.projected_kwh.toFixed(1) + ' kWh' : '');

    const showTrends = now.getDate() >= 5;
    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        let html = '';
        if (showTrends && hp.pct_change !== null) {
            const up = hp.pct_change >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ` +
                    `${Math.abs(hp.pct_change)}% vs. forrige mdr</div>`;
        }
        if (showTrends && hp.pct_change_year !== null) {
            const up = hp.pct_change_year >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ` +
                    `${Math.abs(hp.pct_change_year)}% vs. sidste år</div>`;
        }
        trendEl.innerHTML = html;
        trendEl.className = 'dash-trend';
    }

    const hpSparkStart = new Date(); hpSparkStart.setDate(hpSparkStart.getDate() - 29); hpSparkStart.setHours(0,0,0,0);
    _hpSparkChart = _renderSparkline('hp-sparkline', hp.sparkline, '#22c55e', _hpSparkChart, hpSparkStart);
    _renderProgress('hp-budget-progress', 'hp-budget-fill', 'hp-budget-label',
        hp.month_kwh, localStorage.getItem('sparkspend_budget_hp_kwh'), 'kWh');

    // Abort any in-flight weather enrichment from a previous render
    if (_hpWeatherAbort) { _hpWeatherAbort.abort(); _hpWeatherAbort = null; }
    // Enrich with weather-normalised year-over-year trend (async, non-blocking)
    _enrichHpWithWeather(card, hp);
}

function renderHusCard(hus) {
    const card = document.getElementById('hus-dashboard-card');
    if (!card) return;

    const now   = new Date();
    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-kwh', hus.month_kwh.toFixed(1) + ' kWh');
    _setText(card, '.dash-stat-daily', hus.daily_avg_kwh !== null ? hus.daily_avg_kwh.toFixed(1) + ' kWh' : '—');
    _setText(card, '.dash-projected', hus.projected_kwh !== null ? 'Forventet: ' + hus.projected_kwh.toFixed(1) + ' kWh' : '');

    const showTrends = now.getDate() >= 5;
    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        let html = '';
        if (showTrends && hus.pct_change !== null) {
            const up = hus.pct_change >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ` +
                    `${Math.abs(hus.pct_change)}% vs. forrige mdr</div>`;
        }
        if (showTrends && hus.pct_change_year !== null) {
            const up = hus.pct_change_year >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ` +
                    `${Math.abs(hus.pct_change_year)}% vs. sidste år</div>`;
        }
        trendEl.innerHTML = html;
        trendEl.className = 'dash-trend';
    }

    // Hus composition split bar: EV home (blue) / Jordvarme (green) / Rest (amber)
    const splitWrap = card.querySelector('#hus-split-wrap');
    if (splitWrap && hus.month_kwh > 0 && hus.ev_home_kwh != null && hus.hp_kwh != null) {
        const evKwh   = Math.min(hus.ev_home_kwh, hus.month_kwh);
        const hpKwh   = Math.min(hus.hp_kwh, hus.month_kwh - evKwh);
        const restKwh = Math.max(0, hus.month_kwh - evKwh - hpKwh);
        const evPct   = evKwh   / hus.month_kwh * 100;
        const hpPct   = hpKwh   / hus.month_kwh * 100;
        const restPct = restKwh / hus.month_kwh * 100;
        const evFill   = splitWrap.querySelector('.dash-split-ev-fill');
        const hpFill   = splitWrap.querySelector('.dash-split-hp-fill');
        const restFill = splitWrap.querySelector('.dash-split-rest-fill');
        if (evFill)   evFill.style.width   = evPct.toFixed(1)   + '%';
        if (hpFill)   hpFill.style.width   = hpPct.toFixed(1)   + '%';
        if (restFill) restFill.style.width  = restPct.toFixed(1) + '%';
        const evLbl   = splitWrap.querySelector('.dash-split-ev-lbl');
        const restLbl = splitWrap.querySelector('.dash-split-rest-lbl');
        if (evLbl)   evLbl.textContent   = evPct   >= 5 ? 'EV ' + evPct.toFixed(0)   + '%' : '';
        if (restLbl) restLbl.textContent = restPct >= 5 ? 'Rest ' + restPct.toFixed(0) + '%' : '';
        splitWrap.style.display = '';
    }

    const husSparkStart = new Date(); husSparkStart.setDate(husSparkStart.getDate() - 29); husSparkStart.setHours(0,0,0,0);
    _husSparkChart = _renderSparkline('hus-sparkline', hus.sparkline, '#f59e0b', _husSparkChart, husSparkStart);
    _renderProgress('hus-budget-progress', 'hus-budget-fill', 'hus-budget-label',
        hus.month_kwh, localStorage.getItem('sparkspend_budget_hus_kwh'), 'kWh');
}

async function _enrichHpWithWeather(card, hp) {
    const lat = window.sparkConfig?.weatherLat || '';
    const lon = window.sparkConfig?.weatherLon || '';
    const lastYearKwh = hp.last_year_period_kwh ?? null;
    if (!lat || !lon || hp.month_kwh === null || lastYearKwh === null || lastYearKwh <= 0) return;

    const controller = new AbortController();
    _hpWeatherAbort  = controller;

    const now       = new Date();
    const thisYear  = now.getFullYear();
    const thisMonth = now.getMonth() + 1;
    const lastYear  = thisYear - 1;
    const mm        = String(thisMonth).padStart(2, '0');
    const today     = now.toISOString().slice(0, 10);
    const ddNow     = String(now.getDate()).padStart(2, '0'); // same day last year → MTD comparison

    try {
        const [resThis, resLast] = await Promise.all([
            fetch(`api.php?action=weather&start=${thisYear}-${mm}-01&end=${today}&lat=${lat}&lon=${lon}`, { signal: controller.signal }).then(r => r.json()),
            fetch(`api.php?action=weather&start=${lastYear}-${mm}-01&end=${lastYear}-${mm}-${ddNow}&lat=${lat}&lon=${lon}`, { signal: controller.signal }).then(r => r.json()),
        ]);

        const hddThis = (resThis.records  || []).reduce((s, r) => s + r.hdd, 0);
        const hddLast = (resLast.records  || []).reduce((s, r) => s + r.hdd, 0);
        if (hddThis <= 0 || hddLast <= 0) return;

        const normalizedPct = Math.round(((hp.month_kwh / hddThis) / (lastYearKwh / hddLast) - 1) * 100);

        const trendEl = card.querySelector('.dash-trend');
        if (!trendEl) return;
        const up = normalizedPct >= 0;
        trendEl.innerHTML +=
            `<div class="${up ? 'trend-up' : 'trend-down'}" title="Justeret for graddage (HDD 17°C)">` +
            `${up ? '↑' : '↓'} ${Math.abs(normalizedPct)}% vejrkorrigeret vs. samme måned sidste år</div>`;
    } catch (e) {
        if (e.name !== 'AbortError') console.warn('_enrichHpWithWeather:', e);
    }
}

function _renderProgress(wrapId, fillId, labelId, current, goalStr, unit) {
    const wrap  = document.getElementById(wrapId);
    const fill  = document.getElementById(fillId);
    const label = document.getElementById(labelId);
    if (!wrap || !fill || !label) return;
    const goal = goalStr !== null ? parseFloat(goalStr) : NaN;
    if (isNaN(goal) || goal <= 0) { wrap.style.display = 'none'; return; }
    const pct = Math.min(current / goal * 100, 100);
    wrap.style.display = '';
    fill.style.width = pct.toFixed(1) + '%';
    fill.className = 'dash-progress-fill ' + (pct >= 100 ? 'over' : pct >= 85 ? 'near' : 'under');
    const statusText = current.toFixed(0) + ' / ' + goal.toFixed(0) + ' ' + unit +
                        (pct >= 100 ? ' — mål overskredet' : '');
    label.innerHTML = (window.appUtils?.escapeHtml(statusText) ?? statusText) +
        ' &nbsp;<button type="button" class="dash-goal-btn" ' +
        'onclick="event.stopPropagation();' +
        'var m=document.getElementById(\'budgetModal\');if(m)new bootstrap.Modal(m).show()">Rediger mål</button>';
}

function _renderSparkline(canvasId, dataPoints, color, existing, monthStart = null) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;
    if (existing) existing.destroy();

    return new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: dataPoints.map((_, i) => i),
            datasets: [{
                data: dataPoints,
                backgroundColor: color + 'aa',
                borderColor:     color,
                borderWidth: 1,
                borderRadius: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend:  { display: false },
                tooltip: monthStart ? {
                    enabled: true,
                    callbacks: {
                        title: (items) => {
                            const d = new Date(monthStart);
                            d.setDate(d.getDate() + items[0].dataIndex);
                            return d.toLocaleDateString('da-DK', { day: 'numeric', month: 'short' });
                        },
                        label: (item) => item.raw.toFixed(1) + ' kWh'
                    }
                } : { enabled: false },
                datalabels: { display: false }
            },
            scales: {
                x: { display: false },
                y: { display: false, beginAtZero: true }
            },
            animation: { duration: 500 }
        }
    });
}

let _forecastChart = null;

async function loadElspotForecast() {
    const area = window.sparkConfig?.elspotArea || '';
    const gln  = window.sparkConfig?.elspotGln  || '';
    const card = document.getElementById('elspot-forecast-card');
    if (!area || !gln || !card) return;

    const now      = new Date();
    const today    = now.toISOString().slice(0, 10);
    const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
    const tmrStr   = tomorrow.toISOString().slice(0, 10);

    try {
        const res = await fetch(
            `api.php?action=elspot-prices&start=${today}&end=${tmrStr}` +
            `&area=${encodeURIComponent(area)}&gln=${encodeURIComponent(gln)}&format=hourly`
        );
        if (!res.ok) return;
        const data  = await res.json();
        const hours = (data.records || []).filter(r => r.date === tmrStr).sort((a, b) => a.hour - b.hour);
        if (hours.length === 0) {
            // Day-ahead prices not yet published — show informational message
            card.style.display = '';
            const chartEl = document.getElementById('elspot-forecast-chart');
            if (chartEl) chartEl.innerHTML = '<p class="text-muted text-center py-3">Morgendagens priser offentliggøres normalt ca. kl. 13:00</p>';
            const windowEl = document.getElementById('elspot-cheapest-window');
            if (windowEl) windowEl.innerHTML = '';
            const meta = document.getElementById('elspot-forecast-meta');
            if (meta) meta.textContent = tmrStr;
            return;
        }

        card.style.display = '';
        const meta = document.getElementById('elspot-forecast-meta');
        if (meta) meta.textContent = tmrStr + ' · ' + hours.length + ' timer';

        // Find cheapest consecutive 2-hour window
        let bestIdx = 0, bestSum = Infinity;
        for (let i = 0; i <= hours.length - 2; i++) {
            const s = hours[i].kr_kwh + hours[i + 1].kr_kwh;
            if (s < bestSum) { bestSum = s; bestIdx = i; }
        }
        const cheapH1   = hours[bestIdx].hour;
        const cheapH2   = hours[bestIdx + 1].hour;
        const cheapAvg  = bestSum / 2;
        const cheapEl   = document.getElementById('elspot-cheapest-window');
        if (cheapEl) {
            cheapEl.innerHTML =
                `<span class="badge bg-success me-1"><i class="fas fa-bolt"></i></span>` +
                `Billigste 2-timers ladevindue: <strong>${String(cheapH1).padStart(2,'0')}:00–${String(cheapH2 + 1).padStart(2,'0')}:00</strong>` +
                ` · gns. ${cheapAvg.toFixed(3)} kr/kWh`;
        }

        // ApexCharts bar chart
        const chartEl = document.getElementById('elspot-forecast-chart');
        if (!chartEl) return;

        const minPrice = Math.min(...hours.map(h => h.kr_kwh));
        const maxPrice = Math.max(...hours.map(h => h.kr_kwh));
        const range    = maxPrice - minPrice || 1;

        const colors = hours.map(h => {
            const t = (h.kr_kwh - minPrice) / range; // 0=cheap, 1=expensive
            if (t < 0.33) return '#22c55e';
            if (t < 0.66) return '#f59e0b';
            return '#ef4444';
        });

        if (_forecastChart) { _forecastChart.destroy(); _forecastChart = null; }
        _forecastChart = new ApexCharts(chartEl, {
            series: [{ name: 'kr/kWh', data: hours.map(h => Math.round(h.kr_kwh * 1000) / 1000) }],
            chart:  { type: 'bar', height: 180, toolbar: { show: false }, animations: { enabled: false } },
            colors: colors,
            plotOptions: { bar: { distributed: true, borderRadius: 2, columnWidth: '80%' } },
            dataLabels: { enabled: false },
            legend:     { show: false },
            xaxis: {
                categories: hours.map(h => String(h.hour).padStart(2, '0') + ':00'),
                labels: { rotate: -45, style: { fontSize: '9px' } },
                tickAmount: 6,
            },
            yaxis: { labels: { formatter: v => v.toFixed(2) }, title: { text: 'kr/kWh', style: { fontSize: '11px' } } },
            tooltip: {
                y: { formatter: v => v.toFixed(3) + ' kr/kWh' },
                x: { formatter: (_, { dataPointIndex }) => {
                    const h = hours[dataPointIndex];
                    return `${String(h.hour).padStart(2,'0')}:00–${String(h.hour+1).padStart(2,'0')}:00`;
                }}
            },
            annotations: {
                xaxis: [{
                    x: String(cheapH1).padStart(2, '0') + ':00',
                    x2: String(cheapH2).padStart(2, '0') + ':00',
                    fillColor: '#22c55e',
                    opacity: 0.15,
                    label: { text: 'Billigst', style: { color: '#166534', background: '#dcfce7', fontSize: '10px' } }
                }]
            }
        });
        _forecastChart.render();
    } catch (e) {
        console.warn('loadElspotForecast:', e);
    }
}

async function _enrichCardsWithCost(hp, hus) {
    const area = window.sparkConfig?.elspotArea || '';
    const gln  = window.sparkConfig?.elspotGln  || '';
    if (!area || !gln) return;

    if (_costEnrichAbort) { _costEnrichAbort.abort(); }
    const controller = new AbortController();
    _costEnrichAbort = controller;

    const now   = new Date();
    const mm    = String(now.getMonth() + 1).padStart(2, '0');
    const start = `${now.getFullYear()}-${mm}-01`;
    const end   = now.toISOString().slice(0, 10);

    try {
        const res = await fetch(
            `api.php?action=elspot-prices&start=${start}&end=${end}&area=${encodeURIComponent(area)}&gln=${encodeURIComponent(gln)}`,
            { signal: controller.signal }
        );
        if (!res.ok) return;
        const data = await res.json();
        const records = (data.records || []).filter(r => r.kr_kwh > 0);
        if (records.length === 0) return;
        const avgKrKwh = records.reduce((s, r) => s + r.kr_kwh, 0) / records.length;

        // Jordvarme — HP procesformål rate (accurate)
        if (hp && hp.month_kwh > 0) {
            const cost = Math.round(hp.month_kwh * avgKrKwh);
            const el = document.getElementById('hp-cost-stat');
            if (el) {
                el.querySelector('.dash-est-cost').textContent = cost + ' kr';
                el.title = `Estimeret: ${hp.month_kwh.toFixed(1)} kWh × ${avgKrKwh.toFixed(3)} kr/kWh\n(inkl. systemtarif, nettarif, procesformål elafgift og moms)`;
                el.style.display = '';
            }
        }
        // Hus — same rate, approksimation (boligelafgift er højere end procesformål)
        if (hus && hus.month_kwh > 0) {
            const cost = Math.round(hus.month_kwh * avgKrKwh);
            const el = document.getElementById('hus-cost-stat');
            if (el) {
                el.querySelector('.dash-est-cost').textContent = cost + ' kr';
                el.title = `Estimeret: ${hus.month_kwh.toFixed(1)} kWh × ${avgKrKwh.toFixed(3)} kr/kWh\n(approksimation — bruger VP-elpriser; boligelafgift er højere)`;
                el.style.display = '';
            }
        }
    } catch (e) {
        if (e.name !== 'AbortError') console.warn('_enrichCardsWithCost:', e);
    }
}

async function _enrichSyncLabels() {
    try {
        const res  = await fetch('api.php?action=sync-status');
        if (!res.ok) return;
        const data = await res.json();
        if (!Array.isArray(data.sources)) return;

        const idMap = { monta: 'ev', heatpump: 'hp', housepowerlog: 'hus', hus: 'hus' };
        const now   = Date.now();

        data.sources.forEach(src => {
            const cardPrefix = idMap[src.id];
            if (!cardPrefix) return;
            const el = document.getElementById(cardPrefix + '-sync-label');
            if (!el) return;

            if (!src.last_sync) { el.textContent = ''; return; }

            const syncMs  = new Date(src.last_sync.replace(' ', 'T')).getTime();
            const diffMin = Math.round((now - syncMs) / 60000);
            let label;
            if (diffMin < 2)       label = 'Data: lige nu';
            else if (diffMin < 60) label = `Data: ${diffMin}\u00a0min siden`;
            else if (diffMin < 120) label = 'Data: over 1 time gammel';
            else {
                const h = Math.round(diffMin / 60);
                label = `Data: ${h}\u00a0timer gammel`;
            }

            el.textContent  = label;
            el.className    = 'dash-sync' + (diffMin >= 240 ? ' dash-sync-stale' : '');
        });
    } catch (e) {
        // Best-effort — never surface errors
    }
}

function _monthName() {
    const raw = new Date().toLocaleString('da-DK', { month: 'long', year: 'numeric' });
    return raw.charAt(0).toUpperCase() + raw.slice(1);
}

function _setText(parent, selector, text) {
    const el = parent.querySelector(selector);
    if (el) el.textContent = text;
}

document.addEventListener('DOMContentLoaded', () => {
    // loadDashboard() is called lazily by SparkNav when Oversigt tab is first activated.
    // Re-fetch whenever a charge is saved so totals stay current.
    window.SparkEvents?.addEventListener('charge:saved', loadDashboard);
});

// =============================================================================
// Anomaly Detection
// =============================================================================

async function loadAnomalies() {
    try {
        const res  = await fetch('api.php?action=anomalies');
        if (!res.ok) return;
        const data = await res.json();
        if (data.error || !data.anomalies) return;

        _renderAnomalies(data.anomalies);
    } catch (e) {
        // Anomaly loading is best-effort — never surface errors to the user
        console.warn('loadAnomalies:', e);
    }
}

function _renderAnomalies(anomalies) {
    const panel    = document.getElementById('anomaly-panel');
    const listEl   = document.getElementById('anomaly-list');
    if (!panel || !listEl) return;

    // Only show warning/critical in the panel (suppress info)
    const visible = anomalies.filter(a => a.severity !== 'info');

    // Clear all tab badges first
    ['ev', 'hp', 'hus'].forEach(cat => {
        const el = document.getElementById('anomaly-badge-' + cat);
        if (el) el.style.display = 'none';
    });

    if (visible.length === 0) {
        panel.style.display = 'none';
        return;
    }

    // Update tab badge dots (always, even if panel is dismissed)
    anomalies.forEach(a => {
        const el = document.getElementById('anomaly-badge-' + a.category);
        if (el && a.severity !== 'info') {
            el.className = 'anomaly-tab-badge ' + a.severity;
            el.style.display = '';
        }
    });

    // Check if user has dismissed these exact anomalies this month
    if (_isAnomalyDismissed(visible)) {
        panel.style.display = 'none';
        return;
    }

    // Colour the panel header based on worst severity
    const hasCritical = visible.some(a => a.severity === 'critical');
    panel.className = 'anomaly-panel' + (hasCritical ? ' has-critical' : '');

    const iconMap = {
        critical: '<i class="fas fa-times-circle"></i>',
        warning:  '<i class="fas fa-exclamation-triangle"></i>',
        info:     '<i class="fas fa-info-circle"></i>',
    };

    listEl.innerHTML = visible.map(a => {
        const navAttr = a.nav_sub
            ? `onclick="SparkNav.navigateTo('${a.nav_section}', '${a.nav_sub}')"`
            : `onclick="SparkNav.navigateTo('${a.nav_section}')"`;
        const causeHtml = a.possible_cause
            ? `<span class="anomaly-cause anomaly-cause-${a.possible_cause}">${a.possible_cause === 'vejr' ? '\u2601\ufe0f Vejret kan v\u00e6re \u00e5rsagen' : '\ud83d\udd0d Tjek forbrugsmm\u00f8ster'}</span>`
            : '';
        return `
        <div class="anomaly-item">
          <div class="anomaly-item-icon ${a.severity}">${iconMap[a.severity]}</div>
          <div class="anomaly-item-body">
            <div class="anomaly-item-title">${appUtils.escapeHtml(a.title)} — ${a.pct_above}% over baseline</div>
            <div class="anomaly-item-msg">${appUtils.escapeHtml(a.message)}</div>
            ${causeHtml}
          </div>
          <button class="anomaly-item-action" ${navAttr}>Gå til →</button>
        </div>`;
    }).join('');

    // Add dismiss button
    let dismissBtn = panel.querySelector('.anomaly-dismiss-btn');
    if (!dismissBtn) {
        dismissBtn = document.createElement('button');
        dismissBtn.className = 'anomaly-dismiss-btn';
        dismissBtn.innerHTML = '<i class="fas fa-times"></i> Afvis';
        dismissBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            _dismissAnomalies(visible);
        });
        panel.appendChild(dismissBtn);
    } else {
        // Re-bind with current visible list
        dismissBtn.onclick = (e) => { e.stopPropagation(); _dismissAnomalies(visible); };
    }

    panel.style.display = '';
}

// =============================================================================
// Dashboard Auto-Refresh (5 minutes, only when Oversigt tab is active + visible)
// =============================================================================

function startDashboardAutoRefresh() {
    if (_dashRefreshInterval) return;
    _dashRefreshInterval = setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadDashboard();
        }
    }, 300_000); // 5 minutes
}

function stopDashboardAutoRefresh() {
    if (_dashRefreshInterval) {
        clearInterval(_dashRefreshInterval);
        _dashRefreshInterval = null;
    }
}

// =============================================================================
// Anomaly Dismiss — persistent until new month or severity change
// =============================================================================

function _getAnomalyDismissKey() {
    return 'anomaly_dismissed_' + new Date().toISOString().slice(0, 7);
}

function _isAnomalyDismissed(anomalies) {
    const key = _getAnomalyDismissKey();
    const dismissed = localStorage.getItem(key);
    if (!dismissed) return false;
    const currentSig = anomalies.map(a => a.category + ':' + a.severity).sort().join('|');
    return dismissed === currentSig;
}

function _dismissAnomalies(anomalies) {
    const key = _getAnomalyDismissKey();
    const sig = anomalies.map(a => a.category + ':' + a.severity).sort().join('|');
    localStorage.setItem(key, sig);
    const panel = document.getElementById('anomaly-panel');
    if (panel) panel.style.display = 'none';
    // Don't show "Ingen afvigelser" after dismiss — that would be misleading.
    // Tab badges remain visible as a subtle reminder.
}
