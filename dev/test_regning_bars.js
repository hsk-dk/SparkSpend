/**
 * Hus -> Regning on a wide screen (BACKLOG, panel review: "a wall of numbers"): a bar behind each kr figure, its length the month's
 * share of the column's largest month, in the domain colour at 24 %. The number stays in the cell (a second way to read it, never
 * the only one), a phone shows cards without bars, and the text on the tint passes 4.5:1.
 * Run:  node dev/test_regning_bars.js
 */
const fs = require('fs');
const path = require('path');

let n = 0, fails = 0;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const reg = read('includes/regning.js');
const rawCss = read('includes/style.css');
const css = rawCss.replace(/\/\*[\s\S]*?\*\//g, '');

console.log('the markup');
const rowCells = [...reg.matchAll(/<td class="text-end[^"]*reg-bar reg-bar-(ev|hp|rest)"\$\{_bar\(([^)]*)\)\} data-label="([^"]+)"/g)].map(m => `${m[1]}:${m[3]}:${m[2]}`);
check('exactly the three kr columns of a month row have a bar: El-bil kr, VP kr*, Restforbrug kr*', rowCells.join(' | ') === 'ev:El-bil kr:evRow.cost, colMax.ev | hp:VP kr*:hpCost, colMax.hp | rest:Restforbrug kr*:residualCost, colMax.rest', rowCells.join(' | '));
check('the Total row has no bar (it is the sum, not a month)', !/fw-bold[^"]*reg-bar/.test(reg));
check('each bar is the month\'s share of the column\'s largest month, rounded, and only for a value above zero', /const _bar = \(value, max\) => \(value > 0 && max > 0 \? ` style="--bar:\$\{Math\.round\(value \/ max \* 100\)\}%"` : ''\)/.test(reg));
check('the largest month of each column is found first (EV, heat pump, rest; the rest only where the house meter exists)', /colMax\.ev = Math\.max/.test(reg) && /colMax\.hp = Math\.max/.test(reg) && /colMax\.rest = Math\.max/.test(reg) && /hasResidual && hus\[m\] && hus\[m\]\.kwh > 0/.test(reg));
check('the figure is still in the cell (the bar is decoration)', /reg-bar-ev"[^>]*>\$\{evRow\.cost > 0 \? _kr\(evRow\.cost\)/.test(reg));

console.log('\nthe look');
check('the bar is a background gradient from the left, as long as --bar (0 without it)', /\.reg-bar \{[^}]*linear-gradient\(to right, var\(--bar-color\) var\(--bar, 0%\), transparent var\(--bar, 0%\)\)/.test(css));
const tint = {};
for (const k of ['ev', 'hp', 'rest']) {
    const m = css.match(new RegExp(`\\.reg-bar-${k}\\s*\\{[^}]*--bar-color: color-mix\\(in srgb, var\\(--data-${k}\\) (\\d+)%, transparent\\)`));
    tint[k] = m ? Number(m[1]) : null;
}
check('each column is tinted with its own domain colour at 24 %', tint.ev === 24 && tint.hp === 24 && tint.rest === 24, JSON.stringify(tint));
check('a phone shows cards, and the bars are off there', /@media \(max-width: 600px\) \{[^@]*#regningTableWrapper \.reg-bar \{ background-image: none; \}/.test(css));

console.log('\ncontrast: the text on a full bar');
const tok = {};
for (const m of css.matchAll(/--data-(ev|hp|rest):\s*(#[0-9a-fA-F]{6})/g)) tok[m[1]] = m[2];
const hex = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
const lum = rgb => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const over = (fg, a) => hex(fg).map(c => Math.round(c * a + 255 * (1 - a)));   // fg at alpha a over white
for (const text of ['#212529', '#243140']) {
    for (const k of ['ev', 'hp', 'rest']) {
        const r = ratio(hex(text), over(tok[k], 0.24));
        check(`${text} on ${k} at 24 %: ${r.toFixed(1)}:1 (needs 4.5:1)`, r >= 4.5);
    }
}
const hoverTint = ratio(hex('#212529'), over(tok.rest, 0.24).map(c => Math.round(c * 0.95)));
check(`and still 4.5:1 with a table-hover row darkening it by up to 5 % (${hoverTint.toFixed(1)}:1)`, hoverTint >= 4.5);

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
