/**
 * The pure helpers of includes/annual.js that split a year the way the house meter sees it (read from the real source and
 * run in a bare VM, the file itself needs a browser).
 * Run:  node dev/test_annual_js.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let n = 0, fails = 0;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}

// annual.js has CRLF line endings: read it with LF so the function patterns below match
const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'annual.js'), 'utf8').split('\r\n').join('\n');
function fn(name) {
    const m = src.match(new RegExp('(?:^|\\n)(function ' + name + '\\(.*?\\) \\{[\\s\\S]*?\\n\\})\\n'));
    if (!m) throw new Error('function ' + name + ' not found in annual.js');
    return m[1];
}
const sb = vm.createContext({ Number, Math });
vm.runInContext(fn('_annualParts') + '\n' + fn('_annualPartsKr'), sb);
const parts = sb._annualParts, kr = sb._annualPartsKr;

console.log('_annualParts: the house meter split in three');
// The shape of 2025 (the EV home/away split is assumed; the others are the production figures): the EV at home and the heat pump are parts of the house meter, the rest is what is left
const y2025 = { year: '2025', ev: { kwh: 9192, home_kwh: 8900 }, heatpump: { kwh: 2293 }, hus: { kwh: 15914 } };
const p = parts(y2025);
check('rest = meter - heat pump - home EV (15914 - 2293 - 8900 = 4721)', p.rest === 4721, JSON.stringify(p));
check('the three parts add up to the meter', p.vp + p.evHome + p.rest === p.hus);
check('away charging is kept out of the stack (292 kWh) and reported separately', p.evAway === 292 && p.evHome === 8900, JSON.stringify(p));
check('the house did not double between 2024 and 2025: the rest fell (2024: 8132 - 2354 - 590 = 5188)', parts({ ev: { kwh: 590, home_kwh: 590 }, heatpump: { kwh: 2354 }, hus: { kwh: 8132 } }).rest === 5188 && p.rest < 5188);
check('never negative: a house meter lower than heat pump + EV (a gap in the readings) gives 0', parts({ ev: { kwh: 500, home_kwh: 500 }, heatpump: { kwh: 400 }, hus: { kwh: 700 } }).rest === 0);
check('no EV at all (2021-2023): the rest is the meter minus the heat pump', (q => q.evHome === 0 && q.evAway === 0 && q.rest === 7699 - 2345)(parts({ ev: { kwh: 0, home_kwh: 0 }, heatpump: { kwh: 2345 }, hus: { kwh: 7699 } })));
check('no house meter: rest and meter are null, not 0 (a missing meter is not "no other use")', (q => q.rest === null && q.hus === null && q.vp === 2345)(parts({ ev: { kwh: 10, home_kwh: 10 }, heatpump: { kwh: 2345 }, hus: null })));
check('an older payload without home_kwh: the whole EV total counts as home (what the chart used to show)', parts({ ev: { kwh: 100 }, heatpump: { kwh: 10 }, hus: { kwh: 500 } }).evHome === 100);
check('missing numbers are 0, not NaN', (q => [q.vp, q.evHome, q.evAway].every(v => v === 0))(parts({ ev: {}, heatpump: {}, hus: null })));

console.log('\n_annualPartsKr: the same split in kr');
const k = kr({ ev: { cost: 440, home_cost: 280 }, cost: { vp: 700, rest: 900, hus: 2100 } });
check('Monta price for the home charging only, the model\'s price for the heat pump and the rest', k.evHome === 280 && k.vp === 700 && k.rest === 900, JSON.stringify(k));
check('no estimate (elspot not configured): vp and rest are null, the EV price is still there', (q => q.vp === null && q.rest === null && q.evHome === 280)(kr({ ev: { cost: 440, home_cost: 280 }, cost: null })));
check('no house meter: the cost has no rest', kr({ ev: { cost: 1, home_cost: 1 }, cost: { vp: 5, rest: null, hus: null } }).rest === null);
check('an older payload without home_cost: the EV total price (what the chart used to show)', kr({ ev: { cost: 440 }, cost: null }).evHome === 440);

console.log('\nthe chart is stacked');
check('x and y are stacked and every bar belongs to one stack', /x: \{ stacked: true/.test(src) && /y: \{\s*stacked: true/.test(src) && /stack: 'house'/.test(src));
check('"Hus total" is no longer a bar next to the others', !/label: 'Hus total kWh'|label: 'Hus total kr\*'/.test(src));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
