/* ElTouro live map: riders who share their location with you, refreshed every 10 seconds. */
(function () {
  'use strict';
  var el = document.getElementById('live-map');
  if (!el || !window.L) return;
  var d = el.dataset, T = JSON.parse(d.texts || '{}');
  var status = document.getElementById('live-status');
  var c = d.center.split(',').map(parseFloat);
  var map = L.map(el).setView(c, parseInt(d.zoom, 10) || 6);
  ElTouroMaps.leaflet(map, el);
  var layer = L.layerGroup().addTo(map);

  function riderIcon(r) {
    var box = document.createElement('div');
    box.className = 'live-rider';
    var img = document.createElement('img'); img.src = '/assets/img/mascot/front.webp'; img.alt = '';
    var label = document.createElement('span');
    label.textContent = r.name + (r.crew ? ' · ' + r.crew : '');
    box.appendChild(img); box.appendChild(label);
    return L.divIcon({ className: '', html: box.outerHTML, iconSize: [40, 40], iconAnchor: [20, 30] });
  }

  function load() {
    var b = map.getBounds();
    if (map.getZoom() < 9) { layer.clearLayers(); status.textContent = T.zoom_in; return; }
    var box = [b.getSouth(), b.getWest(), b.getNorth(), b.getEast()].map(function (x) { return x.toFixed(4); }).join(',');
    fetch('/api/live?box=' + box, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : { riders: [] }; }).then(function (j) {
      layer.clearLayers();
      j.riders.forEach(function (r) { L.marker([r.lat, r.lng], { icon: riderIcon(r), keyboard: false, title: r.name }).addTo(layer); });
      status.textContent = j.riders.length ? T.riders.replace('{n}', j.riders.length) : T.none;
    }).catch(function () { /* next round */ });
  }
  map.on('moveend', load);
  load();
  setInterval(function () { if (document.visibilityState === 'visible') load(); }, 10000);

  document.getElementById('live-locate').addEventListener('click', function () {
    if (!navigator.geolocation) { status.textContent = T.locate_error; return; }
    // Only moves the map – the position is not sent anywhere
    navigator.geolocation.getCurrentPosition(function (pos) { map.setView([pos.coords.latitude, pos.coords.longitude], 13); },
      function () { status.textContent = T.locate_error; }, { timeout: 8000 });
  });
})();
