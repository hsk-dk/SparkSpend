// Tests for includes/pricing.js and the optional per-component rates in
// appUtils.elvarmeCost (includes/app.js).   Run:  node dev/test_pricing.js
const fs = require('fs');
const path = require('path');
const P = require('../includes/pricing.js');

// elvarmeCost lives in the browser script app.js: lift just that function out.
const appSrc = fs.readFileSync(path.join(__dirname, '../includes/app.js'), 'utf8');
const start = appSrc.indexOf('const elvarmeCost = (');
const end = appSrc.indexOf('\n};\n', start) + 4;
if (start < 0 || end < 4) { console.error('cannot extract elvarmeCost from app.js'); process.exit(2); }
const elvarmeCost = new Function(appSrc.slice(start, end) + '\nreturn elvarmeCost;')();

let n = 0, fails = 0;
const close = (a, b, eps = 1e-9) => Math.abs(a - b) <= eps;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}

console.log('priceByHour()');
let m = P.priceByHour([{ date: '2026-10-25', hour: 2, kr_kwh: 1.0 }, { date: '2026-10-25', hour: 2, kr_kwh: 3.0 },
                       { date: '2026-10-25', hour: 3, kr_kwh: 2.0 }, { date: '2026-10-25', hour: 4, kr_kwh: NaN }, null]);
check('key is "YYYY-MM-DD HH"', m['2026-10-25 03'] === 2.0);
check('the repeated DST hour is averaged', m['2026-10-25 02'] === 2.0);
check('non-numeric prices are skipped', !('2026-10-25 04' in m));
check('hour is zero-padded', P.priceByHour([{ date: '2026-10-01', hour: 7, kr_kwh: 1 }])['2026-10-01 07'] === 1);

console.log('weightedRate()');
const prices = { h0: 1, h1: 2, h2: 3 };
check('sum(kWh*price)/sum(kWh)', close(P.weightedRate(['h0', 'h1', 'h2'], [1, 1, 2], prices), (1 + 2 + 6) / 4));
check('differs from the plain average (2.0) when use is skewed', !close(P.weightedRate(['h0', 'h1', 'h2'], [1, 1, 2], prices), 2));
check('hours without a price are left out of numerator AND denominator', close(P.weightedRate(['h0', 'h1', 'h9'], [1, 1, 100], prices), 1.5));
check('hours without consumption are ignored', close(P.weightedRate(['h0', 'h1'], [0, 4], prices), 2));
check('equal consumption -> plain average', close(P.weightedRate(['h0', 'h1', 'h2'], [5, 5, 5], prices), 2));
check('no usable hour -> null', P.weightedRate(['h9'], [1], prices) === null && P.weightedRate([], [], prices) === null);
check('night-time use is priced at night prices', P.weightedRate(['h0', 'h2'], [9, 1], prices) < 2);

console.log('weightedRates()');
const cons = { hours: ['2026-10-01 00', '2026-10-01 01'], vp: [3, 1], rest: [1, 3] };
const rec = (a, b) => [{ date: '2026-10-01', hour: 0, kr_kwh: a }, { date: '2026-10-01', hour: 1, kr_kwh: b }];
const wr = P.weightedRates(cons, rec(1, 2), rec(0.5, 1));
check('vp: high', close(wr.vp.high, (3 * 1 + 1 * 2) / 4));
check('vp: low', close(wr.vp.low, (3 * 0.5 + 1 * 1) / 4));
check('rest is weighted by its own consumption', close(wr.rest.high, (1 * 1 + 3 * 2) / 4));
check('no consumption series -> null', P.weightedRates(null, rec(1, 2), rec(1, 2)) === null);
check('prices missing -> null', P.weightedRates(cons, [], []) === null);

console.log('elvarmeCost()');
const base = { vpKwh: 200, restKwh: 300, evKwh: 40, highRate: 3.0, lowRate: 2.0, highKwhPerMonth: 333 };
const legacy = elvarmeCost(base);
const hk = 333, vr = 500, lowK = vr - hk;
check('without per-component rates: unchanged flat model', close(legacy.total, hk * 3 + lowK * 2 + 40 * 2) && close(legacy.vpCost + legacy.restCost, hk * 3 + lowK * 2));
const same = elvarmeCost({ ...base, vpRates: { high: 3, low: 2 }, restRates: { high: 3, low: 2 } });
check('equal per-component rates == flat model', close(same.vpCost, legacy.vpCost) && close(same.restCost, legacy.restCost) && close(same.total, legacy.total));
let worst = 0, seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
for (let i = 0; i < 20000; i++) {
    const p = { vpKwh: rnd() * 800, restKwh: rnd() * 800, evKwh: rnd() * 200, highRate: 1 + rnd() * 3, lowRate: rnd() * 3, highKwhPerMonth: rnd() * 700 };
    const a = elvarmeCost(p), b = elvarmeCost({ ...p, vpRates: { high: p.highRate, low: p.lowRate }, restRates: { high: p.highRate, low: p.lowRate } });
    worst = Math.max(worst, Math.abs(a.total - b.total), Math.abs(a.vpCost - b.vpCost), Math.abs(a.restCost - b.restCost));
}
check('20000 random inputs: equal rates give the flat result (max diff ' + worst.toExponential(1) + ')', worst < 1e-9);
const cheapVp = elvarmeCost({ ...base, vpRates: { high: 2.5, low: 1.5 }, restRates: { high: 3.5, low: 2.5 } });
check('cheaper VP hours -> lower VP cost, higher Rest cost', cheapVp.vpCost < legacy.vpCost && cheapVp.restCost > legacy.restCost);
const f = hk / vr;
check('allowance shared pro rata', close(cheapVp.vpCost, 200 * (f * 2.5 + (1 - f) * 1.5)) && close(cheapVp.restCost, 300 * (f * 3.5 + (1 - f) * 2.5)));
check('total = VP + Rest + EV', close(cheapVp.total, cheapVp.vpCost + cheapVp.restCost + cheapVp.evCost));
check('EV cost is unaffected by component rates', close(cheapVp.evCost, legacy.evCost));
const onlyVp = elvarmeCost({ ...base, vpRates: { high: 2.5, low: 1.5 } });
check('only vpRates given: Rest falls back to the flat rates', close(onlyVp.restCost, f * 300 * 3 + (1 - f) * 300 * 2));
check('zero consumption -> zero cost, no NaN', elvarmeCost({ highRate: 3, lowRate: 2, highKwhPerMonth: 333, vpRates: { high: 1, low: 1 } }).total === 0);

console.log(`\n${n} checks, ${fails} failed`);
process.exit(fails ? 1 : 0);
