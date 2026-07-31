/**
 * SparkSpend – Elbil (EV) Module
 *
 * Handles all EV-related functionality:
 * - Filter drawer
 * - Vehicle / provider data loading
 * - Charge table (render, edit, create, delete)
 * - Pie charts (count / kWh / cost split)
 * - Efficiency charts (km/kWh, kr/km) via ApexCharts
 * - Cost analytics chart + statistics
 * - Vehicle comparison table
 *
 * Exposed as window.elbilApp = { init }
 * init() is called lazily by SparkNav on first visit to the Elbil tab.
 */

const elbilApp = (() => {
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------
    let vehicles  = [];
    let providers = [];
    let pieChartCount, pieChartKwh, pieChartPrice;
    let kmPerKwhChartInstance, krPerKmChartInstance, costTrendChartInstance;
    let currentVehicleSort = null;
    let initialized = false;

    // Sort state for charge table
    let _allCharges = [];
    let _sortCol = 'datetime';
    let _sortDir = 'desc';

    // Pagination
    const _PAGE_SIZE  = 50;
    let   _visibleRows = _PAGE_SIZE;

    // -------------------------------------------------------------------------
    // DOM refs (resolved at init time, after DOM is ready)
    // -------------------------------------------------------------------------
    let filterEl, showZeroKwhEl, dateRangeEl, quickFilterEl, chargeTableBodyEl;

    // -------------------------------------------------------------------------
    // Public: init
    // -------------------------------------------------------------------------
    function init() {
        if (initialized) return;
        initialized = true;

        filterEl          = document.getElementById('filter');
        showZeroKwhEl     = document.getElementById('showZeroKwh');
        dateRangeEl       = document.getElementById('dateRange');
        quickFilterEl     = document.getElementById('quickFilter');
        chargeTableBodyEl = document.getElementById('chargeTableBody');

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

        Chart.register(ChartDataLabels);

        _setupFlatpickr();
        _setupEventListeners();
        _setupModalFlatpickrs();

        // Default filter: this month — or restore from URL hash if present
        setTimeout(() => {
            const hashDr = window.SparkNav?.getHashDateRange?.();
            if (hashDr) {
                dateRangeEl._flatpickr.setDate([hashDr.start, hashDr.end]);
                quickFilterEl.value = '';
            } else {
                const today = new Date();
                const start = new Date(today.getFullYear(), today.getMonth(), 1);
                const end   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                dateRangeEl._flatpickr.setDate([start, end]);
                quickFilterEl.value = 'month';
            }
            fetchVehicles();
            fetchProviders();
        }, 50);
    }

    // -------------------------------------------------------------------------
    // Flatpickr setup
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Date range helpers
    // -------------------------------------------------------------------------
    function getDateRange() {
        if (!dateRangeEl?._flatpickr) return null;
        const dates = dateRangeEl._flatpickr.selectedDates;
        if (dates.length < 2) return null;
        const fmt = d => d.toISOString().slice(0, 10);
        return { start: fmt(dates[0]), end: fmt(dates[1]) };
    }

    function _setupFlatpickr() {
        flatpickr(dateRangeEl, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            locale: 'da',
            onClose: () => {
                fetchCharges();
                fetchEfficiencyStats();
                fetchCostAnalytics();
                fetchVehicleComparison();
                window.SparkNav?.updateHash();
            }
        });
    }

    function _setupModalFlatpickrs() {
        const fpConfig = { dateFormat: 'Y-m-d H:i', locale: 'da', enableTime: true, time_24hr: true };
        const chargeDateTime   = document.getElementById('chargeDateTime');
        const externalDateTime = document.getElementById('externalChargeDateTime');
        if (chargeDateTime)   flatpickr(chargeDateTime, fpConfig);
        if (externalDateTime) flatpickr(externalDateTime, fpConfig);
    }

    // -------------------------------------------------------------------------
    // Event listeners
    // -------------------------------------------------------------------------
    function _setupEventListeners() {
        // Charge table: edit button delegation
        chargeTableBodyEl.addEventListener('click', e => {
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

        filterEl.addEventListener('change',     _onFilterChange);
        showZeroKwhEl.addEventListener('change', _onFilterChange);
        quickFilterEl.addEventListener('change', applyQuickFilter);
        dateRangeEl.addEventListener('change',   _onFilterChange);

        document.querySelectorAll('button[data-grouping]').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('button[data-grouping]').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                fetchCostAnalytics();
            });
        });

        // Filter drawer
        const filterDrawer   = document.getElementById('filterDrawer');
        const filterBackdrop = document.getElementById('filterBackdrop');
        const toggleBtn      = document.getElementById('filterToggleBtn');
        const closeBtn       = document.getElementById('filterCloseBtn');

        toggleBtn?.addEventListener('click', () => {
            filterDrawer.classList.toggle('open');
            filterBackdrop.classList.toggle('visible');
        });
        closeBtn?.addEventListener('click', _closeFilterDrawer);
        filterBackdrop?.addEventListener('click', _closeFilterDrawer);

        function _closeFilterDrawer() {
            filterDrawer.classList.remove('open');
            filterBackdrop.classList.remove('visible');
        }

        // Modal: edit internal charge
        document.getElementById('internalChargeForm').addEventListener('submit', _submitInternalCharge);

        // Modal: edit external charge
        document.getElementById('externalChargeForm').addEventListener('submit', _submitExternalCharge);

        // Modal: create external charge
        document.getElementById('exChargeForm').addEventListener('submit', _submitCreateCharge);

        // Modal: delete external charge
        document.getElementById('deleteExternalChargeButton').addEventListener('click', _deleteExternalCharge);

        // Year-over-year metric toggle
        document.getElementById('evCompareKwhBtn')?.addEventListener('click', function () {
            if (evYearMetric === 'kwh') return;
            evYearMetric = 'kwh';
            this.classList.add('active');
            document.getElementById('evCompareCostBtn')?.classList.remove('active');
            if (evYearCompareData) _renderEvYearChart(evYearCompareData);
        });
        document.getElementById('evCompareCostBtn')?.addEventListener('click', function () {
            if (evYearMetric === 'cost') return;
            evYearMetric = 'cost';
            this.classList.add('active');
            document.getElementById('evCompareKwhBtn')?.classList.remove('active');
            if (evYearCompareData) _renderEvYearChart(evYearCompareData);
        });
    }

    function _onFilterChange() {
        fetchCharges();
        fetchEfficiencyStats();
        fetchCostAnalytics();
        fetchProviderStats();
    }

    // -------------------------------------------------------------------------
    // Quick filter
    // -------------------------------------------------------------------------
    function applyQuickFilter() {
        const val   = quickFilterEl.value;
        const today = new Date();
        let start, end;

        switch (val) {
            case 'today':
                start = end = today; break;
            case 'week':
                start = new Date(today);
                start.setDate(today.getDate() - ((today.getDay() + 6) % 7));
                end = new Date(start);
                end.setDate(start.getDate() + 6);
                break;
            case 'lmonth':
                start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                end   = new Date(today.getFullYear(), today.getMonth(), 0);
                break;
            case 'month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                end   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                break;
            case 'year':
                start = new Date(today.getFullYear(), 0, 1);
                end   = new Date(today.getFullYear(), 11, 31);
                break;
            default:
                start = end = null;
        }

        if (start && end) {
            dateRangeEl._flatpickr.setDate([start, end]);
        } else {
            dateRangeEl._flatpickr.clear();
        }
        fetchCharges();
        fetchEfficiencyStats();
        fetchCostAnalytics();
        fetchVehicleComparison();
        fetchProviderStats();
        window.SparkNav?.updateHash();
    }

    function resetFilters() {
        if (filterEl)      filterEl.value = 'all';
        if (showZeroKwhEl) showZeroKwhEl.checked = false;
        if (quickFilterEl) quickFilterEl.value = 'month';
        _sortCol = 'datetime';
        _sortDir = 'desc';
        applyQuickFilter();
    }

    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------
    async function fetchVehicles() {
        try {
            const res  = await fetch('api.php?action=vehicles');
            const data = await appUtils.handleFetchResponse(res);
            vehicles = data;
            _populateVehicleFilter();
            fetchCharges();
            fetchEfficiencyStats();
            fetchCostAnalytics();
            fetchVehicleComparison();
            fetchProviderStats();
        } catch (e) {
            console.error('fetchVehicles:', e);
        }
    }

    async function fetchProviders() {
        try {
            const res  = await fetch('api.php?action=providers');
            const data = await appUtils.handleFetchResponse(res);
            providers = data;
            _populateProviderSelects();
        } catch (e) {
            console.error('fetchProviders:', e);
        }
    }

    function _populateVehicleFilter() {
        filterEl.querySelectorAll('option[data-vehicle]').forEach(o => o.remove());
        vehicles.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.id;
            opt.textContent = v.vehicleName;
            opt.dataset.vehicle = 'true';
            filterEl.appendChild(opt);
        });
        filterEl.value = 'all';

        // Also populate vehicle selects in modals
        ['internalVehicleId', 'externalVehicleId', 'vehicleId'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.querySelectorAll('option[data-vehicle]').forEach(o => o.remove());
            vehicles.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.id;
                opt.textContent = v.vehicleName;
                opt.dataset.vehicle = 'true';
                sel.appendChild(opt);
            });
        });
    }

    function _populateProviderSelects() {
        ['externalProviderId', 'providerId'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.querySelectorAll('option[data-provider]').forEach(o => o.remove());
            providers.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.providerName;
                opt.dataset.provider = 'true';
                sel.appendChild(opt);
            });
        });
    }

    async function fetchCharges() {
        const params = new URLSearchParams({
            filter:      filterEl.value,
            showZeroKwh: showZeroKwhEl.checked,
            dateRange:   dateRangeEl.value
        });
        appUtils.setLoadingState('chargeTableBody', true);
        try {
            const res  = await fetch('api.php?action=charges&' + params);
            const data = await appUtils.handleFetchResponse(res);
            _allCharges   = data;
            _visibleRows  = _PAGE_SIZE;
            _applySortAndRender();
        } catch (e) {
            chargeTableBodyEl.innerHTML =
                `<tr><td colspan="8" class="text-center text-danger">Fejl: ${e.message}</td></tr>`;
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
                    av = (vehicles.find(v => v.id == a.vehicleId) || {}).vehicleName || '';
                    bv = (vehicles.find(v => v.id == b.vehicleId) || {}).vehicleName || '';
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
        _renderCharges(_sortCharges(_allCharges));
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
        chargeTableBodyEl.innerHTML = '';
        if (data.length === 0) {
            chargeTableBodyEl.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Ingen ladninger fundet for den valgte periode  ·  <button type="button" class="btn btn-sm btn-secondary" onclick="elbilApp.resetFilters()">Nulstil filtre</button></td></tr>';
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
            const vName = (vehicles.find(v => v.id == charge.vehicleId) || {}).vehicleName || 'Ukendt';

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
            const vName    = (vehicles.find(v => v.id == charge.vehicleId)  || {}).vehicleName  || 'Ukendt';
            const pName    = (providers.find(p => p.id == charge.providerId) || {}).providerName || 'Ukendt';

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
                stateBadge = `<br><span class="badge ${cls}"${reasonTip}>${label}</span>`;
            }

            let priceIcon = '', priceText = '';
            if (pPerKwh > avgPricePerKwh) { priceIcon = '↑';   priceText = 'højere end gennemsnit'; }
            else if (pPerKwh < avgPricePerKwh) { priceIcon = '↓'; priceText = 'lavere end gennemsnit'; }

            const fmtDate = new Date(charge.datetime).toLocaleString('da-DK', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });

            // Duration + avg power (internal charges only — ext_charges have no startedAt/stoppedAt)
            let sessionCell = '<td class="text-muted text-center">—</td>';
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
                    sessionCell  = `<td>${durStr}<br><small class="text-muted">Ø ${avgKw.toFixed(1)} kW</small>${socHtml}</td>`;
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

            row.innerHTML = `
                <td>${fmtDate}</td>
                <td>${kwh.toFixed(2)} kWh</td>
                ${sessionCell}
                <td>${pris.toFixed(2)} kr</td>
                <td>${priceIcon ? priceIcon + ' ' : ''}${pPerKwh.toFixed(2)} kr/kWh${priceText ? ' <small>' + priceText + '</small>' : ''}</td>
                <td>${appUtils.escapeHtml(vName)}</td>
                <td>${icon}${stateBadge}</td>
                <td><button class="btn btn-sm btn-secondary" data-action="edit">Rediger</button></td>
            `;
            chargeTableBodyEl.appendChild(row);
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
            chargeTableBodyEl.appendChild(moreRow);
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
        let html = `<h3>Opsummering</h3>
        <table class="table table-sm">
            <tr><th>Bil</th><th>Samlet kWh</th><th>Hjemme (kr)</th><th>Ude (kr)</th><th>Samlet Pris</th><th>Gns. Pris/kWh</th></tr>`;

        Object.keys(totals).forEach(v => {
            const avg = totals[v].totalKwh > 0 ? totals[v].totalPris / totals[v].totalKwh : 0;
            html += `<tr>
                <td>${v}</td>
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

        _updatePieCharts(internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice);
    }

    // -------------------------------------------------------------------------
    // Pie charts
    // -------------------------------------------------------------------------
    function _updatePieCharts(internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice) {
        const datalabelsPlugin = {
            formatter: (value, ctx) => {
                const sum = ctx.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                return sum ? ((value / sum) * 100).toFixed(0) + '%' : '0%';
            },
            color: '#fff',
            font: { weight: 'bold', size: 11 }
        };

        const charts = [
            { id: 'pieChartCount', data: [internalCount, externalCount], title: 'Antal',  ref: 'pieChartCount' },
            { id: 'pieChartKwh',   data: [internalKwh,   externalKwh],   title: 'kWh',   ref: 'pieChartKwh'   },
            { id: 'pieChartPrice', data: [internalPrice, externalPrice], title: 'Pris',  ref: 'pieChartPrice' },
        ];

        charts.forEach(cfg => {
            if (cfg.ref === 'pieChartCount' && pieChartCount) { pieChartCount.destroy(); }
            if (cfg.ref === 'pieChartKwh'   && pieChartKwh)   { pieChartKwh.destroy(); }
            if (cfg.ref === 'pieChartPrice' && pieChartPrice) { pieChartPrice.destroy(); }

            const canvas = document.getElementById(cfg.id);
            if (!canvas) return;
            const chart = new Chart(canvas.getContext('2d'), {
                type: 'pie',
                data: { labels: ['Interne', 'Eksterne'], datasets: [{ data: cfg.data, backgroundColor: ['#4e73df', '#e74a3b'] }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: cfg.title, font: { size: 12, weight: 'bold' } },
                        datalabels: datalabelsPlugin
                    }
                }
            });

            if (cfg.ref === 'pieChartCount') pieChartCount = chart;
            if (cfg.ref === 'pieChartKwh')   pieChartKwh   = chart;
            if (cfg.ref === 'pieChartPrice') pieChartPrice = chart;
        });
    }

    // -------------------------------------------------------------------------
    // Edit charge modal
    // -------------------------------------------------------------------------
    function editCharge(id, source, datetime, kwh, pris, vehicleId, providerId = null) {
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';

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
                appUtils.showFormMessage('internalChargeMsg', 'Intern ladning opdateret!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('internalChargeModal')).hide();
                fetchCharges();
                fetchVehicleComparison();
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
                appUtils.showFormMessage('externalChargeMsg', 'Ekstern ladning opdateret!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
                fetchCharges();
                fetchVehicleComparison();
                fetchProviderStats();
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
                appUtils.showFormMessage('exChargeMsg', 'Ekstern ladning oprettet!', 'success');
                form.reset();
                bootstrap.Modal.getInstance(document.getElementById('createExChargeModal')).hide();
                fetchCharges();
                fetchVehicleComparison();
                fetchProviderStats();
                window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
            } else {
                appUtils.showFormMessage('exChargeMsg', data.error || 'Fejl', 'danger');
            }
        } catch (err) { appUtils.showFormMessage('exChargeMsg', err.message, 'danger'); }
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
                    appUtils.showFormMessage('externalChargeMsg', 'Ladningen er slettet!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
                    fetchCharges();
                    fetchVehicleComparison();
                    fetchProviderStats();
                    window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
                } else {
                    appUtils.showFormMessage('externalChargeMsg', result.error || 'Fejl', 'danger');
                }
            } catch (err) { appUtils.showFormMessage('externalChargeMsg', err.message, 'danger'); }
        };
        confirmModal.show();
    }

    // -------------------------------------------------------------------------
    // Efficiency charts
    // -------------------------------------------------------------------------
    function fetchEfficiencyStats() {
        const params = new URLSearchParams({ filter: filterEl.value, showZeroKwh: showZeroKwhEl.checked, dateRange: dateRangeEl.value });
        fetch('api.php?action=efficiency&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderEfficiencyCharts(data); })
            .catch(err => {
                document.getElementById('kmPerKwhChart').innerHTML = `<div class="alert alert-danger">Fejl: ${appUtils.escapeHtml(err.message)}</div>`;
                document.getElementById('krPerKmChart').innerHTML  = `<div class="alert alert-danger">Fejl: ${appUtils.escapeHtml(err.message)}</div>`;
            });
    }

    function _renderEfficiencyCharts(data) {
        const grouped = {};
        data.forEach(e => { (grouped[e.vehicleId] = grouped[e.vehicleId] || []).push(e); });

        const kmSeries = [], krSeries = [];
        Object.keys(grouped).forEach(vid => {
            const entries = grouped[vid].sort((a, b) => a.timestamp - b.timestamp);
            const vName   = (vehicles.find(v => v.id == vid) || {}).vehicleName || 'Bil ' + vid;
            kmSeries.push({ name: vName, data: entries.map(e => [e.timestamp * 1000, e.totalKmPerKwh]) });
            krSeries.push({ name: vName, data: entries.map(e => [e.timestamp * 1000, e.totalKrPerKm]) });
        });

        const baseOpts = { chart: { type: 'line', height: 300 }, xaxis: { type: 'datetime', title: { text: 'Dato' } }, stroke: { curve: 'smooth' } };

        if (kmPerKwhChartInstance) kmPerKwhChartInstance.destroy();
        kmPerKwhChartInstance = new ApexCharts(document.querySelector('#kmPerKwhChart'), { ...baseOpts, series: kmSeries, yaxis: { title: { text: 'KM per kWh' }, min: 0 }, title: { text: 'KM per kWh pr. bil' } });
        kmPerKwhChartInstance.render();

        if (krPerKmChartInstance) krPerKmChartInstance.destroy();
        krPerKmChartInstance = new ApexCharts(document.querySelector('#krPerKmChart'), { ...baseOpts, series: krSeries, yaxis: { title: { text: 'Kr per KM' }, min: 0 }, title: { text: 'Kr per KM pr. bil' } });
        krPerKmChartInstance.render();
    }

    // -------------------------------------------------------------------------
    // Cost analytics
    // -------------------------------------------------------------------------
    function fetchCostAnalytics() {
        const groupBy = document.querySelector('button[data-grouping].active')?.dataset.grouping || 'week';
        const params  = new URLSearchParams({ filter: filterEl.value, dateRange: dateRangeEl.value, groupBy, showZeroKwh: showZeroKwhEl.checked });
        fetch('api.php?action=charge-analytics&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderCostAnalytics(data); })
            .catch(err => {
                document.getElementById('costTrendChart').innerHTML    = `<div class="alert alert-danger">Fejl: ${appUtils.escapeHtml(err.message)}</div>`;
                document.getElementById('costStatsContent').innerHTML  = `<div class="alert alert-danger">Fejl: ${appUtils.escapeHtml(err.message)}</div>`;
            });
    }

    function _renderCostAnalytics(data) {
        _renderCostTrendChart(data.trend.daily_totals);
        _renderCostStatistics(data.statistics);
    }

    function _renderCostTrendChart(dailyData) {
        // Aggregate duplicate dates defensively (ApexCharts adds A/B/C suffixes
        // when the same category appears multiple times).
        const merged = new Map();
        for (const d of dailyData) {
            if (!merged.has(d.date)) {
                merged.set(d.date, {
                    date:          d.date,
                    internal_kwh:  parseFloat(d.internal_kwh)  || 0,
                    internal_cost: parseFloat(d.internal_cost) || 0,
                    external_kwh:  parseFloat(d.external_kwh)  || 0,
                    external_cost: parseFloat(d.external_cost) || 0,
                });
            } else {
                const m = merged.get(d.date);
                m.internal_kwh  += parseFloat(d.internal_kwh)  || 0;
                m.internal_cost += parseFloat(d.internal_cost) || 0;
                m.external_kwh  += parseFloat(d.external_kwh)  || 0;
                m.external_cost += parseFloat(d.external_cost) || 0;
            }
        }
        const deduped  = [...merged.values()];
        const dates    = deduped.map(d => d.date);
        const internal = deduped.map(d => d.internal_cost);
        const external = deduped.map(d => d.external_cost);

        const opts = {
            chart: { type: 'area', height: 400, stacked: true },
            series: [{ name: 'Indre ladning (kr)', data: internal }, { name: 'Ekstern ladning (kr)', data: external }],
            xaxis: { categories: dates },
            yaxis: { title: { text: 'Omkostning (kr)' } },
            title: { text: 'Omkostningstendenser' },
            stroke: { curve: 'smooth' },
            tooltip: { y: { formatter: v => v.toFixed(2) + ' kr' } }
        };

        if (costTrendChartInstance) costTrendChartInstance.destroy();
        costTrendChartInstance = new ApexCharts(document.querySelector('#costTrendChart'), opts);
        costTrendChartInstance.render();
    }

    function _renderCostStatistics(stats) {
        const el = document.getElementById('costStatsContent');
        if (!el) return;
        const totalKwh       = parseFloat(stats.total_kwh)          || 0;
        const totalCost      = parseFloat(stats.total_cost)         || 0;
        const avgCost        = parseFloat(stats.avg_cost_per_charge) || 0;
        const avgCostPerKwh  = parseFloat(stats.avg_cost_per_kwh)   || 0;
        const minCost        = parseFloat(stats.min_cost)           || 0;
        const maxCost        = parseFloat(stats.max_cost)           || 0;
        el.innerHTML = `
            <table class="table table-sm mb-0">
                <tr><td><strong>Samlet kWh:</strong></td><td>${totalKwh.toFixed(2)}</td></tr>
                <tr><td><strong>I alt kr:</strong></td><td>${totalCost.toFixed(2)}</td></tr>
                <tr><td><strong>Gennemsnit pr. ladning:</strong></td><td>${avgCost.toFixed(2)} kr</td></tr>
                <tr><td><strong>Gennemsnit pr. kWh:</strong></td><td>${avgCostPerKwh.toFixed(3)} kr/kWh</td></tr>
                <tr><td><strong>Min pr. ladning:</strong></td><td>${minCost.toFixed(2)} kr</td></tr>
                <tr><td><strong>Max pr. ladning:</strong></td><td>${maxCost.toFixed(2)} kr</td></tr>
            </table>`;
    }

    // -------------------------------------------------------------------------
    // Vehicle comparison
    // -------------------------------------------------------------------------
    function fetchVehicleComparison(sortBy) {
        if (sortBy) currentVehicleSort = sortBy;
        const params = new URLSearchParams({ dateRange: dateRangeEl.value });
        if (currentVehicleSort) params.set('sortBy', currentVehicleSort);
        fetch('api.php?action=vehicle-comparison&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderVehicleComparison(data.vehicles); })
            .catch(err => { document.getElementById('vehicleComparisonTableBody').innerHTML = `<tr><td colspan="7" class="text-center text-danger">Fejl: ${appUtils.escapeHtml(err.message)}</td></tr>`; });
    }

    function _renderVehicleComparison(data) {
        const tbody = document.getElementById('vehicleComparisonTableBody');
        if (!data || !data.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center">Ingen data</td></tr>'; return; }
        tbody.innerHTML = data.map(v => `
            <tr>
                <td><strong>${v.vehicleName}</strong></td>
                <td>${v.charge_count}</td>
                <td>${v.total_kwh.toFixed(2)} kWh</td>
                <td>${v.total_cost.toFixed(2)} kr</td>
                <td>${v.avg_cost_per_kwh.toFixed(3)} kr/kWh</td>
                <td>${v.internal_percentage.toFixed(1)}%</td>
                <td>${v.external_percentage.toFixed(1)}%</td>
            </tr>`).join('');
    }

    // Expose sortVehicleComparison globally (called from HTML onclick)
    window.sortVehicleComparison = fetchVehicleComparison;

    // -------------------------------------------------------------------------
    // Provider stats
    // -------------------------------------------------------------------------
    function fetchProviderStats() {
        const params = new URLSearchParams({ filter: filterEl.value, dateRange: dateRangeEl.value });
        fetch('api.php?action=provider-stats&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderProviderStats(data); })
            .catch(err => {
                const el = document.getElementById('providerStatsContent');
                if (el) el.innerHTML = `<p class="text-danger small">Fejl: ${appUtils.escapeHtml(err.message)}</p>`;
            });
    }

    function _renderProviderStats(data) {
        const el = document.getElementById('providerStatsContent');
        if (!el) return;
        if (!data || !data.length) {
            el.innerHTML = '<p class="text-muted small">Ingen eksterne ladninger i den valgte periode</p>';
            return;
        }
        el.innerHTML = `
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Ladeoperatør</th>
                    <th class="text-end">Ladninger</th>
                    <th class="text-end">kWh</th>
                    <th class="text-end">kr</th>
                    <th class="text-end">kr/kWh</th>
                  </tr>
                </thead>
                <tbody>
                  ${data.map(p => `
                  <tr>
                    <td>${p.providerName}</td>
                    <td class="text-end">${p.charge_count}</td>
                    <td class="text-end">${p.total_kwh.toFixed(2)}</td>
                    <td class="text-end">${p.total_cost.toFixed(2)}</td>
                    <td class="text-end">${p.avg_cost_per_kwh.toFixed(3)}</td>
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>`;
    }

    // -------------------------------------------------------------------------
    // Year-over-year comparison chart
    // -------------------------------------------------------------------------
    const EV_YEAR_COLORS = ['#3b82f6', '#22c55e', '#f97316', '#8b5cf6', '#ef4444', '#ec4899'];
    const EV_MONTH_NAMES = ['Jan','Feb','Mar','Apr','Maj','Jun','Jul','Aug','Sep','Okt','Nov','Dec'];
    let evYearCompareChart = null;
    let evYearCompareData  = null;
    let evYearMetric       = 'kwh'; // 'kwh' | 'cost'

    function fetchYearlyComparison() {
        const noData  = document.getElementById('evYearCompareNoData');
        const errEl   = document.getElementById('evYearCompareError');
        const statsEl = document.getElementById('evYearCompareStats');
        if (noData)  noData.style.display  = 'none';
        if (errEl)   errEl.style.display   = 'none';
        if (statsEl) statsEl.innerHTML     = '<p class="text-muted small">Indlæser…</p>';

        fetch('api.php?action=charge-compare')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => {
                if (!Array.isArray(data) || data.length === 0) {
                    if (noData) noData.style.display = '';
                    if (statsEl) statsEl.innerHTML = '';
                    return;
                }
                evYearCompareData = data;
                _renderEvYearChart(data);
                _renderEvYearStats(data);
            })
            .catch(err => {
                console.error('fetchYearlyComparison:', err);
                if (errEl) errEl.style.display = '';
                if (statsEl) statsEl.innerHTML = '';
            });
    }

    function _renderEvYearChart(data) {
        const canvas = document.getElementById('evYearCompareChart');
        if (!canvas) return;
        if (evYearCompareChart) { evYearCompareChart.destroy(); evYearCompareChart = null; }

        // Capture hidden datasets before rebuild
        const hiddenLabels = new Set();

        // Pivot: Map<year, Array(12)> — null for months without data
        const yearMap = new Map();
        data.forEach(d => {
            if (!yearMap.has(d.year)) yearMap.set(d.year, new Array(12).fill(null));
            const val = evYearMetric === 'cost' ? parseFloat(d.total_cost) : parseFloat(d.total_kwh);
            yearMap.get(d.year)[parseInt(d.month, 10) - 1] = val;
        });

        const years = [...yearMap.keys()].sort();

        const datasets = years.map((year, i) => {
            const color = EV_YEAR_COLORS[i % EV_YEAR_COLORS.length];
            return {
                label: year,
                data: yearMap.get(year),
                borderColor: color,
                backgroundColor: color + '20',
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.35,
                spanGaps: false,
            };
        });

        // Monthly average across all years (dashed reference line)
        const avgData = Array.from({ length: 12 }, (_, m) => {
            const vals = [...yearMap.values()].map(arr => arr[m]).filter(v => v !== null);
            return vals.length > 0
                ? parseFloat((vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(1))
                : null;
        });
        datasets.push({
            label: 'Gns.',
            data: avgData,
            borderColor: '#94a3b8',
            backgroundColor: 'transparent',
            borderWidth: 2,
            borderDash: [6, 4],
            pointRadius: 0,
            tension: 0.35,
            spanGaps: true,
        });

        const yLabel = evYearMetric === 'cost' ? 'kr' : 'kWh';

        evYearCompareChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: EV_MONTH_NAMES, datasets },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    datalabels: { display: false },
                    tooltip: {
                        callbacks: {
                            label: c => {
                                if (c.parsed.y === null) return `${c.dataset.label}: ingen data`;
                                const v = c.parsed.y;
                                return evYearMetric === 'cost'
                                    ? `${c.dataset.label}: ${v.toFixed(0)} kr`
                                    : `${c.dataset.label}: ${v.toFixed(1)} kWh`;
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: yLabel } }
                }
            }
        });

        _buildEvYearChips(years, hiddenLabels);
    }

    function _buildEvYearChips(years, hiddenLabels = new Set()) {
        const container = document.getElementById('evYearToggleContainer');
        if (!container) return;
        container.innerHTML = '';
        let needsUpdate = false;

        years.forEach((year, i) => {
            const color    = EV_YEAR_COLORS[i % EV_YEAR_COLORS.length];
            const isHidden = hiddenLabels.has(year);
            const chip     = document.createElement('button');
            chip.type      = 'button';
            chip.className = 'year-chip' + (isHidden ? '' : ' active');
            chip.textContent = year;
            chip.style.setProperty('--chip-color', color);
            chip.addEventListener('click', () => {
                const nowActive = chip.classList.toggle('active');
                if (evYearCompareChart) {
                    evYearCompareChart.setDatasetVisibility(i, nowActive);
                    evYearCompareChart.update();
                }
            });
            container.appendChild(chip);
            if (isHidden && evYearCompareChart) {
                evYearCompareChart.setDatasetVisibility(i, false);
                needsUpdate = true;
            }
        });

        // Gns. chip
        const avgIdx  = years.length;
        const avgChip = document.createElement('button');
        avgChip.type  = 'button';
        const avgHidden = hiddenLabels.has('Gns.');
        avgChip.className = 'year-chip' + (avgHidden ? '' : ' active');
        avgChip.textContent = 'Gns.';
        avgChip.style.setProperty('--chip-color', '#94a3b8');
        avgChip.addEventListener('click', () => {
            const nowActive = avgChip.classList.toggle('active');
            if (evYearCompareChart) {
                evYearCompareChart.setDatasetVisibility(avgIdx, nowActive);
                evYearCompareChart.update();
            }
        });
        container.appendChild(avgChip);
        if (avgHidden && evYearCompareChart) {
            evYearCompareChart.setDatasetVisibility(avgIdx, false);
            needsUpdate = true;
        }

        if (needsUpdate && evYearCompareChart) evYearCompareChart.update();
        container.style.display = 'flex';
    }

    function _renderEvYearStats(data) {
        const statsEl = document.getElementById('evYearCompareStats');
        if (!statsEl) return;

        // Per-year totals
        const yearKwh  = new Map();
        const yearCost = new Map();
        const yearCnt  = new Map();
        data.forEach(d => {
            yearKwh.set(d.year,  (yearKwh.get(d.year)  || 0) + parseFloat(d.total_kwh));
            yearCost.set(d.year, (yearCost.get(d.year) || 0) + parseFloat(d.total_cost));
            yearCnt.set(d.year,  (yearCnt.get(d.year)  || 0) + 1);
        });

        const years = [...yearKwh.keys()].sort();
        const rows = years.map((year, i) => {
            const color   = EV_YEAR_COLORS[i % EV_YEAR_COLORS.length];
            const kwh     = yearKwh.get(year);
            const cost    = yearCost.get(year);
            const months  = yearCnt.get(year);
            const cpkwh   = kwh > 0 ? cost / kwh : 0;
            return `<tr>
                <td><span class="year-dot" style="background:${color}"></span>${year}</td>
                <td class="text-end fw-bold">${kwh.toFixed(1)} kWh</td>
                <td class="text-end">${cost.toFixed(0)} kr</td>
                <td class="text-end text-muted" style="font-size:11px">${cpkwh.toFixed(2)} kr/kWh</td>
                <td class="text-end text-muted" style="font-size:11px">${months} mdr.</td>
            </tr>`;
        }).join('');

        statsEl.innerHTML = `
            <table class="table table-sm mb-0">
                <thead><tr>
                    <th>År</th>
                    <th class="text-end">kWh</th>
                    <th class="text-end">kr</th>
                    <th class="text-end">kr/kWh</th>
                    <th class="text-end">Mdr.</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    // -------------------------------------------------------------------------
    // Manage modal: vehicles + providers
    // -------------------------------------------------------------------------

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function _showManageMsg(msg, type = 'danger') {
        const el = document.getElementById('manageMsg');
        if (el) el.innerHTML = `<span class="text-${type}">${msg}</span>`;
    }

    function openManageModal() {
        _renderManageVehicles(vehicles);
        _renderManageProviders(providers);
        document.getElementById('manageMsg').textContent = '';
        document.getElementById('newProviderName').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('manageModal')).show();
    }

    function _renderManageVehicles(list) {
        const el = document.getElementById('manageVehiclesList');
        if (!el) return;
        if (!list.length) {
            el.innerHTML = '<p class="text-muted small">Ingen køretøjer registreret.</p>';
            return;
        }
        el.innerHTML = list.map(v => `
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="flex-grow-1" id="vname-${v.id}">${_esc(v.vehicleName)}</span>
              <input type="text" class="form-control form-control-sm d-none" id="vinput-${v.id}"
                     value="${_esc(v.vehicleName)}" maxlength="80">
              <button class="btn btn-sm btn-outline-secondary" id="vedit-${v.id}"
                      onclick="elbilApp.startEditVehicle(${v.id})">
                <i class="fas fa-pencil"></i>
              </button>
              <button class="btn btn-sm btn-success d-none" id="vsave-${v.id}"
                      onclick="elbilApp.saveVehicle(${v.id})">
                <i class="fas fa-check"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary d-none" id="vcancel-${v.id}"
                      onclick="elbilApp.cancelEditVehicle(${v.id}, ${JSON.stringify(v.vehicleName)})">
                <i class="fas fa-times"></i>
              </button>
            </div>`).join('');
    }

    function _renderManageProviders(list) {
        const el = document.getElementById('manageProvidersList');
        if (!el) return;
        if (!list.length) {
            el.innerHTML = '<p class="text-muted small mb-0">Ingen udbydere registreret.</p>';
            return;
        }
        el.innerHTML = list.map(p => `
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="flex-grow-1" id="pname-${p.id}">${_esc(p.providerName)}</span>
              <input type="text" class="form-control form-control-sm d-none" id="pinput-${p.id}"
                     value="${_esc(p.providerName)}" maxlength="80">
              <button class="btn btn-sm btn-outline-secondary" id="pedit-${p.id}"
                      onclick="elbilApp.startEditProvider(${p.id})">
                <i class="fas fa-pencil"></i>
              </button>
              <button class="btn btn-sm btn-success d-none" id="psave-${p.id}"
                      onclick="elbilApp.saveProvider(${p.id})">
                <i class="fas fa-check"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary d-none" id="pcancel-${p.id}"
                      onclick="elbilApp.cancelEditProvider(${p.id}, ${JSON.stringify(p.providerName)})">
                <i class="fas fa-times"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger" id="pdel-${p.id}"
                      onclick="elbilApp.deleteProvider(${p.id})">
                <i class="fas fa-trash"></i>
              </button>
            </div>`).join('');
    }

    function startEditVehicle(id) {
        document.getElementById('vname-' + id)?.classList.add('d-none');
        document.getElementById('vinput-' + id)?.classList.remove('d-none');
        document.getElementById('vedit-' + id)?.classList.add('d-none');
        document.getElementById('vsave-' + id)?.classList.remove('d-none');
        document.getElementById('vcancel-' + id)?.classList.remove('d-none');
        document.getElementById('vinput-' + id)?.focus();
    }

    function cancelEditVehicle(id, originalName) {
        document.getElementById('vname-' + id)?.classList.remove('d-none');
        document.getElementById('vinput-' + id)?.classList.add('d-none');
        document.getElementById('vedit-' + id)?.classList.remove('d-none');
        document.getElementById('vsave-' + id)?.classList.add('d-none');
        document.getElementById('vcancel-' + id)?.classList.add('d-none');
        const inp = document.getElementById('vinput-' + id);
        if (inp) inp.value = originalName;
    }

    async function saveVehicle(id) {
        const inp  = document.getElementById('vinput-' + id);
        const name = inp?.value.trim();
        if (!name) { _showManageMsg('Navn må ikke være tomt'); return; }
        try {
            const res  = await fetch('api.php?action=update-vehicle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, name }),
            });
            const data = await res.json();
            if (!data.success) { _showManageMsg(data.error || 'Fejl'); return; }
            const v = vehicles.find(x => x.id == id);
            if (v) v.vehicleName = name;
            document.getElementById('vname-' + id).textContent = name;
            cancelEditVehicle(id, name);
            _populateVehicleFilter();
            _showManageMsg('Køretøj opdateret', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    function startEditProvider(id) {
        document.getElementById('pname-' + id)?.classList.add('d-none');
        document.getElementById('pinput-' + id)?.classList.remove('d-none');
        document.getElementById('pedit-' + id)?.classList.add('d-none');
        document.getElementById('psave-' + id)?.classList.remove('d-none');
        document.getElementById('pcancel-' + id)?.classList.remove('d-none');
        document.getElementById('pdel-' + id)?.classList.add('d-none');
        document.getElementById('pinput-' + id)?.focus();
    }

    function cancelEditProvider(id, originalName) {
        document.getElementById('pname-' + id)?.classList.remove('d-none');
        document.getElementById('pinput-' + id)?.classList.add('d-none');
        document.getElementById('pedit-' + id)?.classList.remove('d-none');
        document.getElementById('psave-' + id)?.classList.add('d-none');
        document.getElementById('pcancel-' + id)?.classList.add('d-none');
        document.getElementById('pdel-' + id)?.classList.remove('d-none');
        const inp = document.getElementById('pinput-' + id);
        if (inp) inp.value = originalName;
    }

    async function saveProvider(id) {
        const inp  = document.getElementById('pinput-' + id);
        const name = inp?.value.trim();
        if (!name) { _showManageMsg('Navn må ikke være tomt'); return; }
        try {
            const res  = await fetch('api.php?action=update-provider', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, name }),
            });
            const data = await res.json();
            if (!data.success) { _showManageMsg(data.error || 'Fejl'); return; }
            const p = providers.find(x => x.id == id);
            if (p) p.providerName = name;
            document.getElementById('pname-' + id).textContent = name;
            cancelEditProvider(id, name);
            _populateProviderSelects();
            _showManageMsg('Udbyder opdateret', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    async function createProvider() {
        const inp  = document.getElementById('newProviderName');
        const name = inp?.value.trim();
        if (!name) { _showManageMsg('Navn må ikke være tomt'); return; }
        try {
            const res  = await fetch('api.php?action=create-provider', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name }),
            });
            const data = await res.json();
            if (!data.success) { _showManageMsg(data.error || 'Fejl'); return; }
            providers.push({ id: data.id, providerName: data.providerName });
            providers.sort((a, b) => a.providerName.localeCompare(b.providerName, 'da'));
            if (inp) inp.value = '';
            _renderManageProviders(providers);
            _populateProviderSelects();
            _showManageMsg('Udbyder oprettet', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    async function deleteProvider(id) {
        if (!confirm('Slet denne udbyder?')) return;
        try {
            const res  = await fetch('api.php?action=delete-provider', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id }),
            });
            const data = await res.json();
            if (!data.success) { _showManageMsg(data.error || 'Fejl'); return; }
            providers = providers.filter(p => p.id != id);
            _renderManageProviders(providers);
            _populateProviderSelects();
            _showManageMsg('Udbyder slettet', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    // -------------------------------------------------------------------------
    // Sub-tab hook (called from nav.js when a sub-tab becomes active)
    // -------------------------------------------------------------------------
    let sammenligningLoaded = false;
    let touLoaded = false;

    function onSubTab(subId) {
        if (subId === 'elbil-sammenligning') {
            if (!sammenligningLoaded) {
                sammenligningLoaded = true;
                fetchYearlyComparison();
            } else if (evYearCompareChart) {
                evYearCompareChart.resize();
            }
        }
        if (subId === 'elbil-analyse' && !touLoaded) {
            touLoaded = true;
            fetchTouHeatmap();
        }
    }

    // -------------------------------------------------------------------------
    // Time-of-use heatmap (Elspot-tidsmønster)
    // -------------------------------------------------------------------------
    async function fetchTouHeatmap() {
        const area = window.sparkConfig?.elspotArea || '';
        const gln  = window.sparkConfig?.elspotGln  || '';
        const container = document.getElementById('touHeatmapContainer');
        if (!container) return;
        if (!area || !gln) return; // unconfigured — placeholder text already shown

        const now    = new Date();
        const end    = now.toISOString().slice(0, 10);
        const d90    = new Date(now); d90.setDate(now.getDate() - 89);
        const start  = d90.toISOString().slice(0, 10);

        container.innerHTML = '<p class="text-muted small">Indlæser elspot-data…</p>';

        try {
            const res = await fetch(
                `api.php?action=elspot-prices&start=${start}&end=${end}` +
                `&area=${encodeURIComponent(area)}&gln=${encodeURIComponent(gln)}&format=hourly`
            );
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            const records = data.records || [];
            if (records.length === 0) {
                container.innerHTML = '<p class="text-muted small">Ingen prisdata tilgængeligt for den valgte periode.</p>';
                return;
            }

            // Aggregate to 7×24 matrix: dow 0=Mon…6=Sun, hour 0-23
            const sums = Array.from({length: 7}, () => new Float64Array(24));
            const cnts = Array.from({length: 7}, () => new Int32Array(24));
            for (const r of records) {
                const d   = new Date(r.date + 'T12:00:00'); // noon to avoid DST ambiguity
                const dow = (d.getDay() + 6) % 7;          // 0=Mon
                sums[dow][r.hour] += r.kr_kwh;
                cnts[dow][r.hour]++;
            }

            const dayNames = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
            const hours    = Array.from({length: 24}, (_, h) => h + ':00');

            const priceSeries = dayNames.map((name, dow) => ({
                name,
                data: Array.from({length: 24}, (_, h) =>
                    cnts[dow][h] > 0 ? Math.round(sums[dow][h] / cnts[dow][h] * 1000) / 1000 : 0
                )
            }));

            // Charge count overlay using current _allCharges
            const cCnts = Array.from({length: 7}, () => new Int32Array(24));
            for (const c of _allCharges) {
                if (!c.datetime) continue;
                const cd  = new Date(c.datetime);
                const dow = (cd.getDay() + 6) % 7;
                cCnts[dow][cd.getHours()]++;
            }
            const chargeSeries = dayNames.map((name, dow) => ({
                name,
                data: Array.from({length: 24}, (_, h) => cCnts[dow][h])
            }));

            container.innerHTML =
                '<div id="touPriceChart"></div>' +
                (_allCharges.length > 0 ? '<div id="touChargeChart" class="mt-3"></div>' : '');

            const heatmapOpts = (series, title, color, fmtFn) => ({
                series,
                chart: { type: 'heatmap', height: 210, toolbar: { show: false },
                         animations: { enabled: false } },
                title: { text: title, align: 'left', style: { fontSize: '13px', fontWeight: 600 } },
                dataLabels: { enabled: false },
                colors:     [color],
                xaxis:      { categories: hours, labels: { rotate: -45, style: { fontSize: '10px' } } },
                yaxis:      { labels: { style: { fontSize: '11px' } } },
                tooltip:    { y: { formatter: fmtFn } },
                plotOptions: { heatmap: { enableShades: true } },
                legend:     { show: false }
            });

            new ApexCharts(
                document.getElementById('touPriceChart'),
                heatmapOpts(priceSeries, 'Gns. elpris (kr/kWh inkl. afgifter)', '#22c55e',
                    v => v > 0 ? v.toFixed(3) + ' kr/kWh' : '—')
            ).render();

            if (_allCharges.length > 0) {
                new ApexCharts(
                    document.getElementById('touChargeChart'),
                    heatmapOpts(chargeSeries, 'Antal ladninger pr. time/ugedag', '#6BA3FF',
                        v => v + ' ladning' + (v === 1 ? '' : 'er'))
                ).render();
            }
        } catch (e) {
            console.error('fetchTouHeatmap:', e);
            if (container) container.innerHTML =
                '<p class="text-danger small">Kunne ikke indlæse elspot-data.</p>';
        }
    }

    return {
        init,
        onSubTab,
        openManageModal,
        startEditVehicle, cancelEditVehicle, saveVehicle,
        startEditProvider, cancelEditProvider, saveProvider,
        createProvider, deleteProvider,
        resetFilters,
        getDateRange,
    };
})();

window.elbilApp = elbilApp;
