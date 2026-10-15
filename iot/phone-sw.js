/*
 * Service worker for phone.php only (registered with scope "phone.php").
 * Keeps a copy of the answer page so it still opens when Wi-Fi is down and
 * the phone answers over Bluetooth instead. Always tries the network first,
 * so a new phone.php reaches phones as soon as they are online again.
 * API calls (api/*) are never cached.
 */
var CACHE = 'stem-quiz-phone-v1';
var FILES = ['phone.php', 'phone.webmanifest', 'assets/phone/icon-192.png', 'assets/phone/icon-512.png'];
var NETWORK_WAIT_MS = 3000;

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE)
            .then(function (cache) { return cache.addAll(FILES); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys.filter(function (key) { return key !== CACHE; })
                    .map(function (key) { return caches.delete(key); }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

// Which cached file answers this request, or null to leave it alone.
function cachedName(request) {
    var url = new URL(request.url);
    var base = new URL('./', self.registration.scope).pathname;

    if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.indexOf(base) !== 0) {
        return null;
    }
    var name = url.pathname.slice(base.length);
    return FILES.indexOf(name) >= 0 ? name : null;
}

self.addEventListener('fetch', function (event) {
    var name = cachedName(event.request);

    if (!name) {
        return;
    }

    event.respondWith(new Promise(function (resolve) {
        var settled = false;
        var fromCache = function () {
            caches.match(name, { cacheName: CACHE }).then(function (cached) {
                if (cached && !settled) {
                    settled = true;
                    resolve(cached);
                }
            });
        };
        // Wi-Fi that is up but not reaching the server can hang; do not wait forever.
        var timer = setTimeout(fromCache, NETWORK_WAIT_MS);

        fetch(event.request).then(function (response) {
            clearTimeout(timer);
            if (response.ok) {
                var copy = response.clone();
                caches.open(CACHE).then(function (cache) { cache.put(name, copy); });
            }
            if (!settled) {
                settled = true;
                resolve(response);
            }
        }).catch(function () {
            clearTimeout(timer);
            caches.match(name, { cacheName: CACHE }).then(function (cached) {
                if (!settled) {
                    settled = true;
                    resolve(cached || Response.error());
                }
            });
        });
    }));
});
