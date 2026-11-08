/**
 * SparkSpend Elbil - year-over-year comparison
 *
 * The year-over-year EV chart (kWh / kr), its year chips and statistics.
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;

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

        Api.get('charge-compare')
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
                ...appUtils.monthLineStyle(year, color),
                spanGaps: false,
            };
        });

        // Monthly average across all years (dashed reference line)
        const avgData = Array.from({ length: 12 }, (_, m) => {
            // the running month is not a full month: leave it out of the average
            const vals = [...yearMap.entries()].map(([y, arr]) => appUtils.runningMonthIndex(y) === m ? null : arr[m]).filter(v => v !== null);
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
            tension: 0,
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
                            afterLabel: c => appUtils.runningMonthIndex(c.dataset.label) === c.dataIndex ? 'Måneden er ikke slut' : '',
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
                <td><span class="year-dot" style="background:${color}"></span>${year}${appUtils.runningMonthIndex(year) !== null ? ' <span class="text-muted" style="font-size:11px">til dato</span>' : ''}</td>
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

    /** Re-measure the chart after its tab became visible. */
    function resizeChart() {
        if (evYearCompareChart) evYearCompareChart.resize();
    }

    function bind() {
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

    M.yearly = { resizeChart, fetchYearlyComparison, _renderEvYearChart, _buildEvYearChips, _renderEvYearStats, bind };
})();
