/**
 * Elbil -> Analyse, "Omkostningstendenser": a Chart.js stacked area (technical debt, step 3: ApexCharts is on its way out).
 * Run:  node dev/test_cost_trend_chart.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'elbil', 'analytics.js'), 'utf8').split('\r\n').join('\n');
const fn = src.slice(src.indexOf('function _renderCostTrendChart'), src.indexOf('function _renderCostStatistics'));

check('a Chart.js chart, not ApexCharts', /new Chart\(/.test(fn) && !/ApexCharts/.test(src));
check('two stacked layers: Hjemme from the axis, Ude on top of it', /label: 'Hjemme'[^}]*fill: 'origin'/.test(fn) && /label: 'Ude'[^}]*fill: '-1'/.test(fn) && /y: \{ stacked: true/.test(fn));
check('one point per date (duplicates are merged before drawing)', /merged\.has\(d\.date\)/.test(fn) && /labels: dates/.test(fn));
check('the chart keeps its title, and the canvas is named for a screen reader', /text: 'Omkostningstendenser'/.test(fn) && /data-a11y-title="Omkostningstendenser"/.test(fn));
check('the tooltip gives each layer and the total, in Danish numbers with two decimals', /appUtils\.num\(item\.raw, 2\)/.test(fn) && /I alt:/.test(fn));
check('the y axis is titled and starts at 0 with whole kr', /text: 'Omkostning \(kr\)'/.test(fn) && /beginAtZero: true/.test(fn) && /appUtils\.num\(v, 0\)/.test(fn));
check('an old chart is destroyed first, and the datalabels plugin is off for it', /costTrendChartInstance\.destroy\(\)/.test(fn) && /datalabels: \{ display: false \}/.test(fn));
check('the layers differ in lightness (dark home, lighter away), both at least 3:1 against white', (() => {
    const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    const L = h => { const r = [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16)); return 0.2126 * lin(r[0]) + 0.7152 * lin(r[1]) + 0.0722 * lin(r[2]); };
    const vs = h => 1.05 / (L(h) + 0.05);
    return vs('#224b81') >= 3 && vs('#c87f6d') >= 3 && (L('#c87f6d') + 0.05) / (L('#224b81') + 0.05) >= 1.7;
})());

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
