/**
 * service-worker.js - Offline layer for Now.js
 *
 * Speaks the protocol Now/js/ServiceWorkerManager.js expects:
 *   receives : {type:'UPDATE_CONFIG', payload:{cacheName, precacheUrls,
 *              cachePatterns, networkFirstPatterns, excludeFromCachePatterns,
 *              strategies, apiCacheMaxAge, push}}
 *              {type:'CACHE_URLS', payload:{urls}}, CLEAR_CACHE, CLEAR_API_CACHE,
 *              SKIP_WAITING
 *              (cacheName is ignored - the name is derived from the scope;
 *              the config is kept in the cache for a restarted worker)
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
  strategies: {},
  // Seconds a cached API answer may still be shown offline (0 = no limit).
  // The default is the lifetime of the access token (Index\Auth\Model::TOKEN_EXPIRY):
  // after it the session has to come back online anyway, and what the API told
  // the last user must not stay readable on the device for whoever comes next
  apiCacheMaxAge: 86400
};

/** Header a cached API answer carries with the time it was stored (ms) */
const CACHED_AT = 'x-now-cached-at';

/**
 * @param {Request|string} request
 * @returns {boolean}
 */
const isApi = (request) => new URL(typeof request === 'string' ? request : request.url, self.location.href).pathname.includes('/api/');

const cacheKey = () => CACHE_NAME;

/**
 * Where the last UPDATE_CONFIG is kept. The browser stops an idle worker and
 * starts it again for the next request, and `config` above would be empty
 * until a page sends it again: offline, every script would go to a network
 * that is not there. A restarted worker reads it back before it answers.
 */
const CONFIG_KEY = SCOPE + '__now-sw-config__';
let configLoaded = false;
const configReady = (async () => {
  try {
    const saved = await (await caches.open(cacheKey())).match(CONFIG_KEY);
    const savedConfig = saved ? await saved.json() : null;
    // A page's UPDATE_CONFIG that came in meanwhile is newer
    if (savedConfig && !configLoaded) {
      config = {...config, ...savedConfig};
    }
  } catch (e) {
    // No saved config: the page sends one
  }
  configLoaded = true;
})();

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

/**
 * Whether a pattern matches the URL, or the URL without its query and hash.
 * Patterns are written for file names ('\.js$'), and the files index.php
 * loads carry ?v=: tested on the whole URL only, those were never cached.
 *
 * @param {Array<string>} list - Pattern sources
 * @param {string} url - Request URL
 * @returns {boolean}
 */
const matches = (list, url) => {
  const {origin, pathname} = new URL(url);
  return toRegExp(list).some((re) => re.test(url) || re.test(origin + pathname));
};

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
 * Only usable responses are worth storing, and never one the server marked
 * Cache-Control: no-store (an endpoint that answers with data that must not stay
 * on the device, Response::setNoCacheHeaders() in Kotchasan).
 *
 * @param {Response} response - Response to test
 * @returns {boolean} True when the response may be cached
 */
function isCacheable(response) {
  return Boolean(response) && response.status === 200 && response.type !== 'opaque' &&
    !/(^|[\s,])no-store([\s,;]|$)/i.test(response.headers.get('Cache-Control') || '');
}

/**
 * A copy of an API answer that carries the time it was stored.
 *
 * @param {Response} response
 * @returns {Promise<Response>}
 */
async function stamped(response) {
  const headers = new Headers(response.headers);
  headers.set(CACHED_AT, String(Date.now()));
  return new Response(await response.blob(), {status: response.status, statusText: response.statusText, headers});
}

/**
 * Whether a cached API answer is older than config.apiCacheMaxAge. An answer
 * stored before the time was recorded counts as too old.
 *
 * @param {Response} cached
 * @returns {boolean}
 */
function isTooOld(cached) {
  const maxAge = Number(config.apiCacheMaxAge);
  if (!(maxAge > 0)) {
    return false;
  }
  const storedAt = Number(cached.headers.get(CACHED_AT));
  return !(storedAt > 0) || Date.now() - storedAt > maxAge * 1000;
}

/**
 * Drop every cached API response. An API answering 401 means the session is
 * gone (logged out elsewhere, expired): what the API told the previous user must
 * not be shown offline to whoever uses the device next. Files and pages stay.
 *
 * @param {Cache} cache
 * @returns {Promise<void>}
 */
async function forgetApiResponses(cache) {
  const keys = await cache.keys();
  await Promise.all(keys.filter((key) => new URL(key.url).pathname.includes('/api/')).map((key) => cache.delete(key)));
}

/**
 * A URL that carries its version (?v=): its content never changes, a new
 * release comes under a new URL.
 *
 * @param {Request|string} request
 * @returns {boolean}
 */
