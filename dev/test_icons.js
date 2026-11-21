/**
 * Icons and font without anybody else's server (D14 and D10 of the Useful design system): the icons are inline SVG masks in
 * includes/icons.css (drawings from Lucide, ISC), the font is the system's, and the Content-Security-Policy no longer lets the page
 * load fonts or stylesheets from Google or cdnjs. This keeps a Font Awesome class in the markup from silently turning into an empty
 * square, and an old link from coming back.
 * Run:  node dev/test_icons.js
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
const walk = d => fs.readdirSync(path.join(root, d), { withFileTypes: true }).flatMap(e => e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]);
const sources = ['index.php', ...walk('includes').filter(f => /\.(js|php|css)$/.test(f) && !/useful\.css$|icons\.css$/.test(f))];
const icons = read('includes/icons.css');

console.log('the icon sheet');
const defined = new Set();
for (const m of icons.matchAll(/((?:\.fa-[\w-]+(?:,\s*)?)+)\s*\{\s*--icon:\s*url\("data:image\/svg\+xml,([^"]+)"\)/g)) {
    for (const c of m[1].split(',')) defined.add(c.trim().slice(1));
    check(`${m[1].split(',')[0].trim()} is a well-formed SVG data URI`, decodeURIComponent(m[2]).startsWith('<svg') && decodeURIComponent(m[2]).endsWith('</svg>'));
}
check('it credits Lucide and its licence (ISC)', /Lucide/.test(icons) && /ISC/.test(icons));
check('an icon is a mask in the text colour, one em square (so it follows font-size and color)', /\.fas, \.fa-solid \{[^}]*width: 1em;[^}]*height: 1em;[^}]*background-color: currentColor;[^}]*mask:/.test(icons));
check('it fetches nothing: no http(s) URL except the SVG namespace', !/https?:\/\/(?!www\.w3\.org\/2000\/svg)[^\s"')]+\.(?:css|woff2?|ttf|svg)/.test(icons.replace(/\/\*[\s\S]*?\*\//g, '')));

console.log('\nevery icon the markup asks for is drawn');
const used = new Set(['fa-inbox']);   // the empty state's default, set in app.js
for (const f of sources) for (const m of read(f).matchAll(/\bfa-([a-z][a-z0-9-]*)/g)) used.add('fa-' + m[1]);
const modifiers = new Set(['fa-solid', 'fa-xs', 'fa-spin', 'fa-arrow-up', 'fa-arrow-down']);   // not icons: size, and colours for a pair of arrows no longer used
const missing = [...used].filter(c => !defined.has(c) && !modifiers.has(c));
check(`all ${used.size - [...used].filter(c => modifiers.has(c)).length} fa-* names used in index.php and the scripts have a drawing`, missing.length === 0, missing.join(', '));
check('no Material Symbols span is left (they were an icon font from Google)', !sources.some(f => /material-symbols/.test(read(f).replace(/\/\*[\s\S]*?\*\//g, ''))));

console.log('\nthe page loads no font and no icon font');
const index = read('index.php');
check('index.php links no Google Fonts, no Font Awesome, no Material Symbols', !/fonts\.googleapis|fonts\.gstatic|font-awesome|cdnjs\.cloudflare/.test(index));
check('index.php loads icons.css, before style.css (so style.css can refine)', index.indexOf("asset('includes/icons.css')") > -1 && index.indexOf("asset('includes/icons.css')") < index.indexOf("asset('includes/style.css')"));
const csp = (read('includes/configuration.php').match(/Content-Security-Policy: ([^"]*)"/) || [])[1] || '';
check('the CSP no longer names fonts.googleapis.com, fonts.gstatic.com or cdnjs.cloudflare.com', csp.length > 0 && !/googleapis|gstatic|cdnjs/.test(csp), csp);
check('and fonts may only come from the app itself (font-src \'self\')', /font-src 'self';/.test(csp));
check('the sheet is a data: image, which the CSP allows (img-src \'self\' data:)', /img-src 'self' data:/.test(csp));
check('the body font is Useful\'s system stack, no Inter', /body \{[^}]*font-family:\s*var\(--font-family-base\)/.test(read('includes/style.css')) && !/Inter/.test(read('includes/style.css').replace(/\/\*[\s\S]*?\*\//g, '')));
check('the service worker caches includes/*.css, so the icons work offline', /includes\/\*\.css/.test(read('sw.js.php')));

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
