/**
 * service-worker.js - Offline layer for Now.js
 *
 * Speaks the protocol Now/js/ServiceWorkerManager.js expects:
 *   receives : {type:'UPDATE_CONFIG', payload:{cacheName, precacheUrls,
 *              cachePatterns, networkFirstPatterns, excludeFromCachePatterns,
 *              strategies, push}}
 *              (cacheName is ignored - the name is derived from the scope)
 *   sends    : CONFIG_UPDATED, CACHE_UPDATED, CACHE_ERROR, OFFLINE_READY, LOG
 *
 * This file belongs to the framework, not to any single module - nothing here
 * is specific to an application.
 *
 * @see https://www.nowjs.net/
 */

const VERSION = 'v1';

/** Application scope, used to decide which requests belong to this app */
const SCOPE = new URL(self.registration.scope).pathname;

// The cache name comes from the scope, not from the page's UPDATE_CONFIG:
// - apps sharing an origin (localhost/now.js/booking/, localhost/now.js/crm/)
//   share one CacheStorage, so a fixed name let them clear each other's cache
// - the browser stops an idle service worker and `config` resets on restart,
//   so a name that only arrives by message would differ between precache and fetch
// The '#' after SCOPE (which always ends in '/') keeps '/now.js/' from matching
// '/now.js/booking/' as a prefix.
const CACHE_PREFIX = `now-js-cache@${SCOPE}#`;
const CACHE_NAME = CACHE_PREFIX + VERSION;

/** Names used before the cache was scoped (now-js-cache-v1, booking-now-js-cache-v1), removed on activate */
const LEGACY_CACHE = /^([\w.-]+-)?now-js-cache-v\d+$/;

let config = {
  precacheUrls: [],
  cachePatterns: [],
  networkFirstPatterns: [],
  excludeFromCachePatterns: [],
  strategies: {}
};

const cacheKey = () => CACHE_NAME;

/**
 * Post a message back to every page this service worker controls.
 *
 * @param {string} type - Message type
 * @param {Object} payload - Message payload
 */
async function post(type, payload = {}) {
  const clients = await self.clients.matchAll({includeUncontrolled: true});
  for (const client of clients) {
    client.postMessage({type, payload});
  }
}

/**
 * @param {string} message
 * @param {string} level
 */
const log = (message, level = 'log') => post('LOG', {message, level});

/**
 * Turn the string patterns sent by the manager back into RegExp objects.
 *
 * @param {Array<string>} list - Pattern sources
 * @returns {Array<RegExp>} Compiled patterns
 */
function toRegExp(list) {
  return (list || []).reduce((acc, item) => {
    try {
      acc.push(item instanceof RegExp ? item : new RegExp(item));
    } catch (e) {
      // One broken pattern must not take the whole set down
    }
    return acc;
  }, []);
}

const matches = (list, url) => toRegExp(list).some((re) => re.test(url));

/**
 * Pick the caching strategy for a single request.
 *
 * Decision order:
 *   1. Not a GET, cross-origin, or outside the app scope -> network-only
 *   2. Listed in excludeFromCache                        -> network-only
 *   3. Identity related endpoints                        -> network-only (never cache)
 *   4. Any other API call                                -> network-first (readable offline)
 *   5. A strategy set through config                     -> as configured
 *   6. Files matching cachePatterns                      -> cache-first
 *   7. HTML documents                                    -> network-first (app shell fallback)
 *   8. Everything else                                   -> network-only
 *
 * @param {Request} request - Request being handled
 * @returns {string} Strategy name
 */
function strategyFor(request) {
  const url = new URL(request.url);
  const path = url.pathname;

  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return 'network-only';
  }
  if (!path.startsWith(SCOPE)) {
    return 'network-only';
  }
  if (matches(config.excludeFromCachePatterns, request.url)) {
    return 'network-only';
  }

  // Never cache anything tied to identity or permissions: the response belongs
  // to the signed-in user, so a cached copy would leak to the next person who
  // uses the device
  if (/\/api\/(index\/auth|index\/menus|v1\/)/.test(path)) {
    return 'network-only';
  }

  for (const [prefix, strategy] of Object.entries(config.strategies || {})) {
    if (path.includes(prefix)) {
      return strategy;
    }
  }

  if (path.includes('/api/')) {
    return 'network-first';
  }
  if (matches(config.networkFirstPatterns, request.url)) {
    return 'network-first';
  }
  if (matches(config.cachePatterns, request.url)) {
    return 'cache-first';
  }
  if (request.mode === 'navigate' || request.destination === 'document') {
    return 'network-first';
  }
  return 'network-only';
}

