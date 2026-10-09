/* Profile: switch web push on/off for this device, send a test, choose the home area for "rides nearby". */
(function () {
  'use strict';
  var btn = document.getElementById('push-toggle');
  var status = document.getElementById('push-status');
  var testBtn = document.getElementById('push-test');

  function api(body) {
    return fetch('/api/push', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': btn.dataset.csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function key(b64) {
    var s = (b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(s), function (c) { return c.charCodeAt(0); });
  }
  function b64u(buf) {
    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  if (btn) {
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && btn.dataset.vapid;
    function show(sub) {
      btn.textContent = sub ? btn.dataset.on : btn.dataset.off;
      btn.dataset.state = sub ? 'on' : 'off';
      if (testBtn) testBtn.hidden = !sub;
      if (!sub && window.Notification && Notification.permission === 'denied') status.textContent = btn.dataset.blocked;
    }
    if (!supported) {
      btn.disabled = true; status.textContent = btn.dataset.unsupported;
    } else {
      navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
          show(sub);
          btn.addEventListener('click', function () {
            btn.disabled = true;
            var done = function () { btn.disabled = false; };
            reg.pushManager.getSubscription().then(function (cur) {
              if (cur) {
                return api({ action: 'unsubscribe', endpoint: cur.endpoint }).then(function () { return cur.unsubscribe(); }).then(function () { show(null); });
              }
              return Notification.requestPermission().then(function (perm) {
                if (perm !== 'granted') { status.textContent = btn.dataset.blocked; return null; }
                return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(btn.dataset.vapid) }).then(function (s) {
                  var j = s.toJSON();
                  return api({ action: 'subscribe', endpoint: s.endpoint, keys: { p256dh: j.keys.p256dh, auth: j.keys.auth } }).then(function (r) {
                    if (r.ok) { show(s); status.textContent = ''; }
                  });
                });
              });
            }).then(done, done);
          });
          if (testBtn) testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            api({ action: 'test' }).then(function (r) { status.textContent = r.sent ? '✔' : '✖'; }).then(function () { testBtn.disabled = false; });
          });
        });
      });
    }
  }

  // Home area: tap the map; stored rounded to two decimals (about 1 km). The browser position only moves the map.
  var el = document.getElementById('home-pick');
  if (el && window.L && window.ElTouroMaps) {
    var fLat = document.getElementById('home-lat'), fLng = document.getElementById('home-lng'), state = document.getElementById('home-state');
    var has = fLat.value !== '';
    var map = L.map(el, { scrollWheelZoom: false }).setView([parseFloat(el.dataset.lat), parseFloat(el.dataset.lng)], has ? 10 : 6);
    ElTouroMaps.leaflet(map, el);
    var circle = null;
    function radiusM() { return (parseInt(document.getElementById('radius_km').value, 10) || 25) * 1000; }
    function draw() {
      if (circle) { map.removeLayer(circle); circle = null; }
      if (fLat.value === '') { state.textContent = ''; return; }
      var ll = L.latLng(parseFloat(fLat.value), parseFloat(fLng.value));
      circle = L.circle(ll, { radius: radiusM(), color: '#D7A845', weight: 2, fillOpacity: 0.12 }).addTo(map);
      state.textContent = '≈ ' + ll.lat.toFixed(2) + ', ' + ll.lng.toFixed(2);
    }
    map.on('click', function (e) { fLat.value = e.latlng.lat.toFixed(2); fLng.value = e.latlng.lng.toFixed(2); draw(); });
    document.getElementById('radius_km').addEventListener('change', draw);
    document.getElementById('home-clear').addEventListener('click', function () { fLat.value = ''; fLng.value = ''; draw(); });
    draw();
    if (!has && navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function (p) { map.setView([p.coords.latitude, p.coords.longitude], 9); }, function () {}, { timeout: 6000, maximumAge: 600000 });
    }
  }
})();
