/* Free ride: records GPS positions via /api/track (tour_id 0) and draws them live on a map; hold the stop button for 2 s to finish.
   A ride only ends when the rider ends it: opening /free again continues the open recording (the path so far comes back from the server). */
(function () {
  'use strict';
  var root = document.getElementById('free');
  if (!root) return;
  var csrf = root.dataset.csrf, dec = root.dataset.decimal || ',', RESUME = parseInt(root.dataset.resume || '0', 10) || 0;
  var $ = function (id) { return document.getElementById(id); };
  var session = null, watch = null, buf = [], lastP = null, lastT = 0, total = 0, startAt = 0, tick = null, flushTimer = null, wake = null, top = 0;
  var WP_EVERY = 400;   // a waypoint about every 400 m – these become the waypoints of the tour when the ride is saved

  // ---- map
  var el = $('free-map');
  var map = L.map(el, { scrollWheelZoom: false }).setView([parseFloat(el.dataset.lat), parseFloat(el.dataset.lng)], 6);
  ElTouroMaps.leaflet(map, el);
  var line = L.polyline([], { color: '#2F5E8C', weight: 5, opacity: .95 }).addTo(map);
  var wpLayer = L.layerGroup().addTo(map), me = null, startMark = null, sinceWp = 0, followMap = true;
  map.on('dragstart zoomstart', function (e) { if (e.originalEvent) followMap = false; });
  // moving the map by hand only to look around – the position never leaves the browser except as the recording the rider switched on
  if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) { if (!lastP) map.setView([p.coords.latitude, p.coords.longitude], 15); }, function () {}, { timeout: 6000, maximumAge: 600000 });

  function addPoint(p, isRestore) {
    var ll = L.latLng(p[0], p[1]);
    line.addLatLng(ll);
    if (!startMark) startMark = L.circleMarker(ll, { radius: 8, color: '#14263F', fillColor: '#D7A845', fillOpacity: 1, weight: 3 }).addTo(map);
    if (lastP) { var d = dist(lastP, p); sinceWp += d; if (isRestore) total += d; }
    if (sinceWp >= WP_EVERY) { L.circleMarker(ll, { radius: 4, color: '#14263F', fillColor: '#fff', fillOpacity: 1, weight: 2 }).addTo(wpLayer); sinceWp = 0; }
    if (!me) me = L.circleMarker(ll, { radius: 9, color: '#fff', fillColor: '#C2553F', fillOpacity: 1, weight: 3 }).addTo(map); else me.setLatLng(ll);
    if (followMap && !isRestore) map.panTo(ll, { animate: true });
  }

  function api(body, keepalive) {
    return fetch('/api/track', { method: 'POST', credentials: 'same-origin', keepalive: !!keepalive,
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify(body) })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  function dist(a, b) {
    var R = 6371000, r = Math.PI / 180, dl = (b[0] - a[0]) * r, dn = (b[1] - a[1]) * r;
    var h = Math.sin(dl / 2) * Math.sin(dl / 2) + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dn / 2) * Math.sin(dn / 2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }
  function fmt(n, digits) { return n.toFixed(digits).replace('.', dec); }
  function render() {
    $('f-km').textContent = fmt(total / 1000, 1);
    var s = Math.max(0, Math.floor((Date.now() - startAt) / 1000)), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    $('f-time').textContent = (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
  }
  function flush(keep) {
    if (!session || !buf.length) return Promise.resolve();
    var pts = buf; buf = [];
    return api({ action: 'points', session_id: session, points: pts }, keep).catch(function () { buf = pts.concat(buf); });
  }
  function onFix(pos) {
    var c = pos.coords, p = [c.latitude, c.longitude], t = pos.timestamp;
    var kmh = c.speed != null && c.speed >= 0 ? c.speed * 3.6 : 0;
    $('f-speed').textContent = Math.round(kmh);
    $('f-speed').classList.toggle('speed-hot', kmh >= 22);
    if (kmh > top) top = kmh;
    if (me) me.setLatLng(p);
    if (t - lastT < 2000 || (lastP && dist(lastP, p) < 5)) return;
    if (lastP) total += dist(lastP, p);
    addPoint(p, false);
    lastP = p; lastT = t;
    buf.push([t, c.latitude, c.longitude, c.accuracy, Math.round(kmh * 10) / 10]);
    render();
  }

  // ---- keep the page alive with the screen off (silent sound + wake lock); not every phone allows it – see the hint on the page
  var audio = null;
  function keepAlive() {
    try {
      if (!audio) { audio = new Audio('data:audio/wav;base64,UklGRjQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YRAAAACAgICAgICAgICAgICAgICA'); audio.loop = true; audio.volume = 0.01; }
      audio.play().catch(function () {});
      if ('mediaSession' in navigator) navigator.mediaSession.metadata = new MediaMetadata({ title: 'ElTouro', artist: $('free').dataset.running || 'Free ride' });
    } catch (e) { /* none */ }
    if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (w) { wake = w; }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && session) keepAlive();
    if (document.visibilityState === 'hidden') flush(true);
  });

  function begin(sessionId, startedMs) {
    session = sessionId; startAt = startedMs || Date.now();
    watch = navigator.geolocation.watchPosition(onFix, function () { $('f-msg').textContent = 'GPS?'; }, { enableHighAccuracy: true, maximumAge: 1000, timeout: 20000 });
    tick = setInterval(render, 1000); flushTimer = setInterval(flush, 15000);
    $('f-start').hidden = true; $('f-stop').hidden = false;
    keepAlive();
    render();
  }
  function start() {
    if (!navigator.geolocation) { $('f-msg').textContent = 'GPS?'; return; }
    $('f-start').disabled = true;
    api({ action: 'start', tour_id: 0 }).then(function (j) { begin(j.session_id, Date.now()); $('f-msg').textContent = ''; })
      .catch(function () { $('f-start').disabled = false; $('f-msg').textContent = 'Error'; });
  }
  function finish() {
    navigator.geolocation.clearWatch(watch); clearInterval(tick); clearInterval(flushTimer);
    if (wake) { try { wake.release(); } catch (e) { /* ignore */ } }
    if (audio) audio.pause();
    flush(true).then(function () { return api({ action: 'finish', session_id: session }); })
      .then(function (j) { location.href = (j.distance_m >= 100 ? '/drive/' + j.session_id + '?done=1' : '/drives'); })
      .catch(function () { location.href = '/drives'; });
  }
  $('f-start').addEventListener('click', start);
  window.addEventListener('pagehide', function () { flush(true); });

  // ---- continue an open recording: the path so far comes from the server, then the position keeps being recorded
  if (RESUME && navigator.geolocation) {
    api({ action: 'resume', session_id: RESUME }).then(function (j) {
      (j.points || []).forEach(function (p) { addPoint(p, true); lastP = p; });
      total = j.distance_m || total;
      if (line.getLatLngs().length > 1) map.fitBounds(line.getBounds(), { padding: [30, 30], maxZoom: 17 });
      begin(j.session_id, Date.parse(j.started_at) || Date.now());
      $('f-msg').textContent = $('free').dataset.resumed || '';
    }).catch(function () { /* the recording is over: the rider can start a new one */ });
  }

  // hold 2 s to finish
  var stop = $('f-stop'), holdT = null;
  function press(ev) { if (ev.type === 'keydown' && ev.key !== ' ' && ev.key !== 'Enter') return; if (holdT) return; stop.classList.add('holding'); holdT = setTimeout(finish, 2000); }
  function cancel() { stop.classList.remove('holding'); clearTimeout(holdT); holdT = null; }
  ['pointerdown', 'keydown'].forEach(function (n) { stop.addEventListener(n, press); });
  ['pointerup', 'pointerleave', 'pointercancel', 'keyup', 'blur'].forEach(function (n) { stop.addEventListener(n, cancel); });
})();
