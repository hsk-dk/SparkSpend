/**
 * SparkSpend Elbil - main
 *
 * Exposed as window.elbilApp = { init, onSubTab, ... }. init() is called lazily by SparkNav on the
 * first visit to the Elbil tab. This part owns the filter bar and drawer, the vehicle / provider
 * data loading and the sub-tab hook; the rest lives in the other includes/elbil/*.js parts.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    let initialized = false;

    // -------------------------------------------------------------------------
    // Public: init
    // -------------------------------------------------------------------------
    function init() {
        if (initialized) return;
        initialized = true;

        S.filterEl          = document.getElementById('filter');
        S.showZeroKwhEl     = document.getElementById('showZeroKwh');
        S.dateRangeEl       = document.getElementById('dateRange');
        S.quickFilterEl     = document.getElementById('quickFilter');
        S.chargeTableBodyEl = document.getElementById('chargeTableBody');

        Chart.register(ChartDataLabels);

        _setupFlatpickr();
        _setupEventListeners();
        M.charges.bind();
        M.optimization.bind();
        M.analytics.bind();
        M.yearly.bind();

        // Default filter: this month — or restore from URL hash if present
        setTimeout(() => {
            const hashDr = window.SparkNav?.getHashDateRange?.();
            if (hashDr) {
                S.dateRangeEl._flatpickr.setDate([hashDr.start, hashDr.end]);
                S.quickFilterEl.value = '';
                window.SparkNav?.updateHash();   // keep ?dr= in the URL after restoring it
            } else {
                const today = new Date();
                const start = new Date(today.getFullYear(), today.getMonth(), 1);
                const end   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                S.dateRangeEl._flatpickr.setDate([start, end]);
                S.quickFilterEl.value = 'month';
            }
            fetchVehicles();
            fetchProviders();
        }, 50);
    }


    function getDateRange() {
        if (!S.dateRangeEl?._flatpickr) return null;
        const dates = S.dateRangeEl._flatpickr.selectedDates;
        if (dates.length < 2) return null;
        const fmt = d => appUtils.ymd(d);
        return { start: fmt(dates[0]), end: fmt(dates[1]) };
    }

    function _setupFlatpickr() {
        flatpickr(S.dateRangeEl, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            locale: 'da',
            onClose: () => {
                M.charges.fetchCharges();
                M.efficiency.fetchEfficiencyStats();
                M.analytics.fetchCostAnalytics();
                M.analytics.fetchVehicleComparison();
                window.SparkNav?.updateHash();
            }
        });
    }


    function _setupEventListeners() {
        S.filterEl.addEventListener('change',     _onFilterChange);
        S.showZeroKwhEl.addEventListener('change', _onFilterChange);
        S.quickFilterEl.addEventListener('change', applyQuickFilter);
        S.dateRangeEl.addEventListener('change',   _onFilterChange);

        // Filter drawer
        const filterDrawer   = document.getElementById('filterDrawer');
        const filterBackdrop = document.getElementById('filterBackdrop');
        const toggleBtn      = document.getElementById('filterToggleBtn');
        const closeBtn       = document.getElementById('filterCloseBtn');

        let _drawerKeydownHandler = null;

        function _openFilterDrawer() {
            filterDrawer.classList.add('open');
            filterBackdrop.classList.add('visible');
            document.body.classList.add('filter-open');
            toggleBtn?.setAttribute('aria-expanded', 'true');

            // Move focus into the drawer for keyboard users.
            const firstField = filterDrawer.querySelector('select, input, button');
            firstField?.focus();

            // Escape to close + simple focus-trap within the drawer.
            _drawerKeydownHandler = (e) => {
                if (e.key === 'Escape') { _closeFilterDrawer(); return; }
                if (e.key !== 'Tab') return;
                const focusables = filterDrawer.querySelectorAll(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                if (!focusables.length) return;
                const first = focusables[0];
                const last  = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault(); first.focus();
                }
            };
            document.addEventListener('keydown', _drawerKeydownHandler);
        }

        function _closeFilterDrawer() {
            const wasOpen = filterDrawer.classList.contains('open');
            filterDrawer.classList.remove('open');
            filterBackdrop.classList.remove('visible');
            document.body.classList.remove('filter-open');
            toggleBtn?.setAttribute('aria-expanded', 'false');
            if (_drawerKeydownHandler) {
                document.removeEventListener('keydown', _drawerKeydownHandler);
                _drawerKeydownHandler = null;
            }
            // Return focus to the trigger so keyboard users keep their place.
            if (wasOpen) toggleBtn?.focus();
        }

        toggleBtn?.addEventListener('click', () => {
            if (filterDrawer.classList.contains('open')) _closeFilterDrawer();
            else _openFilterDrawer();
        });
        closeBtn?.addEventListener('click', _closeFilterDrawer);
        filterBackdrop?.addEventListener('click', _closeFilterDrawer);
    }

    function _onFilterChange() {
        M.charges.fetchCharges();
        M.efficiency.fetchEfficiencyStats();
        M.analytics.fetchCostAnalytics();
        M.analytics.fetchProviderStats();
    }

    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Quick filter
    // -------------------------------------------------------------------------
    function applyQuickFilter() {
        const val   = S.quickFilterEl.value;
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
            S.dateRangeEl._flatpickr.setDate([start, end]);
        } else {
            S.dateRangeEl._flatpickr.clear();
        }
        M.charges.fetchCharges();
        M.efficiency.fetchEfficiencyStats();
        M.analytics.fetchCostAnalytics();
        M.analytics.fetchVehicleComparison();
        M.analytics.fetchProviderStats();
        window.SparkNav?.updateHash();
    }

    function resetFilters() {
        if (S.filterEl)      S.filterEl.value = 'all';
        if (S.showZeroKwhEl) S.showZeroKwhEl.checked = false;
        if (S.quickFilterEl) S.quickFilterEl.value = 'month';
        M.charges.resetSort();
        applyQuickFilter();
    }


    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------
    async function fetchVehicles() {
        try {
            S.vehicles = await Api.get('vehicles');
            _populateVehicleFilter();
            M.charges.fetchCharges();
            M.efficiency.fetchEfficiencyStats();
            M.analytics.fetchCostAnalytics();
            M.analytics.fetchVehicleComparison();
            M.analytics.fetchProviderStats();
            M.optimization.fetchChargeOptimization();
        } catch (e) {
            console.error('fetchVehicles:', e);
        }
    }

    async function fetchProviders() {
        try {
            S.providers = await Api.get('providers');
            _populateProviderSelects();
        } catch (e) {
            console.error('fetchProviders:', e);
        }
    }

    function _populateVehicleFilter() {
        S.filterEl.querySelectorAll('option[data-vehicle]').forEach(o => o.remove());
        S.vehicles.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.id;
            opt.textContent = v.vehicleName;
            opt.dataset.vehicle = 'true';
            S.filterEl.appendChild(opt);
        });
        S.filterEl.value = 'all';

        // Also populate vehicle selects in modals
        ['internalVehicleId', 'externalVehicleId', 'vehicleId'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.querySelectorAll('option[data-vehicle]').forEach(o => o.remove());
            S.vehicles.forEach(v => {
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
            S.providers.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.providerName;
                opt.dataset.provider = 'true';
                sel.appendChild(opt);
            });
        });
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
                M.yearly.fetchYearlyComparison();
            } else {
                M.yearly.resizeChart();
            }
        }
        if (subId === 'elbil-analyse' && !touLoaded) {
            touLoaded = true;
            M.tou.fetchTouHeatmap();

            // Period selector for TOU heatmap
            document.querySelectorAll('#touPeriodNav [data-tou-days]').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('#touPeriodNav .hp-mode-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    M.tou.fetchTouHeatmap(parseInt(this.dataset.touDays, 10));
                });
            });
        }
    }


    M.main = { _populateVehicleFilter, _populateProviderSelects };

    const manage = M.manage;
    window.elbilApp = {
        init,
        onSubTab,
        openManageModal: manage.openManageModal,
        startEditVehicle: manage.startEditVehicle, cancelEditVehicle: manage.cancelEditVehicle, saveVehicle: manage.saveVehicle,
        startEditProvider: manage.startEditProvider, cancelEditProvider: manage.cancelEditProvider, saveProvider: manage.saveProvider,
        createProvider: manage.createProvider, deleteProvider: manage.deleteProvider,
        resetFilters,
        getDateRange,
    };
})();
