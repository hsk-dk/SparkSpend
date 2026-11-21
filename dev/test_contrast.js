/**
 * Text contrast (WCAG AA, 4.5:1) of the colours the overview, the year chips and the cost figures use, read from the
 * stylesheet itself so a change of colour is caught. The page behind the cards is a photo, so a translucent card is
 * measured against white and against a mid grey (the worse of the two counts).
 * Run:  node dev/test_contrast.js
 */
const fs = require('fs');
const path = require('path');

let n = 0, fails = 0;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}

const root = path.join(__dirname, '..', 'includes');
const css = fs.readFileSync(path.join(root, 'style.css'), 'utf8');
const usefulCss = fs.readFileSync(path.join(root, 'useful.css'), 'utf8');   // the design system's tokens (--text-good, --color-text-muted ...)
const read = f => fs.readFileSync(path.join(root, f), 'utf8');

// ---- colour maths -------------------------------------------------------------------------------------------------
function parseColor(s) {
    s = s.trim();
    let m = s.match(/^#([0-9a-f]{3})$/i);
    if (m) return [...m[1]].map(h => parseInt(h + h, 16)).concat(1);
    m = s.match(/^#([0-9a-f]{6})$/i);
    if (m) return [0, 2, 4].map(i => parseInt(m[1].slice(i, i + 2), 16)).concat(1);
    m = s.match(/^rgba?\(([^)]+)\)$/i);
    if (m) { const p = m[1].split(',').map(Number); return [p[0], p[1], p[2], p[3] === undefined ? 1 : p[3]]; }
    throw new Error('cannot read colour: ' + s);
}
const over = (fg, bg) => [0, 1, 2].map(i => fg[i] * fg[3] + bg[i] * (1 - fg[3])).concat(1);
const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
const ratio = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };

// ---- the stylesheet: the LAST rule that sets a property for a selector wins (the cascade for same specificity) ------------
const rules = [];
for (const m of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}@][^{}]*)\{([^{}]*)\}/g)) {
    rules.push({ selectors: m[1].split(',').map(s => s.trim()), body: m[2] });
}
const vars = {};
// Tokens first (useful.css loads first), then SparkSpend's own: the later definition wins, as in the page
for (const source of [usefulCss, css]) {
    for (const m of source.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}@][^{}]*)\{([^{}]*)\}/g)) {
        if (!m[1].split(',').map(s => s.trim()).includes(':root')) continue;
        for (const d of m[2].matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) vars[d[1]] = d[2].trim();
    }
}
/** var(--a) that points at var(--b) ... down to a value (up to five levels). */
function resolve(value) {
    for (let i = 0; i < 5 && /var\(/.test(value); i++) value = value.replace(/var\((--[\w-]+)(?:,\s*([^)]+))?\)/g, (_, v, fb) => vars[v] || fb);
    return value;
}
function prop(selector, name) {
    let value = null;
    for (const r of rules) {
        if (!r.selectors.includes(selector)) continue;
        const m = r.body.match(new RegExp('(?:^|[;\\s])' + name + '\\s*:\\s*([^;]+)'));
        if (m) value = m[1].trim();
    }
    if (value === null) throw new Error(selector + ' has no ' + name);
    return resolve(value);
}

const WHITE = [255, 255, 255, 1], GREY = [128, 128, 128, 1];
/**
 * The contrast of fg on a stack of (possibly translucent) layers, outermost first, laid over the page. The page is a photo,
 * so it is tried as white and as mid grey and the worse result counts: a layer that is opaque (or nearly) does not care.
 */
function worst(fg, ...layers) {
    return Math.min(...[WHITE, GREY].map(page => { const b = layers.reduce((under, l) => over(l, under), page); return ratio(over(fg, b), b); }));
}
const AA = 4.5;
const atLeast = (name, value, min = AA) => check(`${name}: ${value.toFixed(1)}:1 (needs ${min}:1)`, value >= min);

console.log('year chips (dark text, the series colour on the border and the dot)');
const palette = [...read('app.js').match(/SERIES_FALLBACK = \[([^\]]*)\]/)[1].matchAll(/'(#[0-9a-f]{6})'/gi)].map(m => m[1]).concat('#94a3b8');
const chipText = parseColor(prop('.year-chip', 'color'));
for (const c of palette) {
    const col = parseColor(c);
    const tint = over([col[0], col[1], col[2], 0.16], WHITE);        // color-mix(<series> 16%, white)
    atLeast('active chip text on the ' + c + ' tint', ratio(chipText, tint));
}
atLeast('hidden chip text on white', ratio(parseColor(prop('.year-chip:not(.active)', 'color')), WHITE));
check('no chip rule puts white letters on a series colour', !rules.some(r => r.selectors.some(s => s.startsWith('.year-chip') && !s.includes('::before')) && /(^|[;\s])color\s*:\s*#(?:fff|ffffff)\b/i.test(r.body)));

console.log('\nwarnings, buttons and badges on the overview');
const card = parseColor(prop('.dashboard-card', 'background'));
const bgOf = sel => parseColor(prop(sel, 'background'));
atLeast('freshness alert (.alert-warning)', worst(parseColor(prop('.alert-warning', 'color')), bgOf('.alert-warning')));
check('...and its background is opaque, because it sits straight on the page photo', bgOf('.alert-warning')[3] === 1);
atLeast('"Afvis" button (inside the anomaly panel)', worst(parseColor(prop('.anomaly-dismiss-btn', 'color')), bgOf('.anomaly-panel'), bgOf('.anomaly-dismiss-btn')));
atLeast('badge "under normalt" (inside a card)', worst(parseColor(prop('.dash-season-badge.season-low', 'color')), card, bgOf('.dash-season-badge.season-low')));
atLeast('badge "over normalt" (inside a card)', worst(parseColor(prop('.dash-season-badge.season-high', 'color')), card, bgOf('.dash-season-badge.season-high')));
atLeast('EV card "Hjemme" label', worst(parseColor(prop('.dash-split-home-lbl', 'color')), card));
atLeast('EV card "Ude" label', worst(parseColor(prop('.dash-split-ext-lbl', 'color')), card));
atLeast('muted text on the cards', worst(parseColor(resolve(vars['--muted-strong'])), card));
atLeast('red (money) on the cards', worst(parseColor(resolve(vars['--bad-strong'])), card));
atLeast('green (cheaper) on the cards', worst(parseColor(resolve(vars['--ok-strong'])), card));

console.log('\nthe cost figures of Hus -> Fordeling');
const regning = read('regning.js');
check('the figure is not set in the series colour (green and amber were 2.0-2.1:1); the colour is the card\'s top border',
    !/class="fw-bold fs-5"\s+style="color:/.test(regning) && /border-top:4px solid \$\{c\.colour\}/.test(regning));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
