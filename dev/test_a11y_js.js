/**
 * The text alternatives of the charts (includes/a11y.js): the builder is a plain function, read from the real source and run
 * in a bare VM. What a screen reader is told about a chart is what the test pins down.
 * Run:  node dev/test_a11y_js.js
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

const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'a11y.js'), 'utf8').split('\r\n').join('\n');
function fn(name) {
    const m = src.match(new RegExp('(?:^|\\n)(function ' + name + '\\(.*?\\) \\{[\\s\\S]*?\\n\\})\\n'));
    if (!m) throw new Error('function ' + name + ' not found in a11y.js');
    return m[1];
}
const kinds = src.match(/const _A11Y_KINDS = \{[^}]*\};/)[0].replace('const ', 'var ');
const sb = vm.createContext({ Number, Math, Intl, String, Array });
vm.runInContext(kinds + '\n' + fn('_a11yNum') + '\n' + fn('_a11yPercents') + '\n' + fn('_a11yChartText'), sb);
const text = sb._a11yChartText;

console.log('_a11yChartText: bars and lines');
const annual = text('Årsrapport', 'bar', ['2024', '2025', '2026 (til dato)'], [
    { label: 'Jordvarme kWh', data: [2354, 2293, 2041] },
    { label: 'El-bil hjemme kWh', data: [508.4, 8743.1, 6422.7] },
]);
check('title, kind, number of points and the span of the labels', annual.startsWith('Årsrapport. Søjlediagram med 3 punkter fra 2024 til 2026 (til dato).'), annual);
check('every series: lowest, highest and latest, in Danish number format', annual.includes('Jordvarme kWh: laveste 2.041, højeste 2.354, seneste 2.041') && annual.includes('El-bil hjemme kWh: laveste 508,4, højeste 8.743,1, seneste 6.422,7'), annual);
const spark = text('Elbil, de seneste 30 dage', 'bar', Array.from({ length: 30 }, (_, i) => i), [
    { data: Array.from({ length: 30 }, (_, i) => i % 5), backgroundColor: ['x'] },
    { data: Array(30).fill(2), borderDash: [4, 3] },
]);
check('a sparkline (labels 0, 1, 2 ...) has no span to read out', !spark.includes(' fra '), spark);
check('its unnamed series are "Værdier" and the dashed one "Gennemsnit"', spark.includes('Værdier: laveste 0, højeste 4') && spark.includes('Gennemsnit: 2'), spark);
check('a flat series is one number, not a range', text('T', 'line', ['a', 'b'], [{ label: 'Flad', data: [3, 3] }]).includes('Flad: 3.'));
check('missing points (null) are left out of lowest, highest and latest', text('T', 'line', ['a', 'b', 'c'], [{ label: 'S', data: [4, null, 9] }]).includes('S: laveste 4, højeste 9, seneste 9'));
check('points given as {x, y} count by y', text('T', 'line', [], [{ label: 'S', data: [{ x: 1, y: 2 }, { x: 2, y: 8 }] }]).includes('S: laveste 2, højeste 8, seneste 8'));
check('a series of only null says so', text('T', 'bar', ['a'], [{ label: 'S', data: [null] }]).includes('S: ingen værdier'));
check('a line chart is a "Kurvediagram"', text('T', 'line', ['a', 'b'], [{ label: 'S', data: [1, 2] }]).startsWith('T. Kurvediagram'));
check('more than six series: the first six and how many more', (t => t.includes('og 2 serier mere'))(text('T', 'bar', ['a'], Array.from({ length: 8 }, (_, i) => ({ label: 'S' + i, data: [i + 1] })))));

check('a title that ends in a question mark gets no full stop on top', text('Hvad koster det?', 'bar', ['a'], [{ label: 'S', data: [1] }]).startsWith('Hvad koster det? Søjlediagram') && !text('Hvad koster det?', 'bar', ['a'], [{ label: 'S', data: [1] }]).includes('?.'));

console.log('\n_a11yChartText: a doughnut and an empty chart');
const donut = text('Hvad bruger penge', 'doughnut', ['El-bil', 'Varmepumpe', 'Restforbrug'], [{ data: [10021, 4352, 1044] }]);
check('a doughnut says each slice\'s share (65, 28, 7 %)', donut === 'Hvad bruger penge. Cirkeldiagram: El-bil 65 %, Varmepumpe 28 %, Restforbrug 7 %.', donut);
check('slices that round to 101 % are made to add up to 100 (44.6, 18.8, 36.6 reads 45, 19, 36, not 45, 19, 37)', text('P', 'doughnut', ['A', 'B', 'C'], [{ data: [446, 188, 366] }]) === 'P. Cirkeldiagram: A 45 %, B 19 %, C 36 %.');
check('a doughnut of nothing: values, not NaN %', !text('T', 'doughnut', ['A'], [{ data: [0] }]).includes('NaN'));
check('no data at all: it says so', text('Tom', 'bar', [], [{ data: [] }]) === 'Tom. Søjlediagram uden data.' && text('Tom', 'bar', [], []) === 'Tom. Søjlediagram uden data.');
check('an unknown chart type is a plain "Diagram"', text('T', 'whatever', ['a'], [{ label: 'S', data: [1] }]).startsWith('T. Diagram'));

console.log('\nwhere it is wired in');
check('one global Chart.js plugin labels every chart after each update', /Chart\.register\(\{ id: 'a11yLabel', afterUpdate: _a11yLabelChart \}\)/.test(src));
check('the state of the menus and toggles is said in ARIA (aria-current, aria-pressed)', /aria-current/.test(src) && /aria-pressed/.test(src));
check('the skip link moves focus to <main> without changing the address (the router keeps the page in the hash)', /querySelector\('\.skip-link'\)\?\.addEventListener\('click', e => \{\s*e\.preventDefault\(\);\s*document\.getElementById\('main'\)\?\.focus\(\);/.test(src));
check('every chart is a Chart.js canvas, so there is no separate branch for another library', !/apex/i.test(src) && /setAttribute\('role', 'img'\)/.test(src));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
