/* ElTouro route planner – set waypoints, calculate the route via /api/route, write the result into the form. */
(function () {
  'use strict';
  var el = document.getElementById('planner-map');
  if (!el || !window.L) return;

  var d = el.dataset;
  var T = JSON.parse(d.texts || '{}');
  var MAX = 60;
  var MAX_LEG = parseFloat(d.maxLegKm || '50') * 1000;   // metres, crow-flies, between two waypoints
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
  var fieldTitle = document.getElementById('title');
  var nameButton = document.getElementById('tour-name-suggest');
  var autoName = '';   // the last suggestion we filled in ourselves – may be replaced, a typed name never

  // Loading animation: only shown if the calculation takes a moment, progress is an estimate
  var loading = document.getElementById('planner-loading');
  var saying = document.getElementById('planner-saying');
  var bar = document.getElementById('planner-progress');
  var sayings = [];
  for (var k = 1; T['loading_' + k]; k++) sayings.push(T['loading_' + k]);
  var loadTimer = null, tickTimer = null, loadGen = 0;

  function startLoading() {
    stopLoading(true);
    if (!loading) return;
    var gen = ++loadGen;
    loadTimer = setTimeout(function () {
      var started = Date.now(), ticks = 0, no = Math.floor(Math.random() * sayings.length);
      saying.textContent = sayings[no] || '';
      bar.style.width = '0%';
      loading.hidden = false;
      tickTimer = setInterval(function () {
        if (gen !== loadGen) return;
        var t = (Date.now() - started) / 1000;
        bar.style.width = (92 * (1 - Math.exp(-t / 3))).toFixed(1) + '%';
        if (++ticks % 9 === 0 && sayings.length > 1) {
          no = (no + 1) % sayings.length;
          saying.textContent = sayings[no];
        }
      }, 200);
    }, 300);
  }

  function stopLoading(immediate) {
    clearTimeout(loadTimer);
    clearInterval(tickTimer);
    if (!loading || loading.hidden) return;
    if (immediate) { loading.hidden = true; return; }
    var gen = loadGen;
    bar.style.width = '100%';
    setTimeout(function () { if (gen === loadGen) loading.hidden = true; }, 350);
  }

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

  function legOk(p, q) { return !p || !q || distance([p[1], p[0]], [q[1], q[0]]) <= MAX_LEG; }
  function tooFar() { setStatus(T.leg_too_long.replace('{km}', Math.round(MAX_LEG / 1000))); }

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
        var moved = [ll.lat, ll.lng];
        if (!legOk(points[i - 1], moved) || !legOk(moved, points[i + 1])) { drawMarkers(); tooFar(); return; }
        points[i] = moved;
        calculate();
      });
      m.on('click', function () {
        points.splice(i, 1);
        calculate();
      });
      m.addTo(markerLayer);
    });
  }

  // Name suggestion from the server (place in the middle of the track, length, loop)
  function suggestName(force) {
    if (!current || !fieldTitle || !nameButton) return;
    var untouched = function () { return fieldTitle.value.trim() === '' || fieldTitle.value === autoName; };
    if (!force && !untouched()) return;
    nameButton.disabled = true;
    fetch('/api/tour-name', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf },
      body: JSON.stringify({ geojson: current, exclude: fieldTitle.value })
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) {
      if (!j.name || (!force && !untouched())) return;
      autoName = j.name;
      fieldTitle.value = j.name;
    }).catch(function () { /* no suggestion – the field simply stays as it is */ }).then(function () {
      nameButton.disabled = !current;
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
      stopLoading(true);
      saveButton.disabled = true;
      if (nameButton) nameButton.disabled = true;
      setStatus(T.empty);
      return;
    }
    var no = ++requestNo;
    saveButton.disabled = true;
    setStatus(T.calculating);
    startLoading();
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
      stopLoading(false);
      current = j.geojson;
      fieldGj.value = JSON.stringify(current);
      drawRoute(current);
      showStats(current);
      saveButton.disabled = false;
      setStatus(j.notice ? T['notice_' + j.notice] : T.done);
      if (nameButton) nameButton.disabled = false;
      suggestName(false);
    }).catch(function () {
      if (no !== requestNo) return;
      stopLoading(false);
      setStatus(T.error);
    });
  }

  map.on('click', function (e) {
    if (points.length >= MAX) return;
    var p = [e.latlng.lat, e.latlng.lng];
    if (!legOk(points[points.length - 1], p)) { tooFar(); return; }
    points.push(p);
    calculate();
  });

  document.getElementById('pl-undo').addEventListener('click', function () { points.pop(); calculate(); });
  document.getElementById('pl-clear').addEventListener('click', function () { points = []; calculate(); });
  document.getElementById('pl-loop').addEventListener('click', function () {
    if (points.length < 2 || points.length >= MAX) return;
    if (!legOk(points[points.length - 1], points[0])) { tooFar(); return; }
    points.push(points[0].slice());
    calculate();
  });
  document.getElementById('pl-locate').addEventListener('click', function () {
    if (!navigator.geolocation) { setStatus(T.locate_error); return; }
    // Location only moves the map – it is never sent anywhere
    navigator.geolocation.getCurrentPosition(function (pos) {
      map.setView([pos.coords.latitude, pos.coords.longitude], 14);
    }, function () { setStatus(T.locate_error); }, { enableHighAccuracy: false, timeout: 8000 });
  });
  if (nameButton) nameButton.addEventListener('click', function () { suggestName(true); });
  if (fieldRules) fieldRules.addEventListener('change', function () { if (points.length >= 2) calculate(); });

  // Initial state: show a saved route without recalculating
  drawMarkers();
  if (current) {
    drawRoute(current);
    showStats(current);
    map.fitBounds(L.geoJSON(current).getBounds(), { padding: [30, 30] });
    saveButton.disabled = false;
    if (nameButton) nameButton.disabled = false;
  } else {
    saveButton.disabled = true;
    setStatus(T.empty);
  }
})();
