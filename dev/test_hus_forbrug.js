/**
 * Hus -> Forbrug (BACKLOG, panel review): with real data the car's charges (blue) dwarfed the stacked bars, so Jordvarme and
 * Restforbrug could hardly be read. The steady parts now sit at the bottom, where they share a baseline, the car is on top, and
 * "Skjul El-bil" takes it out of the stack (the axis rescales to the steady parts).
 * Run:  node dev/test_hus_forbrug.js
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
const hus = read('includes/hus.js');
const index = read('index.php');
const stacked = hus.slice(hus.indexOf('function _renderStacked'), hus.indexOf('function _renderCompare'));

console.log('the stack');
const order = [...stacked.matchAll(/label: '([^']+)'/g)].map(m => m[1]);
check('the steady parts first, the car last (on top): Jordvarme, Restforbrug, El-bil', order.join() === 'Jordvarme,Restforbrug,El-bil', order.join());
check('only the top segment is rounded', (stacked.match(/borderRadius: 4/g) || []).length === 1 && /label: 'El-bil'[\s\S]*borderRadius: 4/.test(stacked));
check('the car dataset follows the switch (hidden: hideEv)', /label: 'El-bil'[\s\S]*hidden: hideEv/.test(stacked));
check('the tooltip total leaves the car out, and says so, when it is hidden (no kr either: the cost includes it)', /\(hideEv \? 0 : evVals\[idx\]\)/.test(stacked) && /hideEv \? 'Uden El-bil' : 'Total'/.test(stacked) && /!hideEv && costVals/.test(stacked));

console.log('\nthe switch');
check('a button in the period row, with aria-pressed', /<button[^>]*id="husEvToggle"[^>]*aria-pressed="false"[^>]*>Skjul El-bil<\/button>/.test(index) && /id="husPeriodNav"[\s\S]*id="husEvToggle"[\s\S]*id="husChart"/.test(index));
check('it flips the state, its label and aria-pressed, and draws again', /hideEv = !hideEv;\s*syncEvToggle\(\);\s*if \(lastData\) renderChart\(lastData\);/.test(hus) && /hideEv \? 'Vis El-bil' : 'Skjul El-bil'/.test(hus) && /setAttribute\('aria-pressed', hideEv \? 'true' : 'false'\)/.test(hus));
check('a click on the legend entry keeps the button in step', /legend\.chart\.isDatasetVisible\(ev\)[\s\S]*syncEvToggle\(\)/.test(hus) && /Chart\.defaults\.plugins\.legend\.onClick\.call/.test(hus));
check('it sits in the period row, which Sammenligning and År til dato hide (the switch is for the stacked views)', /periodNav\.style\.display = isChronological \? 'none' : ''/.test(hus));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
