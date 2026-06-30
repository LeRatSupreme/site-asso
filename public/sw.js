/* =====================================================================
   AEIC — Service Worker (PWA)
   Stratégies :
     - assets statiques (CSS/JS/img/police) : cache-first
     - navigations (pages HTML)            : network-first (fallback cache)
   Aucun build, vanilla JS. Cache versionnée pour faciliter la mise à jour.
   ===================================================================== */

const CACHE_VERSION = 'aeic-v1';
const CACHE_ASSETS  = CACHE_VERSION + '-assets';
const CACHE_PAGES   = CACHE_VERSION + '-pages';

const PRECACHE = [
    '/',
    '/manifest.json',
    '/assets/img/icon.svg',
    '/assets/img/favicon.svg',
];

// Filtre ce qui relève des "assets" (cache-first).
function isAssetRequest(url) {
    return /\.(?:css|js|svg|png|jpe?g|gif|webp|woff2?|ttf|eot|ico)(?:\?|$)/i.test(url.pathname)
        || url.pathname.startsWith('/assets/');
}

// Filtre les navigations HTML (network-first).
function isHtmlRequest(request) {
    return request.mode === 'navigate'
        || (request.headers.get('accept') || '').includes('text/html');
}

// ---- Installation : pré-cache léger ----------------------------------
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_ASSETS).then((cache) => cache.addAll(PRECACHE))
              .catch(() => {})
    );
    self.skipWaiting();
});

// ---- Activation : nettoyage des anciens caches -----------------------
self.addEventListener('activate', (event) => {
    const keep = [CACHE_ASSETS, CACHE_PAGES];
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((k) => !keep.includes(k)).map((k) => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

// ---- Fetch : stratégie selon le type de requête ---------------------
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // On ne gère que GET (les POST/PUT/etc. vont au réseau).
    if (request.method !== 'GET') {
        return;
    }

    let url;
    try {
        url = new URL(request.url);
    } catch (e) {
        return;
    }

    // On ne traite que les requêtes same-origin (jamais le cross-origin).
    if (url.origin !== self.location.origin) {
        return;
    }

    // Assets statiques → cache-first.
    if (isAssetRequest(url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Pages HTML → network-first.
    if (isHtmlRequest(request)) {
        event.respondWith(networkFirst(request));
        return;
    }
});

// ---- Cache-first (avec mise à jour en arrière-plan) -----------------
async function cacheFirst(request) {
    const cache = await caches.open(CACHE_ASSETS);
    const cached = await cache.match(request);
    const networkFetch = fetch(request)
        .then((response) => {
            if (response && response.ok) {
                cache.put(request, response.clone()).catch(() => {});
            }
            return response;
        })
        .catch(() => null);

    return cached || (await networkFetch) || Response.error();
}

// ---- Network-first (fallback cache, puis page hors-ligne) -----------
async function networkFirst(request) {
    const cache = await caches.open(CACHE_PAGES);
    try {
        const response = await fetch(request);
        if (response && response.ok) {
            cache.put(request, response.clone()).catch(() => {});
        }
        return response;
    } catch (e) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        // Dernier recours : page d'accueil hors-ligne si disponible.
        const fallback = await cache.match('/');
        if (fallback) {
            return fallback;
        }
        return Response.error();
    }
}
