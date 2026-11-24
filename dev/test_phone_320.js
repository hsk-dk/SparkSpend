/**
 * Phones at 320 px: the Hus -> Forbrug period row (« November 2026 » and "Skjul El-bil") was 9 px wider than the screen. The row wraps,
 * the arrows are « and » (their words are for wider screens, their name is in aria-label), and no button in the row is stretched to the
 * full width by the generic phone rule "button { width: 100% }".
 * Run:  node dev/test_phone_320.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const index = read('index.php'), css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');

check('the Hus period row wraps instead of running over the edge', index.includes('class="d-flex align-items-center flex-wrap row-gap-2 mb-2" id="husPeriodNav"'));
for (const p of ['heatpump', 'hus']) {
    check(`${p}: the arrows are « and » on a phone (the words from 576 px), and named for a screen reader`,
        index.includes(`id="${p}Prev" aria-label="Forrige periode">&laquo;<span class="d-none d-sm-inline"> Forrige</span></button>`)
        && index.includes(`id="${p}Next" aria-label="Næste periode"><span class="d-none d-sm-inline">Næste </span>&raquo;</button>`));
}
check('on a phone the period buttons keep their own width (not 100 %) and are at least 44 px wide', /@media \(max-width: 480px\) \{[^@]*#heatpumpPeriodNav button, #husPeriodNav button \{ width: auto; min-width: var\(--target-size\); \}/.test(css));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
