/* ElTouro route planner – set waypoints, calculate the route via /api/route, write the result into the form. */
(function () {
  'use strict';
  var el = document.getElementById('planner-map');
  if (!el || !window.L) return;

  var d = el.dataset;
  var T = JSON.parse(d.texts || '{}');
  var MAX = 60;
  var points = [];
  var current = null;
  var requestNo = 0;
  try { points = JSON.parse(d.waypoints || '[]') || []; } catch (e) { points = []; }
  try { current = d.geojson ? JSON.parse(d.geojson) : null; } catch (e) { current = null; }

  var fieldWp = document.getElementById('waypoints_json');
  var fieldGj = document.getElementById('geojson');
  var fieldRules = document.getElementById('rule_set');
  var saveButton = document.getElementById('tour-save');
  var status = document.getElementById('planner-status');
  var info = document.getElementById('planner-info');

  var map = L.map(el, { zoomControl: true }).setView([51.2, 10.4], 6);
  L.tileLayer(d.tiles, { maxZoom: 19, attribution: d.attribution }).addTo(map);
  var routeLayer = L.layerGroup().addTo(map);
  var markerLayer = L.layerGroup().addTo(map);

  function setStatus(text) { status.textContent = text || ''; }

  function distance(a, b) { // [lng,lat]
    var r = 6371008.8, rad = Math.PI / 180;
    var dp = (b[1] - a[1]) * rad, dl = (b[0] - a[0]) * rad;
    var x = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a[1] * rad) * Math.cos(b[1] * rad) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(x)));
  }

  function showStats(gj) {
    if (!gj) { info.textContent = ''; return; }
    var total = 0, free = 0;
    gj.features.forEach(function (f) {
      var c = f.geometry.coordinates;
      for (var i = 1; i < c.length; i++) {
        var s = distance(c[i - 1], c[i]);
        total += s;
        if (f.properties && f.properties.freehand) free += s;
      }
    });
    var km = (total / 1000).toLocaleString(d.lang === 'de' ? 'de-DE' : 'en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var text = T.stats.replace('{km}', km).replace('{n}', points.length);
    if (free > 0) text += ' · ' + T.freehand_share.replace('{p}', Math.round(free / total * 100));
    info.textContent = text;
  }

  function drawRoute(gj) {
    routeLayer.clearLayers();
    if (!gj) return;
    L.geoJSON(gj, {
      style: function (f) {
        return f.properties && f.properties.freehand
          ? { color: '#A3261B', weight: 5, opacity: 0.9, dashArray: '8 8' }
          : { color: '#2F5E8C', weight: 5, opacity: 0.9 };
      }
    }).addTo(routeLayer);
  }

  function drawMarkers() {
    markerLayer.clearLayers();
    points.forEach(function (p, i) {
      var m = L.marker(p, { draggable: true, keyboard: true, title: T.point + ' ' + (i + 1) });
      m.on('dragend', function (e) {
        var ll = e.target.getLatLng();
        points[i] = [ll.lat, ll.lng];
        calculate();
      });
      m.on('click', function () {
        points.splice(i, 1);
        calculate();
      });
      m.addTo(markerLayer);
    });
  }

  function calculate() {
    drawMarkers();
    fieldWp.value = JSON.stringify(points);
    if (points.length < 2) {
      current = null;
      fieldGj.value = '';
      drawRoute(null);
      showStats(null);
      saveButton.disabled = true;
      setStatus(T.empty);
      return;
    }
    var no = ++requestNo;
    saveButton.disabled = true;
    setStatus(T.calculating);
    fetch('/api/route', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf },
      body: JSON.stringify({ points: points, rule_set: fieldRules ? fieldRules.value : 'ekfv' })
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) {
      if (no !== requestNo) return;          // ignore outdated responses
      current = j.geojson;
      fieldGj.value = JSON.stringify(current);
      drawRoute(current);
      showStats(current);
      saveButton.disabled = false;
      setStatus(j.notice ? T['notice_' + j.notice] : T.done);
    }).catch(function () {
      if (no !== requestNo) return;
      setStatus(T.error);
    });
  }

  map.on('click', function (e) {
    if (points.length >= MAX) return;
    points.push([e.latlng.lat, e.latlng.lng]);
    calculate();
  });

  document.getElementById('pl-undo').addEventListener('click', function () { points.pop(); calculate(); });
  document.getElementById('pl-clear').addEventListener('click', function () { points = []; calculate(); });
  document.getElementById('pl-loop').addEventListener('click', function () {
    if (points.length >= 2 && points.length < MAX) { points.push(points[0].slice()); calculate(); }
  });
  document.getElementById('pl-locate').addEventListener('click', function () {
    if (!navigator.geolocation) { setStatus(T.locate_error); return; }
    // Location only moves the map – it is never sent anywhere
    navigator.geolocation.getCurrentPosition(function (pos) {
      map.setView([pos.coords.latitude, pos.coords.longitude], 14);
    }, function () { setStatus(T.locate_error); }, { enableHighAccuracy: false, timeout: 8000 });
  });
  if (fieldRules) fieldRules.addEventListener('change', function () { if (points.length >= 2) calculate(); });

  // Initial state: show a saved route without recalculating
  drawMarkers();
  if (current) {
    drawRoute(current);
    showStats(current);
    map.fitBounds(L.geoJSON(current).getBounds(), { padding: [30, 30] });
    saveButton.disabled = false;
  } else {
    saveButton.disabled = true;
    setStatus(T.empty);
  }
})();
