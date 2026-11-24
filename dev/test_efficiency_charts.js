/**
 * Elbil -> Forbrug pr. km: the two cumulative line charts are Chart.js (technical debt, step 4: ApexCharts is on its way out).
 * A category per day instead of a date adapter; one line per car in the design system's series colours and marker shapes.
 * Run:  node dev/test_efficiency_charts.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'elbil', 'efficiency.js'), 'utf8').split('\r\n').join('\n');

const code = src.replace(/\/\/.*$/gm, '');   // comments may talk about the adapter
check('no ApexCharts, no date adapter: Chart.js with a category per day', /new Chart\(/.test(code) && !/ApexCharts/.test(code) && !/type: 'time'/.test(code) && !/adapter/i.test(code));
check('both charts are drawn by one function, with their titles, axis titles and decimals', /'Kumuleret km pr\. kWh pr\. bil', 'km pr\. kWh', 1/.test(src) && /'Kumuleret kr pr\. km pr\. bil', 'kr pr\. km', 2/.test(src));
check('one line per car in the series colours, with a marker shape each (colour is not the only cue)', /appUtils\.seriesColors\(\)/.test(src) && /pointStyle: appUtils\.SERIES_POINTS/.test(src));
check('a day without a measurement is a gap the line spans, and the tooltip leaves it out', /spanGaps: true/.test(src) && /filter: item => item\.raw !== null/.test(src));
check('a car name is no longer HTML-escaped for the chart (a canvas shows text as text)', !/escapeHtml\(vName\)/.test(src));
check('the days of all cars are collected as arrays (flatMap does not open an iterator: that broke the first version)', /flatMap\(s => \[\.\.\.s\.points\.keys\(\)\]\)/.test(src));
check('the canvas has a name for a screen reader', /data-a11y-title/.test(src));

// the day axis, run for real: every day between the first and the last, in order, across a month and a leap day
const fn = src.slice(src.indexOf('const lastDay = days[days.length - 1];'), src.indexOf('const colors = appUtils.seriesColors();'));
const run = days => { const labels = []; new Function('days', 'labels', fn + '; return labels;')(days, labels); return labels; };
const span = run(['2028-02-27', '2028-03-02']);
check('the axis has every day between the first and the last measurement, leap day included', span.join() === '2028-02-27,2028-02-28,2028-02-29,2028-03-01,2028-03-02', span.join());
check('one measurement day gives one point', run(['2026-11-05']).join() === '2026-11-05');
const year = run(['2026-12-30', '2027-01-02']);
check('and it crosses a year', year.join() === '2026-12-30,2026-12-31,2027-01-01,2027-01-02', year.join());

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
