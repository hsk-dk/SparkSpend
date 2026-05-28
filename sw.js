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

const CACHE = 'sparkspend-v2';

// Minimal precache — app shell needed to bootstrap offline
const PRECACHE = [
  '/',
  '/manifest.json',
  '/images/icon-192.png',
];

// ── Install ──────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(PRECACHE))
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
          caches.open(CACHE).then(c => c.put(request, response.clone()));
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
        cache.match(request).then(cached =>
          cached || fetch(request).then(response => {
            cache.put(request, response.clone());
            return response;
          })
        )
      )
    );
    return;
  }

  // ── Local PHP API endpoints (GET only) ────────────────────────────────────
  if (url.pathname.endsWith('.php')) {
    event.respondWith(
      fetch(request)
        .then(response => {
          caches.open(CACHE).then(c => c.put(request, response.clone()));
          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }

  // ── Local static assets (CSS, JS, images, fonts) — stale-while-revalidate ─
  event.respondWith(
    caches.open(CACHE).then(cache =>
      cache.match(request).then(cached => {
        const networkFetch = fetch(request).then(response => {
          cache.put(request, response.clone());
          return response;
        });
        return cached || networkFetch;
      })
    )
  );
});
