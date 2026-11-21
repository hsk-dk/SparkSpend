/**
 * The type scale is the Useful design system's (includes/useful.css): --text-xs 12, -sm 14, -md 16, -lg 20, -xl 24, -2xl 32, -3xl 40 px,
 * in rem. The overview used 18 different sizes, down to 10 px, and the labels the card is read by were the smallest. This keeps it from
 * drifting back: every font-size in the stylesheet is one of the tokens, and nothing written inline by the scripts or index.php is a
 * loose px/rem.
 * Run:  node dev/test_typescale.js
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
const read = f => fs.readFileSync(path.join(root, f), 'utf8');
const css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');

console.log('the scale (from useful.css)');
const useful = read('includes/useful.css').replace(/\/\*[\s\S]*?\*\//g, '');
const expected = { 'text-xs': 0.75, 'text-sm': 0.875, 'text-md': 1, 'text-lg': 1.25, 'text-xl': 1.5, 'text-2xl': 2, 'text-3xl': 2.5 };
const defined = {};
for (const m of useful.matchAll(/--(text-(?:xs|sm|md|lg|xl|2xl|3xl))\s*:\s*([\d.]+)rem/g)) defined[m[1]] = Number(m[2]);
check('seven steps: 12, 14, 16, 20, 24, 32 and 40 px (in rem)', JSON.stringify(defined) === JSON.stringify(expected), JSON.stringify(defined));
check('nothing in the scale is smaller than 12 px', Math.min(...Object.values(defined)) * 16 >= 12);
check('style.css does not define a scale of its own (no --text-*, no --fs-*: they would override the tokens)', !/--(text|fs)-[\w]+\s*:/.test(css));

console.log('\nthe stylesheet');
const decls = [...css.matchAll(/(?<![-\w])font-size\s*:\s*([^;}]+)/g)].map(m => m[1].trim());
const loose = [...new Set(decls.filter(v => !/^var\(--text-(xs|sm|md|lg|xl|2xl|3xl)\)( !important)?$/.test(v)))];
check(`every one of the ${decls.length} font-size declarations is a step of the scale`, loose.length === 0, 'loose: ' + loose.join(', '));
const used = new Set(decls.map(v => (v.match(/--(text-[a-z0-9]+)/) || [])[1]));
check('and the five steps the app needs are in use (xs to xl)', ['text-xs', 'text-sm', 'text-md', 'text-lg', 'text-xl'].every(s => used.has(s)), [...used].join(','));

console.log('\nwritten inline by the scripts and the page');
const files = ['index.php', ...fs.readdirSync(path.join(root, 'includes')).filter(f => f.endsWith('.js')).map(f => 'includes/' + f),
    ...fs.readdirSync(path.join(root, 'includes', 'elbil')).filter(f => f.endsWith('.js')).map(f => 'includes/elbil/' + f)];
const inlineLoose = [];
for (const f of files) {
    for (const m of read(f).matchAll(/font-size\s*:\s*([^;"'`)]+\)?)/g)) {
        const v = m[1].trim();
        if (!/^var\(--text-(xs|sm|md|lg|xl|2xl|3xl)\)$/.test(v)) inlineLoose.push(f + ': ' + v);
    }
}
check('no inline font-size is a loose px or rem (use the scale, or the .text-xs class)', inlineLoose.length === 0, inlineLoose.join('; '));
check('Bootstrap\'s own size helpers follow the scale too (.small, .fs-4/.fs-5/.fs-6, .badge)',
    /\.small, small \{ font-size: var\(--text-xs\); \}/.test(css) && /\.fs-4, \.fs-5 \{ font-size: var\(--text-lg\) !important; \}/.test(css)
    && /\.fs-6 \{ font-size: var\(--text-md\) !important; \}/.test(css) && /\.badge \{ --bs-badge-font-size: var\(--text-xs\); \}/.test(css));
check('the small notes inside the tables use .text-xs', /\.text-xs \{ font-size: var\(--text-xs\); \}/.test(css));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
