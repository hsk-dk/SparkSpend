/**
 * Design review findings 6 and 10: a period is named in Danish ("Uge 45", "nov. 2026", "5. nov.") instead of the server's key
 * ("2026-W45"), a charge state has a Danish word (never the raw "releasing"), and the price chart on Oversigt has level tick labels
 * and says what its colours mean.
 * Run:  node dev/test_period_and_states.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const an = read('includes/elbil/analytics.js'), charges = read('includes/elbil/charges.js'), dash = read('includes/dashboard.js');

console.log('period labels');
const m = an.match(/\n    (function _periodLabel[\s\S]*?\n    \})\n/);
const sb = vm.createContext({ Number, Date, String });
vm.runInContext(m[1] + '\nthis.f = _periodLabel;', sb);
const f = sb.f;
check('a week is "Uge 45", with the year only when the axis spans years', f('2026-W45', false) === 'Uge 45' && f('2026-W01', true) === 'Uge 1 2026', f('2026-W45', false) + ' / ' + f('2026-W01', true));
check('a month is "nov. 2026"', f('2026-11', false) === 'nov. 2026', f('2026-11', false));
check('a day is "5. nov.", and "5. nov. 2026" across years', f('2026-11-05', false) === '5. nov.' && f('2026-11-05', true) === '5. nov. 2026', f('2026-11-05', false) + ' / ' + f('2026-11-05', true));
check('anything else is shown as it came', f('x', false) === 'x');
check('the chart takes its axis labels from it, and says so when a year is crossed', an.includes('dates.map(d => _periodLabel(d, spansYears))') && an.includes('                labels,'));

console.log('\ncharge states');
check('every state Monta sends has a Danish word: completed, stopped_by_user, error, charging, releasing, released', ['completed', 'stopped_by_user', 'error', 'charging', 'releasing', 'released'].every(s => charges.includes(s + ':')));
check('Afslutter and Frigivet are the words for releasing and released', /releasing:\s+\['bg-secondary', 'Afslutter'\]/.test(charges) && /released:\s+\['bg-secondary', 'Frigivet'\]/.test(charges));
check('an unknown state is "Ukendt" with Monta\'s word in the tooltip, not the raw word as the label', /known \|\| \['bg-secondary', 'Ukendt'\]/.test(charges) && /\(known \? '' : charge\.state\)/.test(charges) && !/\|\| \['bg-secondary', charge\.state\]/.test(charges));

console.log('\nthe price chart on Oversigt');
const fc = dash.slice(dash.indexOf('async function loadElspotForecast'), dash.indexOf('function _showForecastProblem'));
check('the hour labels are level (the old 45 degrees were forced) and thin themselves out when there is no room', /maxRotation: 0, minRotation: 0, autoSkip: true/.test(fc) && !/minRotation: 45/.test(fc));
check('the card says that the colours are relative to the day, not a fixed price limit', /Farven viser timen i forhold til dagens billigste og dyreste, ikke en fast prisgrænse/.test(fc));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
