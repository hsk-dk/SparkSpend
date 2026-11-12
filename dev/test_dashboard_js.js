/**
 * Pure helpers of includes/dashboard.js that explain the cards' numbers. The file needs a browser, so the functions are cut out
 * of the real source and run in a bare VM.
 * Run:  node dev/test_dashboard_js.js
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

const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'dashboard.js'), 'utf8');
function fn(name) {
    const m = src.match(new RegExp('(?:^|\\n)((?:async )?function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n\\})\\n'));
    if (!m) throw new Error('function ' + name + ' not found in dashboard.js');
    return m[1];
}
const sandbox = vm.createContext({ Number, Math });
vm.runInContext(fn('_fmt') + '\n' + fn('_hddExplanation'), sandbox);
const why = sandbox._hddExplanation;

console.log('_hddExplanation(hddThis, hddLast)');
check('colder this year: says so, with the percentage (134.7 vs 97.6 = +38 %)',
    why(134.7, 97.6) === 'Koldere vejr end samme periode sidste år: 38 % flere gradedage', why(134.7, 97.6));
check('warmer this year: says so', why(70, 97.6) === 'Varmere vejr end samme periode sidste år: 28 % færre gradedage', why(70, 97.6));
check('about the same weather (under 10 %): no line', why(100, 97.6) === '' && why(90, 97.6) === '');
check('exactly 10 % is explained, 9 % is not', why(110, 100) !== '' && why(109, 100) === '');
check('no degree days last year (division by zero): no line, no exception', why(10, 0) === '' && why(0, 0) === '');
check('the percentage uses the Danish number format (2150 -> "2.150")', why(2250, 100).includes('2.150 %'), why(2250, 100));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
