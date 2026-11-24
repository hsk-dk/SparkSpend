/**
 * Design review findings 4, 5 and 7:
 *  4  the month's expected bill is the biggest figure on Oversigt (a small label over a 32 px number)
 *  5  the degree-days line on Jordvarme is named (legend, axis "Gradedage") and dark enough to be a graphic
 *  7  figures are right-aligned in Opsummering (Ladninger) and Bilsammenligning
 * Run:  node dev/test_review_4_5_7.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const dash = read('includes/dashboard.js'), css = read('includes/style.css'), jv = read('includes/jordvarme.js');
const charges = read('includes/elbil/charges.js'), an = read('includes/elbil/analytics.js'), index = read('index.php');
const px = tok => ({ xs: 12, sm: 14, md: 16, lg: 20, xl: 24, '2xl': 32, '3xl': 40 })[tok];

console.log('4: the expected bill');
check('a small label over the figure, and the figure in Danish format (3.026, not 3026)', /class="total-cost-label">Forventet elregning</.test(dash) && /class="total-cost-amount">~\$\{_fmt\(cost\.total_proj, 0\)\} kr</.test(dash));
const amount = css.match(/\.total-cost-card \.total-cost-amount \{[^}]*font-size: var\(--text-([\w]+)\)/);
check('the figure is 32 px (--text-2xl)', !!amount && px(amount[1]) === 32, amount && amount[1]);
const stat = [...css.matchAll(/\.dash-stat-value[^{]*\{[^}]*font-size: var\(--text-([\w]+)\)/g)].map(m => px(m[1]));
check('it is larger than the figures on the three cards under it', stat.length > 0 && stat.every(s => s < 32), stat.join());

console.log('\n5: the degree-days line on Jordvarme');
check('the line and its axis are named: legend shown when it is there, axis "Gradedage" (not "GD")', /display: !!hddValues, position: 'top'/.test(jv) && /text: 'Gradedage'/.test(jv) && !/text: 'GD'/.test(jv));
check('the live overlay and the redraw keep the legend in step (on with the line, off without it)', (jv.match(/legend\.display = !!hddValues/g) || []).length === 2);
check('one definition of the line\'s colour, label and axis (no pale slate left)', /const HDD_COLOR = '#64748b'/.test(jv) && !/148, 163, 184/.test(jv) && /const hddAxis = /.test(jv) && (jv.match(/hddAxis\(\)/g) || []).length === 3);
const hex = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
const lum = rgb => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
const vsWhite = 1.05 / (lum(hex('#64748b')) + 0.05);
check(`the line is at least 3:1 against white (${vsWhite.toFixed(1)}:1; the old pale slate was 2.5:1)`, vsWhite >= 3);

console.log('\n7: figures to the right');
const sum = charges.slice(charges.indexOf('function _updateSummary'), charges.indexOf('// Edit charge modal'));
check('Opsummering: every column but the car is right-aligned, header and cells', (sum.match(/<th class="text-end">/g) || []).length === 5 && (sum.match(/<td class="text-end">/g) || []).length === 10 && !/<td>\$\{appUtils\.num/.test(sum));
const head = index.slice(index.indexOf('vehicleComparisonTableBody') - 900, index.indexOf('vehicleComparisonTableBody'));
check('Bilsammenligning: six right-aligned headers, the car on the left', (head.match(/<th class="text-end">/g) || []).length === 6 && /<th>Bil<\/th>/.test(head));
const rows = an.slice(an.indexOf('function _renderVehicleComparison') >= 0 ? an.indexOf('function _renderVehicleComparison') : an.indexOf('charge_count) > 0'));
check('and the six figure cells of each row are right-aligned', (rows.match(/<td class="text-end">\$\{(v\.charge_count|appUtils\.num\(v\.)/g) || []).length === 6, (rows.match(/<td class="text-end">/g) || []).length);

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
