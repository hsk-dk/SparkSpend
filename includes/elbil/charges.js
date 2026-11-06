/**
 * SparkSpend Elbil - charges
 *
 * The charge table (fetch, sort, paginate, render), the summary, and the edit / create / delete charge modals.
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;
    // Charge table state
    let _sortCol = 'datetime';
    let _sortDir = 'desc';
    const _PAGE_SIZE  = 50;
    let   _visibleRows = _PAGE_SIZE;

    async function fetchCharges() {
        const params = new URLSearchParams({
            filter:      S.filterEl.value,
            showZeroKwh: S.showZeroKwhEl.checked,
            dateRange:   S.dateRangeEl.value
        });
        appUtils.setLoadingState('chargeTableBody', true);
        try {
            const res  = await fetch('api.php?action=charges&' + params);
            const data = await appUtils.handleFetchResponse(res);
            S.allCharges   = data;
            _visibleRows  = _PAGE_SIZE;
            _applySortAndRender();
        } catch (e) {
            appUtils.showRetryError(S.chargeTableBodyEl, {
                message: 'Kunne ikke hente ladninger: ' + e.message,
                onRetry: fetchCharges,
                asTableRow: true,
                colspan: 8,
            });
        }
    }

    // -------------------------------------------------------------------------
    // Sort helpers
    // -------------------------------------------------------------------------
    function _sortCharges(data) {
        return [...data].sort((a, b) => {
            let av, bv;
            switch (_sortCol) {
                case 'datetime': av = a.datetime || ''; bv = b.datetime || ''; break;
                case 'kwh':      av = parseFloat(a.kwh)  || 0; bv = parseFloat(b.kwh)  || 0; break;
                case 'pris':     av = parseFloat(a.pris) || 0; bv = parseFloat(b.pris) || 0; break;
                case 'ppkwh': {
                    const ak = parseFloat(a.kwh) || 0; av = ak > 0 ? (parseFloat(a.pris) || 0) / ak : 0;
                    const bk = parseFloat(b.kwh) || 0; bv = bk > 0 ? (parseFloat(b.pris) || 0) / bk : 0;
                    break;
                }
                case 'vehicle': {
                    av = (S.vehicles.find(v => v.id == a.vehicleId) || {}).vehicleName || '';
                    bv = (S.vehicles.find(v => v.id == b.vehicleId) || {}).vehicleName || '';
                    break;
                }
                default: return 0;
            }
            if (av < bv) return _sortDir === 'asc' ? -1 : 1;
            if (av > bv) return _sortDir === 'asc' ?  1 : -1;
            return 0;
        });
    }

    function _applySortAndRender() {
        _updateSortHeaders();
        _renderCharges(_sortCharges(S.allCharges));
    }

    function _updateSortHeaders() {
        document.querySelectorAll('#chargeTableHead th[data-sort]').forEach(th => {
            const arrow = th.querySelector('.sort-arrow');
            if (!arrow) return;
            if (th.dataset.sort === _sortCol) {
                arrow.textContent = _sortDir === 'asc' ? ' ↑' : ' ↓';
                th.classList.add('sort-active');
            } else {
                arrow.textContent = '';
                th.classList.remove('sort-active');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Render: charge table
    // -------------------------------------------------------------------------
    function _renderCharges(data) {
        S.chargeTableBodyEl.innerHTML = '';
        if (data.length === 0) {
            appUtils.showEmptyState(S.chargeTableBodyEl, {
                message: 'Ingen ladninger fundet for den valgte periode.',
                icon: 'fa-charging-station',
                actionLabel: 'Nulstil filtre',
                onAction: () => elbilApp.resetFilters(),
                asTableRow: true,
                colspan: 8,
            });
            _updateSummary({}, 0, 0, 0, 0, 0, 0, 0, 0);
            return;
        }
        const visible   = data.slice(0, _visibleRows);
        const remaining = data.length - visible.length;

        let overallKwh = 0, overallPris = 0;
        let internalCount = 0, externalCount = 0;
        let internalKwh = 0, externalKwh = 0;
        let internalPrice = 0, externalPrice = 0;
        const totals = {};

        data.forEach(charge => {
            const kwh  = parseFloat(charge.kwh)  || 0;
            const pris = parseFloat(charge.pris) || 0;
            const pricePerKwh = kwh > 0 ? pris / kwh : 0;
            const vName = (S.vehicles.find(v => v.id == charge.vehicleId) || {}).vehicleName || 'Ukendt';

            if (charge.source === 'internal') {
                internalCount++; internalKwh += kwh; internalPrice += pris;
            } else {
                externalCount++; externalKwh += kwh; externalPrice += pris;
            }

            if (!totals[vName]) totals[vName] = { totalKwh: 0, totalPris: 0, internalPris: 0, externalPris: 0 };
            totals[vName].totalKwh  += kwh;
            totals[vName].totalPris += pris;
            if (charge.source === 'internal') totals[vName].internalPris += pris;
            else                              totals[vName].externalPris += pris;

            overallKwh  += kwh;
            overallPris += pris;
        });

        const avgPricePerKwh = overallKwh > 0 ? overallPris / overallKwh : 0;

        visible.forEach(charge => {
            const kwh      = parseFloat(charge.kwh)  || 0;
            const pris     = parseFloat(charge.pris) || 0;
            const pPerKwh  = kwh > 0 ? pris / kwh : 0;
            const vName    = (S.vehicles.find(v => v.id == charge.vehicleId)  || {}).vehicleName  || 'Ukendt';
            const pName    = (S.providers.find(p => p.id == charge.providerId) || {}).providerName || 'Ukendt';

            const icon = charge.source === 'internal'
                ? `<span class="material-symbols-outlined" data-bs-toggle="tooltip" title="ID: ${charge.id}">electrical_services</span>`
                : `<span class="material-symbols-outlined" data-bs-toggle="tooltip" title="${appUtils.escapeHtml(pName)}">ev_station</span>`;

            // State badge (internal charges only)
            let stateBadge = '';
            if (charge.state) {
                const stateMap = {
                    completed:       ['bg-success',   'Afsluttet'],
                    stopped_by_user: ['bg-warning text-dark', 'Stoppet'],
                    error:           ['bg-danger',    'Fejl'],
                };
                const [cls, label] = stateMap[charge.state] || ['bg-secondary', charge.state];
                const reasonTip = charge.stopReason ? ` data-bs-toggle="tooltip" title="${appUtils.escapeHtml(charge.stopReason)}"` : '';
                stateBadge = `<br><span class="badge ${cls}"${reasonTip}>${appUtils.escapeHtml(label)}</span>`;
            }

            let priceIcon = '', priceText = '';
            if (pPerKwh > avgPricePerKwh) { priceIcon = '↑';   priceText = 'højere end gennemsnit'; }
            else if (pPerKwh < avgPricePerKwh) { priceIcon = '↓'; priceText = 'lavere end gennemsnit'; }

            const fmtDate = new Date(charge.datetime).toLocaleString('da-DK', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });

            // Duration + avg power (internal charges only — ext_charges have no startedAt/stoppedAt)
            let sessionCell = '<td data-label="Varighed" class="text-muted text-center">—</td>';
            if (charge.startedAt && charge.stoppedAt) {
                const durH = (new Date(charge.stoppedAt) - new Date(charge.startedAt)) / 3_600_000;
                if (durH > 0.01) {
                    const h      = Math.floor(durH);
                    const m      = Math.round((durH - h) * 60);
                    const durStr = h > 0 ? `${h}h ${m < 10 ? '0' + m : m}m` : `${m}m`;
                    const avgKw  = kwh / durH;
                    let socHtml  = '';
                    if (charge.socPercentage !== null && charge.socPercentage !== undefined) {
                        const socStr = charge.socLimit
                            ? `SoC: ${charge.socPercentage}% / ${charge.socLimit}%`
                            : `SoC: ${charge.socPercentage}%`;
                        socHtml = `<br><small class="text-muted">${socStr}</small>`;
                    }
                    sessionCell  = `<td data-label="Varighed">${durStr}<br><small class="text-muted">Ø ${avgKw.toFixed(1)} kW</small>${socHtml}</td>`;
                }
            }

            const row = document.createElement('tr');
            row.dataset.chargeId  = charge.id;
            row.dataset.source    = charge.source;
            row.dataset.datetime  = charge.datetime;
            row.dataset.kwh       = kwh;
            row.dataset.pris      = pris;
            row.dataset.vehicleId = charge.vehicleId;
            if (charge.providerId) row.dataset.providerId = charge.providerId;

            // Pairing confidence indicator for internal charges.
            // 'default'/'unknown' = no automatic determination (unknown: vehicleId is NULL); 'uncertain' =
            // timing was ambiguous or outside the match window (two cars share
            // the charger, so timing is the only signal). Both warrant review.
            let pairingBadge = '';
            if (charge.source === 'internal' &&
                (charge.pairingSource === 'default' || charge.pairingSource === 'uncertain' || charge.pairingSource === 'unknown')) {
                const msg = charge.pairingSource === 'uncertain'
                    ? 'Bil-kobling usikker (flere biler tæt i tid, eller ingen aflæsning i tidsvinduet) — klik Rediger for at bekræfte'
                    : 'Bil kunne ikke bestemmes automatisk — klik Rediger for at rette';
                pairingBadge = ` <span class="text-warning" data-bs-toggle="tooltip" title="${msg}">⚠️</span>`;
            }

            row.innerHTML = `
                <td data-label="Dato">${fmtDate}</td>
                <td data-label="Forbrugt kWh">${kwh.toFixed(2)} kWh</td>
                ${sessionCell}
                <td data-label="Pris">${pris.toFixed(2)} kr</td>
                <td data-label="Pris pr kWh">${priceIcon ? priceIcon + ' ' : ''}${pPerKwh.toFixed(2)} kr/kWh${priceText ? ' <small>' + priceText + '</small>' : ''}</td>
                <td data-label="Bil">${appUtils.escapeHtml(vName)}${pairingBadge}</td>
                <td data-label="Type">${icon}${stateBadge}</td>
                <td data-label="Handling"><button class="btn btn-sm btn-secondary" data-action="edit">Rediger</button></td>
            `;
            S.chargeTableBodyEl.appendChild(row);
        });

        // "Hent flere" button
        if (remaining > 0) {
            const moreRow = document.createElement('tr');
            moreRow.id = 'charges-load-more-row';
            moreRow.innerHTML = `<td colspan="8" class="text-center py-2">
                <button class="btn btn-sm btn-outline-secondary" id="charges-load-more-btn">
                    Vis ${Math.min(remaining, _PAGE_SIZE)} flere <span class="text-muted">(${remaining} tilbage)</span>
                </button>
            </td>`;
            S.chargeTableBodyEl.appendChild(moreRow);
            document.getElementById('charges-load-more-btn').addEventListener('click', () => {
                _visibleRows += _PAGE_SIZE;
                _applySortAndRender();
            });
        }

        _updateSummary(totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice);
        appUtils.initializeTooltips();
    }

    function _updateSummary(totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice) {
        const summaryTextEl = document.getElementById('summaryText');
        const period = (S.dateRangeEl && S.dateRangeEl.value) ? S.dateRangeEl.value : 'alle datoer';
        let html = `<h3>Opsummering <small class="text-muted fw-normal">· ${appUtils.escapeHtml(period)}</small></h3>
        <table class="table table-sm">
            <tr><th>Bil</th><th>Samlet kWh</th><th>Hjemme (kr)</th><th>Ude (kr)</th><th>Samlet Pris</th><th>Gns. Pris/kWh</th></tr>`;

        Object.keys(totals).forEach(v => {
            const avg = totals[v].totalKwh > 0 ? totals[v].totalPris / totals[v].totalKwh : 0;
            html += `<tr>
                <td>${appUtils.escapeHtml(v)}</td>
                <td>${totals[v].totalKwh.toFixed(2)} kWh</td>
                <td>${totals[v].internalPris.toFixed(2)} kr</td>
                <td>${totals[v].externalPris.toFixed(2)} kr</td>
                <td>${totals[v].totalPris.toFixed(2)} kr</td>
                <td>${avg.toFixed(2)} kr/kWh</td>
            </tr>`;
        });

        const overallAvg = overallKwh > 0 ? overallPris / overallKwh : 0;
        html += `<tr style="font-weight:bold;">
            <td>Total</td>
            <td>${overallKwh.toFixed(2)} kWh</td>
            <td>${internalPrice.toFixed(2)} kr</td>
            <td>${externalPrice.toFixed(2)} kr</td>
            <td>${overallPris.toFixed(2)} kr</td>
            <td>${overallAvg.toFixed(2)} kr/kWh</td>
        </tr></table>`;
        if (summaryTextEl) summaryTextEl.innerHTML = html;

    }

    // -------------------------------------------------------------------------
    // Edit charge modal
    // -------------------------------------------------------------------------
    function editCharge(id, source, datetime, kwh, pris, vehicleId, providerId = null) {
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';

        // A charge without a vehicle (pairingSource 'unknown') arrives as NaN: leave the dropdown empty.
        if (!Number.isFinite(vehicleId)) vehicleId = '';

        if (source === 'internal') {
            document.getElementById('internalChargeId').value    = id;
            document.getElementById('internalVehicleId').value   = vehicleId;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('internalChargeModal')).show();
        } else if (source === 'external') {
            document.getElementById('externalChargeId').value    = id;
            document.getElementById('externalVehicleId').value   = vehicleId;
            document.getElementById('externalProviderId').value  = providerId;
            const dtEl = document.getElementById('externalChargeDateTime');
            dtEl._flatpickr ? dtEl._flatpickr.setDate(datetime) : (dtEl.value = datetime);
            document.getElementById('externalKwh').value  = kwh;
            document.getElementById('externalPris').value = pris;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('externalChargeModal')).show();
        }
    }

    // -------------------------------------------------------------------------
    // Modal form handlers
    // -------------------------------------------------------------------------
    async function _submitInternalCharge(e) {
        e.preventDefault();
        const form = e.target;
        if (!form.checkValidity()) { appUtils.showFormMessage('internalChargeMsg', 'Udfyld alle felter', 'danger'); return; }
        try {
            const res    = await fetch('api.php?action=update-internal-charge', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: document.getElementById('internalChargeId').value, vehicleId: document.getElementById('internalVehicleId').value }) });
            const result = await appUtils.handleFetchResponse(res);
            if (result.success) {
                bootstrap.Modal.getInstance(document.getElementById('internalChargeModal')).hide();
                appUtils.showToast('Intern ladning opdateret', 'success');
                fetchCharges();
                M.analytics.fetchVehicleComparison();
                window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
            } else {
                appUtils.showFormMessage('internalChargeMsg', result.error || 'Fejl', 'danger');
            }
        } catch (err) { appUtils.showFormMessage('internalChargeMsg', err.message, 'danger'); }
    }

    async function _submitExternalCharge(e) {
        e.preventDefault();
        const form = e.target;
        if (!form.checkValidity()) { appUtils.showFormMessage('externalChargeMsg', 'Udfyld alle felter', 'danger'); return; }
        try {
            const payload = {
                id: document.getElementById('externalChargeId').value,
                vehicleId: document.getElementById('externalVehicleId').value,
                providerId: document.getElementById('externalProviderId').value,
                datetime: document.getElementById('externalChargeDateTime').value,
                kwh: document.getElementById('externalKwh').value,
                pris: document.getElementById('externalPris').value,
            };
            const res    = await fetch('api.php?action=update-ext-charge', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const result = await appUtils.handleFetchResponse(res);
            if (result.success) {
                bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
                appUtils.showToast('Ekstern ladning opdateret', 'success');
                fetchCharges();
                M.analytics.fetchVehicleComparison();
                M.analytics.fetchProviderStats();
                window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
            } else {
                appUtils.showFormMessage('externalChargeMsg', result.error || 'Fejl', 'danger');
            }
        } catch (err) { appUtils.showFormMessage('externalChargeMsg', err.message, 'danger'); }
    }

    async function _submitCreateCharge(e) {
        e.preventDefault();
        const form = e.target;
        if (!form.checkValidity()) { appUtils.showFormMessage('exChargeMsg', 'Udfyld alle felter', 'danger'); return; }
        try {
            const payload = {
                vehicleId:      document.getElementById('vehicleId').value,
                providerId:     document.getElementById('providerId').value,
                chargeDateTime: document.getElementById('chargeDateTime').value,
                kwh:            document.getElementById('kwh').value,
                pris:           document.getElementById('pris').value,
            };
            const res  = await fetch('api.php?action=create-ext-charge', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.success) {
                // Remember choices to prefill the next time the modal opens.
                try {
                    if (payload.vehicleId)  sessionStorage.setItem('sparkspend_last_vehicle',  payload.vehicleId);
                    if (payload.providerId) sessionStorage.setItem('sparkspend_last_provider', payload.providerId);
                } catch (_) { /* storage may be unavailable � non-fatal */ }
                form.reset();
                _updateCreateCpkwh();
                bootstrap.Modal.getInstance(document.getElementById('createExChargeModal')).hide();
                appUtils.showToast('Ekstern ladning oprettet', 'success');
                fetchCharges();
                M.analytics.fetchVehicleComparison();
                M.analytics.fetchProviderStats();
                window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
            } else {
                appUtils.showFormMessage('exChargeMsg', data.error || 'Fejl', 'danger');
            }
        } catch (err) { appUtils.showFormMessage('exChargeMsg', err.message, 'danger'); }
    }

    // Prefill the create-charge modal with sensible defaults on open.
    function _prefillCreateCharge() {
        // Time = now (only if empty, so a mid-edit reopen isn't clobbered).
        const dtEl = document.getElementById('chargeDateTime');
        if (dtEl && !dtEl.value) {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ` +
                          `${pad(now.getHours())}:${pad(now.getMinutes())}`;
            dtEl._flatpickr ? dtEl._flatpickr.setDate(stamp, true) : (dtEl.value = stamp);
        }
        // Last used vehicle/provider.
        try {
            const lastV = sessionStorage.getItem('sparkspend_last_vehicle');
            const lastP = sessionStorage.getItem('sparkspend_last_provider');
            const vEl = document.getElementById('vehicleId');
            const pEl = document.getElementById('providerId');
            if (lastV && vEl && !vEl.value && vEl.querySelector(`option[value="${lastV}"]`)) vEl.value = lastV;
            if (lastP && pEl && !pEl.value && pEl.querySelector(`option[value="${lastP}"]`)) pEl.value = lastP;
        } catch (_) { /* storage unavailable � skip */ }
        _updateCreateCpkwh();
    }

    // Live kr/kWh readout under the price field to catch obvious typos.
    function _updateCreateCpkwh() {
        const kwh  = parseFloat(document.getElementById('kwh')?.value);
        const pris = parseFloat(document.getElementById('pris')?.value);
        let el = document.getElementById('createCpkwhHint');
        if (!el) {
            const priceInput = document.getElementById('pris');
            if (!priceInput) return;
            el = document.createElement('div');
            el.id = 'createCpkwhHint';
            el.className = 'form-text small mt-1';
            priceInput.insertAdjacentElement('afterend', el);
        }
        if (kwh > 0 && pris >= 0) {
            el.textContent = `� ${(pris / kwh).toFixed(2)} kr/kWh`;
            el.style.display = '';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    async function _deleteExternalCharge() {
        const id = document.getElementById('externalChargeId').value;
        const confirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteConfirmModal'));
        document.getElementById('deleteConfirmBtn').onclick = async () => {
            confirmModal.hide();
            try {
                const res    = await fetch('api.php?action=delete-ext-charge', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const result = await res.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
                    appUtils.showToast('Ladningen er slettet', 'success');
                    fetchCharges();
                    M.analytics.fetchVehicleComparison();
                    M.analytics.fetchProviderStats();
                    window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
                } else {
                    appUtils.showFormMessage('externalChargeMsg', result.error || 'Fejl', 'danger');
                }
            } catch (err) { appUtils.showFormMessage('externalChargeMsg', err.message, 'danger'); }
        };
        confirmModal.show();
    }


    function _setupModalFlatpickrs() {
        const fpConfig = { dateFormat: 'Y-m-d H:i', locale: 'da', enableTime: true, time_24hr: true };
        const chargeDateTime   = document.getElementById('chargeDateTime');
        const externalDateTime = document.getElementById('externalChargeDateTime');
        if (chargeDateTime)   flatpickr(chargeDateTime, fpConfig);
        if (externalDateTime) flatpickr(externalDateTime, fpConfig);
    }

    /** Back to the default order (newest first). */
    function resetSort() {
        _sortCol = 'datetime';
        _sortDir = 'desc';
    }

    function bind() {
        // Sort header click handler
        document.getElementById('chargeTableHead')?.addEventListener('click', e => {
            const th = e.target.closest('th[data-sort]');
            if (!th) return;
            const col = th.dataset.sort;
            if (_sortCol === col) {
                _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                _sortCol = col;
                _sortDir = col === 'datetime' ? 'desc' : 'asc';
            }
            _applySortAndRender();
        });


        // Charge table: edit button delegation
        S.chargeTableBodyEl.addEventListener('click', e => {
            const btn = e.target.closest('[data-action="edit"]');
            if (!btn) return;
            const row = btn.closest('tr');
            editCharge(
                row.dataset.chargeId,
                row.dataset.source,
                row.dataset.datetime,
                parseFloat(row.dataset.kwh),
                parseFloat(row.dataset.pris),
                parseInt(row.dataset.vehicleId),
                row.dataset.providerId ? parseInt(row.dataset.providerId) : null
            );
        });

        // Modal: edit internal charge
        document.getElementById('internalChargeForm').addEventListener('submit', _submitInternalCharge);

        // Modal: edit external charge
        document.getElementById('externalChargeForm').addEventListener('submit', _submitExternalCharge);

        // Modal: create external charge
        document.getElementById('exChargeForm').addEventListener('submit', _submitCreateCharge);

        // Prefill the create-charge modal each time it opens: current time and
        // the last used vehicle/provider, so the common case is one tap away.
        const createModalEl = document.getElementById('createExChargeModal');
        createModalEl?.addEventListener('show.bs.modal', _prefillCreateCharge);

        // Live kr/kWh readout as the user types kWh + price (catches typos).
        ['kwh', 'pris'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', _updateCreateCpkwh);
        });

        // Modal: delete external charge
        document.getElementById('deleteExternalChargeButton').addEventListener('click', _deleteExternalCharge);


        _setupModalFlatpickrs();
    }

    M.charges = { resetSort, fetchCharges, _sortCharges, _applySortAndRender, _updateSortHeaders, _renderCharges, _updateSummary, editCharge, _submitInternalCharge, _submitExternalCharge, _submitCreateCharge, _prefillCreateCharge, _updateCreateCpkwh, _deleteExternalCharge, _setupModalFlatpickrs, bind };
})();
