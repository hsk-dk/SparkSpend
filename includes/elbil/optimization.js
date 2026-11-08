/**
 * SparkSpend Elbil - charging optimisation
 *
 * Elbil > Analyse > Ladeoptimering (api.php?action=charge-optimization, ChargeOptimizer on the server).
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;

    // -------------------------------------------------------------------------
    // Charging optimisation (api.php?action=charge-optimization, ChargeOptimizer on the server)
    // -------------------------------------------------------------------------
    function fetchChargeOptimization() {
        const el = document.getElementById('chargeOptContent');
        if (!el) return;
        const months = document.getElementById('chargeOptMonths')?.value || '3';
        el.innerHTML = '<p class="text-muted small mb-0">Indlæser…</p>';
        Api.get('charge-optimization', { months })
            .then(d => _renderChargeOptimization(el, d))
            .catch(err => {
                el.innerHTML = '';
                appUtils.showRetryError(el, { message: 'Kunne ikke hente ladeoptimering: ' + err.message, onRetry: fetchChargeOptimization });
            });
    }

    function _renderChargeOptimization(el, d) {
        const esc = appUtils.escapeHtml;
        const kr  = v => Number(v).toLocaleString('da-DK', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' kr';
        const kwh = v => Number(v).toLocaleString('da-DK', { maximumFractionDigits: 0 }) + ' kWh';
        const p2  = n => String(n).padStart(2, '0');

        if (!d.available) {
            const why = d.reason === 'prices_not_configured'
                ? 'Elpriser er ikke sat op (ELSPOT_AREA og ELSPOT_GLN i .env), så ladningerne kan ikke prissættes.'
                : 'Elpriserne kunne ikke hentes lige nu. Prøv igen om lidt.';
            el.innerHTML = `<p class="text-muted small mb-0">${esc(why)}</p>`;
            return;
        }
        const t = d.totals || {};
        if (!t.charges) {
            el.innerHTML = '<p class="text-muted small mb-0">Ingen afsluttede ladninger hjemme i perioden.</p>';
            return;
        }
        const when = h => `${h.date.slice(8, 10)}/${h.date.slice(5, 7)} kl. ${p2(h.from)}\u2013${p2(h.to % 24)}`;
        const tile = (title, value, sub, cls = '') => `
            <div class="me-4 mb-2"><div class="text-muted small">${esc(title)}</div>
            <div class="fs-4 fw-semibold ${cls}">${value}</div>${sub ? `<div class="text-muted small">${sub}</div>` : ''}</div>`;

        let html = '<div class="d-flex flex-wrap">' +
            tile('Ladet til timeprisen', kr(t.actual), `${kwh(t.kwh)} i ${t.charges} ladninger`) +
            tile('Billigste mulige', kr(t.best), 'samme kWh i de billigste timer') +
            tile('Potentiale', kr(t.saving), `${String(t.saving_pct).replace('.', ',')} % af prisen`, 'text-success') + '</div>';

        if (d.months && d.months.length > 1) {
            html += '<div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0"><thead><tr>' +
                '<th>Måned</th><th class="text-end">Ladninger</th><th class="text-end">kWh</th><th class="text-end">Timepris</th>' +
                '<th class="text-end">Billigste</th><th class="text-end">Potentiale</th></tr></thead><tbody>' +
                d.months.map(m => {
                    const [y, mo] = m.month.split('-').map(Number);
                    const label = new Date(y, mo - 1, 1).toLocaleDateString('da-DK', { month: 'short', year: 'numeric' });
                    return `<tr><td>${esc(label)}</td><td class="text-end">${m.charges}</td><td class="text-end">${kwh(m.kwh)}</td>` +
                           `<td class="text-end">${kr(m.actual)}</td><td class="text-end">${kr(m.best)}</td>` +
                           `<td class="text-end text-success fw-semibold">${kr(m.saving)}</td></tr>`;
                }).join('') + '</tbody></table></div>';
        }

        const top = (d.charges || []).filter(c => c.saving >= 1).slice(0, 5);
        if (top.length) {
            html += '<h5 class="mt-3">Størst potentiale</h5><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>' +
                '<th>Ladet</th><th class="text-end">kWh</th><th class="text-end">Timepris</th><th class="text-end">Billigste</th>' +
                '<th class="text-end">Potentiale</th><th>Billigst ville være</th></tr></thead><tbody>' +
                top.map(c => {
                    const dt = new Date(c.startedAt);
                    const label = dt.toLocaleDateString('da-DK', { day: '2-digit', month: '2-digit' }) + ' ' +
                                  dt.toLocaleTimeString('da-DK', { hour: '2-digit', minute: '2-digit' });
                    return `<tr><td>${esc(label)}</td><td class="text-end">${kwh(c.kwh)}</td><td class="text-end">${kr(c.actual)}</td>` +
                           `<td class="text-end">${kr(c.best)}</td><td class="text-end text-success fw-semibold">${kr(c.saving)}</td>` +
                           `<td class="small">${esc(c.best_hours.map(when).join(', '))}</td></tr>`;
                }).join('') + '</tbody></table></div>';
        }

        const prm = d.params || {};
        html += `<p class="text-muted small mt-3 mb-0">Beregnet ud fra timepriser (spot, tariffer, elafgift og moms) \u2014 ikke din Monta-regning. ` +
                `Antager at bilen kunne lade i op til ${esc(String(prm.flex_hours).replace('.', ','))} timer efter tilslutning ` +
                `med højst ${esc(String(prm.max_kw).replace('.', ','))} kW. Det er et potentiale, ikke en garanti: det afhænger af, hvornår bilen skal bruges.</p>`;
        el.innerHTML = html;
    }

    function bind() {
        document.getElementById('chargeOptMonths')?.addEventListener('change', fetchChargeOptimization);
    }

    M.optimization = { fetchChargeOptimization, _renderChargeOptimization, bind };
})();
