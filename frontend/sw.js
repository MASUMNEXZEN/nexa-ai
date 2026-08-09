// Strict versioned cache, network-first for API, emergency clear supported

const CACHE = 'nexa-v34';
const STATIC = [
  './',
  './index.html',
  './manifest.json',
  './nexa-main.css?v=47',
  './nexa-app.js?v=50',
  '/logo.png?v=5',
  '/logo-icon.png?v=5',
  '/nexa-workspace.css?v=8',
  '/icon-192.png',
  '/icon-512.png',
  './vendor/fonts.css',
  './vendor/katex/katex.min.css',
  './vendor/katex/katex.min.js',
  './vendor/katex/auto-render.min.js',
  './vendor/marked/marked.min.js',
  './vendor/dompurify/purify.min.js',
  './vendor/highlight/highlight.min.js',
  './vendor/highlight/github-dark.min.css',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoRCf_0ugVKxGv.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoRCf_0uYVKw.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoRCf_0vQVKxGv.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoREP-0ugVKxGv.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoREP-0uYVKw.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoREP-0vQVKxGv.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoRG_50ugVKxGv.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoRG_50uYVKw.woff2',
  './vendor/fonts/ijwOs5juQtsyLLR5jN4cxBEoRG_50vQVKxGv.woff2',
  './vendor/fonts/ijwTs5juQtsyLLR5jN4cxBEoTI7ax9k0.woff2',
  './vendor/fonts/ijwTs5juQtsyLLR5jN4cxBEoTJLax9k0.woff2',
  './vendor/fonts/ijwTs5juQtsyLLR5jN4cxBEoTJzaxw.woff2',
  './vendor/fonts/QGYvz_MVcBeNP4NJtEtq.woff2',
  './vendor/fonts/QGYvz_MVcBeNP4NJuktqQ4E.woff2',
  './vendor/fonts/tDbv2o-flEEny0FZhsfKu5WU4zr3E_BX0PnT8RD8yKwBNntkaToggR7BYRbKPx_cwhsk.woff2',
  './vendor/fonts/tDbv2o-flEEny0FZhsfKu5WU4zr3E_BX0PnT8RD8yKwBNntkaToggR7BYRbKPx3cwhsk.woff2',
  './vendor/fonts/tDbv2o-flEEny0FZhsfKu5WU4zr3E_BX0PnT8RD8yKwBNntkaToggR7BYRbKPx7cwhsk.woff2',
  './vendor/fonts/tDbv2o-flEEny0FZhsfKu5WU4zr3E_BX0PnT8RD8yKwBNntkaToggR7BYRbKPxDcwg.woff2',
  './vendor/fonts/tDbv2o-flEEny0FZhsfKu5WU4zr3E_BX0PnT8RD8yKwBNntkaToggR7BYRbKPxPcwhsk.woff2',
  './vendor/fonts/tDbv2o-flEEny0FZhsfKu5WU4zr3E_BX0PnT8RD8yKwBNntkaToggR7BYRbKPxTcwhsk.woff2',
];

// Install: cache static assets
self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC).catch(() => {}))
  );
});

// Activate: purge ALL old caches immediately
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Fetch strategy:
self.addEventListener('fetch', e => {
  const url = e.request.url;

  // API calls - always bypass cache
  if (url.includes('/api/') || url.includes('auth-check') || url.includes('ask.php')) {
    return; // let browser handle natively
  }

  // App shell (HTML, CSS, JS) - Network first for freshness, fallback to cache
  e.respondWith(
    fetch(e.request).then(res => {
      if (res.ok && res.type === 'basic') {
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
      }
      return res;
    }).catch(() => caches.match(e.request))
  );
});
