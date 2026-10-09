/* ElTouro service worker: makes the web app installable and keeps the shell (styles, scripts, images, fonts) cached.
   Pages and API answers are never cached – they are personal and must stay current. Offline, navigations get
   offline.html. Bump CACHE when the list below changes; versioned asset URLs (?v=) refresh themselves. */
'use strict';
var CACHE = 'eltouro-shell-1';
var PRECACHE = ['/offline.html', '/assets/style.css', '/assets/img/icon-192.png', '/assets/img/mascot/side-large.webp', '/assets/img/mascot/front.webp'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;   // map tiles and the rest go straight to the network

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
