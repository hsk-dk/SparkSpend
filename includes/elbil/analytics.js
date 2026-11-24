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
        Api.get('charge-analytics', params)
            .then(_renderCostAnalytics)
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

    // The server names a period "2026-W45", "2026-11" or "2026-11-05"; the axis says "Uge 45", "nov. 2026" or "5. nov." The year is added
    // to a week or a day when the axis spans more than one year (Uge 1 would otherwise be ambiguous).
    function _periodLabel(key, withYear) {
        let m;
        if ((m = /^(\d{4})-W(\d{2})$/.exec(key))) return `Uge ${Number(m[2])}` + (withYear ? ` ${m[1]}` : '');
        if ((m = /^(\d{4})-(\d{2})$/.exec(key))) return new Date(Number(m[1]), Number(m[2]) - 1, 1).toLocaleDateString('da-DK', { month: 'short', year: 'numeric' });
        if ((m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key))) {
            return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
                .toLocaleDateString('da-DK', withYear ? { day: 'numeric', month: 'short', year: 'numeric' } : { day: 'numeric', month: 'short' });
        }
        return String(key);
    }

    function _renderCostTrendChart(dailyData) {
        // Aggregate duplicate dates defensively: one point per date, so a repeated date cannot show twice on the axis.
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
        const spansYears = new Set(dates.map(d => String(d).slice(0, 4))).size > 1;
        const labels   = dates.map(d => _periodLabel(d, spansYears));
        const internal = deduped.map(d => d.internal_cost);
        const external = deduped.map(d => d.external_cost);

        // Hjemme in the EV colour (the house meter's car), Ude in the system's fourth series colour: a lighter step, so the two
        // layers of the stack differ in lightness as well as hue.
        const HOME = '#224b81', AWAY = '#c87f6d';
        const host = document.getElementById('costTrendChart');
        if (!host) return;
        if (costTrendChartInstance) { costTrendChartInstance.destroy(); costTrendChartInstance = null; }
        host.innerHTML = '<div style="position:relative;height:360px"><canvas data-a11y-title="Omkostningstendenser"></canvas></div>';
        costTrendChartInstance = new Chart(host.querySelector('canvas').getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [
                    { label: 'Hjemme', data: internal, borderColor: HOME, backgroundColor: HOME + 'd9', borderWidth: 2, fill: 'origin', pointRadius: 0, pointHoverRadius: 4 },
                    { label: 'Ude',    data: external, borderColor: AWAY, backgroundColor: AWAY + 'd9', borderWidth: 2, fill: '-1',     pointRadius: 0, pointHoverRadius: 4 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    title: { display: true, text: 'Omkostningstendenser', align: 'start', font: { size: 16, weight: '600' } },
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: item => ` ${item.dataset.label}: ${appUtils.num(item.raw, 2)} kr`,
                            footer: items => `I alt: ${appUtils.num(items.reduce((s, i) => s + i.raw, 0), 2)} kr`,
                        },
                    },
                },
                scales: {
                    x: { ticks: { autoSkip: true, maxTicksLimit: 10, maxRotation: 0 }, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Omkostning (kr)' }, ticks: { callback: v => appUtils.num(v, 0) } },
                },
            },
        });
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
                <tr><td><strong>Samlet kWh:</strong></td><td>${appUtils.num(totalKwh, 2)}</td></tr>
                <tr><td><strong>I alt kr:</strong></td><td>${appUtils.num(totalCost, 2)}</td></tr>
                <tr><td><strong>Gennemsnit pr. ladning:</strong></td><td>${appUtils.num(avgCost, 2)} kr</td></tr>
                <tr><td><strong>Gennemsnit pr. kWh:</strong></td><td>${appUtils.num(avgCostPerKwh, 3)} kr/kWh</td></tr>
                <tr><td><strong>Min pr. ladning:</strong></td><td>${appUtils.num(minCost, 2)} kr</td></tr>
                <tr><td><strong>Max pr. ladning:</strong></td><td>${appUtils.num(maxCost, 2)} kr</td></tr>
            </table>`;
    }

    // -------------------------------------------------------------------------
    // Vehicle comparison
    // -------------------------------------------------------------------------
    function fetchVehicleComparison(sortBy) {
        if (sortBy) currentVehicleSort = sortBy;
        const params = new URLSearchParams({ dateRange: S.dateRangeEl.value });
        if (currentVehicleSort) params.set('sortBy', currentVehicleSort);
        Api.get('vehicle-comparison', params)
            .then(data => _renderVehicleComparison(data.vehicles))
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
        data = (data || []).filter(v => Number(v.charge_count) > 0);   // "Ukendt" with 0 charges and 0 kWh is noise
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
                <td class="text-end">${v.charge_count}</td>
                <td class="text-end">${appUtils.num(v.total_kwh, 2)} kWh</td>
                <td class="text-end">${appUtils.num(v.total_cost, 2)} kr</td>
                <td class="text-end">${appUtils.num(v.avg_cost_per_kwh, 3)} kr/kWh</td>
                <td class="text-end">${appUtils.num(v.internal_percentage, 1)}%</td>
                <td class="text-end">${appUtils.num(v.external_percentage, 1)}%</td>
            </tr>`).join('');
    }

    // Expose sortVehicleComparison globally (called from HTML onclick)
    window.sortVehicleComparison = fetchVehicleComparison;

    // -------------------------------------------------------------------------
    // Provider stats
    // -------------------------------------------------------------------------
    function fetchProviderStats() {
        const params = new URLSearchParams({ filter: S.filterEl.value, dateRange: S.dateRangeEl.value });
        Api.get('provider-stats', params)
            .then(_renderProviderStats)
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
                    <td class="text-end">${appUtils.num(p.total_kwh, 2)}</td>
                    <td class="text-end">${appUtils.num(p.total_cost, 2)}</td>
                    <td class="text-end">${appUtils.num(p.avg_cost_per_kwh, 3)}</td>
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
