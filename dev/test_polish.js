/**
 * What a review against the real production data found (2026-11-21), kept from coming back:
 *  - numbers the user reads are formatted the Danish way everywhere (9.192,2 kWh), not with toFixed() (9192.2);
 *  - the cost trend chart on Elbil -> Analyse labelled its points with raw floats (382.28999999999996);
 *  - ApexCharts speaks Danish (axes, download menu), and the labels use the app's words (Hjemme/Ude, km pr. kWh);
 *  - shares that add up to 100, not 101; a car with no charges is not a table row; the subscription is in the legend.
 * Run:  node dev/test_polish.js
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
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const app = read('includes/app.js');

// ---- appUtils.num and appUtils.percents, run from the real source -------------------------------------------------------
function grab(name) {
    const m = app.match(new RegExp('(?:^|\\n)(const ' + name + ' = [\\s\\S]*?\\n\\};)\\n'));
    if (!m) throw new Error(name + ' not found in app.js');
    return m[1];
}
const sb = vm.createContext({ Number, Math, Intl });
vm.runInContext(grab('num') + '\n' + grab('percents') + '\nthis.num = num; this.percents = percents;', sb);

console.log('appUtils.num: Danish, fixed decimals');
check('9192.2 with one decimal is 9.192,2 (toFixed said 9192.2)', sb.num(9192.2, 1) === '9.192,2', sb.num(9192.2, 1));
check('2.075 with three decimals keeps the zero: 2,075; 0.93 with two is 0,93', sb.num(2.075, 3) === '2,075' && sb.num(0.93, 2) === '0,93');
check('rounds like the rest of the app: 382.28999999999996 with two decimals is 382,29', sb.num(382.28999999999996, 2) === '382,29');
check('null, undefined, NaN and "" are a dash, not "NaN"', [null, undefined, NaN, ''].every(v => sb.num(v, 1) === '—'));
check('a numeric string works (the API sends some numbers as strings)', sb.num('12.5', 1) === '12,5');
check('it is exported on appUtils', /\n  num,\n/.test(app));

console.log('\nappUtils.percents: whole numbers that add up to 100');
const p = sb.percents([446, 188, 366]);
check('44.6, 18.8 and 36.6 are 45, 19, 36 (rounding each gives 101)', JSON.stringify(Array.from(p)) === '[45,19,36]', JSON.stringify(Array.from(p)));
check('always 100 in total, for a few awkward sets', [[1, 1, 1], [1, 1, 1, 1, 1, 1, 1], [5, 3, 2], [333, 333, 334], [0.1, 0.2, 0.7]].every(v => Array.from(sb.percents(v)).reduce((a, b) => a + b, 0) === 100));
check('nothing and negatives: zeros', Array.from(sb.percents([0, 0])).join() === '0,0' && Array.from(sb.percents([-5, 5])).join() === '0,100');

console.log('\nno toFixed() on what the user reads');
const files = ['includes/hus.js', 'includes/jordvarme.js', 'includes/regning.js', 'includes/dashboard.js', 'includes/elbil/analytics.js', 'includes/elbil/yearly.js', 'includes/elbil/charges.js', 'includes/elbil/tou.js', 'includes/elbil/efficiency.js'];
const stray = [];
for (const f of files) {
    read(f).split('\n').forEach((line, i) => {
        if (!/toFixed\(/.test(line)) return;
        // allowed: a number that is computed with (parseFloat), a CSS width, a value turned into a decimal comma on the spot
        if (/parseFloat\(|style\.width|width:\$\{|\.replace\('\.', ','\)/.test(line)) return;
        stray.push(f + ':' + (i + 1) + ' ' + line.trim().slice(0, 90));
    });
}
check('every toFixed left is a computation, a CSS width or already has its decimal comma', stray.length === 0, '\n      ' + stray.join('\n      '));

console.log('\nthe cost trend chart (Elbil -> Analyse)');
const an = read('includes/elbil/analytics.js');
check('its points are not labelled with the raw sum (dataLabels off; the tooltip has the figure)', /dataLabels: \{ enabled: false \}/.test(an));
check('its y axis is formatted (no 400.00000000000000)', /yaxis: \{ title: \{[^}]*\}, labels: \{ formatter: v => appUtils\.num\(v, 0\) \}/.test(an));
check('its series are Hjemme / Ude like the rest of the app, not Indre / Ekstern', /name: 'Hjemme \(kr\)'/.test(an) && /name: 'Ude \(kr\)'/.test(an) && !/Indre ladning/.test(an));
check('the car comparison says Hjemme (%) / Ude (%)', /<th>Hjemme \(%\)<\/th>/.test(read('index.php')) && /<th>Ude \(%\)<\/th>/.test(read('index.php')));
check('a car with no charges in the period is not a row ("Ukendt" with 0 kWh)', /filter\(v => Number\(v\.charge_count\) > 0\)/.test(an));
const ef = read('includes/elbil/efficiency.js');
check('the efficiency charts say km pr. kWh and kr pr. km, with formatted axes', /'km pr\. kWh'/.test(ef) && /'kr pr\. km'/.test(ef) && !/KM per kWh|Kr per KM/.test(ef));

console.log('\nApexCharts in Danish');
check('the Danish locale is registered globally: months, days and the download menu', /defaultLocale: 'da'/.test(app) && /exportToSVG: 'Hent som SVG'/.test(app) && /exportToCSV: 'Hent som CSV'/.test(app) && /'jan\.'/.test(app));

console.log('\nsmall things');
const dash = read('includes/dashboard.js'), css = read('includes/style.css');
check('the subscription has a key in the cost band\'s legend (the grey bit at the end of the kr bar)', /Abonnement <b>\$\{cPct\[3\]\}/.test(dash));
check('the "·" between figures is hidden from screen readers and no longer 2.6:1', /class=\\"trend-sep\\" aria-hidden=\\"true\\"|class="trend-sep" aria-hidden="true"/.test(dash) && /\.trend-sep \{ color: var\(--muted-strong\)/.test(css));
const reg = read('includes/regning.js');
check('the doughnut and its cards use the shares that add up to 100', /appUtils\.percents\(data\)/.test(reg) && /appUtils\.percents\(\[totEv, totHp, totRest\]\)/.test(reg) && !/Math\.round\(v \/ total \* 100\)|Math\.round\(ctx\.raw \/ total \* 100\)/.test(reg));
check('the Jordvarme and Hus year tables say why a year\'s total can differ from the Årsrapport', /Årsrapporten bruger tællerdifferenser/.test(read('includes/jordvarme.js')) && /Årsrapporten bruger tællerdifferenser/.test(read('includes/hus.js')));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
