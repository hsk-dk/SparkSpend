/**
 * Consumption-weighted electricity prices (pure functions, unit-tested:
 *   node dev/test_pricing.js).
 *
 * A plain average of the month's prices treats every hour alike. The estimate is
 * more accurate when each hour counts in proportion to how much was consumed in
 * it: a heat pump that mostly runs at night should be priced at the night prices.
 *
 *   weighted price = sum(kWh_h * price_h) / sum(kWh_h)
 *
 * over the hours that have BOTH a consumption figure and a price (hours without a
 * published price are left out of numerator and denominator alike).
 */
(function (root) {
    'use strict';

    const pad2 = n => String(n).padStart(2, '0');

    /**
     * Hourly price records ({date:'YYYY-MM-DD', hour:0..23, kr_kwh}) -> { 'YYYY-MM-DD HH': kr_kwh }.
     * On the autumn DST change the same local hour occurs twice: those are averaged.
     */
    function priceByHour(records) {
        const acc = {};
        for (const r of records || []) {
            if (!r) continue;
            const price = Number(r.kr_kwh);
            if (!Number.isFinite(price)) continue;
            const key = `${r.date} ${pad2(r.hour)}`;
            (acc[key] ||= { sum: 0, n: 0 });
            acc[key].sum += price;
            acc[key].n += 1;
        }
        const out = {};
        for (const k of Object.keys(acc)) out[k] = acc[k].sum / acc[k].n;
        return out;
    }

    /**
     * @param {string[]} hours  'YYYY-MM-DD HH' keys
     * @param {number[]} kwh    consumption per hour, aligned with hours
     * @param {Object<string,number>} priceMap  from priceByHour()
     * @returns {number|null}   kr/kWh, or null when no hour has both consumption and price
     */
    function weightedRate(hours, kwh, priceMap) {
        let cost = 0, total = 0;
        for (let i = 0; i < hours.length; i++) {
            const c = Number(kwh[i]);
            const p = priceMap[hours[i]];
            if (!(c > 0) || p === undefined) continue;
            cost += c * p;
            total += c;
        }
        return total > 0 ? cost / total : null;
    }

    /**
     * Weighted HIGH and LOW tariff rates for the heat pump and for the rest of the house.
     * @param {{hours:string[], vp:number[], rest:number[]}} consumption  api.php?action=hourly-consumption
     * @returns {{vp:?{high:number,low:number}, rest:?{high:number,low:number}}|null}
     */
    function weightedRates(consumption, highRecords, lowRecords) {
        if (!consumption || !Array.isArray(consumption.hours)) return null;
        const hi = priceByHour(highRecords), lo = priceByHour(lowRecords);
        const pair = kwh => {
            const high = weightedRate(consumption.hours, kwh || [], hi);
            const low  = weightedRate(consumption.hours, kwh || [], lo);
            return high === null || low === null ? null : { high, low };
        };
        const vp = pair(consumption.vp), rest = pair(consumption.rest);
        return vp || rest ? { vp, rest } : null;
    }

    const api = { priceByHour, weightedRate, weightedRates };
    root.SparkPricing = api;
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