const isVersioned = (request) => new URL(typeof request === 'string' ? request : request.url, self.location.href).searchParams.has('v');

/**
 * Put a response in the cache. A versioned file replaces its copies with an
 * other ?v= (an earlier release), so the cache does not grow with every deploy.
 *
 * @param {Cache} cache
 * @param {Request} request
 * @param {Response} response
 * @returns {Promise<void>}
 */
async function store(cache, request, response) {
  await cache.put(request, response);
  if (!isVersioned(request)) {
    return;
  }
  const url = new URL(request.url);
  const keys = await cache.keys();
  await Promise.all(keys.filter((key) => {
    const other = new URL(key.url);
    return other.origin === url.origin && other.pathname === url.pathname &&
      other.searchParams.has('v') && other.searchParams.get('v') !== url.searchParams.get('v');
  }).map((key) => cache.delete(key)));
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
      (isApi(request) ? stamped(response.clone()) : Promise.resolve(response.clone()))
        .then((copy) => store(cache, request, copy))
        .catch(() => {});
    } else if (response.status === 401 && isApi(request)) {
      forgetApiResponses(cache).catch(() => {});
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached && isApi(request) && isTooOld(cached)) {
      // Older than apiCacheMaxAge: not shown, and not kept either
      await cache.delete(request);
    } else if (cached) {
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
    // Refresh in the background so the next visit gets the newer copy - but a
    // versioned file is the same until its ?v= changes, so it is not fetched again
    if (!isVersioned(request)) {
      fetch(request)
        .then((response) => {
          if (isCacheable(response)) {
            return store(cache, request, response.clone());
          }
        })
        .catch(() => {});
    }
    return cached;
  }
  const response = await fetch(request);
  if (isCacheable(response)) {
    store(cache, request, response.clone()).catch(() => {});
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

/**
 * Fetch and keep the URLs that are not in the cache yet (a versioned file never
 * changes; the others are refreshed as they are used). One by one: a URL that
 * fails leaves the others in, and is tried again the next time.
 *
 * @param {Cache} cache
 * @param {Array<string>} urls
 * @returns {Promise<void>}
 */
async function cacheMissing(cache, urls) {
  await Promise.all((urls || []).filter(Boolean).map(async (url) => {
    try {
      if (await cache.match(url)) {
        return;
      }
      const request = new Request(url, {cache: 'reload'});
      const response = await fetch(request);
      if (isCacheable(response)) {
        await store(cache, request, response);
      }
    } catch (e) {
      // Not reachable now
    }
  }));
}

/**
 * Answer a request with its strategy.
 *
 * @param {Request} request
 * @param {string} strategy
 * @returns {Promise<Response>}
 */
function respond(request, strategy) {
  if (strategy === 'network-only') {
    return fetch(request);
  }
  if (strategy === 'cache-first') {
    return cacheFirst(request);
  }
  // network-first, and any other name a page configured
  return networkFirst(request);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (!configLoaded) {
    // A worker the browser has just started again: the strategy depends on the
    // saved config, so the requests it may cache wait for it (a few ms)
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith(SCOPE)) {
      return;
    }
    event.respondWith(configReady.then(() => respond(request, strategyFor(request))));
    return;
  }
  const strategy = strategyFor(request);
  if (strategy === 'network-only') {
    return;
  }
  event.respondWith(respond(request, strategy));
});

self.addEventListener('message', (event) => {
  const data = event.data;
  if (!data || !data.type) {
    return;
  }

  if (data.type === 'UPDATE_CONFIG') {
    config = {...config, ...data.payload};
    configLoaded = true;
    event.waitUntil(
      (async () => {
        await post('CONFIG_UPDATED');
        try {
          const cache = await caches.open(cacheKey());
          await cache.put(CONFIG_KEY, new Response(JSON.stringify(config), {headers: {'Content-Type': 'application/json'}}));
          // Every page sends its config, so only what is not cached yet is fetched
          await cacheMissing(cache, config.precacheUrls);
          await post('CACHE_UPDATED', {cacheName: cacheKey()});
          await post('OFFLINE_READY');
        } catch (error) {
          await post('CACHE_ERROR', {message: error.message});
        }
      })()
    );
    return;
  }

  // ServiceWorkerManager.cacheUrls(urls)
  if (data.type === 'CACHE_URLS') {
    event.waitUntil(
      (async () => {
        await cacheMissing(await caches.open(cacheKey()), data.payload && data.payload.urls);
        await post('CACHE_UPDATED', {cacheName: cacheKey()});
      })()
    );
    return;
  }

  // ServiceWorkerManager.clearApiCache(): the page ended or refused the session
  if (data.type === 'CLEAR_API_CACHE') {
    event.waitUntil(caches.open(cacheKey()).then(forgetApiResponses));
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
