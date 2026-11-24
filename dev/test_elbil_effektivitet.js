/**
 * Elbil -> Effektivitet (design review, finding 12): Analyse was 4,500 px long with seven modules. "Forbrug pr. km" and its two curves
 * are a page of their own, between Analyse and Sammenligning; the router is generic (data-sub), so #elbil/effektivitet is a link that
 * works, and the old #elbil/analyse is still a page.
 * Run:  node dev/test_elbil_effektivitet.js
 */
const fs = require('fs');
const path = require('path');
let n = 0, fails = 0;
function check(name, ok, info = '') { n++; if (ok) { console.log('  ok   ' + name); return; } fails++; console.log('  FAIL ' + name + ' ' + info); }
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').split('\r\n').join('\n');
const index = read('index.php'), nav = read('includes/nav.js'), css = read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '');
const section = id => { const a = index.indexOf(`<div id="${id}" class="sub-section`); const b = index.indexOf(`<!-- /#${id} -->`); return a < 0 || b < 0 ? '' : index.slice(a, b); };
const analyse = section('elbil-analyse'), eff = section('elbil-effektivitet');

console.log('the pages');
const order = [...index.matchAll(/<button class="sub-nav-btn" data-sub="(elbil-[a-z]+)">/g)].map(m => m[1]);
check('the Elbil sub-nav is Ladninger, Analyse, Effektivitet, Sammenligning', order.join() === 'elbil-ladninger,elbil-analyse,elbil-effektivitet,elbil-sammenligning', order.join());
check('Effektivitet is a sub-page of its own, after Analyse and before Sammenligning', index.indexOf('id="elbil-analyse"') < index.indexOf('id="elbil-effektivitet"') && index.indexOf('id="elbil-effektivitet"') < index.indexOf('id="elbil-sammenligning"'));
check('it holds "Forbrug pr. km", the tiles and the two curves', eff.includes('<h3>Forbrug pr. km</h3>') && eff.includes('id="efficiencySummary"') && eff.includes('id="kmPerKwhChart"') && eff.includes('id="krPerKmChart"'));
check('Analyse no longer has them, and keeps its four cards: Ladeoptimering, Omkostningsanalyse, Ladesteder, Elspot-tidsmønster',
    !/efficiencySummary|kmPerKwhChart|krPerKmChart|Forbrug pr\. km/.test(analyse) && ['Ladeoptimering', 'Omkostningsanalyse', 'Ladesteder', 'Elspot-tidsmønster'].every(h => analyse.includes(`<h3 class="mb-0">${h}</h3>`) || analyse.includes(`<h3>${h}</h3>`)));
check('the button has a name and an icon like the others', /data-sub="elbil-effektivitet">\s*<i class="fas fa-gauge-high"><\/i> Effektivitet\s*<\/button>/.test(index));

console.log('\nthe router');
check('it works for any sub-page: hash #elbil/<name> becomes elbil-<name>, and the button is found by data-sub (nothing is hard-coded)', nav.includes("const sub = parts[1] ? 'elbil-' + parts[1] : 'elbil-ladninger';") && nav.includes('.sub-nav-btn[data-sub="${subId}"]') && !/elbil-analyse|elbil-effektivitet|elbil-sammenligning/.test(nav));
check('the efficiency numbers are fetched whatever page is open, so the page is full when it is opened', read('includes/elbil/main.js').includes('M.efficiency.fetchEfficiencyStats();'));

console.log('\na phone');
check('four pills and the Administrér button wrap to two rows instead of being cut off at 320 px', /\.sub-nav \{ gap: 4px; flex-wrap: wrap; \}/.test(css) && !/\.sub-nav \{ gap: 4px; flex-wrap: nowrap; \}/.test(css));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
