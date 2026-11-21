/**
 * The shared Useful design system (hsk-dk/useful-design) as SparkSpend uses it: includes/useful.css is a PINNED copy of
 * dist/useful.css at tag v0.3.0 (commit 483f3da), never edited here; it loads before style.css; SparkSpend's own variables for the
 * strong text colours are aliases of its tokens; and style.css never redefines a token (it would silently change the meaning of
 * everything that reads it).
 * Upgrading is a deliberate act: copy the new dist/useful.css in, change VERSION and SHA256 below, and read its CHANGELOG.
 * Run:  node dev/test_useful_css.js
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

let n = 0, fails = 0;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}

const VERSION = '0.3.0';
const SHA256 = 'bd5571d5201b45edd842ad8aa5e5a8f75729432cf0dfb787a03af98b7b86f735';   // dist/useful.css at v0.3.0, LF line endings

const root = path.join(__dirname, '..');
const raw = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const useful = raw('includes/useful.css');
const style = raw('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');
const index = raw('index.php');

console.log('the pinned copy');
check(`the first line carries the version: useful-design v${VERSION}`, new RegExp(`^/\\*! useful-design v${VERSION.replace(/\./g, '\\.')} `).test(useful), useful.split('\n')[0]);
check('the file is byte for byte the one at that tag (nobody edited it here)', crypto.createHash('sha256').update(useful).digest('hex') === SHA256, crypto.createHash('sha256').update(useful).digest('hex'));
check('it fetches nothing from elsewhere (no @import, no @font-face, and url() only in a comment)', !/@import|@font-face/.test(useful) && !/url\(/.test(useful.replace(/\/\*[\s\S]*?\*\//g, '')));

console.log('\nhow it is loaded');
const posUseful = index.indexOf("asset('includes/useful.css')"), posStyle = index.indexOf("asset('includes/style.css')");
check('index.php loads useful.css, and before style.css (so SparkSpend\'s own rules can refine it)', posUseful > -1 && posStyle > -1 && posUseful < posStyle);
check('the service worker caches includes/*.css, so the file is in the offline shell and the cache version', /includes\/\*\.css/.test(raw('sw.js.php')));

console.log('\nno token is redefined');
const tokens = new Set();
for (const m of useful.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/(--[\w-]+)\s*:/g)) tokens.add(m[1]);
const redefined = [...tokens].filter(t => new RegExp('(?<![\\w-])' + t.replace(/[-]/g, '\\-') + '\\s*:').test(style));
check(`style.css defines none of the ${tokens.size} tokens of useful.css (SparkSpend had a scale of its own under the same names, one step off)`, redefined.length === 0, redefined.join(', '));

console.log('\nSparkSpend\'s strong text colours are the tokens');
const alias = (name, token) => check(`${name} is var(${token}), and ${token} exists`, new RegExp(name + '\\s*:\\s*var\\(' + token + '\\)').test(style) && tokens.has(token));
alias('--accent-strong', '--color-action-strong');
alias('--ok-strong', '--text-good');
alias('--bad-strong', '--text-bad');
alias('--muted-strong', '--color-text-muted');
alias('--warn-strong', '--text-warn');
check('--link-strong stays SparkSpend\'s own (links straight on its darker page background; the token is only 3.9:1 there)', /--link-strong\s*:\s*#1e3a8a/.test(style));

console.log('\nfigures');
check('tables and the cards\' figures have equal-width digits (D10, Useful\'s .u-num)', /table,[^{}]*\.dash-stat-value[^{}]*\{\s*font-variant-numeric:\s*tabular-nums;/.test(style));

console.log('\nheader, primary button and cards (D1, D2, D6)');
const esc = s => s.replace(/[.\[\]]/g, '\\$&');
const rule = sel => { const m = style.match(new RegExp('(?:^|\\n)' + esc(sel) + '\\s*\\{([^}]*)\\}')); return m ? m[1] : ''; };
check('the header is the system\'s blue gradient (D1), not the marine --brand-dark', /background:\s*var\(--header-gradient\)/.test(rule('header')) && tokens.has('--header-gradient'));
check('the primary button is flat #446EA8 with white text, hover darker (D2; the old #6BA3FF was 2.5:1)', /background:\s*var\(--color-action-strong\)/.test(rule('.btn-primary')) && /background:\s*var\(--color-action-hover\)/.test(rule('.btn-primary:hover')));
check('the other filled blue controls with white text (active page, sub-tab, ticked box) use it too', ['.pagination span.active', '.sub-nav-btn.active', '.form-check-input:checked'].every(s => /background:\s*var\(--color-action-strong\)/.test(rule(s))));
check('cards are opaque (D6): no translucent white background and no backdrop blur on them', ['.dashboard-card', '.card', '#summaryBox', '#costStatsBox', '.chart-container', '.filter-drawer', '.pagination-section', '.anomaly-panel', '.total-cost-card'].every(s => !/background:\s*rgba\(255,\s*255,\s*255/.test(rule(s))) && !/backdrop-filter:\s*saturate/.test(style));
check('the dashboard cards use the system\'s radius and card shadow', /var\(--radius-large\)/.test(rule('.dashboard-card')) && /var\(--shadow-card\)/.test(rule('.dashboard-card')));
check('the inactive header tabs are white at 94 % or more (70 % was 3.5:1 on the gradient)', /color:\s*rgba\(255, 255, 255, 0\.9[4-9]\)/.test(rule('.header-tab-button')));

console.log('\ntouch targets (D21, pointer-aware)');
const raw2 = raw('includes/style.css');
const tail = raw2.slice(raw2.lastIndexOf('D21, pointer-aware'));
const coarse = tail.slice(tail.indexOf('@media (pointer: coarse)'));
check('every control is at least 36 px high by default (mouse)', /\.btn, \.btn-sm, button[^{]*\{\s*min-height: 36px;/.test(tail));
check('and at least 44 px under pointer: coarse (finger), buttons, tabs, chips, selects and text fields', /min-height: 44px;/.test(coarse) && /\.header-tab-button/.test(coarse) && /\.year-chip/.test(coarse) && /select/.test(coarse) && /input\[type="text"\]/.test(coarse));
check('the small ones the audit found are named: Gå til, Afvis, close drawer, skip link, and Rediger (.btn-sm)', ['.anomaly-item-action', '.anomaly-dismiss-btn', '.btn-close-drawer', '.skip-link', '.btn-sm'].every(s => coarse.includes(s)));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
