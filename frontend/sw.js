// Strict versioned cache, network-first for API, emergency clear supported

const CACHE = 'nexa-v26';
const STATIC = [
  './',
  './index.html',
  './manifest.json',
  './nexa-main.css?v=43',
  './nexa-app.js?v=46',
  '/logo.png?v=2',
  '/logo-icon.png',
  '/icon-192.png',
  '/icon-512.png',
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

  // CDN resources - cache first
  if (url.includes('cdn.jsdelivr.net') || url.includes('fonts.googleapis') || url.includes('fonts.gstatic') || url.includes('cdnjs.cloudflare')) {
    e.respondWith(
      caches.match(e.request).then(cached => {
        if (cached) return cached;
        return fetch(e.request).then(res => {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
          return res;
        });
      })
    );
    return;
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
