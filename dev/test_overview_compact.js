/**
 * Oversigt as a summary (design panel, proposals 1 + 3): the bill is one big figure over ONE labelled band in kr; each card has a
 * figure, the forecast, ONE verdict and the sparkline; every other comparison, split and percentage is behind "Vis detaljer"
 * (a switch, remembered in this browser), not deleted.
 * Run:  node dev/test_overview_compact.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const dash = read('includes/dashboard.js'), index = read('index.php'), css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');
const fnSrc = name => { const m = dash.match(new RegExp('\\nfunction ' + name + '\\([\\s\\S]*?\\n\\}\\n')); if (!m) throw new Error(name + ' not found'); return m[0]; };
const sb = vm.createContext({ Number, Math, Intl, String });
vm.runInContext("const _SHARE_SUB_COLOR = '#94a3b8';\n" + ['_fmt', '_verdict', '_cardSub', '_billBand'].map(fnSrc).join('\n') + '\nthis._verdict = _verdict; this._cardSub = _cardSub; this._billBand = _billBand;', sb);

console.log('the verdict: one comparison in one phrase');
const v1 = sb._verdict('Pris', 60.4, '1.–24. okt.', 'cost');
check('"Pris ↑ 60 % mod 1.–24. okt.", red for a rise in money', v1.text === 'Pris ↑ 60 % mod 1.–24. okt.' && v1.tone === 'up', JSON.stringify(v1));
check('a fall in money is green; kWh is neutral either way (more use is not automatically bad)', sb._verdict('Pris', -12.2, 'x', 'cost').tone === 'down' && sb._verdict('kWh', 97, 'x').tone === 'neutral' && sb._verdict('kWh', -97, 'x').tone === 'neutral');
check('a change that rounds to zero is "uændret", neutral, with no arrow', (() => { const v = sb._verdict('Pris', 0.3, 'okt.', 'cost'); return v.text === 'Pris uændret mod okt.' && v.tone === 'neutral'; })());
check('nothing to compare with (null, undefined, NaN): no verdict', [null, undefined, NaN].every(x => sb._verdict('kWh', x, 'okt.') === null));

console.log('\nthe line under the figure');
const sub = sb._cardSub(1280.4, 'kr', ['493 kWh', '14 ladninger']);
check('forecast, then the facts: "af ~1.280 kr · 493 kWh · 14 ladninger"', sub === 'af ~1.280 kr · 493 kWh · 14 ladninger', sub);
check('no forecast: just the facts', sb._cardSub(null, 'kr', ['6 kWh']) === '6 kWh' && sb._cardSub(undefined, 'kWh') === '');

console.log('\nthe bill band');
const band = sb._billBand({ ev: 1101, hp: 770, rest: 1144, sub: 15 });
check('one band with a piece per part and the names on the wide ones (Abonnement, 0,5 %, has none)', (band.match(/class="bill-seg( is-named)?"/g) || []).length === 4 && band.includes('EV hjemme 1.101<') && band.includes('Jordvarme 770<') && band.includes('Øvrigt 1.144<') && !band.split('<div class="bill-keys">')[0].includes('>Abonnement'));
check('keys for the phone name every part, and the band is one image for a screen reader with the kr', (band.match(/class="bill-key"/g) || []).length === 4 && band.includes('role="img" aria-label="EV hjemme 1.101 kr, Jordvarme 770 kr, Øvrigt 1.144 kr, Abonnement 15 kr"'));
check('no positive total: no band', sb._billBand({ ev: 0, hp: 0, rest: 0, sub: 0 }) === '' && sb._billBand({ ev: NaN, hp: null, rest: undefined, sub: 0 }) === '');
check('the old two bars are detail, not gone', dash.includes('<div class="total-cost-bars dash-detail">') && dash.includes('class="total-cost-breakdown dash-detail"'));

console.log('\nthe cards');
check('each of the three cards has a summary (figure, line, verdict)', (index.match(/class="dash-sum"/g) || []).length === 3 && (index.match(/class="dash-fig"/g) || []).length === 3 && (index.match(/class="dash-verdict" hidden/g) || []).length === 3);
check('the KPI row, forecast, splits, drive line, composition and comparisons are .dash-detail', (index.match(/class="dash-stats dash-detail"/g) || []).length === 3 && (index.match(/class="dash-meta dash-detail"/g) || []).length === 3 && (index.match(/class="dash-trend dash-detail"/g) || []).length === 3 && index.includes('dash-split-wrap dash-detail') && index.includes('dash-drive dash-detail') && index.includes('dash-comp dash-detail'));
check('a renderer that resets the comparison block keeps its class (it would show in the compact view otherwise)', !dash.includes("className = 'dash-trend';") && (dash.match(/className = 'dash-trend dash-detail'/g) || []).length === 2);
check('every card fills its summary: EV from its own figures, Jordvarme and Hus first in kWh and then in kr from the cost model',
    dash.includes("_setSummary('ev-dashboard-card'") && dash.includes("_setSummary('hp-dashboard-card', {\n        fig: `${_fmt(hp.month_kwh") && dash.includes("_setSummary('hus-dashboard-card', {\n        fig: `${_fmt(rest.month_kwh")
    && dash.includes("_setSummary('hp-dashboard-card', { fig: `${_fmt(cost.hp_mtd") && dash.includes("_setSummary('hus-dashboard-card', { fig: `${_fmt(cost.rest_mtd"));
check('Jordvarme\'s verdict becomes the weather-corrected one when the weather has answered, with the degree-day reason in its tooltip', dash.includes("verdict: _verdict('Vejrkorrigeret', pctPrev, labels.prev)") && dash.includes('verdictTitle:'));
check('the comparisons wait for the 5th of the month, like the old lines (a few days are no basis)', (dash.match(/showTrends \? _verdict\(/g) || []).length === 3 && dash.includes('now.getDate() >= 5 && pctPrev !== null'));

console.log('\n"Vis detaljer"');
check('a switch with aria-pressed, remembered in localStorage, that puts .show-details on the section', index.includes('id="dashDetailsToggle" aria-pressed="false">Vis detaljer<') && dash.includes('sparkspend.dashDetails') && dash.includes("classList.toggle('show-details', on)") && dash.includes("'Skjul detaljer' : 'Vis detaljer'"));
check('without it the detail is hidden, with it everything is as before', css.includes('#oversigt-section:not(.show-details) .dash-detail { display: none; }'));
check('storage that is blocked does not break the page (reading and writing are both guarded)', (dash.match(/catch \(e\) \{ \/\* storage blocked/g) || []).length === 2);

console.log('\nno dead surfaces');
const raw = read('includes/style.css');
const hint = (raw.match(/\.dashboard-card::after \{[^}]*\}/) || [''])[0];
check('the "Se detaljer →" hint is out of the flow (it reserved an empty 35 px band at the bottom of every card, also on a phone)', /position: absolute;/.test(hint) && /top: 24px;/.test(hint) && /right: 24px;/.test(hint) && !/align-self/.test(hint) && /\.dashboard-card \{ position: relative; \}/.test(raw));
check('the hint also shows on keyboard focus, not only on mouse hover', raw.includes('.dashboard-card:focus-visible::after'));
check('with the detail the cards are as tall as their own content, so the shorter ones have no hole above the sparkline', raw.includes('#oversigt-section.show-details .dashboard-grid { align-items: start; }'));

console.log('\nlook');
const px = tok => ({ xs: 12, sm: 14, md: 16, lg: 20, xl: 24, '2xl': 32 })[tok];
const fig = css.match(/\.dash-fig \{[^}]*font-size: var\(--text-(\w+)\)/), bill = css.match(/\.total-cost-card \.total-cost-amount \{[^}]*font-size: var\(--text-(\w+)\)/);
check('the bill is still the biggest figure: 32 px over the cards\' 24 px', !!fig && !!bill && px(fig[1]) === 24 && px(bill[1]) === 32);
const hex = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
const lum = rgb => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
const ratio = (a, b) => { const [x, y] = [lum(hex(a)), lum(hex(b))].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
for (const [cls, bg, fg] of [['is-up', '#fdecec', '#9f2a26'], ['is-down', '#e6f4ea', '#166534']]) {
    check(`the ${cls} verdict is the colours in the stylesheet and passes 4.5:1 (${ratio(bg, fg).toFixed(1)}:1)`, css.includes(`.dash-verdict.${cls}`) && css.includes(`background: ${bg}; color: ${fg};`) && ratio(bg, fg) >= 4.5);
}
const bands = [['#224b81', '#ffffff'], ['#0ea495', '#0b0b0b'], ['#9e5f00', '#ffffff']];
check('the names on the band pass 4.5:1 on every piece (white on the teal one would be 3:1: it is dark)', bands.every(([bg, fg]) => ratio(bg, fg) >= 4.5) && ratio('#0ea495', '#ffffff') < 4.5 && dash.includes("text: '#0b0b0b' },\n        { label: 'Øvrigt'"));
check('on a phone the names leave the band and the keys come under it', css.includes('.bill-seg-name { display: none; }') && css.includes('.bill-keys { display: flex; }') && css.includes('.bill-keys { display: none;'));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
