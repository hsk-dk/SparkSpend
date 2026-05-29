/**
 * Annual Summary Module
 *
 * Renders the Årsrapport tab: a grouped bar chart and a table showing
 * per-year totals for EV, heatpump, and house consumption.
 */

const annualApp = (() => {
    let chartInstance = null;

    // ── Init ──────────────────────────────────────────────────────────────────
    function init() {
        const loadingEl = document.getElementById('annualLoading');
        const contentEl = document.getElementById('annualContent');
        if (!loadingEl || !contentEl) return;

        loadingEl.innerHTML = 'Indlæser…';
        loadingEl.style.display = '';
        contentEl.style.display = 'none';

        fetch('getAnnualSummary.php')
            .then(r => {
                const ct = r.headers.get('content-type') || '';
                if (ct.includes('application/json')) {
                    return r.json().then(data => ({ ok: r.ok, data, raw: null }));
                }
                return r.text().then(raw => ({ ok: false, data: null, raw }));
            })
            .then(({ ok, data, raw }) => {
                if (raw !== null) throw new Error('Server returnerede ikke JSON: ' + raw.slice(0, 200));
                if (!ok || data.error) throw new Error(data?.error || 'HTTP fejl');
                // Make content visible before chart init so Chart.js can measure canvas dimensions
                loadingEl.style.display = 'none';
                contentEl.style.display = '';
                _renderChart(data);
                _renderTable(data);
            })
            .catch(err => {
                const msg = window.appUtils?.escapeHtml?.(err.message) ?? err.message;
                loadingEl.innerHTML = '<span class="text-danger">Fejl: ' + msg + '</span>';
                console.error('annualApp:', err);
            });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function _fmtKwh(v) {
        return new Intl.NumberFormat('da-DK', { maximumFractionDigits: 1 }).format(v) + '\u00a0kWh';
    }

    // ── Chart ─────────────────────────────────────────────────────────────────
    function _renderChart(data) {
        const canvas = document.getElementById('annualChart');
        if (!canvas) return;

        if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

        const labels = data.years.map(y => y.year);
        const hasHus = data.years.some(y => y.hus !== null);

        const datasets = [
            {
                label: 'El-bil kWh',
                data: data.years.map(y => y.ev.kwh),
                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                borderRadius: 3,
            },
            {
                label: 'Jordvarme kWh',
                data: data.years.map(y => y.heatpump.kwh),
                backgroundColor: 'rgba(34, 197, 94, 0.8)',
                borderRadius: 3,
            },
        ];

        if (hasHus) {
            datasets.push({
                label: 'Hus total kWh',
                data: data.years.map(y => y.hus?.kwh ?? 0),
                backgroundColor: 'rgba(245, 158, 11, 0.8)',
                borderRadius: 3,
            });
        }

        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    datalabels: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.dataset.label + ': ' + _fmtKwh(ctx.raw),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'kWh' },
                        ticks: {
                            callback: v => new Intl.NumberFormat('da-DK').format(v),
                        },
                    },
                },
            },
        });
    }

    // ── Table ─────────────────────────────────────────────────────────────────
    function _renderTable(data) {
        const el = document.getElementById('annualTable');
        if (!el) return;

        const hasHus = data.years.some(y => y.hus !== null);
        const rows   = [...data.years].reverse();   // newest year first

        let html = `
        <div class="table-responsive mt-3">
          <table class="table table-hover table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>År</th>
                <th class="text-end">EV ladninger</th>
                <th class="text-end">EV kWh</th>
                <th class="text-end">EV pris</th>
                <th class="text-end">Jordvarme kWh</th>
                ${hasHus ? '<th class="text-end">Hus total kWh</th>' : ''}
              </tr>
            </thead>
            <tbody>`;

        rows.forEach(y => {
            const husCell = hasHus
                ? `<td class="text-end">${y.hus !== null ? _fmtKwh(y.hus.kwh) : '—'}</td>`
                : '';
            html += `
              <tr>
                <td class="fw-bold">${y.year}</td>
                <td class="text-end">${y.ev.charges}</td>
                <td class="text-end">${_fmtKwh(y.ev.kwh)}</td>
                <td class="text-end">${appUtils.formatCurrency(y.ev.cost)}</td>
                <td class="text-end">${_fmtKwh(y.heatpump.kwh)}</td>
                ${husCell}
              </tr>`;
        });

        html += `
            </tbody>
          </table>
        </div>`;

        el.innerHTML = html;
    }

    return { init };
})();

window.annualApp = annualApp;
