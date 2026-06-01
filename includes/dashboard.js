/**
 * SparkSpend Dashboard
 *
 * Loads summary data from getDashboardSummary.php and renders the two
 * overview cards (EV + Jordvarme) with mini sparkline bar charts.
 * Clicking a card navigates to the relevant section via SparkNav.
 */

let _evSparkChart  = null;
let _hpSparkChart  = null;
let _husSparkChart = null;
let _hpWeatherAbort = null;
let _costEnrichAbort = null;

async function loadDashboard() {
    const cards = ['ev-dashboard-card', 'hp-dashboard-card', 'hus-dashboard-card'];
    cards.forEach(id => document.getElementById(id)?.classList.add('dash-loading'));
    try {
        const res = await fetch('getDashboardSummary.php');
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
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_kwh)}% kWh vs. samme periode sidst måned</div>`;
        }
        if (showTrends && ev.pct_change_cost !== null) {
            const up = ev.pct_change_cost >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_cost)}% pris vs. samme periode sidst måned</div>`;
        }
        if (showTrends && ev.pct_change_year_kwh !== null) {
            const up = ev.pct_change_year_kwh >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_year_kwh)}% kWh vs. samme måned sidste år</div>`;
        }
        if (showTrends && ev.pct_change_year_cost !== null) {
            const up = ev.pct_change_year_cost >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ${Math.abs(ev.pct_change_year_cost)}% pris vs. samme måned sidste år</div>`;
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
                    `${Math.abs(hp.pct_change)}% vs. samme periode sidst måned</div>`;
        }
        if (showTrends && hp.pct_change_year !== null) {
            const up = hp.pct_change_year >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ` +
                    `${Math.abs(hp.pct_change_year)}% vs. samme måned sidste år</div>`;
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
                    `${Math.abs(hus.pct_change)}% vs. samme periode sidst måned</div>`;
        }
        if (showTrends && hus.pct_change_year !== null) {
            const up = hus.pct_change_year >= 0;
            html += `<div class="${up ? 'trend-up' : 'trend-down'}">` +
                    `${up ? '↑' : '↓'} ` +
                    `${Math.abs(hus.pct_change_year)}% vs. samme måned sidste år</div>`;
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
            fetch(`getWeatherData.php?start=${thisYear}-${mm}-01&end=${today}&lat=${lat}&lon=${lon}`, { signal: controller.signal }).then(r => r.json()),
            fetch(`getWeatherData.php?start=${lastYear}-${mm}-01&end=${lastYear}-${mm}-${ddNow}&lat=${lat}&lon=${lon}`, { signal: controller.signal }).then(r => r.json()),
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
            `getElspotPrices.php?start=${start}&end=${end}&area=${encodeURIComponent(area)}&gln=${encodeURIComponent(gln)}`,
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
        const res  = await fetch('getSyncStatus.php');
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
        const res  = await fetch('getAnomalyStats.php');
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
        // Show "alt normalt" confirmation from day 5 onwards
        const okEl = document.getElementById('anomaly-ok');
        if (okEl) okEl.style.display = new Date().getDate() >= 5 ? '' : 'none';
        return;
    }
    const okEl2 = document.getElementById('anomaly-ok');
    if (okEl2) okEl2.style.display = 'none';

    // Update tab badge dots
    anomalies.forEach(a => {
        const el = document.getElementById('anomaly-badge-' + a.category);
        if (el && a.severity !== 'info') {
            el.className = 'anomaly-tab-badge ' + a.severity;
            el.style.display = '';
        }
    });

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

    panel.style.display = '';
}
