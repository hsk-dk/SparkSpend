/**
 * The phone items of the review (BACKLOG: Mobil): what takes the screen before the first card, and how long Hus -> Regning is.
 * Measured on real data at 375 px: Hus -> Regning 8,295 px (about 60 month cards of nine rows), the cost band on Oversigt ~300 px.
 *  - the cost band's legend is folded on a phone (a <details>, open on a wide screen where it is one line);
 *  - a Regning month card keeps the house and the three kr, not the kWh behind each, and only the newest twelve months;
 *  - the older months are one tap away, and none of this is visible on a wide screen.
 * Run:  node dev/test_mobile.js
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
const reg = read('includes/regning.js');
const dash = read('includes/dashboard.js');

console.log('the cost band on Oversigt');
check('the legend is inside a <details class="tc-fold">, open when the window is wider than 600 px', /<details class="tc-fold"\$\{wide \? ' open' : ''\}>/.test(dash) && /matchMedia\('\(min-width: 601px\)'\)/.test(dash));
check('turning the phone or widening the window opens or folds every legend', /addEventListener\('change', e => document\.querySelectorAll\('\.tc-fold'\)\.forEach\(d => \{ d\.open = e\.matches; \}\)\)/.test(dash));
check('on a wide screen the "Fordeling i procent" handle is hidden and the legend lines up with the bars', /\.tc-fold > summary \{ display: none; \}/.test(css) && /\.tc-fold \{ margin-left: 160px; \}/.test(css));
check('on a phone the handle is shown (display: list-item) and the legend is flush left', /@media[^{]*\{[^@]*\.tc-fold > summary \{ display: list-item;/.test(css) && /\.tc-fold \{ margin-left: 0; \}/.test(css));
check('without matchMedia (a test, an old browser) it draws open, as before', /window\.matchMedia \? window\.matchMedia\('\(min-width: 601px\)'\)\.matches : true/.test(dash));

console.log('\nHus -> Regning on a phone');
check('the kWh cells of EV, heat pump and rest, and the shed, are .m-extra (when the kr beside them exist)', /class="text-end\$\{evRow\.cost > 0 \? ' m-extra' : ''\}" data-label="El-bil kWh"/.test(reg) && /class="text-end\$\{hasCosts \? ' m-extra' : ''\}" data-label="VP kWh"/.test(reg) && /fw-semibold\$\{hasResidual \? ' m-extra' : ''\}/.test(reg) && /text-muted m-extra" data-label="heraf Varme - Skur kWh"/.test(reg));
check('the Total card follows the month cards (the same cells are .m-extra)', /fw-bold\$\{hasResidual \? ' m-extra' : ''\}/.test(reg) && /\$\{totEvCost > 0 \? ' m-extra' : ''\}" data-label="El-bil kWh"/.test(reg));
check('the house and the three kr are never .m-extra (Hus kWh, El-bil kr, VP kr*, Restforbrug kr*)', !/m-extra[^>]*data-label="(Hus kWh|El-bil kr|VP kr\*|Restforbrug kr\*)"/.test(reg) && !/data-label="(Hus kWh|El-bil kr|VP kr\*|Restforbrug kr\*)"[^>]*m-extra/.test(reg));
check('months beyond the newest twelve are .reg-old', /idx >= MOBILE_MONTHS \? ' class="reg-old"' : ''/.test(reg) && /const MOBILE_MONTHS = 12;/.test(reg));
check('a button shows how many older months there are, and one tap shows them all', /Vis ældre måneder \(\$\{months\.length - MOBILE_MONTHS\}\)/.test(reg) && /classList\.add\('reg-all'\)/.test(reg));
check('the button is hidden unless the window is a phone', /\n\.reg-more \{ display: none; \}/.test(css));
const phone = (css.match(/@media \(max-width: 600px\) \{\s*#regningTableWrapper \.table-responsive[\s\S]*?\n\}/) || [''])[0];
check('on a phone: .m-extra hidden, .reg-old hidden until .reg-all, the button shown and then hidden again', /#regningTableWrapper \.m-extra \{ display: none; \}/.test(phone) && /:not\(\.reg-all\) tbody tr\.reg-old \{ display: none; \}/.test(phone) && /\.reg-more \{ display: block;/.test(phone) && /\.reg-all \.reg-more \{ display: none; \}/.test(phone));
check('nothing of it applies outside that media query (the table keeps every column and row on a wide screen)', !/(^|\n)#regningTableWrapper \.m-extra/.test(css) && !/(^|\n)#regningTableWrapper:not/.test(css));

console.log('\nthe other wide table (Opsummering on Elbil -> Ladninger)');
check('wide tables scroll sideways inside their own box with a shadow at the edge that has more (no page-wide scroll), the summary too', /\.table-responsive, #summaryText \{\s*background:[\s\S]*?linear-gradient\(to right, #fff 30%/.test(css) && /#summaryText \{ overflow-x: auto; \}/.test(css));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
