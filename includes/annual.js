/**
 * Annual Summary Module
 *
 * Renders the Årsrapport tab: a grouped bar chart and a table showing
 * per-year totals for EV, heatpump, and house consumption.
 */

/**
 * One year as the house meter sees it. The meter's total is split into the heat pump, the EV charged at home and the rest
 * ("Øvrigt" = meter - heat pump - home EV, never negative, the same rule as the dashboard and the cost model). Charging away
 * from home never passes the meter, so it is not in the stack (`evAway`); it is only in the table.
 * `rest` and `hus` are null for a year without a house meter.
 */
function _annualParts(y) {
    const vp     = Number(y.heatpump && y.heatpump.kwh) || 0;
    const evHome = Number(y.ev && (y.ev.home_kwh ?? y.ev.kwh)) || 0;
    const hus    = y.hus ? (Number(y.hus.kwh) || 0) : null;
    return {
        vp, evHome, hus,
        rest: hus === null ? null : Math.max(0, hus - vp - evHome),
        evAway: Math.max(0, (Number(y.ev && y.ev.kwh) || 0) - evHome),
    };
}

/** The same split in kr: Monta's price for the home charging, the model's price for the heat pump and the rest (null when not estimated). */
function _annualPartsKr(y) {
    const c = y.cost || null;
    return {
        evHome: Number(y.ev && (y.ev.home_cost ?? y.ev.cost)) || 0,
        vp:     c && c.vp !== null && c.vp !== undefined ? Number(c.vp) : null,
        rest:   c && c.rest !== null && c.rest !== undefined ? Number(c.rest) : null,
    };
}

