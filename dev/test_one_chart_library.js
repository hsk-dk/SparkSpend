/**
 * One chart library (technical debt, step 6): Chart.js and its datalabels plugin are the only chart scripts the page loads, and
 * nothing in the app refers to ApexCharts any more. A new chart is a Chart.js chart (or a table), with the text alternative that
 * the global plugin in includes/a11y.js gives it.
 * Run:  node dev/test_one_chart_library.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const walk = d => fs.readdirSync(path.join(root, d), { withFileTypes: true }).flatMap(e => e.isDirectory() ? walk(d + '/' + e.name) : [d + '/' + e.name]);

const index = read('index.php');
const cdn = [...index.matchAll(/<script[^>]*src="https:\/\/cdn\.jsdelivr\.net\/npm\/([^"/@]+(?:\/[^"/@]+)?)@/g)].map(m => m[1]);
const chartScripts = cdn.filter(s => /chart|apex|plotly|echarts|d3|uplot/i.test(s));
check('the page loads Chart.js and nothing else for charts: no other library, no plugin', chartScripts.join() === 'chart.js', chartScripts.join());

const code = walk('includes').filter(f => /\.(js|php|css)$/.test(f) && !/useful\.css$/.test(f));
const hits = code.filter(f => /apex/i.test(read(f)));
check('no file under includes/ mentions ApexCharts (code, styles or comments)', hits.length === 0, hits.join(', '));
check('index.php does not either', !/apex/i.test(index));
check('the service worker does not either', !/apex/i.test(read('sw.js.php')));
check('the lint globals do not declare it', !/Apex/.test(read('dev/eslintrc.json')));
check('no file under includes/ or index.php mentions the datalabels plugin any more (no registration, no "off" switch, no workaround)', !/datalabels|_listened/i.test(index) && code.every(f => !/datalabels|_listened/i.test(read(f))), code.filter(f => /datalabels|_listened/i.test(read(f))).join(', '));
const reg = read('includes/regning.js');
check('the share of each slice of the doughnut is in its legend ("El-bil 43 %") and its tooltip, with whole numbers that add up to 100', reg.includes('Chart.overrides.doughnut.plugins.legend.labels.generateLabels(chart)') && reg.includes('`${item.text} ${shares[i]} %`') && reg.includes('appUtils.percents(data)') && reg.includes("shares[ctx.dataIndex] + '%)'"));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
