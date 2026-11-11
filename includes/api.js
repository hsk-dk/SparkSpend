/**
 * The one way the front end talks to api.php.
 *
 *   const data = await Api.get('charges', { filter: 'all' });
 *   const res  = await Api.post('update-vehicle', { id, name });
 *
 * Both return the parsed JSON, or throw an ApiError whose message is fit to show the user:
 *   - the server's own "error" text when it sent one (the envelope is {"success": false, "error": "..."}),
 *   - otherwise "HTTP <status>" for a failed response, or a note that the answer was not JSON.
 * A POST answer with "success": false also throws, so a caller only handles the happy path and one catch.
 * CSRF headers and session-expiry detection live in the fetch wrapper in app.js; this builds on it.
 */
const Api = (() => {
    class ApiError extends Error {
        constructor(message, status = 0, data = null) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.data = data;
        }
    }

    /** api.php?action=<action>[&k=v...] ; null/undefined values are left out, the rest is URL-encoded. */
    function url(action, params) {
        const q = new URLSearchParams({ action });
        // The action is the first argument and nothing else: a stray "action" in the params would be appended
        // after it, and PHP reads the last one.
        if (params instanceof URLSearchParams) {
            params.forEach((v, k) => { if (k !== 'action') q.append(k, v); });
        } else if (params) {
            for (const [k, v] of Object.entries(params)) {
                if (k !== 'action' && v !== null && v !== undefined) q.append(k, String(v));
            }
        }
        return 'api.php?' + q.toString();
    }

    async function request(action, params, init) {
        const res = await fetch(url(action, params), init);
        let data = null;
        try {
            data = await res.json();
        } catch (_) {
            // not JSON: fall through to the status check below
        }
        const serverError = data && !Array.isArray(data) && typeof data.error === 'string' ? data.error : null;
        if (!res.ok) throw new ApiError(serverError || 'HTTP ' + res.status, res.status, data);
        if (data === null) throw new ApiError('Svaret var ikke JSON', res.status);
        if (serverError) throw new ApiError(serverError, res.status, data);
        if (data && data.success === false) throw new ApiError('Fejl', res.status, data);
        return data;
    }

    /** @param {string} action  @param {Object|URLSearchParams} [params]  @param {{signal?: AbortSignal, headers?: Object}} [opts]
     *  headers: for the few reads that want proof of a served page (system-log needs X-CSRF-Token even on a GET). */
    const get = (action, params, opts = {}) => request(action, params, { signal: opts.signal, headers: opts.headers });

    /** POST a JSON body. @param {string} action  @param {Object} [body]  @param {Object|URLSearchParams} [params] */
    const post = (action, body = {}, params) => request(action, params, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });

    return { get, post, url, ApiError };
})();

window.Api = Api;
