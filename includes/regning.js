/**
 * SparkSpend Regning Module
 *
 * Shows a monthly table of EV charging and heat pump electricity consumption
 * (kWh and estimated cost) so the user can subtract these known loads from
 * their total electricity bill to find residual household consumption.
 *
 * Heat pump cost is computed the same way as jordvarme.js:
 *   monthly_kwh × average(daily_kr_kwh across the month)
 * using getElspotPrices.php (requires Priszone + Netselskab settings).
 */

const regningApp = (() => {
    let initialized = false;

    function init() {
        if (initialized) return;
        initialized = true;

        const tableWrapper = document.getElementById('regningTableWrapper');
        const statusEl     = document.getElementById('regningStatus');
        if (!tableWrapper) return;

        _setStatus(statusEl, 'Indlæser data…');

        fetch('getMonthlyBillData.php?months=24')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(billData => {
                if (billData.error) throw new Error(billData.error);
                _renderTable(tableWrapper, billData, {});
                _clearStatus(statusEl);

                // Asynchronously enrich with HP electricity cost if settings are ready
                const area = window.sparkConfig?.elspotArea || '';
                const gln  = window.sparkConfig?.elspotGln  || '';
                if (!area || !gln) {
                    _setStatus(statusEl,
                        'Varmepumpe el-omkostninger vises ikke — sæt ELSPOT_AREA og ELSPOT_GLN i .env-filen.',
                        'text-muted');
                    return;
                }

                const { start, end } = billData.range;
                const params = new URLSearchParams({ start, end, area, gln });
                return fetch('getElspotPrices.php?' + params)
                    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(elData => {
                        const costMap = {};
                        (elData.records || []).forEach(r => { costMap[r.date] = parseFloat(r.kr_kwh); });
                        _renderTable(tableWrapper, billData, costMap);
                        _clearStatus(statusEl);
                    });
            })
            .catch(err => {
                console.error('regningApp:', err);
                _setStatus(statusEl, 'Fejl ved hentning af data: ' + err.message, 'text-danger');
            });
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    function _renderTable(wrapper, billData, costMap) {
        const { months, ev, hp, hus } = billData;
        const hasCosts    = Object.keys(costMap).length > 0;
        const hasHus      = hus && Object.keys(hus).length > 0;
        const hasResidual = hasHus && hasCosts;

        // Accumulate totals
        let totEvKwh = 0, totEvCost = 0, totHpKwh = 0, totHpCost = 0,
            totHusKwh = 0, totResidualCost = 0;

        const rows = months.map(m => {
            const evRow  = ev[m]  || { kwh: 0, cost: 0 };
            const hpRow  = hp[m]  || { kwh: 0 };
            const husRow = hus[m] || { kwh: 0 };
            const hpCost = hasCosts ? hpRow.kwh * _monthAvgRate(costMap, m) : null;

            totEvKwh  += evRow.kwh;
            totEvCost += evRow.cost;
            totHpKwh  += hpRow.kwh;
            totHusKwh += husRow.kwh;
            if (hpCost !== null) totHpCost += hpCost;

            const knownKwh    = evRow.kwh + hpRow.kwh;
            const residualKwh = hasHus && husRow.kwh > 0 ? husRow.kwh - knownKwh : null;
            const residualCost = hasResidual && residualKwh !== null
                ? residualKwh * _monthAvgRate(costMap, m) : null;
            if (residualCost !== null) totResidualCost += residualCost;

            return `<tr>
                <td class="text-nowrap">${_monthLabel(m)}</td>
                ${hasHus ? `<td class="text-end">${husRow.kwh > 0 ? husRow.kwh.toFixed(1) : '—'}</td>` : ''}
                <td class="text-end">${evRow.kwh > 0 ? evRow.kwh.toFixed(1) : '—'}</td>
                <td class="text-end">${evRow.cost > 0 ? _kr(evRow.cost) : '—'}</td>
                <td class="text-end">${hpRow.kwh > 0 ? hpRow.kwh.toFixed(1) : '—'}</td>
                ${hasCosts ? `<td class="text-end">${hpCost > 0 ? _kr(hpCost) : '—'}</td>` : ''}
                ${hasHus ? `<td class="text-end fw-semibold${residualKwh !== null && residualKwh < 0 ? ' text-danger' : ''}">${residualKwh !== null ? (residualKwh < 0 ? '<span title="Data inkonsistent: EV\u00a0+\u00a0VP overstiger hus-m\u00e5leren \u2014 tjek systemlog">\u26a0\ufe0f\u00a0</span>' : '') + residualKwh.toFixed(1) : '\u2014'}</td>` : ''}
                ${hasResidual ? `<td class="text-end fw-semibold">${residualCost !== null ? _kr(residualCost) : '—'}</td>` : ''}
            </tr>`;
        }).join('');

        const totResidualKwh = hasHus && totHusKwh > 0 ? totHusKwh - totEvKwh - totHpKwh : null;

        const husTh          = hasHus      ? '<th class="text-end">Hus kWh</th>' : '';
        const vpKrTh         = hasCosts    ? '<th class="text-end">VP kr*</th>' : '';
        const residualKwhTh  = hasHus      ? '<th class="text-end">Restforbrug kWh</th>' : '';
        const residualKrTh   = hasResidual ? '<th class="text-end">Restforbrug kr*</th>' : '';

        const totHusTd       = hasHus
            ? `<td class="text-end fw-bold">${totHusKwh > 0 ? totHusKwh.toFixed(1) : '—'}</td>` : '';
        const totHpCostTd    = hasCosts
            ? `<td class="text-end fw-bold">${totHpCost > 0 ? _kr(totHpCost) : '—'}</td>` : '';
        const totResidualKwhTd = hasHus
            ? `<td class="text-end fw-bold${totResidualKwh !== null && totResidualKwh < 0 ? ' text-danger' : ''}">${totResidualKwh !== null ? (totResidualKwh < 0 ? '<span title="Data inkonsistent: EV\u00a0+\u00a0VP overstiger hus-m\u00e5leren \u2014 tjek systemlog">\u26a0\ufe0f\u00a0</span>' : '') + totResidualKwh.toFixed(1) : '\u2014'}</td>` : '';
        const totResidualCostTd = hasResidual
            ? `<td class="text-end fw-bold">${totResidualCost > 0 ? _kr(totResidualCost) : '—'}</td>` : '';

        wrapper.innerHTML = `
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Måned</th>
                            ${husTh}
                            <th class="text-end">El-bil kWh</th>
                            <th class="text-end">El-bil kr</th>
                            <th class="text-end">VP kWh</th>
                            ${vpKrTh}
                            ${residualKwhTh}
                            ${residualKrTh}
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>Total</td>
                            ${totHusTd}
                            <td class="text-end">${totEvKwh.toFixed(1)}</td>
                            <td class="text-end">${totEvCost > 0 ? _kr(totEvCost) : '—'}</td>
                            <td class="text-end">${totHpKwh.toFixed(1)}</td>
                            ${totHpCostTd}
                            ${totResidualKwhTd}
                            ${totResidualCostTd}
                        </tr>
                    </tfoot>
                </table>
            </div>
            ${hasCosts ? '<p class="text-muted small mt-2 mb-0">* Estimeret baseret på spotpris, nettarif, systemtarif og reduceret elafgift for varmepumper (procesformål). El-bil kr er fra Monta.</p>' : ''}
        `;
    }

    // Average kr/kWh for all days in a given YYYY-MM month from the cost map
    function _monthAvgRate(costMap, month) {
        const rates = Object.entries(costMap)
            .filter(([d]) => d.startsWith(month))
            .map(([, r]) => r);
        return rates.length > 0 ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
    }

    function _monthLabel(ym) {
        // e.g. '2026-01' → 'Januar 2026'
        const raw = new Date(ym + '-15').toLocaleString('da-DK', { month: 'long', year: 'numeric' });
        return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    function _kr(val) {
        return Math.round(val) + ' kr';
    }

    function _setStatus(el, msg, cls = 'text-muted') {
        if (!el) return;
        el.textContent = msg;
        el.className = 'small mt-2 ' + cls;
    }

    function _clearStatus(el) {
        if (!el) return;
        el.textContent = '';
    }

    return { init };
})();

window.regningApp = regningApp;

// =============================================================================
// Omkostningsfordeling Module
//
// Stacked monthly cost chart (EV / Varmepumpe / Restforbrug) + summary donut.
// Uses the same getMonthlyBillData.php + getElspotPrices.php data as regningApp.
// Falls back to kWh breakdown when elspot settings are not configured.
// =============================================================================
const fordelingApp = (() => {
    let initialized = false;
    let _barChart   = null;
    let _donutChart = null;

    // Colours consistent with the rest of the app
    const C_EV   = 'rgba(59, 130, 246, 0.85)';   // blue
    const C_HP   = 'rgba(34, 197, 94,  0.85)';   // green
    const C_REST = 'rgba(245, 158, 11, 0.85)';   // amber

    function init() {
        if (initialized) return;
        initialized = true;

        const statusEl  = document.getElementById('fordelingStatus');
        const contentEl = document.getElementById('fordelingContent');
        if (!contentEl) return;

        _setStatus(statusEl, 'Indlæser data…');

        fetch('getMonthlyBillData.php?months=24')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(billData => {
                if (billData.error) throw new Error(billData.error);

                const area = window.sparkConfig?.elspotArea || '';
                const gln  = window.sparkConfig?.elspotGln  || '';

                if (!area || !gln) {
                    _render(billData, {}, false);
                    _setStatus(statusEl,
                        'Viser kWh-fordeling — sæt ELSPOT_AREA og ELSPOT_GLN i .env for kr-estimater.',
                        'text-muted');
                    return;
                }

                const { start, end } = billData.range;
                const params = new URLSearchParams({ start, end, area, gln });
                return fetch('getElspotPrices.php?' + params)
                    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(elData => {
                        const costMap = {};
                        (elData.records || []).forEach(r => { costMap[r.date] = parseFloat(r.kr_kwh); });
                        _render(billData, costMap, true);
                        _clearStatus(statusEl);
                    });
            })
            .catch(err => {
                console.error('fordelingApp:', err);
                _setStatus(statusEl, 'Fejl ved hentning af data: ' + appUtils.escapeHtml(err.message), 'text-danger');
            });
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------
    function _render(billData, costMap, hasCosts) {
        const { months, ev, hp, hus } = billData;
        const hasHus = hus && Object.keys(hus).length > 0;

        // Last 12 months, oldest → newest for the bar chart
        const last12 = months.slice(0, 12).reverse();

        const labels = [], evData = [], hpData = [], restData = [];
        let totEv = 0, totHp = 0, totRest = 0;

        for (const m of last12) {
            const evRow  = ev[m]  || { kwh: 0, cost: 0 };
            const hpRow  = hp[m]  || { kwh: 0 };
            const husRow = hus && hus[m] ? hus[m] : { kwh: 0 };

            labels.push(_monthShort(m));

            if (hasCosts) {
                const rate     = _monthAvgRate(costMap, m);
                const evCost   = evRow.cost;
                const hpCost   = hpRow.kwh * rate;
                const residKwh = hasHus && husRow.kwh > 0 ? husRow.kwh - evRow.kwh - hpRow.kwh : 0;
                const restCost = Math.max(0, residKwh) * rate;

                evData.push(Math.round(evCost));
                hpData.push(Math.round(hpCost));
                restData.push(Math.round(restCost));
                totEv   += evCost;
                totHp   += hpCost;
                totRest += Math.max(0, residKwh) * rate;
            } else {
                evData.push(Math.round(evRow.kwh * 10) / 10);
                hpData.push(Math.round(hpRow.kwh * 10) / 10);
                const residKwh = hasHus && husRow.kwh > 0 ? Math.max(0, husRow.kwh - evRow.kwh - hpRow.kwh) : 0;
                restData.push(Math.round(residKwh * 10) / 10);
                totEv   += evRow.kwh;
                totHp   += hpRow.kwh;
                totRest += residKwh;
            }
        }

        _renderSummaryCards(totEv, totHp, totRest, hasCosts, hasHus);
        _renderBarChart(labels, evData, hpData, restData, hasCosts, hasHus);
        _renderDonut(totEv, totHp, totRest, hasCosts, hasHus);
    }

    // -------------------------------------------------------------------------
    // Summary cards
    // -------------------------------------------------------------------------
    function _renderSummaryCards(totEv, totHp, totRest, hasCosts, hasHus) {
        const el = document.getElementById('fordelingSummaryCards');
        if (!el) return;

        const total = totEv + totHp + totRest;
        const pct = v => total > 0 ? Math.round(v / total * 100) + '%' : '—';
        const fmt = v => hasCosts
            ? new Intl.NumberFormat('da-DK').format(Math.round(v)) + ' kr'
            : new Intl.NumberFormat('da-DK', { maximumFractionDigits: 0 }).format(v) + ' kWh';

        const cards = [
            { label: 'El-bil', val: totEv,   colour: '#3b82f6', bg: 'rgba(59,130,246,0.08)' },
            { label: 'Varmepumpe', val: totHp, colour: '#22c55e', bg: 'rgba(34,197,94,0.08)' },
        ];
        if (hasHus) cards.push({ label: 'Restforbrug', val: totRest, colour: '#f59e0b', bg: 'rgba(245,158,11,0.08)' });

        el.innerHTML = cards.map(c => `
            <div class="col">
                <div class="rounded p-3 text-center h-100" style="background:${c.bg}; border:1px solid ${c.colour}33">
                    <div class="small text-muted mb-1">${c.label}</div>
                    <div class="fw-bold fs-5" style="color:${c.colour}">${fmt(c.val)}</div>
                    <div class="small text-muted">${pct(c.val)} af total</div>
                </div>
            </div>`).join('');
    }

    // -------------------------------------------------------------------------
    // Stacked bar chart
    // -------------------------------------------------------------------------
    function _renderBarChart(labels, evData, hpData, restData, hasCosts, hasHus) {
        const canvas = document.getElementById('fordelingChart');
        if (!canvas) return;
        if (_barChart) { _barChart.destroy(); _barChart = null; }

        const unit   = hasCosts ? 'kr' : 'kWh';
        const fmtTip = v => hasCosts
            ? new Intl.NumberFormat('da-DK').format(v) + ' kr'
            : new Intl.NumberFormat('da-DK', { maximumFractionDigits: 1 }).format(v) + ' kWh';

        const datasets = [
            { label: 'El-bil',      data: evData,   backgroundColor: C_EV,   borderRadius: { topLeft: 0, topRight: 0 } },
            { label: 'Varmepumpe',  data: hpData,   backgroundColor: C_HP,   borderRadius: { topLeft: 0, topRight: 0 } },
        ];
        if (hasHus) datasets.push(
            { label: 'Restforbrug', data: restData, backgroundColor: C_REST, borderRadius: { topLeft: 3, topRight: 3 } }
        );

        _barChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    datalabels: { display: false },
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.dataset.label + ': ' + fmtTip(ctx.raw),
                            footer: items => {
                                const s = items.reduce((a, i) => a + i.raw, 0);
                                return 'Total: ' + fmtTip(s);
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: unit },
                        ticks: { callback: v => new Intl.NumberFormat('da-DK').format(v) },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Donut chart
    // -------------------------------------------------------------------------
    function _renderDonut(totEv, totHp, totRest, hasCosts, hasHus) {
        const canvas = document.getElementById('fordelingDonutChart');
        if (!canvas) return;
        if (_donutChart) { _donutChart.destroy(); _donutChart = null; }

        const labels = ['El-bil', 'Varmepumpe'];
        const data   = [totEv, totHp];
        const colors = [C_EV, C_HP];
        if (hasHus) { labels.push('Restforbrug'); data.push(totRest); colors.push(C_REST); }

        const fmtTip = v => hasCosts
            ? new Intl.NumberFormat('da-DK').format(Math.round(v)) + ' kr'
            : new Intl.NumberFormat('da-DK', { maximumFractionDigits: 0 }).format(v) + ' kWh';

        const total = data.reduce((a, b) => a + b, 0);

        _donutChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data, backgroundColor: colors, borderWidth: 2 }],
            },
            options: {
                responsive: true,
                cutout: '62%',
                plugins: {
                    datalabels: {
                        color: '#fff',
                        font: { weight: 'bold', size: 12 },
                        formatter: (v) => total > 0 ? Math.round(v / total * 100) + '%' : '',
                        display: ctx => ctx.dataset.data[ctx.dataIndex] / total > 0.05,
                    },
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.label + ': ' + fmtTip(ctx.raw)
                                + (total > 0 ? ' (' + Math.round(ctx.raw / total * 100) + '%)' : ''),
                        },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Helpers (shared logic identical to regningApp helpers)
    // -------------------------------------------------------------------------
    function _monthAvgRate(costMap, month) {
        const rates = Object.entries(costMap)
            .filter(([d]) => d.startsWith(month))
            .map(([, r]) => r);
        return rates.length > 0 ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
    }

    function _monthShort(ym) {
        const d = new Date(ym + '-15');
        const mo = d.toLocaleString('da-DK', { month: 'short' });
        return mo.charAt(0).toUpperCase() + mo.slice(1) + ' ' + d.getFullYear().toString().slice(2);
    }

    function _setStatus(el, msg, cls = 'text-muted') {
        if (!el) return;
        el.innerHTML = msg;
        el.className = 'small mb-3 ' + cls;
    }

    function _clearStatus(el) {
        if (!el) return;
        el.textContent = '';
    }

    return { init };
})();

window.fordelingApp = fordelingApp;
