/**
 * SparkSpend Elbil - time-of-use heatmap
 *
 * The Elspot time-of-use heat tables on the Analyse tab (price by hour/weekday and charge counts).
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
            const data = await Api.get('elspot-prices', { start, end, area, gln, format: 'hourly', tariff: 'low' });
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
            const hours    = Array.from({length: 24}, (_, h) => String(h).padStart(2, '0') + ':00');

            // Rows are the hours and the columns the weekdays (Man to Søn): 24 x 7 fits a phone without sideways scroll
            const prices = hours.map((_, h) => dayNames.map((__, dow) => (cnts[dow][h] > 0 ? sums[dow][h] / cnts[dow][h] : null)));

            // Charge count overlay using current _allCharges
            const cCnts = Array.from({length: 7}, () => new Int32Array(24));
            for (const c of S.allCharges) {
                if (!c.datetime) continue;
                const cd  = new Date(c.datetime);
                const dow = (cd.getDay() + 6) % 7;
                cCnts[dow][cd.getHours()]++;
            }
            const charges = hours.map((_, h) => dayNames.map((__, dow) => cCnts[dow][h]));

            // Heat tables, not charts: the figure stands in every cell, and a screen reader gets a real table
            const priceTable = appUtils.heatTable({
                caption: 'Gns. elpris (kr/kWh inkl. afgifter)', cols: dayNames, rows: hours, values: prices, cls: 'heat-price',
                fmt: v => appUtils.num(v, 2),
                title: (hour, day, v) => `${day} kl. ${hour}: ${appUtils.num(v, 3)} kr/kWh`,
            });
            const chargeTable = S.allCharges.length === 0 ? '' : appUtils.heatTable({
                caption: 'Antal ladninger pr. time/ugedag', cols: dayNames, rows: hours, values: charges, cls: 'heat-ev',
                fmt: v => String(v),
                title: (hour, day, v) => `${day} kl. ${hour}: ${v} ladning${v === 1 ? '' : 'er'}`,
            });
            container.innerHTML = '<div class="row g-4"><div class="' + (chargeTable ? 'col-lg-6' : 'col-12') + '">' + priceTable + '</div>' +
                (chargeTable ? '<div class="col-lg-6">' + chargeTable + '</div>' : '') + '</div>';
        } catch (e) {
            console.error('fetchTouHeatmap:', e);
            if (container) container.innerHTML =
                '<p class="text-danger small">Kunne ikke indlæse elspot-data.</p>';
        }
    }

    M.tou = { fetchTouHeatmap };
})();
