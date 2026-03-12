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
        const hasCosts = Object.keys(costMap).length > 0;
        const hasHus   = hus && Object.keys(hus).length > 0;

        // Accumulate totals
        let totEvKwh = 0, totEvCost = 0, totHpKwh = 0, totHpCost = 0, totHusKwh = 0;

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

            const totalKwh    = evRow.kwh + hpRow.kwh;
            const totalCost   = hpCost !== null ? evRow.cost + hpCost : null;
            const residualKwh = hasHus && husRow.kwh > 0 ? husRow.kwh - totalKwh : null;

            return `<tr>
                <td class="text-nowrap">${_monthLabel(m)}</td>
                ${hasHus ? `<td class="text-end">${husRow.kwh > 0 ? husRow.kwh.toFixed(1) : '—'}</td>` : ''}
                <td class="text-end">${evRow.kwh > 0 ? evRow.kwh.toFixed(1) : '—'}</td>
                <td class="text-end">${evRow.cost > 0 ? _kr(evRow.cost) : '—'}</td>
                <td class="text-end">${hpRow.kwh > 0 ? hpRow.kwh.toFixed(1) : '—'}</td>
                ${hasCosts ? `<td class="text-end">${hpCost > 0 ? _kr(hpCost) : '—'}</td>` : ''}
                <td class="text-end fw-semibold">${totalKwh > 0 ? totalKwh.toFixed(1) : '—'}</td>
                ${hasCosts ? `<td class="text-end fw-semibold">${totalCost > 0 ? _kr(totalCost) : '—'}</td>` : ''}
                ${hasHus ? `<td class="text-end fw-semibold">${residualKwh !== null ? residualKwh.toFixed(1) : '—'}</td>` : ''}
            </tr>`;
        }).join('');

        const totalKwhAll  = totEvKwh + totHpKwh;
        const totalCostAll = hasCosts ? totEvCost + totHpCost : null;
        const totResidual  = hasHus && totHusKwh > 0 ? totHusKwh - totalKwhAll : null;

        const husTh       = hasHus ? '<th class="text-end">Hus kWh</th>' : '';
        const vpKrTh      = hasCosts ? '<th class="text-end">VP kr*</th>' : '';
        const totalKrTh   = hasCosts ? '<th class="text-end">I alt kr*</th>' : '';
        const residualTh  = hasHus ? '<th class="text-end">Restforbrug kWh</th>' : '';
        const totHusTd    = hasHus
            ? `<td class="text-end fw-bold">${totHusKwh > 0 ? totHusKwh.toFixed(1) : '—'}</td>` : '';
        const totHpCostTd = hasCosts
            ? `<td class="text-end fw-bold">${totHpCost > 0 ? _kr(totHpCost) : '—'}</td>`  : '';
        const totCostTd   = hasCosts
            ? `<td class="text-end fw-bold">${totalCostAll > 0 ? _kr(totalCostAll) : '—'}</td>` : '';
        const totResidualTd = hasHus
            ? `<td class="text-end fw-bold">${totResidual !== null ? totResidual.toFixed(1) : '—'}</td>` : '';

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
                            <th class="text-end">I alt kWh</th>
                            ${totalKrTh}
                            ${residualTh}
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
                            <td class="text-end">${totalKwhAll.toFixed(1)}</td>
                            ${totCostTd}
                            ${totResidualTd}
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
