/**
 * Design review findings 8, 9 and 11:
 *  8  no card in a card: the charts inside a card have no box of their own, a panel inside a card has no shadow
 *  9  no dead surfaces: the Oversigt sparklines sit level at the bottom of equal-height cards, the Jordvarme/Hus charts have a height
 *     of their own beside the statistics panel, the Opsummering card has no empty band under its table
 * 11  a year with only a few months is drawn as points (not a line) and is left out of the average
 * Run:  node dev/test_review_8_9_11.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, ''), index = read('index.php');
const rule = sel => { const i = css.indexOf(sel + ' {'); return i < 0 ? '' : css.slice(i, css.indexOf('}', i) + 1); };

console.log('8: no card in a card');
const box = rule('#costTrendChart');
check('the three charts in Analyse have no box: no background, border, shadow or padding of their own', /margin: 12px 0/.test(box) && !/background|border|box-shadow|padding|min-height/.test(box), box);
check('a panel inside a card (Statistik, Omkostningsstatistik) has no shadow, also on hover', /\.card \.card \{[^}]*box-shadow: none/.test(css) && /\.card \.card:hover \{ box-shadow: none; \}/.test(css));

console.log('\n9: no dead surfaces');
check('the card body fills its card and the sparkline sits at the bottom of it (level across the three cards)', /\.dash-body \{[^}]*flex: 1/.test(css) && /\.dash-sparkline \{[^}]*margin-top: auto/.test(css));
check('Jordvarme and Hus draw their chart in a .chart-fill box of 300 px (and the box goes with a hidden canvas)', /\.chart-fill \{ position: relative; height: 300px; \}/.test(css) && /\.chart-fill:has\(> canvas\[style\*="display: none"\]\)/.test(css) && index.includes('<div class="chart-fill"><canvas id="heatpumpChart"></canvas></div>') && index.includes('<div class="chart-fill"><canvas id="husChart"></canvas></div>'));
for (const f of ['includes/hus.js', 'includes/jordvarme.js']) {
    const s = read(f);
    check(`${f}: all three charts fill the box (maintainAspectRatio: false)`, (s.match(/responsive: true,\n\s+maintainAspectRatio: false/g) || []).length === 3 && (s.match(/responsive: true/g) || []).length === 3);
}
check('Opsummering has no empty band under its table (no inline margin, table without a bottom margin)', !/<div id="summaryText" style=/.test(index) && /<table class="table table-sm mb-0">\n\s+<tr><th>Bil<\/th>/.test(read('includes/elbil/charges.js')));

console.log('\n11: a year with few months');
const yr = read('includes/elbil/yearly.js');
const m = yr.match(/\n    (function _sparseYears[\s\S]*?\n    \})\n/);
const sb = vm.createContext({ Set, Map, Array });
vm.runInContext('const SPARSE_MONTHS = 3;\n' + m[1] + '\nthis.f = _sparseYears;', sb);
const arr = (...idx) => { const a = new Array(12).fill(null); idx.forEach(i => { a[i] = 100; }); return a; };
const sp = Array.from(sb.f(new Map([['2024', arr(10, 11)], ['2025', arr(0, 1, 2, 3)], ['2026', arr(0, 1, 2)], ['2023', arr(5)], ['2022', arr()]])));
check('two months and one month and none are sparse; three or more are not', sp.sort().join() === '2022,2023,2024', sp.join());
check('a sparse year is drawn without a line, and says so in its tooltip', /showLine: !sparse\.has\(year\)/.test(yr) && /Kun få måneder med data: ikke med i gennemsnittet/.test(yr));
check('the average leaves the sparse years out', /\.filter\(\(\[y\]\) => !sparse\.has\(y\)\)\.map\(\(\[y, arr\]\) => appUtils\.runningMonthIndex/.test(yr));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
