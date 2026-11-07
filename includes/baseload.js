/**
 * SparkSpend Basislast (Hus > Forbrug)
 *
 * What the house draws when nothing big is running, in watts, per month for the last two years.
 * Calculated on the server (includes/BaseLoad.php); this only displays it.
 */
const baseloadApp = (() => {
    let initialized = false;
    let chart = null;
    const MONTHS = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];

    function init() {
        if (initialized) return;
        initialized = true;
        const headline = document.getElementById('baseloadHeadline');
        const canvas = document.getElementById('baseloadChart');
        if (!headline || !canvas) return;

        fetch('api.php?action=base-load&months=24')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => _render(data, headline, canvas))
            .catch(err => {
                console.error('baseloadApp:', err);
                initialized = false;
                headline.textContent = 'Kunne ikke hente basislasten (' + err.message + ').';
            });
    }

    function _label(month) {
        const [y, m] = month.split('-');
        return MONTHS[Number(m) - 1] + ' ' + y.slice(2);
    }

    function _render(data, headline, canvas) {
        const rows = data.months || [];
        if (!rows.length) { headline.textContent = 'Ikke nok måledata endnu.'; return; }

        // headline: now, and the same month a year ago
        const last = rows[rows.length - 1];
        const [ly, lm] = last.month.split('-').map(Number);
        const yearAgo = rows.find(r => r.month === (ly - 1) + '-' + String(lm).padStart(2, '0'));
        const now = data.latest;
        let html = '';
        if (now) {
            html += `<span class="baseload-now">${now.watts}&nbsp;W</span> ` +
                `<span class="text-muted">lige nu (median af de seneste ${now.days} døgn) · ca. ${now.kwh_per_month}&nbsp;kWh pr. måned</span>`;
        }
        if (yearAgo) {
            const pct = Math.round((last.median - yearAgo.median) / yearAgo.median * 100);
            const dir = pct === 0 ? 'uændret' : (pct > 0 ? `↑${pct} %` : `↓${Math.abs(pct)} %`);
            html += `<div class="text-muted small mt-1">${_label(last.month)}: ${last.median} W mod ${yearAgo.median} W for et år siden (${dir})</div>`;
        }
        headline.innerHTML = html;

        if (chart) { chart.destroy(); chart = null; }
        const runningIdx = rows.findIndex(r => r.month === appUtils.ymd().slice(0, 7));
        chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: rows.map(r => _label(r.month)),
                datasets: [
                    { label: 'Højeste døgn', data: rows.map(r => r.max), borderColor: 'rgba(148,163,184,0.7)', borderDash: [4, 4], pointRadius: 0, borderWidth: 1, tension: 0 },
                    { label: 'Median', data: rows.map(r => r.median), borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderWidth: 2, pointRadius: 3, tension: 0,
                      pointBackgroundColor: (c) => (c.dataIndex === runningIdx ? '#fff' : '#f59e0b'), pointBorderColor: '#f59e0b', pointBorderWidth: 2,
                      segment: { borderDash: (c) => (c.p1DataIndex === runningIdx ? [4, 4] : undefined) } },
                    { label: 'Laveste døgn', data: rows.map(r => r.min), borderColor: 'rgba(148,163,184,0.7)', borderDash: [4, 4], pointRadius: 0, borderWidth: 1, tension: 0 },
                ],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: true, position: 'bottom' },
                    datalabels: { display: false },
                    tooltip: { callbacks: {
                        label: (c) => `${c.dataset.label}: ${c.parsed.y} W`,
                        afterBody: (items) => {
                            const r = rows[items[0].dataIndex];
                            return `${r.days} døgn med data` + (items[0].dataIndex === runningIdx ? ' · måneden er ikke slut' : '');
                        },
                    } },
                },
                scales: { y: { beginAtZero: true, title: { display: true, text: 'W' } } },
            },
        });
    }

    return { init };
})();

window.baseloadApp = baseloadApp;
