/**
 * Annual Summary Module
 *
 * Renders the Årsrapport tab: a grouped bar chart and a table showing
 * per-year totals for EV, heatpump, and house consumption.
 */

const annualApp = (() => {
    let chartInstance = null;
    let _mode = 'kwh';
    let _data = null;
    // Estimated spot-price cost per year for VP and Hus: { [year]: kr }.
    // Filled from the server's years[].cost by _readCosts().
    let _vpCostByYear  = {};
    let _husCostByYear = {};
    let _costsReady    = false;

    // ── Init ──────────────────────────────────────────────────────────────────
    function init() {
        const loadingEl = document.getElementById('annualLoading');
        const contentEl = document.getElementById('annualContent');
        if (!loadingEl || !contentEl) return;

        // Wire kWh / kr toggle
        document.getElementById('annualKwhBtn')?.addEventListener('click', () => {
            _mode = 'kwh';
            document.getElementById('annualKwhBtn')?.classList.add('active');
            document.getElementById('annualKrBtn')?.classList.remove('active');
            if (_data) _renderChart(_data);
        });
        document.getElementById('annualKrBtn')?.addEventListener('click', () => {
            _mode = 'kr';
            document.getElementById('annualKrBtn')?.classList.add('active');
            document.getElementById('annualKwhBtn')?.classList.remove('active');
            if (_data) _renderChart(_data);
        });

        loadingEl.innerHTML = 'Indlæser…';
        loadingEl.style.display = '';
        contentEl.style.display = 'none';

        fetch('api.php?action=annual')
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
                _data = data;
                _readCosts(data);
                // Make content visible before chart init so Chart.js can measure canvas dimensions
                loadingEl.style.display = 'none';
                contentEl.style.display = '';
                _renderChart(data);
                _renderTable(data);
            })
            .catch(err => {
                loadingEl.innerHTML = '';
                const span = document.createElement('span');
                span.className = 'text-danger';
                span.textContent = 'Fejl: ' + err.message;
                loadingEl.appendChild(span);
                console.error('annualApp:', err);
            });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function _fmtKwh(v) {
        return new Intl.NumberFormat('da-DK', { maximumFractionDigits: 1 }).format(v) + '\u00a0kWh';
    }

    function _fmtKr(v) {
        return new Intl.NumberFormat('da-DK', { maximumFractionDigits: 0 }).format(v) + '\u00a0kr';
    }

    // ── Estimated VP/Hus cost ───────────────────────────────────────────────────
    // Computed on the server (CostEstimates::annual, elvarme model) and delivered as
    // years[].cost = {vp, hus}; this only reads it.
    function _readCosts(data) {
        _vpCostByYear = {}; _husCostByYear = {};
        (data.years || []).forEach(y => {
            if (!y.cost) return;
            _vpCostByYear[y.year] = y.cost.vp;
            if (y.cost.hus !== null && y.cost.hus !== undefined) _husCostByYear[y.year] = y.cost.hus;
        });
        _costsReady = Object.keys(_vpCostByYear).length > 0
                   || Object.keys(_husCostByYear).length > 0;
    }

    // ── Chart ─────────────────────────────────────────────────────────────────
    function _renderChart(data) {
        const canvas = document.getElementById('annualChart');
        const noteEl = document.getElementById('annualKrNote');
        if (!canvas) return;

        if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

        const labels = data.years.map(y => _yearLabel(y));

        let datasets, yLabel, tooltipFmt;

        if (_mode === 'kr') {
            const hasHus = data.years.some(y => y.hus !== null);
            datasets = [{
                label: 'El-bil kr',
                data: data.years.map(y => y.ev.cost),
                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                borderRadius: 3,
            }];
            // VP + Hus estimated cost — only once spot-price estimates are ready
            if (_costsReady) {
                datasets.push({
                    label: 'Jordvarme kr*',
                    data: data.years.map(y => _vpCostByYear[y.year] ?? 0),
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderRadius: 3,
                });
                if (hasHus) {
                    datasets.push({
                        label: 'Hus total kr*',
                        data: data.years.map(y => _husCostByYear[y.year] ?? 0),
                        backgroundColor: 'rgba(245, 158, 11, 0.8)',
                        borderRadius: 3,
                    });
                }
            }
            yLabel = 'kr';
            tooltipFmt = ctx => ' ' + ctx.dataset.label + ': ' + _fmtKr(ctx.raw);
            // Show the "EV-only" note only when VP/Hus estimates are unavailable
            // (elspot not configured). Once estimates arrive, the chart shows all
            // three and the table footnote explains the estimate instead.
            if (noteEl) noteEl.style.display = _costsReady ? 'none' : '';
        } else {
            const hasHus = data.years.some(y => y.hus !== null);
            datasets = [
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
            yLabel = 'kWh';
            tooltipFmt = ctx => ' ' + ctx.dataset.label + ': ' + _fmtKwh(ctx.raw);
            if (noteEl) noteEl.style.display = 'none';
        }

        if (chartInstance) {
            // In-place update — avoids destroy/recreate on mode toggle
            chartInstance.data.labels = labels;
            chartInstance.data.datasets.length = 0;
            datasets.forEach(ds => chartInstance.data.datasets.push(ds));
            chartInstance.options.scales.y.title.text = yLabel;
            chartInstance.options.plugins.tooltip.callbacks.label = tooltipFmt;
            chartInstance.update('none');
            return;
        }

        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                aspectRatio: window.innerWidth < 600 ? 1.1 : 2.8,
                plugins: {
                    legend: { position: 'top' },
                    datalabels: { display: false },
                    tooltip: {
                        callbacks: { label: tooltipFmt },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: yLabel },
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
        const showCost = _costsReady;   // VP/Hus kr columns only once estimated

        const vpKrTh  = showCost ? '<th class="text-end">Jordvarme kr*</th>' : '';
        const husKrTh = (showCost && hasHus) ? '<th class="text-end">Hus total kr*</th>' : '';

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
                ${vpKrTh}
                ${hasHus ? '<th class="text-end">Hus total kWh</th>' : ''}
                ${husKrTh}
              </tr>
            </thead>
            <tbody>`;

        rows.forEach(y => {
            const husCell = hasHus
                ? `<td class="text-end">${y.hus !== null ? _fmtKwh(y.hus.kwh) : '—'}</td>`
                : '';
            const vpKrCell  = showCost
                ? `<td class="text-end">${_vpCostByYear[y.year] > 0 ? _fmtKr(_vpCostByYear[y.year]) : '—'}</td>`
                : '';
            const husKrCell = (showCost && hasHus)
                ? `<td class="text-end">${_husCostByYear[y.year] > 0 ? _fmtKr(_husCostByYear[y.year]) : '—'}</td>`
                : '';
            html += `
              <tr>
                <td class="fw-bold">${y.year}${_partialNote(y) ? ` <span class="text-muted fw-normal small">${_partialNote(y)}</span>` : ''}</td>
                <td class="text-end">${y.ev.charges}</td>
                <td class="text-end">${_fmtKwh(y.ev.kwh)}</td>
                <td class="text-end">${appUtils.formatCurrency(y.ev.cost)}</td>
                <td class="text-end">${_fmtKwh(y.heatpump.kwh)}</td>
                ${vpKrCell}
                ${husCell}
                ${husKrCell}
              </tr>`;
        });

        html += `
            </tbody>
          </table>
        </div>`;

        if (showCost) {
            html += `<p class="text-muted small mt-2 mb-0">* Estimeret ud fra spotpris, ` +
                `nettarif, systemtarif, elafgift og moms. Elvarme-model: første ` +
                `4.000 kWh/år af VP+Restforbrug til fuld elafgift, resten (og EV) ` +
                `til reduceret elvarmesats.</p>`;
        }

        el.innerHTML = html;
    }

    // A year is partial when it is still running ("til dato") or has charges in fewer than 12 months.
    function _partialNote(y) {
        if (String(y.year) === String(new Date().getFullYear())) return 'til dato';
        // Months with METER readings (house / heat pump). EV charges starting later does not make the year partial.
        const m = y.data_months;
        return (m > 0 && m < 12) ? `kun ${m} ${m === 1 ? 'md.' : 'mdr.'} data` : '';
    }
    function _yearLabel(y) {
        const n = _partialNote(y);
        return n ? `${y.year} (${n})` : String(y.year);
    }

    return { init };
})();

window.annualApp = annualApp;
