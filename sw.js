/* ElTouro service worker: makes the web app installable and keeps the shell (styles, scripts, images, fonts) cached.
   Pages and API answers are never cached – they are personal and must stay current. Offline, navigations get
   offline.html. Bump CACHE when the list below changes; versioned asset URLs (?v=) refresh themselves.
   Map tiles of the known providers are kept on the device (up to TILE_MAX, refreshed after TILE_DAYS) – faster maps,
   fewer requests, and a map on the road with a weak signal. Only on the device: MapTiler forbids caching on our server. */
'use strict';
var CACHE = 'eltouro-shell-5';
var TILES = 'eltouro-tiles-1';
var TILE_MAX = 5000;
var TILE_DAYS = 30;
var PRECACHE = ['/offline.html', '/assets/style.css', '/assets/img/icon-192.png', '/assets/img/mascot/side-large.webp?v=2', '/assets/img/mascot/front.webp?v=2'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE && k !== TILES; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) {
    if (isTile(url)) e.respondWith(tile(req, url));
    return;   // everything else from elsewhere goes straight to the network
  }

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () { return caches.match('/offline.html'); }));
    return;
  }
  // Static files: from the cache, refreshed in the background (stale-while-revalidate)
  if (url.pathname.indexOf('/assets/') === 0) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});

function isTile(url) {
  return (url.hostname === 'api.maptiler.com' && url.pathname.indexOf('/maps/') === 0)
    || url.hostname === 'tile.openstreetmap.org'
    || /\.tile-cyclosm\.openstreetmap\.fr$/.test(url.hostname);
}

/** Cache key without the API key and subdomain, so a new key or another of a/b/c finds the same tile */
function tileKey(url) {
  var u = new URL(url.href);
  u.searchParams.delete('key');
  u.hostname = u.hostname.replace(/^[abc]\.tile-cyclosm/, 'a.tile-cyclosm');
  return u.href;
}

var tilePuts = 0;
function tile(req, url) {
  var key = tileKey(url);
  return caches.open(TILES).then(function (c) {
    return c.match(key).then(function (hit) {
      if (hit && Date.now() - Number(hit.headers.get('X-Cached') || 0) < TILE_DAYS * 864e5) return hit;
      // Fetched as CORS (all three providers allow it): an opaque answer would count megabytes in the storage quota
      var opts = { mode: 'cors', credentials: 'omit' };
      if (req.referrer && req.referrer.indexOf(self.location.origin) === 0) opts.referrer = req.referrer;
      return fetch(req.url, opts).then(function (res) {
        if (!res.ok) return hit || res;
        return res.blob().then(function (blob) {
          var stored = new Response(blob, { headers: { 'Content-Type': res.headers.get('Content-Type') || 'image/png', 'X-Cached': String(Date.now()) } });
          c.put(key, stored.clone()).then(function () { if (++tilePuts % 100 === 0) trimTiles(c); }).catch(function () { /* quota: just not kept */ });
          return stored;
        });
      }).catch(function () { return hit || fetch(req); });   // offline: the old tile; no CORS: the plain request
    });
  });
}

/** Oldest tiles go first (keys come back in the order they were stored) */
function trimTiles(c) {
  return c.keys().then(function (keys) {
    var extra = keys.length - TILE_MAX;
    if (extra <= 0) return;
    return Promise.all(keys.slice(0, extra + Math.round(TILE_MAX / 10)).map(function (k) { return c.delete(k); }));
  });
}

// Web push: show the message; a tap opens (or focuses) the page it is about
self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'ElTouro', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(data.title || 'ElTouro', {
    body: data.body || '', icon: '/assets/img/icon-192.png', badge: '/assets/img/icon-192.png', tag: data.tag || undefined, data: { url: data.url || '/' }
  }));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf(self.location.origin) === 0 && 'focus' in list[i]) { list[i].navigate(url); return list[i].focus(); }
    }
    return self.clients.openWindow(url);
  }));
});
