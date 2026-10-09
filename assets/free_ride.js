/* Free ride: records GPS positions via /api/track (tour_id 0); hold the stop button for 2 s to finish. */
(function () {
  'use strict';
  var root = document.getElementById('free');
  if (!root) return;
  var csrf = root.dataset.csrf, dec = root.dataset.decimal || ',';
  var $ = function (id) { return document.getElementById(id); };
  var session = null, watch = null, buf = [], lastP = null, lastT = 0, total = 0, startAt = 0, tick = null, flushTimer = null, wake = null, last = null, top = 0;

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
    var s = Math.floor((Date.now() - startAt) / 1000), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
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
    if (t - lastT < 2000 || (lastP && dist(lastP, p) < 5)) return;
    if (lastP) total += dist(lastP, p);
    lastP = p; lastT = t;
    buf.push([t, c.latitude, c.longitude, c.accuracy, Math.round(kmh * 10) / 10]);
    render();
  }
  function start() {
    if (!navigator.geolocation) { $('f-msg').textContent = root.dataset.noGps || 'GPS?'; return; }
    $('f-start').disabled = true;
    api({ action: 'start', tour_id: 0 }).then(function (j) {
      session = j.session_id; startAt = Date.now();
      watch = navigator.geolocation.watchPosition(onFix, function () {}, { enableHighAccuracy: true, maximumAge: 1000, timeout: 20000 });
      tick = setInterval(render, 1000); flushTimer = setInterval(flush, 15000);
      $('f-start').hidden = true; $('f-stop').hidden = false;
      $('f-msg').textContent = '';
      if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (w) { wake = w; }).catch(function () {});
    }).catch(function () { $('f-start').disabled = false; $('f-msg').textContent = 'Error'; });
  }
  function finish() {
    navigator.geolocation.clearWatch(watch); clearInterval(tick); clearInterval(flushTimer);
    if (wake) { try { wake.release(); } catch (e) { /* ignore */ } }
    flush(true).then(function () { return api({ action: 'finish', session_id: session }); })
      .then(function (j) { location.href = (j.distance_m >= 100 ? '/drive/' + j.session_id + '?done=1' : '/drives'); })
      .catch(function () { location.href = '/drives'; });
  }
  $('f-start').addEventListener('click', start);

  // hold 2 s to finish
  var stop = $('f-stop'), holdT = null;
  function begin(ev) { if (ev.type === 'keydown' && ev.key !== ' ' && ev.key !== 'Enter') return; if (holdT) return; stop.classList.add('holding'); holdT = setTimeout(finish, 2000); }
  function cancel() { stop.classList.remove('holding'); clearTimeout(holdT); holdT = null; }
  ['pointerdown', 'keydown'].forEach(function (n) { stop.addEventListener(n, begin); });
  ['pointerup', 'pointerleave', 'pointercancel', 'keyup', 'blur'].forEach(function (n) { stop.addEventListener(n, cancel); });
  window.addEventListener('pagehide', function () { flush(true); });
})();
