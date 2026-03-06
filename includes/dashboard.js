/**
 * SparkSpend Dashboard
 *
 * Loads summary data from getDashboardSummary.php and renders the two
 * overview cards (EV + Jordvarme) with mini sparkline bar charts.
 * Clicking a card navigates to the relevant section via SparkNav.
 */

let _evSparkChart  = null;
let _hpSparkChart  = null;

async function loadDashboard() {
    try {
        const res = await fetch('getDashboardSummary.php');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        renderEvCard(data.ev);
        renderHeatpumpCard(data.heatpump);
    } catch (e) {
        console.error('Dashboard load error:', e);
        ['ev-dashboard-card', 'hp-dashboard-card'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.querySelector('.dash-body').innerHTML =
                '<p class="text-muted text-center small py-3">Data ikke tilgængeligt</p>';
        });
    }
}

function renderEvCard(ev) {
    const card = document.getElementById('ev-dashboard-card');
    if (!card) return;

    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-charges', ev.month_charges + ' ladninger');
    _setText(card, '.dash-stat-kwh', ev.month_kwh.toFixed(1) + ' kWh');
    _setText(card, '.dash-stat-cost', ev.month_cost.toFixed(0) + ' kr');

    _evSparkChart = _renderSparkline(
        'ev-sparkline', ev.sparkline, '#6BA3FF', _evSparkChart
    );
}

function renderHeatpumpCard(hp) {
    const card = document.getElementById('hp-dashboard-card');
    if (!card) return;

    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-kwh', hp.month_kwh.toFixed(1) + ' kWh');

    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        if (hp.pct_change !== null) {
            const up = hp.pct_change >= 0;
            trendEl.innerHTML =
                `<i class="fas fa-arrow-${up ? 'up' : 'down'}"></i> ` +
                `${Math.abs(hp.pct_change)}% vs. forrige måned`;
            trendEl.className = 'dash-trend ' + (up ? 'trend-up' : 'trend-down');
        } else {
            trendEl.textContent = '';
        }
    }

    _hpSparkChart = _renderSparkline(
        'hp-sparkline', hp.sparkline, '#22c55e', _hpSparkChart
    );
}

function _renderSparkline(canvasId, dataPoints, color, existing) {
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
                tooltip: { enabled: false },
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

document.addEventListener('DOMContentLoaded', loadDashboard);
document.addEventListener('DOMContentLoaded', () => {
    window.SparkEvents?.addEventListener('charge:saved', loadDashboard);
});
