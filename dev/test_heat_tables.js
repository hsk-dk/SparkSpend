/**
 * Elbil -> Analyse, "Elspot-tidsmønster": two heat tables (technical debt, step 5: ApexCharts' heatmaps are gone). A figure stands in
 * every cell, so the colour is never the only way to read it; the tint is capped so the text keeps 4.5:1; hours are the rows and
 * the weekdays the columns (24 x 7 fits a phone without sideways scroll).
 * Run:  node dev/test_heat_tables.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const app = read('includes/app.js'), tou = read('includes/elbil/tou.js'), css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');

const m = app.match(/\nconst heatTable = ([\s\S]*?\n\};)\n/);
const sb = vm.createContext({ Number, Math });
vm.runInContext('this.heatTable = ' + m[1], sb);
const heatTable = sb.heatTable;

console.log('the table');
const days = ['Man', 'Tir', 'Ons'], hours = ['00:00', '01:00'];
const html = heatTable({ caption: 'Titel', cols: days, rows: hours, values: [[1, 2, null], [0, 4, 4]], cls: 'heat-x', maxAlpha: 0.5, fmt: v => 'f' + v, title: (r, c, v) => `${c} ${r}: ${v}` });
check('a table with a caption, a column header per weekday and a row header per hour', /<table class="heat-table heat-x"><caption>Titel<\/caption>/.test(html) && (html.match(/<th scope="col">/g) || []).length === 3 && (html.match(/<th scope="row">/g) || []).length === 2);
check('six cells carry a figure and one is a dash', (html.match(/>f\d<\/td>/g) || []).length === 5 && html.includes('<td>–</td>'), html);
check('the title names the day and the hour: "Tir 00:00: 2"', html.includes('title="Tir 00:00: 2"'));
const heat = [...html.matchAll(/--heat:([\d.]+)/g)].map(x => Number(x[1]));
check('the lowest figure gets the faintest tint (0.08), the highest the cap (0.5), and a zero none', heat[0] === 0.08 && heat[heat.length - 1] === 0.5 && heat[2] === 0, heat.join());
check('the tint never goes above the cap', heat.every(h => h <= 0.5));
check('all values equal: the cap, not a division by zero', heatTable({ caption: 'x', cols: ['a'], rows: ['r'], values: [[3]], cls: 'c', fmt: String, title: () => '' }).includes('--heat:0.5'));
check('nothing at all: a table of dashes, no NaN', !/NaN|Infinity/.test(heatTable({ caption: 'x', cols: ['a'], rows: ['r'], values: [[null]], cls: 'c', fmt: String, title: () => '' })));

console.log('\nthe page');
check('no ApexCharts left in the file, and the tables come from appUtils.heatTable', !/ApexCharts/.test(tou.replace(/\/\/.*$/gm, '')) && (tou.match(/appUtils\.heatTable\(/g) || []).length === 2);
check('hours are the rows, Man to Søn the columns', /cols: dayNames, rows: hours/.test(tou) && /dayNames = \['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'\]/.test(tou));
check('heatTable is exported on appUtils', /\n  heatTable,\n/.test(app));
check('the tint is a custom property over the table\'s colour: price in slate, charges in the EV colour', /\.heat-table td \{[^}]*color-mix\(in srgb, var\(--heat-color\) calc\(var\(--heat, 0\) \* 100%\), transparent\)/.test(css) && /\.heat-price\s+\{ --heat-color: #334155; \}/.test(css) && /\.heat-ev\s+\{ --heat-color: var\(--data-ev\); \}/.test(css));

console.log('\ncontrast: the text on the strongest tint');
const cap = Number((app.match(/maxAlpha = ([\d.]+)/) || [])[1]);
const ev = (css.match(/--data-ev:\s*(#[0-9a-fA-F]{6})/) || [])[1];
const hex = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
const lum = rgb => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const over = (fg, a) => hex(fg).map(c => Math.round(c * a + 255 * (1 - a)));
check('the cap is 0.5', cap === 0.5, String(cap));
for (const [name, col] of [['price (slate #334155)', '#334155'], ['charges (EV colour)', ev]]) {
    for (const text of ['#212529', '#243140']) {
        const r = ratio(hex(text), over(col, cap));
        check(`${text} on ${name} at ${cap}: ${r.toFixed(1)}:1 (needs 4.5:1)`, r >= 4.5);
    }
}

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
