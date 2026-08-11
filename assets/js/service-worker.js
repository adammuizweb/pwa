/* Jyavani PWA service worker contribution. Composed into Core's /sw.js. */
(() => {
  'use strict';
  const config = self.__JY_PWA_CONFIG || {};
  const cacheName = String(config.cacheName || 'jyavani-pwa-fallback');
  const cachePrefix = String(config.cachePrefix || 'jyavani-pwa-');
  const offlineUrl = String(config.offlineUrl || '/pwa-offline/');
  const manifestUrl = String(config.manifestUrl || '/manifest.webmanifest');
  const precache = Array.isArray(config.precache) ? config.precache : [offlineUrl, manifestUrl];
  const excludedPaths = Array.isArray(config.excludedPaths) ? config.excludedPaths : ['/admin/', '/api/', '/private/'];
  const precacheUrls = new Set(precache.map((url) => new URL(url, self.location.origin).href));

  const cacheableResponse = (response) => {
    if (!response || !response.ok || response.type !== 'basic') return false;
    const policy = String(response.headers.get('Cache-Control') || '').toLowerCase();
    return !policy.includes('no-store') && !policy.includes('private');
  };

  const configuredAssetRequest = (url) => precacheUrls.has(url.href);
  const sensitiveRequest = (url) => {
    const path = url.pathname.toLowerCase();
    if (/(?:^|\/)(?:api|private)(?:\/|$)/.test(path)) return true;
    return excludedPaths.some((prefix) => {
      const normalized = String(prefix || '').toLowerCase();
      return normalized && (path === normalized.slice(0, -1) || path.startsWith(normalized));
    });
  };

  const storePrecache = (cache, url) => fetch(new Request(url, {cache: 'reload', credentials: 'same-origin'})).then((response) => {
    if (!cacheableResponse(response)) throw new Error('Uncacheable PWA resource: ' + url);
    return cache.put(url, response);
  });

  self.addEventListener('message', (event) => {
    if (!event.data || event.data.type !== 'JY_PWA_WARM_CACHE') return;
    event.waitUntil(caches.open(cacheName).then((cache) => {
      return Promise.allSettled(precache.map((url) => storePrecache(cache, url)));
    }).then(() => caches.keys()).then((keys) => Promise.all(keys.map((key) => {
      return key.startsWith(cachePrefix) && key !== cacheName ? caches.delete(key) : Promise.resolve(false);
    }))));
  });

  self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (sensitiveRequest(url)) return;

    if (request.mode === 'navigate') {
      event.respondWith(fetch(request).catch(() => caches.match(offlineUrl).then((response) => response || Response.error())));
      return;
    }

    if (url.pathname === manifestUrl) {
      event.respondWith(fetch(request).then((response) => {
        if (!cacheableResponse(response)) return response;
        return caches.open(cacheName)
          .then((cache) => cache.put(request, response.clone()))
          .then(() => response);
      }).catch(() => caches.match(request).then((response) => response || caches.match(manifestUrl)).then((response) => response || Response.error())));
      return;
    }

    if (!configuredAssetRequest(url)) return;
    event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (!cacheableResponse(response)) return response;
      return caches.open(cacheName)
        .then((cache) => cache.put(request, response.clone()))
        .then(() => response);
    })));
  });
})();