/** A year whose heat pump meter starts after the house meter: the house total is right, but it cannot be split (see AnnualSummary.php). */
function _annualUnsplit(y) {
    return !!y.hus && y.split_ok === false;
}

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

        Api.get('annual')
            .then(data => {
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
    // Stacked: what the house meter went to, year by year (heat pump, EV at home, the rest), so the bar is the house.
    // Beside each other, "Hus total" looked like a fourth thing and a year with an EV looked like a year of doubled use.
    const _COLOURS = { vp: '#0ea495', ev: '#224b81', rest: '#9e5f00', unsplit: '#6b7785' };   // the --data-* tokens, opaque (dev/test_datacolors.js); grey: the house total of a year that cannot be split

    function _renderChart(data) {
        const canvas = document.getElementById('annualChart');
        const noteEl = document.getElementById('annualKrNote');
        if (!canvas) return;

        if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

        const labels = data.years.map(y => _yearLabel(y));
        const hasHus = data.years.some(y => y.hus !== null);
        const seg = (label, key, values) => ({ label, data: values, backgroundColor: _COLOURS[key], borderRadius: 2, stack: 'house' });
        let datasets, yLabel, fmt;

        if (_mode === 'kr') {
            const parts = data.years.map(_annualPartsKr);
            const un = data.years.map(_annualUnsplit);
            datasets = [];
            // The heat pump and the rest are estimated from spot prices: only once those are there
            if (_costsReady) datasets.push(seg('Jordvarme kr*', 'vp', parts.map((p, i) => un[i] ? null : (p.vp ?? 0))));
            datasets.push(seg('El-bil hjemme kr', 'ev', parts.map((p, i) => un[i] ? null : p.evHome)));
            if (_costsReady && hasHus) datasets.push(seg('Øvrigt kr*', 'rest', parts.map((p, i) => un[i] ? null : (p.rest ?? 0))));
            if (_costsReady && un.some(Boolean)) datasets.push(seg('Hus i alt, ikke fordelt kr*', 'unsplit', data.years.map((y, i) => un[i] ? (Number(_husCostByYear[y.year]) || 0) : null)));
            yLabel = 'kr';
            fmt = _fmtKr;
            // The "EV only" note is for when the spot-price estimates are unavailable (elspot not configured)
            if (noteEl) noteEl.style.display = _costsReady ? 'none' : '';
        } else {
            const parts = data.years.map(_annualParts);
            const un = data.years.map(_annualUnsplit);
            datasets = [
                seg('Jordvarme kWh', 'vp', parts.map((p, i) => un[i] ? null : p.vp)),
                seg('El-bil hjemme kWh', 'ev', parts.map((p, i) => un[i] ? null : p.evHome)),
            ];
            if (hasHus) datasets.push(seg('Øvrigt kWh', 'rest', parts.map((p, i) => un[i] ? null : (p.rest ?? 0))));
            if (un.some(Boolean)) datasets.push(seg('Hus i alt, ikke fordelt kWh', 'unsplit', parts.map((p, i) => un[i] ? (p.hus ?? 0) : null)));
            yLabel = hasHus ? 'kWh (husmåler)' : 'kWh';
            fmt = _fmtKwh;
            if (noteEl) noteEl.style.display = 'none';
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
                        callbacks: {
                            label: ctx => ' ' + ctx.dataset.label + ': ' + fmt(ctx.raw),
                            footer: items => {
                                if (!items.length) return '';
                                const lines = ['I alt: ' + fmt(items.reduce((s, i) => s + i.raw, 0))];
                                const away = _mode === 'kwh' ? _annualParts(data.years[items[0].dataIndex]).evAway : 0;
                                if (away > 0) lines.push('Ladning ude (ikke på måleren): ' + _fmtKwh(away));
                                return lines;
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
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

        const vpKrTh   = showCost ? '<th class="text-end">Jordvarme kr*</th>' : '';
        const restTh   = hasHus ? '<th class="text-end">Øvrigt kWh</th>' : '';
        const restKrTh = (showCost && hasHus) ? '<th class="text-end">Øvrigt kr*</th>' : '';
        const husKrTh  = (showCost && hasHus) ? '<th class="text-end">Hus total kr*</th>' : '';

        let html = `
        <div class="table-responsive mt-3">
          <table class="table table-hover table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>År</th>
                <th class="text-end">EV ladninger</th>
                <th class="text-end">EV kWh (alt)</th>
                <th class="text-end">EV pris</th>
                <th class="text-end">Jordvarme kWh</th>
                ${vpKrTh}
                ${restTh}
                ${restKrTh}
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
            const unsplit    = _annualUnsplit(y);
            const restCell   = hasHus ? `<td class="text-end">${y.hus !== null && !unsplit ? _fmtKwh(_annualParts(y).rest) : '—'}</td>` : '';
            const restKr     = _annualPartsKr(y).rest;
            const restKrCell = (showCost && hasHus) ? `<td class="text-end">${restKr > 0 && !unsplit ? _fmtKr(restKr) : '—'}</td>` : '';
            const husKrCell = (showCost && hasHus)
                ? `<td class="text-end">${_husCostByYear[y.year] > 0 ? _fmtKr(_husCostByYear[y.year]) : '—'}</td>`
                : '';
            html += `
              <tr>
                <td class="fw-bold">${y.year}${_partialNote(y) ? ` <span class="text-muted fw-normal small">${_partialNote(y)}</span>` : ''}</td>
                <td class="text-end">${y.ev.charges}</td>
                <td class="text-end">${_fmtKwh(y.ev.kwh)}</td>
                <td class="text-end">${_fmtKr(y.ev.cost)}</td>
                <td class="text-end">${_fmtKwh(y.heatpump.kwh)}</td>
                ${vpKrCell}
                ${restCell}
                ${restKrCell}
                ${husCell}
                ${husKrCell}
              </tr>`;
        });

        html += `
            </tbody>
          </table>
        </div>`;

        html += `<p class="text-muted small mt-2 mb-0">Diagrammet deler husmåleren i jordvarme, el-bil (kun hjemmeladning) og øvrigt (måler minus de to). ` +
            `Ladning ude passerer ikke måleren og står kun i tabellens EV-kolonner.</p>`;

        const unsplitYears = data.years.filter(_annualUnsplit).map(y => y.year);
        if (unsplitYears.length && data.meters) {
            const dk = iso => iso ? new Date(iso + 'T12:00:00').toLocaleDateString('da-DK', { day: 'numeric', month: 'long' }) : '?';
            html += `<p class="text-muted small mt-2 mb-0">${unsplitYears.join(', ')}: husmåleren starter ${dk(data.meters.hus_from)} og jordvarmemåleren først ${dk(data.meters.hp_from)}. ` +
                `Jordvarmens måneder før da ville ellers tælle med som "Øvrigt", så året kan ikke deles op; den grå søjle er hustotalen, og "Øvrigt" står som —.</p>`;
        }

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
