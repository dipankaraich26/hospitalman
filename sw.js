const CACHE_NAME = 'hospital-erp-v1';
const STATIC_ASSETS = [
    '/hospitalman/assets/css/style.css',
    '/hospitalman/assets/css/mobile.css',
    '/hospitalman/assets/js/app.js',
    '/hospitalman/offline.html',
    '/hospitalman/assets/icons/icon-192.png',
    '/hospitalman/assets/icons/icon-512.png'
];

// Install: cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Fetch: network-first for pages, cache-first for assets
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET requests
    if (event.request.method !== 'GET') return;

    // Cache-first for static assets (CSS, JS, images)
    if (url.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|eot)$/)) {
        event.respondWith(
            caches.match(event.request).then(cached => {
                if (cached) return cached;
                return fetch(event.request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Network-first for pages
    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response.ok && url.pathname.endsWith('.php')) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request).then(cached => {
                    return cached || caches.match('/hospitalman/offline.html');
                });
            })
    );
});
