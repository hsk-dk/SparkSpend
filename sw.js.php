<?php
/**
 * Auto-versioned Service Worker generator.
 *
 * Generates sw.js with a cache version derived from the content hash of all
 * cacheable static files. When any JS/CSS/PHP file changes, the hash changes,
 * the browser detects a new SW, and all caches are purged on activate.
 *
 * No manual version bumping needed — deploy and forget.
 */

header('Content-Type: application/javascript');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Compute content hash from all cacheable source files
$files = array_merge(
    glob(__DIR__ . '/includes/*.js')  ?: [],
    glob(__DIR__ . '/includes/*.css') ?: [],
    glob(__DIR__ . '/routes/*.php')   ?: [],
    [
        __DIR__ . '/index.php',
        __DIR__ . '/api.php',
        __DIR__ . '/manifest.json',
    ]
);

// Filter to existing files only
$files = array_filter($files, 'file_exists');

// Hash based on file contents — only changes when code actually changes
$hashes = array_map(function($f) { return md5_file($f); }, $files);
sort($hashes); // deterministic order
$version = 'sparkspend-' . substr(md5(implode('', $hashes)), 0, 10);
?>
/**
 * SparkSpend Service Worker (auto-versioned)
 *
 * Cache version: <?= $version ?> (generated from content hash)
 *
 * Caching strategy by request type:
 *   Navigation (HTML)       — network-first, fallback to cached shell
 *   Local static assets     — stale-while-revalidate
 *   Local PHP API (GET)     — network-first, cache fallback (offline shows last data)
 *   CDN / cross-origin      — cache-first (Bootstrap, Chart.js etc. rarely change)
 *   POST / mutation         — always network, never cached
 */

const CACHE = '<?= $version ?>';

// Minimal precache — app shell needed to bootstrap offline
const PRECACHE = [
  '/',
  '/manifest.json',
  '/images/icon-192.png',
];

// ── Install ──────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
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
          .catch(() => new Response('', { status: 503 }));
        return cached || networkFetch;
      })
    )
  );
});
