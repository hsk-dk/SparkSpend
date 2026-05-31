/**
 * SparkSpend Service Worker
 *
 * Caching strategy by request type:
 *   Navigation (HTML)       — network-first, fallback to cached shell
 *   Local static assets     — stale-while-revalidate
 *   Local PHP API (GET)     — network-first, cache fallback (offline shows last data)
 *   CDN / cross-origin      — cache-first (Bootstrap, Chart.js etc. rarely change)
 *   POST / mutation         — always network, never cached
 *
 * Bump CACHE version to invalidate all caches on a new deployment.
 */

const CACHE = 'sparkspend-v9';

// Minimal precache — app shell needed to bootstrap offline
const PRECACHE = [
  '/',
  '/manifest.json',
  '/images/icon-192.png',
];

// ── Install ──────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
  // Use individual add() so one failing URL (e.g. auth-redirected manifest)
  // does not abort the entire install.
  event.waitUntil(
    caches.open(CACHE).then(cache =>
      Promise.all(PRECACHE.map(url => cache.add(url).catch(() => {})))
    )
  );
  self.skipWaiting();
});

// ── Activate: purge stale caches ─────────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE).map(k => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// ── Fetch ────────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Never intercept non-GET or chrome-extension requests
  if (request.method !== 'GET' || url.protocol !== 'https:' && url.protocol !== 'http:') {
    return;
  }

  // ── Navigation (full page load) ───────────────────────────────────────────
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => {
          // Clone synchronously before returning — cloning inside a deferred
          // caches.open().then() callback races with the browser consuming the body.
          if (response.ok) {
            const toCache = response.clone();
            caches.open(CACHE).then(c => c.put(request, toCache));
          }
          return response;
        })
        .catch(() => caches.match('/'))
    );
    return;
  }

  // ── CDN / cross-origin (Bootstrap, Chart.js, Font Awesome, etc.) ──────────
  if (url.origin !== self.location.origin) {
    event.respondWith(
      caches.open(CACHE).then(cache =>
        cache.match(request).then(cached => {
          if (cached) return cached;
          return fetch(request).then(response => {
            if (response.ok) {
              const toCache = response.clone();
              cache.put(request, toCache);
            }
            return response;
          });
        })
      )
    );
    return;
  }

  // ── Local PHP API endpoints (GET only) ────────────────────────────────────
  if (url.pathname.endsWith('.php')) {
    event.respondWith(
      fetch(request)
        .then(response => {
          // Clone synchronously — same timing issue as navigate block.
          if (response.ok) {
            const toCache = response.clone();
            caches.open(CACHE).then(c => c.put(request, toCache));
          }
          return response;
        })
        .catch(() =>
          caches.match(request).then(
            r => r || new Response('{"error":"offline"}', {
              status: 503,
              headers: { 'Content-Type': 'application/json' },
            })
          )
        )
    );
    return;
  }

  // ── Local static assets (CSS, JS, images, fonts) — stale-while-revalidate ─
  event.respondWith(
    caches.open(CACHE).then(cache =>
      cache.match(request).then(cached => {
        const networkFetch = fetch(request)
          .then(response => {
            if (response.ok) cache.put(request, response.clone());
            return response;
          })
          .catch(() => new Response('', { status: 503 })); // keep respondWith happy on network failure
        return cached || networkFetch;
      })
    )
  );
});