/**
 * Only usable responses are worth storing.
 *
 * @param {Response} response - Response to test
 * @returns {boolean} True when the response may be cached
 */
function isCacheable(response) {
  return response && response.status === 200 && response.type !== 'opaque';
}

/**
 * @param {Request} request
 * @returns {Promise<Response>}
 */
async function networkFirst(request) {
  const cache = await caches.open(cacheKey());
  try {
    const response = await fetch(request);
    if (isCacheable(response)) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    // A document opened while offline falls back to the app shell so the SPA
    // can still boot
    if (request.mode === 'navigate') {
      const shell = await cache.match(SCOPE) || await cache.match(SCOPE + 'index.php');
      if (shell) {
        return shell;
      }
    }
    throw error;
  }
}

/**
 * @param {Request} request
 * @returns {Promise<Response>}
 */
async function cacheFirst(request) {
  const cache = await caches.open(cacheKey());
  const cached = await cache.match(request);
  if (cached) {
    // Refresh in the background so the next visit gets the newer copy
    fetch(request)
      .then((response) => {
        if (isCacheable(response)) {
          cache.put(request, response.clone());
        }
      })
      .catch(() => {});
    return cached;
  }
  const response = await fetch(request);
  if (isCacheable(response)) {
    cache.put(request, response.clone());
  }
  return response;
}

self.addEventListener('install', (event) => {
  // Skip waiting so a new version takes over as soon as the user reloads
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((key) => LEGACY_CACHE.test(key) || (key.startsWith(CACHE_PREFIX) && key !== cacheKey()))
          .map((key) => caches.delete(key))
      );
      await self.clients.claim();
      log('Service worker activated');
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const strategy = strategyFor(event.request);
  if (strategy === 'network-only') {
    return;
  }
  if (strategy === 'cache-first') {
    event.respondWith(cacheFirst(event.request));
    return;
  }
  event.respondWith(networkFirst(event.request));
});

self.addEventListener('message', (event) => {
  const data = event.data;
  if (!data || !data.type) {
    return;
  }

  if (data.type === 'UPDATE_CONFIG') {
    config = {...config, ...data.payload};
    event.waitUntil(
      (async () => {
        await post('CONFIG_UPDATED');
        try {
          const cache = await caches.open(cacheKey());
          const urls = (config.precacheUrls || []).filter(Boolean);
          if (urls.length) {
            // addAll fails the whole batch if one entry fails, so add one by one
            await Promise.all(
              urls.map((url) =>
                cache.add(new Request(url, {cache: 'reload'})).catch(() => {})
              )
            );
          }
          await post('CACHE_UPDATED', {cacheName: cacheKey()});
          await post('OFFLINE_READY');
        } catch (error) {
          await post('CACHE_ERROR', {message: error.message});
        }
      })()
    );
    return;
  }

  if (data.type === 'CLEAR_CACHE') {
    event.waitUntil(
      (async () => {
        const keys = await caches.keys();
        await Promise.all(
          keys.filter((key) => key.startsWith(CACHE_PREFIX)).map((key) => caches.delete(key))
        );
        await log('Cache cleared');
      })()
    );
    return;
  }

  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('push', (event) => {
  if (!event.data) {
    return;
  }
  let payload = {};
  try {
    payload = event.data.json();
  } catch (e) {
    payload = {title: 'Notification', body: event.data.text()};
  }
  event.waitUntil(
    self.registration.showNotification(payload.title || 'Notification', {
      body: payload.body || '',
      icon: payload.icon || SCOPE + 'images/web-app-manifest-192x192.png',
      badge: payload.badge,
      data: payload.data || {}
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || SCOPE;
  event.waitUntil(
    self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((clients) => {
      for (const client of clients) {
        if (client.url.includes(SCOPE) && 'focus' in client) {
          client.navigate(target);
          return client.focus();
        }
      }
      return self.clients.openWindow(target);
    })
  );
});
