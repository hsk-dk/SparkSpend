/**
 * The three data colours (elbil, jordvarme, øvrigt): one definition in style.css (--data-ev, --data-hp, --data-rest), the same
 * colour on every card and chart. The palette is stepped in LIGHTNESS on purpose (the Useful design system's four data colours
 * all have the same lightness, so neighbours differ by hue only: delta E 7-9 for colour-blind eyes). This pins down what it was
 * chosen for, and that every place that draws one of the three uses exactly the token.
 * Run:  node dev/test_datacolors.js
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
const css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');

// ---- colour maths (the same as dev/test_contrast.js, plus colour vision) ---------------------------------------------
const hex = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16) / 255);
const lin = c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
const lum = h => { const [r, g, b] = hex(h).map(lin); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const M = {
    normal: [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
    protan: [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
    deutan: [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
    tritan: [[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.303900]],
};
function lab(h, kind) {
    const l = hex(h).map(lin);
    const [R, G, B] = M[kind].map(r => Math.min(1, Math.max(0, r[0] * l[0] + r[1] * l[1] + r[2] * l[2])));
    let X = 0.4124564 * R + 0.3575761 * G + 0.1804375 * B, Y = 0.2126729 * R + 0.7151522 * G + 0.0721750 * B, Z = 0.0193339 * R + 0.1191920 * G + 0.9503041 * B;
    const f = t => t > 216 / 24389 ? Math.cbrt(t) : (24389 / 27 * t + 16) / 116;
    X /= 0.95047; Z /= 1.08883;
    return [116 * f(Y) - 16, 500 * (f(X) - f(Y)), 200 * (f(Y) - f(Z))];
}
const dE = (a, b, k) => { const p = lab(a, k), q = lab(b, k); return Math.hypot(p[0] - q[0], p[1] - q[1], p[2] - q[2]); };

console.log('the tokens');
const tok = {};
for (const m of css.matchAll(/--(data-(?:ev|hp|rest))\s*:\s*(#[0-9a-fA-F]{6})/g)) tok[m[1]] = m[2].toLowerCase();
check('three tokens: --data-ev, --data-hp, --data-rest', Object.keys(tok).sort().join() === 'data-ev,data-hp,data-rest', JSON.stringify(tok));
const { 'data-ev': EV, 'data-hp': HP, 'data-rest': REST } = tok;

console.log('\nwhat the palette is for');
for (const [name, c] of [['elbil', EV], ['jordvarme', HP], ['øvrigt', REST]]) {
    check(`${name} ${c}: at least 3:1 on white (WCAG 1.4.11, a graphic)`, ratio(c, '#ffffff') >= 3, ratio(c, '#ffffff').toFixed(2));
}
const pairs = [['elbil/jordvarme', EV, HP], ['elbil/øvrigt', EV, REST], ['jordvarme/øvrigt', HP, REST]];
for (const [name, a, b] of pairs) {
    check(`${name}: neighbours in a stack differ in lightness (at least 1.5:1)`, ratio(a, b) >= 1.5, ratio(a, b).toFixed(2));
    const worst = Math.min(...Object.keys(M).map(k => dE(a, b, k)));
    check(`${name}: clearly different for every kind of colour vision (delta E at least 20, worst ${worst.toFixed(0)})`, worst >= 20);
}
check('the Useful palette (equal lightness) would not pass this: blå/violet is delta E 7 for deuteranopia', dE('#446ea8', '#6d52ab', 'deutan') < 10);

console.log('\nwhere they are used');
const has = (file, re, what) => check(what, re.test(read(file)), file);
has('includes/annual.js', new RegExp(`vp: '${HP}', ev: '${EV}', rest: '${REST}'`, 'i'), 'the Årsrapport stack uses the three tokens, opaque');
has('includes/hus.js', new RegExp(`COLOR_HP   = '${HP}'[\\s\\S]*COLOR_EV   = '${EV}'[\\s\\S]*COLOR_REST = '${REST}'`, 'i'), 'Hus uses the three tokens');
has('includes/regning.js', new RegExp(`C_EV   = '${EV}'[\\s\\S]*C_HP   = '${HP}'[\\s\\S]*C_REST = '${REST}'`, 'i'), 'Hus → Regning and Fordeling use the three tokens');
has('includes/regning.js', new RegExp(`colour: '${EV}'[\\s\\S]*colour: '${HP}'[\\s\\S]*colour: '${REST}'`, 'i'), 'the three cost cards use them');
const dash = read('includes/dashboard.js');
check('the three sparklines use them', dash.includes(`'${EV}', _evSparkChart`) && dash.includes(`'${HP}', _hpSparkChart`) && dash.includes(`'${REST}', _husSparkChart`));
check('the house composition bar uses them (EV hjemme, Jordvarme, Øvrigt)', dash.includes(`label: 'EV hjemme', color: '${EV}'`) && dash.includes(`label: 'Jordvarme', color: '${HP}'`) && dash.includes(`label: 'Øvrigt',    color: '${REST}'`));
has('includes/jordvarme.js', new RegExp(`backgroundColor: "${HP}"`, 'i'), 'the Jordvarme bars use the jordvarme colour, opaque');
has('includes/elbil/tou.js', new RegExp(`'Antal ladninger pr\\. time/ugedag', '${EV}'`, 'i'), 'the heatmap of charges is in the EV colour');
check('the card icons are elbil, jordvarme and "Øvrigt" (Hus)', /#ev-dashboard-card \.dash-icon\s*\{ background: var\(--data-ev\); \}/.test(css) && /#hp-dashboard-card \.dash-icon\s*\{ background: var\(--data-hp\); \}/.test(css) && /#hus-dashboard-card \.dash-icon \{ background: var\(--data-rest\); \}/.test(css));
check('the EV card\'s home bar and the house composition use the tokens', /\.dash-split-home-fill \{[^}]*var\(--data-ev\)/.test(css) && /\.dash-split-hp-fill \{[^}]*var\(--data-hp\)/.test(css) && /\.dash-split-rest-fill \{[^}]*var\(--data-rest\)/.test(css));
check('the teal is never text (3.1:1): no color: var(--data-hp)', !/(?<![-\w])color\s*:\s*var\(--data-hp\)/.test(css));

console.log('\nthe old colours are gone where the three things are drawn');
const stray = [];
for (const f of ['includes/annual.js', 'includes/hus.js', 'includes/regning.js']) {
    for (const m of read(f).matchAll(/#3b82f6|#22c55e|#6BA3FF|#f59e0b|rgba\(34, ?197, ?94|rgba\(59, ?130, ?246|rgba\(245, ?158, ?11/gi)) {
        // hus.js keeps the year-comparison palette (years, not the three domains)
        if (f === 'includes/hus.js' && /YEAR_COLORS/.test(read(f).split('\n').find(l => l.includes(m[0])) || '')) continue;
        stray.push(f + ': ' + m[0]);
    }
}
check('annual.js, hus.js and regning.js carry none of the old blue, green and amber', stray.length === 0, stray.join('; '));

console.log('\nthe six year colours (year comparisons on Hus, Jordvarme and Elbil)');
// Chosen so the common cases read: the first three (a three-year chart) differ in LIGHTNESS as well as hue (dark brown, light orange, mid blue),
// every pair differs for every kind of colour vision, and each year has its own marker shape as a second cue. The first set (found on hue
// distance alone) put navy and maroon at the same lightness: on real data (Hus -> Sammenligning) the two thin lines were hard to tell apart.
const YEARS = ['#4f1e1e', '#f35e14', '#1b67be', '#c87f6d', '#7c14f3', '#2c2460'];
const list = YEARS.map(c => `'${c}'`).join(', ');
for (const [f, name] of [['includes/hus.js', 'YEAR_COLORS'], ['includes/jordvarme.js', 'YEAR_COLORS'], ['includes/elbil/yearly.js', 'EV_YEAR_COLORS']]) {
    has(f, new RegExp(`${name} = \\[${list}\\]`, 'i'), `${f} uses the same six year colours`);
}
const Ls = c => lab(c, 'normal')[0];
check('each year colour is at least 3:1 on white (a line or a bar)', YEARS.every(c => ratio(c, '#ffffff') >= 3), YEARS.map(c => ratio(c, '#ffffff').toFixed(2)).join(' '));
let yearWorst = 99;
for (let i = 0; i < YEARS.length; i++) for (let j = i + 1; j < YEARS.length; j++) yearWorst = Math.min(yearWorst, ...Object.keys(M).map(k => dE(YEARS[i], YEARS[j], k)));
check(`any two of the six differ by delta E 30 or more for every kind of colour vision (worst ${yearWorst.toFixed(0)})`, yearWorst >= 30);
const dl = (i, j) => Math.abs(Ls(YEARS[i]) - Ls(YEARS[j]));
check(`the first three (a three-year chart) differ in lightness by at least 14 L* each (${dl(0, 1).toFixed(0)}, ${dl(0, 2).toFixed(0)}, ${dl(1, 2).toFixed(0)}); the first set had 3`, dl(0, 1) >= 14 && dl(0, 2) >= 14 && dl(1, 2) >= 14);
check('neighbouring years never have the same lightness (at least 8 L* between index i and i+1)', YEARS.slice(1).every((_, i) => dl(i, i + 1) >= 8));
check('the old year palettes are gone', !['#3b82f6', '#22c55e', '#f97316', '#8b5cf6', '#ef4444', '#ec4899', '#303091', '#f95706', '#63212c', '#27a1b9', '#cb7462', '#9406f9'].some(c => ['includes/hus.js', 'includes/jordvarme.js', 'includes/elbil/yearly.js'].some(f => read(f).toLowerCase().includes(c))));
for (const [f, pfx] of [['includes/hus.js', 'YEAR_POINTS'], ['includes/jordvarme.js', 'YEAR_POINTS'], ['includes/elbil/yearly.js', 'EV_YEAR_POINTS']]) {
    const src = read(f);
    check(`${f}: six different marker shapes, used on the year lines (a cue that does not depend on colour)`, new RegExp(`${pfx} = \\['circle', 'triangle', 'rect', 'rectRot', 'crossRot', 'star'\\]`).test(src) && new RegExp(`pointStyle: ${pfx}\\[i % ${pfx}\\.length\\]`).test(src));
}

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
