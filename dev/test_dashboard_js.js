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
vm.runInContext(fn('_fmt') + '\n' + fn('_hddExplanation') + '\n' + fn('_weatherCorrectedLine'), sandbox);
const why = sandbox._hddExplanation;
const line = sandbox._weatherCorrectedLine;

console.log('_hddExplanation(hddThis, hddLast)');
check('colder this year: says so, with the percentage (134.7 vs 97.6 = +38 %)',
    why(134.7, 97.6) === 'Koldere vejr end samme periode sidste år: 38 % flere gradedage', why(134.7, 97.6));
check('warmer this year: says so', why(70, 97.6) === 'Varmere vejr end samme periode sidste år: 28 % færre gradedage', why(70, 97.6));
check('about the same weather (under 10 %): no line', why(100, 97.6) === '' && why(90, 97.6) === '');
check('exactly 10 % is explained, 9 % is not', why(110, 100) !== '' && why(109, 100) === '');
check('no degree days last year (division by zero): no line, no exception', why(10, 0) === '' && why(0, 0) === '');
check('the percentage uses the Danish number format (2150 -> "2.150")', why(2250, 100).includes('2.150 %'), why(2250, 100));

console.log('\n_weatherCorrectedLine(pct)');
const arrow = /[↑↓]/;
check('a value that rounds to zero is "uændret": neutral, no arrow, no "0,0"',
    line(0.3).includes('trend-neutral') && line(0.3).includes('uændret') && !line(0.3).includes('0,0') && !arrow.test(line(0.3)), line(0.3));
check('-0.4 is also unchanged', line(-0.4).includes('uændret'), line(-0.4));
check('a rise: up arrow, red class, whole percent', line(7.6).includes('trend-up') && line(7.6).includes('↑ 8 % vejrkorrigeret'), line(7.6));
check('a fall: down arrow, green class, whole percent', line(-12.2).includes('trend-down') && line(-12.2).includes('↓ 12 % vejrkorrigeret'), line(-12.2));
check('no decimals on a rounded figure', !/\d,\d/.test(line(7.6)) && !/\d,\d/.test(line(-12.2)));
check('not a number: no line', line(NaN) === '' && line(Infinity) === '');

console.log('\n_sharePercents / _costShareBars');
const shares = vm.createContext({ Number, Math });
vm.runInContext(fn('_fmt') + '\n' + fn('_sharePercents') + '\n' + fn('_costShareBars') + '\n' +
    src.match(/const _SHARE_PARTS = \[[\s\S]*?\];\nconst _SHARE_SUB_COLOR = '[^']+';/)[0].replace(/^const /gm, 'var '), shares);
const pct = shares._sharePercents, bars = shares._costShareBars;
const sum = a => a.reduce((s, v) => s + v, 0);
check('percents add up to exactly 100 (thirds: 34/33/33)', sum(pct([1, 1, 1])) === 100 && pct([1, 1, 1]).join() === '34,33,33', pct([1, 1, 1]).join());
check('the dashboard figures (389/178/250 kWh) give 48/22/30 % (sums to 100)', pct([388.73, 178.4, 249.78]).join() === '48,22,30', pct([388.73, 178.4, 249.78]).join());
check('nothing, negatives and NaN count as zero', pct([0, 0]).join() === '0,0' && pct([-5, 10, NaN]).join() === '0,100,0');
const html = bars({ ev: 388.73, hp: 178.4, rest: 249.78 }, { ev: 1036, hp: 752, rest: 1141, sub: 105 });
check('two bars and a legend', (html.match(/class="tc-row"/g) || []).length === 2 && html.includes('class="tc-legend"'), html.slice(0, 80));
check('kWh bar has three segments, kr bar four (with the subscription)', (html.match(/class="tc-track"[\s\S]*?<\/div><\/div>/g) || []).map(s => (s.match(/tc-seg/g) || []).length).join() === '3,4');
check('segment widths of a bar add up to 100 %', (() => {
    const bar = html.match(/class="tc-track"[\s\S]*?<\/div><\/div>/)[0];
    return Math.abs(sum([...bar.matchAll(/width:([\d.]+)%/g)].map(m => +m[1])) - 100) < 0.05;
})());
check('the legend sets the kWh share beside the kr share (EV 48 % of kWh, 34 % of kr)', html.includes('EV hjemme <b>48 %</b> af kWh · <b>34 %</b> af kr'), html.match(/EV hjemme <b>[^<]*<\/b>[^<]*<b>[^<]*<\/b>/)?.[0]);
check('each bar is described for screen readers', html.includes('role="img" aria-label="kWh hidtil 817: EV hjemme 48 %, Jordvarme 22 %, Øvrigt 30 %"'), html.match(/aria-label="[^"]*"/)?.[0]);
check('no subscription: the kr bar has three segments', (bars({ ev: 1, hp: 1, rest: 1 }, { ev: 1, hp: 1, rest: 1, sub: 0 }).match(/tc-seg/g) || []).length === 6);
check('no kWh or no kr: no bars', bars({ ev: 0, hp: 0, rest: 0 }, { ev: 1, hp: 1, rest: 1, sub: 0 }) === '' && bars({ ev: 1, hp: 1, rest: 1 }, { ev: 0, hp: 0, rest: 0, sub: 0 }) === '');

console.log('\n_sparklineTrend / _sparkBarColors (today is only part over)');
const spark = vm.createContext({ Number, Math });
vm.runInContext(fn('_sparklineTrend') + '\n' + fn('_sparkBarColors'), spark);
const trend = spark._sparklineTrend;
const flat29 = Array(29).fill(10);
check('a steady series with a half-finished today is "Stabilt", not "Faldende"',
    trend([...flat29, 5.3]).label.includes('Stabilt'), JSON.stringify(trend([...flat29, 5.3])));
check('the same today bar being huge changes nothing either', trend([...flat29, 90]).label.includes('Stabilt'));
check('a real rise over the finished days is still found', trend([...Array(10).fill(5), ...Array(10).fill(7), ...Array(10).fill(10)]).label.includes('Stigende'));
check('a real fall is still found', trend([...Array(10).fill(10), ...Array(10).fill(7), ...Array(10).fill(5)]).label.includes('Faldende'));
check('too short a series, or nothing at all: no trend', trend([1, 2, 3]) === null && trend(null) === null && trend(Array(30).fill(0)) === null);
const colors = spark._sparkBarColors(4, '#22c55e');
check('only the last bar (today) is pale', colors.join() === '#22c55eaa,#22c55eaa,#22c55eaa,#22c55e33', colors.join());
check('one bar: it is today, so pale', spark._sparkBarColors(1, '#fff')[0] === '#fff33' && spark._sparkBarColors(0, '#fff').length === 0);

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
