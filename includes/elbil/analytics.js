/**
 * SparkSpend Elbil - cost analytics, vehicle comparison, provider statistics
 *
 * The cost trend chart and statistics, the vehicle comparison table and the provider statistics.
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;
    let costTrendChartInstance;
    let currentVehicleSort = null;

    // -------------------------------------------------------------------------
    // Cost analytics
    // -------------------------------------------------------------------------
    function fetchCostAnalytics() {
        const groupBy = document.querySelector('button[data-grouping].active')?.dataset.grouping || 'week';
        const params  = new URLSearchParams({ filter: S.filterEl.value, dateRange: S.dateRangeEl.value, groupBy, showZeroKwh: S.showZeroKwhEl.checked });
        fetch('api.php?action=charge-analytics&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderCostAnalytics(data); })
            .catch(err => {
                appUtils.showRetryError('costTrendChart', {
                    message: 'Kunne ikke hente omkostningsdata: ' + err.message,
                    onRetry: fetchCostAnalytics,
                });
                document.getElementById('costStatsContent').innerHTML = '';
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
            stroke: { curve: 'straight' },
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
        const params = new URLSearchParams({ dateRange: S.dateRangeEl.value });
        if (currentVehicleSort) params.set('sortBy', currentVehicleSort);
        fetch('api.php?action=vehicle-comparison&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderVehicleComparison(data.vehicles); })
            .catch(err => {
                appUtils.showRetryError('vehicleComparisonTableBody', {
                    message: 'Kunne ikke hente bilsammenligning: ' + err.message,
                    onRetry: () => fetchVehicleComparison(),
                    asTableRow: true,
                    colspan: 7,
                });
            });
    }

    function _renderVehicleComparison(data) {
        const tbody = document.getElementById('vehicleComparisonTableBody');
        if (!data || !data.length) {
            appUtils.showEmptyState(tbody, {
                message: 'Ingen biler at sammenligne i den valgte periode.',
                icon: 'fa-car',
                asTableRow: true,
                colspan: 7,
            });
            return;
        }
        tbody.innerHTML = data.map(v => `
            <tr>
                <td><strong>${_esc(v.vehicleName)}</strong></td>
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
        const params = new URLSearchParams({ filter: S.filterEl.value, dateRange: S.dateRangeEl.value });
        fetch('api.php?action=provider-stats&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderProviderStats(data); })
            .catch(err => {
                appUtils.showRetryError('providerStatsContent', {
                    message: 'Kunne ikke hente ladesteder: ' + err.message,
                    onRetry: fetchProviderStats,
                });
            });
    }

    function _renderProviderStats(data) {
        const el = document.getElementById('providerStatsContent');
        if (!el) return;
        if (!data || !data.length) {
            appUtils.showEmptyState(el, {
                message: 'Ingen eksterne ladninger i den valgte periode.',
                icon: 'fa-charging-station',
            });
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
                    <td>${_esc(p.providerName)}</td>
                    <td class="text-end">${p.charge_count}</td>
                    <td class="text-end">${p.total_kwh.toFixed(2)}</td>
                    <td class="text-end">${p.total_cost.toFixed(2)}</td>
                    <td class="text-end">${p.avg_cost_per_kwh.toFixed(3)}</td>
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>`;
    }

    function bind() {
        document.querySelectorAll('button[data-grouping]').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('button[data-grouping]').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                fetchCostAnalytics();
            });
        });
    }

    M.analytics = { fetchCostAnalytics, _renderCostAnalytics, _renderCostTrendChart, _renderCostStatistics, fetchVehicleComparison, _renderVehicleComparison, fetchProviderStats, _renderProviderStats, bind };
})();
