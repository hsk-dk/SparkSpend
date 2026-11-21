/**
 * The type scale: five sizes for all text (--fs-xs 12, --fs-sm 14, --fs-md 16, --fs-lg 20, --fs-xl 28 px). The overview used
 * 18 different sizes, down to 10 px, and the labels the card is read by were the smallest. This keeps it from drifting back:
 * every font-size in the stylesheet is one of the five, and nothing written inline by the scripts or index.php is a loose px/rem.
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

console.log('the scale');
const expected = { 'fs-xs': 12, 'fs-sm': 14, 'fs-md': 16, 'fs-lg': 20, 'fs-xl': 28 };
const defined = {};
for (const m of css.matchAll(/--(fs-[a-z]+)\s*:\s*(\d+)px/g)) defined[m[1]] = Number(m[2]);
check('five steps are defined: 12, 14, 16, 20 and 28 px', JSON.stringify(defined) === JSON.stringify(expected), JSON.stringify(defined));
check('nothing in the scale is smaller than 12 px', Math.min(...Object.values(defined)) >= 12);

console.log('\nthe stylesheet');
const decls = [...css.matchAll(/(?<![-\w])font-size\s*:\s*([^;}]+)/g)].map(m => m[1].trim());
const loose = [...new Set(decls.filter(v => !/^var\(--fs-(xs|sm|md|lg|xl)\)( !important)?$/.test(v)))];
check(`every one of the ${decls.length} font-size declarations is a step of the scale`, loose.length === 0, 'loose: ' + loose.join(', '));
const used = new Set(decls.map(v => (v.match(/--(fs-[a-z]+)/) || [])[1]));
check('and all five steps are in use', ['fs-xs', 'fs-sm', 'fs-md', 'fs-lg', 'fs-xl'].every(s => used.has(s)), [...used].join(','));

console.log('\nwritten inline by the scripts and the page');
const files = ['index.php', ...fs.readdirSync(path.join(root, 'includes')).filter(f => f.endsWith('.js')).map(f => 'includes/' + f),
    ...fs.readdirSync(path.join(root, 'includes', 'elbil')).filter(f => f.endsWith('.js')).map(f => 'includes/elbil/' + f)];
const inlineLoose = [];
for (const f of files) {
    for (const m of read(f).matchAll(/font-size\s*:\s*([^;"'`)]+\)?)/g)) {
        const v = m[1].trim();
        if (!/^var\(--fs-(xs|sm|md|lg|xl)\)$/.test(v)) inlineLoose.push(f + ': ' + v);
    }
}
check('no inline font-size is a loose px or rem (use the scale, or the .text-xs class)', inlineLoose.length === 0, inlineLoose.join('; '));
check('Bootstrap\'s own size helpers follow the scale too (.small, .fs-4/.fs-5/.fs-6, .badge)',
    /\.small, small \{ font-size: var\(--fs-xs\); \}/.test(css) && /\.fs-4, \.fs-5 \{ font-size: var\(--fs-lg\) !important; \}/.test(css)
    && /\.fs-6 \{ font-size: var\(--fs-md\) !important; \}/.test(css) && /\.badge \{ --bs-badge-font-size: var\(--fs-xs\); \}/.test(css) && /\.material-symbols-outlined \{ font-size: var\(--fs-lg\); \}/.test(css));
check('the small notes inside the tables use .text-xs', /\.text-xs \{ font-size: var\(--fs-xs\); \}/.test(css));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
