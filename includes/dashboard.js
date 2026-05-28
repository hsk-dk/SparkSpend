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
    _setText(card, '.dash-split', ev.home_kwh_pct !== null ? ev.home_kwh_pct.toFixed(0) + '% hjemme' : '');
    _setText(card, '.dash-projected', ev.projected_cost !== null ? 'Forventet: ' + ev.projected_cost + ' kr' : '');

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
        trendEl.innerHTML = html;
    }

    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    _evSparkChart = _renderSparkline('ev-sparkline', ev.sparkline, '#6BA3FF', _evSparkChart, monthStart);
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

    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    _hpSparkChart = _renderSparkline('hp-sparkline', hp.sparkline, '#22c55e', _hpSparkChart, monthStart);

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

    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    _husSparkChart = _renderSparkline('hus-sparkline', hus.sparkline, '#f59e0b', _husSparkChart, monthStart);
}

async function _enrichHpWithWeather(card, hp) {
    const lat = window.sparkConfig?.weatherLat || '';
    const lon = window.sparkConfig?.weatherLon || '';
    const lastYearKwh = hp.last_year_period_kwh ?? null;
    if (!lat || !lon || hp.month_kwh === null || lastYearKwh === null || lastYearKwh <= 0) return;

    const now       = new Date();
    const thisYear  = now.getFullYear();
    const thisMonth = now.getMonth() + 1;
    const lastYear  = thisYear - 1;
    const mm        = String(thisMonth).padStart(2, '0');
    const today     = now.toISOString().slice(0, 10);
    const ddNow     = String(now.getDate()).padStart(2, '0'); // same day last year → MTD comparison

    try {
        const [resThis, resLast] = await Promise.all([
            fetch(`getWeatherData.php?start=${thisYear}-${mm}-01&end=${today}&lat=${lat}&lon=${lon}`).then(r => r.json()),
            fetch(`getWeatherData.php?start=${lastYear}-${mm}-01&end=${lastYear}-${mm}-${ddNow}&lat=${lat}&lon=${lon}`).then(r => r.json()),
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
        // Silently ignore — weather enrichment is best-effort
    }
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
