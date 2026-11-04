/**
 * SparkSpend Elbil - time-of-use heatmap
 *
 * The Elspot time-of-use heatmap on the comparison tab (price by hour/weekday and charge counts).
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;

    // -------------------------------------------------------------------------
    // Time-of-use heatmap (Elspot-tidsmønster)
    // -------------------------------------------------------------------------
    async function fetchTouHeatmap(days = 90) {
        const area = window.sparkConfig?.elspotArea || '';
        const gln  = window.sparkConfig?.elspotGln  || '';
        const container = document.getElementById('touHeatmapContainer');
        if (!container) return;
        if (!area || !gln) return; // unconfigured — placeholder text already shown

        const now    = new Date();
        const end    = appUtils.ymd(now);
        const dStart = new Date(now); dStart.setDate(now.getDate() - (days - 1));
        const start  = appUtils.ymd(dStart);

        container.innerHTML = '<p class="text-muted small">Indlæser elspot-data…</p>';

        try {
            const res = await fetch(
                `api.php?action=elspot-prices&start=${start}&end=${end}` +
                `&area=${encodeURIComponent(area)}&gln=${encodeURIComponent(gln)}&format=hourly&tariff=low`
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
            for (const c of S.allCharges) {
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
                (S.allCharges.length > 0 ? '<div id="touChargeChart" class="mt-3"></div>' : '');

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

            if (S.allCharges.length > 0) {
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

    M.tou = { fetchTouHeatmap };
})();
