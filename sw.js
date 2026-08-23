// Nouveau cache afin de supprimer les anciennes réponses API éventuellement
// mises en cache par les versions précédentes du service worker.
const CACHE_NAME = 'suivi-prospects-v8';
const ASSETS_TO_CACHE = [
    './',
    './index.html',
    './assets/css/style.css',
    './assets/js/app.js',
    './manifest.json',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/apple-touch-icon.png'
];

// Installation : Mise en cache des ressources statiques
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        }).then(() => self.skipWaiting())
    );
});

// Activation : Nettoyage des anciens caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Interception des requêtes
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // ── Requêtes POST : jamais de cache, toujours le réseau (Network Only)
    // Évite que le SW retourne undefined sur une requête POST non cachée
    if (event.request.method === 'POST') {
        event.respondWith(fetch(event.request));
        return;
    }

    // ── API : réseau uniquement. Les réponses contiennent des données privées
    // propres à la session ; les mettre en Cache Storage les exposerait après
    // déconnexion à un autre utilisateur du même navigateur.
    if (url.pathname.includes('/api/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // ── Assets statiques : Cache First puis mise à jour en arrière-plan (Stale While Revalidate)
    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            const fetchPromise = fetch(event.request).then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseToCache = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseToCache);
                    });
                }
                return networkResponse;
            }).catch(() => cachedResponse);

            return cachedResponse || fetchPromise;
        })
    );
});
