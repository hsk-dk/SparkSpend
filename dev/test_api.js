/**
 * The front end's request layer (includes/api.js) and its one HTML escape function (includes/app.js).
 * Loads the real files into a bare VM with a fake fetch, so no browser or server is needed.
 * Run:  node dev/test_api.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let n = 0, fails = 0;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}

function load(file, sandbox) {
    const code = fs.readFileSync(path.join(__dirname, '..', 'includes', file), 'utf8');
    vm.runInContext(code, sandbox, { filename: file });
}

// ---- escapeHtml, from the real app.js -----------------------------------------
{
    const win = { addEventListener() {}, innerWidth: 1200 };
    const sandbox = vm.createContext({
        window: win,
        document: { addEventListener() {}, querySelector: () => null, querySelectorAll: () => [] },
        console, URLSearchParams, setTimeout, clearTimeout, Intl, Event, EventTarget,
        fetch: async () => ({ status: 200, headers: { get: () => '' }, redirected: false }),
    });
    win.fetch = sandbox.fetch;
    load('app.js', sandbox);
    const esc = win.appUtils.escapeHtml;
    console.log('escapeHtml');
    check('escapes the five HTML characters', esc(`<a href="x" onclick='y'>&</a>`) === '&lt;a href=&quot;x&quot; onclick=&#039;y&#039;&gt;&amp;&lt;/a&gt;');
    check('null and undefined become an empty string, not an exception', esc(null) === '' && esc(undefined) === '');
    check('a number becomes text', esc(42) === '42' && esc(0) === '0');
    check('already-escaped text is escaped again, never trusted', esc('&lt;') === '&amp;lt;');
}

// ---- Api, from the real api.js ---------------------------------------------------
(async () => {
    const calls = [];
    let reply;
    const sandbox = vm.createContext({
        window: {}, URLSearchParams, console, JSON,
        fetch: async (url, init) => { calls.push({ url, init }); return reply; },
    });
    load('api.js', sandbox);
    const Api = sandbox.window.Api;
    const json = (status, body, ok = status >= 200 && status < 300) => ({ ok, status, json: async () => body });
    const text = (status) => ({ ok: status < 400, status, json: async () => { throw new SyntaxError('not json'); } });
    const fail = async (fn) => { try { await fn(); return null; } catch (e) { return e; } };

    console.log('Api.url');
    check('action only', Api.url('dashboard') === 'api.php?action=dashboard');
    check('params are appended and URL-encoded', Api.url('x', { a: 'b c', n: 5 }) === 'api.php?action=x&a=b+c&n=5');
    check('null and undefined params are left out, 0 and false are kept',
        Api.url('x', { a: null, b: undefined, c: 0, d: false }) === 'api.php?action=x&c=0&d=false');
    check('a URLSearchParams works too', Api.url('x', new URLSearchParams({ q: '1' })) === 'api.php?action=x&q=1');
    check('an action cannot be overridden from the params', Api.url('x', { action: 'evil', a: 1 }) === 'api.php?action=x&a=1'
        && Api.url('x', new URLSearchParams({ action: 'evil' })) === 'api.php?action=x');

    console.log('Api.get');
    reply = json(200, [{ id: 1 }]);
    check('returns the parsed JSON (an array stays an array)', JSON.stringify(await Api.get('charges')) === '[{"id":1}]');
    check('a GET carries no body and no method', calls.at(-1).init.method === undefined && calls.at(-1).init.body === undefined);

    reply = json(500, { success: false, error: 'Databasen er optaget' });
    let e = await fail(() => Api.get('x'));
    check('a failed response throws an ApiError carrying the server text', e instanceof Api.ApiError && e.message === 'Databasen er optaget' && e.status === 500);

    reply = text(502);
    e = await fail(() => Api.get('x'));
    check('a failed non-JSON response says HTTP <status>', e instanceof Api.ApiError && e.message === 'HTTP 502');

    reply = text(200);
    e = await fail(() => Api.get('x'));
    check('a 200 that is not JSON is an error, not a silent undefined', e instanceof Api.ApiError && /ikke JSON/.test(e.message));

    reply = json(200, { error: 'Ugyldig periode' });
    e = await fail(() => Api.get('x'));
    check('an "error" field in a 200 answer throws', e instanceof Api.ApiError && e.message === 'Ugyldig periode');

    reply = json(200, { records: [], error: null });
    check('a null error field is not an error', (await Api.get('x')).records.length === 0);

    reply = json(200, { success: true, ok: 1 });
    check('a GET answer with success:true passes', (await Api.get('x')).ok === 1);

    reply = json(200, { x: 1 });
    await Api.get('x', {}, { headers: { 'X-CSRF-Token': 'T' } });
    check('extra headers are passed on to fetch (system-log needs the CSRF token on a GET)', calls.at(-1).init.headers['X-CSRF-Token'] === 'T');
    await Api.get('x', {}, { signal: 'SIG' });
    check('an AbortSignal is passed on to fetch', calls.at(-1).init.signal === 'SIG');

    console.log('Api.post');
    reply = json(200, { success: true, id: 7 });
    check('returns the answer', (await Api.post('create-provider', { name: 'N' })).id === 7);
    const c = calls.at(-1);
    check('POST, JSON header and JSON body', c.init.method === 'POST' && c.init.headers['Content-Type'] === 'application/json' && c.init.body === '{"name":"N"}');
    check('the action is in the URL, not the body', c.url === 'api.php?action=create-provider');

    reply = json(200, { success: false, error: 'Navnet findes allerede' });
    e = await fail(() => Api.post('x', {}));
    check('success:false throws with the server text', e instanceof Api.ApiError && e.message === 'Navnet findes allerede');

    reply = json(200, { success: false });
    e = await fail(() => Api.post('x', {}));
    check('success:false without text throws a default message', e instanceof Api.ApiError && e.message === 'Fejl');

    reply = json(403, { success: false, error: 'CSRF' });
    e = await fail(() => Api.post('x', {}));
    check('a 403 with a JSON error keeps its text and status', e.message === 'CSRF' && e.status === 403 && e.data.error === 'CSRF');

    console.log('\n' + n + ' checks, ' + fails + ' failed');
    process.exit(fails === 0 ? 0 : 1);
})();
