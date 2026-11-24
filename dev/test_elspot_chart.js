/**
 * Dashboard "Elpris i morgen / i dag": a Chart.js bar chart (technical debt, step 2: ApexCharts is on its way out).
 * Run:  node dev/test_elspot_chart.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'dashboard.js'), 'utf8').split('\r\n').join('\n');
const fc = src.slice(src.indexOf('async function loadElspotForecast'), src.indexOf('function _showForecastProblem'));

check('the price chart is a Chart.js bar chart, not ApexCharts', /new Chart\(/.test(fc) && /type: 'bar'/.test(fc) && !/ApexCharts/.test(fc));
check('one colour per bar (cheap green, middle amber, dear red)', /backgroundColor: colors/.test(fc) && /#22c55e/.test(fc) && /#f59e0b/.test(fc) && /#ef4444/.test(fc));
check('the tooltip names the hour as 14:00–15:00 and gives kr/kWh with three decimals in Danish format', /title: items =>/.test(fc) && /hh\(hours\[items\[0\]\.dataIndex\]\.hour \+ 1\)/.test(fc) && /appUtils\.num\(item\.raw, 3\) \+ ' kr\/kWh'/.test(fc));
check('the cheapest 2-hour window is shaded behind the bars and labelled "Billigst"', /id: 'cheapestWindow'/.test(fc) && /beforeDatasetsDraw/.test(fc) && /'Billigst'/.test(fc));
check('the y axis starts at 0, is titled kr/kWh and uses Danish numbers', /beginAtZero: true/.test(fc) && /text: 'kr\/kWh'/.test(fc) && /appUtils\.num\(v, 2\)/.test(fc));
check('an old chart is destroyed before the card is drawn again (the card can show a message in between)', /_forecastChart\.destroy\(\)/.test(fc));
check('the datalabels plugin is switched off for it, like every Chart.js chart', /datalabels: \{ display: false \}/.test(fc));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
