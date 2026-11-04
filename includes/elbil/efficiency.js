/**
 * SparkSpend Elbil - consumption per km
 *
 * Forbrug pr. km: month table with temperature, tiles and the cumulative km/kWh and kr/km charts.
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;
    let kmPerKwhChartInstance, krPerKmChartInstance;

    // -------------------------------------------------------------------------
    // Efficiency charts
    // -------------------------------------------------------------------------
    function fetchEfficiencyStats() {
        const params = new URLSearchParams({ filter: S.filterEl.value, showZeroKwh: S.showZeroKwhEl.checked, dateRange: S.dateRangeEl.value });
        fetch('api.php?action=efficiency&' + params)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => { if (data.error) throw new Error(data.error); _renderEfficiency(data); })
            .catch(err => {
                appUtils.showRetryError('kmPerKwhChart', {
                    message: 'Kunne ikke hente effektivitetsdata: ' + err.message,
                    onRetry: fetchEfficiencyStats,
                });
                document.getElementById('krPerKmChart').innerHTML = '';
                const sum = document.getElementById('efficiencySummary'); if (sum) sum.innerHTML = '';
            });
    }

    function _renderEfficiency(data) {
        // The route used to return the cumulative series as a bare array.
        const legacy = Array.isArray(data);
        _renderEfficiencySummary(legacy ? [] : (data.vehicles || []), legacy ? null : data.unassigned);
        _renderEfficiencyCharts(legacy ? data : (data.series || []));
    }

    // Per vehicle: the last 30 / 90 days and the selected period as tiles, then a month table.
    // Every figure carries its confidence level (see EfficiencyCalc::confidence).
    function _renderEfficiencySummary(vehs, unassigned) {
        const el = document.getElementById('efficiencySummary');
        if (!el) return;
        const esc = appUtils.escapeHtml;
        const num = (v, d) => v == null ? '—' : Number(v).toLocaleString('da-DK', { minimumFractionDigits: d, maximumFractionDigits: d });
        const badge = m => {
            const cls = { 'høj': 'bg-success', 'middel': 'bg-warning text-dark', 'lav': 'bg-secondary' }[m.confidence] || 'bg-secondary';
            return `<span class="badge ${cls}" title="${esc(m.confidence_why || '')}">${esc(m.confidence)} sikkerhed</span>`;
        };
        const krTxt = m => m.kr_per_km == null ? '—' : num(m.kr_per_km, 2) + (m.kr_estimated ? '*' : '');
        const tile = (title, m) => `
            <div class="border rounded p-2 me-2 mb-2" style="min-width:11rem">
                <div class="text-muted small">${esc(title)}</div>
                <div class="fs-4 fw-semibold">${num(m.kwh_per_100km, 1)} <span class="fs-6 fw-normal">kWh/100 km</span></div>
                <div class="small">${num(m.km_per_kwh, 2)} km/kWh · ${krTxt(m)} kr/km</div>
                <div class="small text-muted">${num(m.km, 0)} km · ${num(m.kwh, 0)} kWh</div>
                <div class="mt-1">${badge(m)}</div>
            </div>`;
        const monthLabel = ym => { const [y, mo] = ym.split('-').map(Number); return new Date(y, mo - 1, 1).toLocaleDateString('da-DK', { month: 'short', year: 'numeric' }); };

        if (!vehs.length) {
            el.innerHTML = '<p class="text-muted">Ingen kilometertællermålinger endnu. De kommer fra Home Assistant, når en bil tilsluttes.</p>';
            return;
        }
        let html = '';
        if (unassigned && unassigned.charges > 0) {
            html += `<div class="alert alert-warning small">${unassigned.charges} ladning${unassigned.charges === 1 ? '' : 'er'} ` +
                    `(${num(unassigned.kwh, 0)} kWh) har ingen bil og tæller ikke med her. Ret dem under Ladninger.</div>`;
        }
        let anyEstimated = false;
        vehs.forEach(v => {
            const name = (S.vehicles.find(x => x.id == v.vehicleId) || {}).vehicleName || 'Bil ' + v.vehicleId;
            const months = (v.monthly || []).slice().reverse().slice(0, 12);
            anyEstimated = anyEstimated || [v.overall, ...Object.values(v.rolling || {}), ...months].some(m => m && m.kr_estimated);
            html += `<div class="mb-4"><h5>${esc(name)}</h5><div class="d-flex flex-wrap">` +
                    tile('Seneste 30 dage', v.rolling['30']) + tile('Seneste 90 dage', v.rolling['90']) +
                    tile('Valgt periode', v.overall) + `</div>`;
            if (months.length) {
                html += `<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>` +
                        `<th>Måned</th><th class="text-end" title="Gennemsnitstemperatur i måneden (Open-Meteo)">Temp.</th><th class="text-end">km</th><th class="text-end">kWh</th><th class="text-end">kWh/100 km</th>` +
                        `<th class="text-end">km/kWh</th><th class="text-end">kr/km</th><th>Sikkerhed</th></tr></thead><tbody>` +
                        months.map(m => `<tr><td>${esc(monthLabel(m.month))}</td><td class="text-end text-muted eff-temp" data-month="${esc(m.month)}">—</td><td class="text-end">${num(m.km, 0)}</td>` +
                            `<td class="text-end">${num(m.kwh, 0)}</td><td class="text-end">${num(m.kwh_per_100km, 1)}</td>` +
                            `<td class="text-end">${num(m.km_per_kwh, 2)}</td><td class="text-end">${krTxt(m)}</td><td>${badge(m)}</td></tr>`).join('') +
                        `</tbody></table></div>`;
            }
            if (v.dropped > 0) html += `<div class="text-muted small mt-1">${v.dropped} måling${v.dropped === 1 ? '' : 'er'} sprunget over, fordi kilometertælleren gik baglæns.</div>`;
            html += `</div>`;
        });
        if (anyEstimated) html += `<div class="text-muted small">* Indeholder ladninger uden pris, som er regnet med gennemsnitsprisen for de øvrige.</div>`;
        el.innerHTML = html;
        _fillEfficiencyTemps(el);
    }

    // Efficiency (kWh/100 km) swings with the season; show each month's mean
    // temperature next to it so a "worse" winter month is read in context.
    function _fillEfficiencyTemps(root) {
        const lat = window.sparkConfig?.weatherLat, lon = window.sparkConfig?.weatherLon;
        const cells = root.querySelectorAll('.eff-temp[data-month]');
        if (!lat || !lon || !cells.length) return;
        const months = [...cells].map(c => c.dataset.month).sort();
        const first = months[0] + '-01';
        const [ly, lm] = months[months.length - 1].split('-').map(Number);
        const lastDay = new Date(ly, lm, 0).getDate();
        const todayStr = appUtils.ymd();
        const last = months[months.length - 1] + '-' + String(lastDay).padStart(2, '0');
        const end = last > todayStr ? todayStr : last;
        if (end < first) return;
        const params = new URLSearchParams({ action: 'weather', start: first, end, lat, lon });
        fetch('api.php?' + params)
            .then(r => r.ok ? r.json() : null)
            .then(d => {
                if (!d || !Array.isArray(d.records)) return;
                const acc = {};
                d.records.forEach(r => {
                    const k = String(r.date).slice(0, 7);
                    (acc[k] = acc[k] || []).push(Number(r.mean_temp_c));
                });
                cells.forEach(c => {
                    const v = acc[c.dataset.month];
                    if (v && v.length) c.textContent = (v.reduce((a, b) => a + b, 0) / v.length).toLocaleString('da-DK', { maximumFractionDigits: 1 }) + ' °C';
                });
            })
            .catch(() => { /* temperature is optional context */ });
    }

    function _renderEfficiencyCharts(data) {
        const grouped = {};
        data.forEach(e => { (grouped[e.vehicleId] = grouped[e.vehicleId] || []).push(e); });

        const kmSeries = [], krSeries = [];
        Object.keys(grouped).forEach(vid => {
            const entries = grouped[vid].sort((a, b) => a.timestamp - b.timestamp);
            const vName   = (S.vehicles.find(v => v.id == vid) || {}).vehicleName || 'Bil ' + vid;
            kmSeries.push({ name: appUtils.escapeHtml(vName), data: entries.map(e => [e.timestamp * 1000, e.totalKmPerKwh]) });
            krSeries.push({ name: appUtils.escapeHtml(vName), data: entries.map(e => [e.timestamp * 1000, e.totalKrPerKm]) });
        });

        const baseOpts = { chart: { type: 'line', height: 300 }, xaxis: { type: 'datetime', title: { text: 'Dato' } }, stroke: { curve: 'smooth' } };

        if (kmPerKwhChartInstance) kmPerKwhChartInstance.destroy();
        kmPerKwhChartInstance = new ApexCharts(document.querySelector('#kmPerKwhChart'), { ...baseOpts, series: kmSeries, yaxis: { title: { text: 'KM per kWh' }, min: 0 }, title: { text: 'Kumuleret km pr. kWh pr. bil' } });
        kmPerKwhChartInstance.render();

        if (krPerKmChartInstance) krPerKmChartInstance.destroy();
        krPerKmChartInstance = new ApexCharts(document.querySelector('#krPerKmChart'), { ...baseOpts, series: krSeries, yaxis: { title: { text: 'Kr per KM' }, min: 0 }, title: { text: 'Kumuleret kr pr. km pr. bil' } });
        krPerKmChartInstance.render();
    }

    M.efficiency = { fetchEfficiencyStats, _renderEfficiency, _renderEfficiencySummary, _fillEfficiencyTemps, _renderEfficiencyCharts };
})();
