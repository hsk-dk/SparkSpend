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

        Chart.register(ChartDataLabels);

        _setupFlatpickr();
        _setupEventListeners();
        _setupModalFlatpickrs();

        // Default filter: this month
        setTimeout(() => {
            const today = new Date();
            const start = new Date(today.getFullYear(), today.getMonth(), 1);
            const end   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            dateRangeEl._flatpickr.setDate([start, end]);
            quickFilterEl.value = 'month';
            fetchVehicles();
            fetchProviders();
        }, 50);
    }

    // -------------------------------------------------------------------------
    // Flatpickr setup
    // -------------------------------------------------------------------------
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
    }

    function _onFilterChange() {
        fetchCharges();
        fetchEfficiencyStats();
        fetchCostAnalytics();
        fetchVehicleComparison();
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
                start.setDate(today.getDate() - today.getDay());
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
    }

    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------
    async function fetchVehicles() {
        try {
            const res  = await fetch('getVehicles.php');
            const data = await appUtils.handleFetchResponse(res);
            vehicles = data;
            _populateVehicleFilter();
            fetchCharges();
            fetchEfficiencyStats();
            fetchCostAnalytics();
            fetchVehicleComparison();
        } catch (e) {
            console.error('fetchVehicles:', e);
        }
    }

    async function fetchProviders() {
        try {
            const res  = await fetch('getProvideres.php');
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
        ['internalVehicleId', 'externalVehicleId', 'chargeVehicleId'].forEach(id => {
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
        ['externalProviderId', 'chargeProviderId'].forEach(id => {
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
            const res  = await fetch('getCharges.php?' + params);
            const data = await appUtils.handleFetchResponse(res);
            _renderCharges(data);
        } catch (e) {
            chargeTableBodyEl.innerHTML =
                `<tr><td colspan="7" class="text-center text-danger">Fejl: ${e.message}</td></tr>`;
        }
    }

    // -------------------------------------------------------------------------
    // Render: charge table
    // -------------------------------------------------------------------------
    function _renderCharges(data) {
        chargeTableBodyEl.innerHTML = '';
        let overallKwh = 0, overallPris = 0;
        let internalCount = 0, externalCount = 0;
        let internalKwh = 0, externalKwh = 0;
        let internalPrice = 0, externalPrice = 0;
        let pricePerKwhArray = [];
        const totals = {};

        data.forEach(charge => {
            const kwh  = parseFloat(charge.kwh)  || 0;
            const pris = parseFloat(charge.pris) || 0;
            const pricePerKwh = kwh > 0 ? pris / kwh : 0;
            pricePerKwhArray.push(pricePerKwh);
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

        data.forEach(charge => {
            const kwh      = parseFloat(charge.kwh)  || 0;
            const pris     = parseFloat(charge.pris) || 0;
            const pPerKwh  = kwh > 0 ? pris / kwh : 0;
            const vName    = (vehicles.find(v => v.id == charge.vehicleId)  || {}).vehicleName  || 'Ukendt';
            const pName    = (providers.find(p => p.id == charge.providerId) || {}).providerName || 'Ukendt';

            const icon = charge.source === 'internal'
                ? `<span class="material-symbols-outlined" data-bs-toggle="tooltip" title="ID: ${charge.id}">electrical_services</span>`
                : `<span class="material-symbols-outlined" data-bs-toggle="tooltip" title="${pName}">ev_station</span>`;

            let priceIcon = '', priceText = '';
            if (pPerKwh > avgPricePerKwh) { priceIcon = '↑';   priceText = 'højere end gennemsnit'; }
            else if (pPerKwh < avgPricePerKwh) { priceIcon = '↓'; priceText = 'lavere end gennemsnit'; }

            const fmtDate = new Date(charge.datetime).toLocaleString('da-DK', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });

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
                <td>${pris.toFixed(2)} kr</td>
                <td>${priceIcon} ${pPerKwh.toFixed(2)} kr/kWh <small>${priceText}</small></td>
                <td>${vName}</td>
                <td>${icon}</td>
                <td><button class="btn btn-sm btn-secondary" data-action="edit">Rediger</button></td>
            `;
            chargeTableBodyEl.appendChild(row);
        });

        _updateSummary(totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice);
        appUtils.initializeTooltips();
    }

    function _updateSummary(totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice) {
        const summaryTextEl = document.getElementById('summaryText');
        let html = `<h3>Opsummering</h3>
        <table class="table table-sm">
            <tr><th>Bil</th><th>Samlet kWh</th><th>Hjemme</th><th>Ude</th><th>Samlet Pris</th><th>Gns. Pris/kWh</th></tr>`;

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
            const res    = await fetch('updateInternalCharge.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: document.getElementById('internalChargeId').value, vehicleId: document.getElementById('internalVehicleId').value }) });
            const result = await appUtils.handleFetchResponse(res);
            if (result.success) {
                appUtils.showFormMessage('internalChargeMsg', 'Intern ladning opdateret!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('internalChargeModal')).hide();
                fetchCharges();
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
            const res    = await fetch('updateExtCharge.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const result = await appUtils.handleFetchResponse(res);
            if (result.success) {
                appUtils.showFormMessage('externalChargeMsg', 'Ekstern ladning opdateret!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
                fetchCharges();
                fetchVehicleComparison();
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
            const res  = await fetch('createExCharge.php', { method: 'POST', body: new FormData(form) });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.success) {
                appUtils.showFormMessage('exChargeMsg', 'Ekstern ladning oprettet!', 'success');
                form.reset();
                bootstrap.Modal.getInstance(document.getElementById('createExChargeModal')).hide();
                fetchCharges();
                fetchVehicleComparison();
                window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
            } else {
                appUtils.showFormMessage('exChargeMsg', data.error || 'Fejl', 'danger');
            }
        } catch (err) { appUtils.showFormMessage('exChargeMsg', err.message, 'danger'); }
    }

    async function _deleteExternalCharge() {
        const id = document.getElementById('externalChargeId').value;
        if (!confirm('Er du sikker på, at du vil slette denne ladning?')) return;
        try {
            const res    = await fetch('deleteExtCharge.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const result = await res.json();
            if (result.success) {
                appUtils.showFormMessage('externalChargeMsg', 'Ladningen er slettet!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
                fetchCharges();
                fetchVehicleComparison();
                window.SparkEvents?.dispatchEvent(new Event('charge:saved'));
            } else {
                appUtils.showFormMessage('externalChargeMsg', result.error || 'Fejl', 'danger');
            }
        } catch (err) { appUtils.showFormMessage('externalChargeMsg', err.message, 'danger'); }
    }

    // -------------------------------------------------------------------------
    // Efficiency charts
    // -------------------------------------------------------------------------
    function fetchEfficiencyStats() {
        const params = new URLSearchParams({ filter: filterEl.value, showZeroKwh: showZeroKwhEl.checked, dateRange: dateRangeEl.value });
        fetch('getEfficiencyStats.php?' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderEfficiencyCharts(data); })
            .catch(err => {
                document.getElementById('kmPerKwhChart').innerHTML = `<div class="alert alert-danger">Fejl: ${err.message}</div>`;
                document.getElementById('krPerKmChart').innerHTML  = `<div class="alert alert-danger">Fejl: ${err.message}</div>`;
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
        fetch('getChargeAnalytics.php?' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderCostAnalytics(data); })
            .catch(err => {
                document.getElementById('costTrendChart').innerHTML    = `<div class="alert alert-danger">Fejl: ${err.message}</div>`;
                document.getElementById('costStatsContent').innerHTML  = `<div class="alert alert-danger">Fejl: ${err.message}</div>`;
            });
    }

    function _renderCostAnalytics(data) {
        _renderCostTrendChart(data.trend.daily_totals);
        _renderCostStatistics(data.statistics);
    }

    function _renderCostTrendChart(dailyData) {
        const dates    = dailyData.map(d => d.date);
        const internal = dailyData.map(d => parseFloat(d.internal_cost) || 0);
        const external = dailyData.map(d => parseFloat(d.external_cost) || 0);

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
        fetch('getVehicleComparison.php?' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderVehicleComparison(data.vehicles); })
            .catch(err => { document.getElementById('vehicleComparisonTableBody').innerHTML = `<tr><td colspan="7" class="text-center text-danger">Fejl: ${err.message}</td></tr>`; });
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

    return { init };
})();

window.elbilApp = elbilApp;
