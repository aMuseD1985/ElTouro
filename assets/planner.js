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
  var fieldVehicle = document.getElementById('vehicle_class');
  var bullrunHint = document.getElementById('bullrun-hint');
  var saveButton = document.getElementById('tour-save');
  var status = document.getElementById('planner-status');
  var info = document.getElementById('planner-info');
  var fieldTitle = document.getElementById('title');
  var nameButton = document.getElementById('tour-name-suggest');
  var autoName = '';   // the last suggestion we filled in ourselves – may be replaced, a typed name never
  var fieldDesc = document.getElementById('description');
  var autoDesc = '';
  // Difficulty and style are only suggested for new tours and until the user picks something
  var isNew = (document.querySelector('#tour-form input[name="id"]') || {}).value === '0';
  var touched = {};
  ['difficulty', 'style'].forEach(function (id) {
    var sel = document.getElementById(id);
    if (sel) sel.addEventListener('change', function () { touched[id] = true; });
  });

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
    // A click on the line inserts a waypoint there (it must not also reach the map, which would append one)
    L.geoJSON(gj, {
      bubblingMouseEvents: false,
      style: function (f) {
        return f.properties && f.properties.freehand
          ? { color: '#A3261B', weight: 6, opacity: 0.9, dashArray: '8 8' }
          : { color: '#2F5E8C', weight: 6, opacity: 0.9 };
      }
    }).on('click', function (e) { insertOnRoute([e.latlng.lat, e.latlng.lng]); }).addTo(routeLayer);
  }

  // ---- Waypoint editor: numbered pins on the map and the list below it
  var selected = -1;
  var pins = [];
  var list = document.getElementById('waypoint-list');
  var listBox = document.getElementById('waypoints');

  function pinIcon(i) {
    var cls = 'wp-pin' + (i === selected ? ' is-selected' : '') + (i === 0 ? ' is-start' : '');
    return L.divIcon({ className: '', html: '<div class="' + cls + '">' + (i + 1) + '</div>', iconSize: [30, 30], iconAnchor: [15, 15] });
  }

  // Only the icons change – redrawing the markers would close an open popup
  function select(i, pan) {
    selected = i;
    pins.forEach(function (m, k) { m.setIcon(pinIcon(k)); m.setZIndexOffset(k === i ? 1000 : 0); });
    renderList();
    if (pan && points[i]) map.panTo(points[i]);
  }

  function popupFor(i) {
    var box = document.createElement('div');
    var title = document.createElement('strong');
    title.textContent = T.point + ' ' + (i + 1);
    var del = document.createElement('button');
    del.type = 'button'; del.className = 'link danger'; del.textContent = T.remove;
    del.addEventListener('click', function () { removePoint(i); });
    box.appendChild(title); box.appendChild(document.createElement('br')); box.appendChild(del);
    return box;
  }

  function drawMarkers() {
    markerLayer.clearLayers();
    pins = [];
    points.forEach(function (p, i) {
      var m = L.marker(p, { draggable: true, keyboard: true, title: T.point + ' ' + (i + 1), icon: pinIcon(i),
                            zIndexOffset: i === selected ? 1000 : 0 });
      m.on('dragend', function (e) {
        var ll = e.target.getLatLng();
        var moved = [ll.lat, ll.lng];
        if (!legOk(points[i - 1], moved) || !legOk(moved, points[i + 1])) { drawMarkers(); tooFar(); return; }
        points[i] = moved;
        selected = i;
        calculate();
      });
      m.on('click', function () { select(i, false); });
      m.bindPopup(function () { return popupFor(i); }, { closeButton: false, offset: [0, -10] });
      m.addTo(markerLayer);
      pins.push(m);
    });
    renderList();
  }

  function fmtKm(m) {
    return (m / 1000).toLocaleString(d.lang === 'de' ? 'de-DE' : 'en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  }

  function listButton(text, label, onClick, disabled, extra) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'link' + (extra ? ' ' + extra : ''); b.textContent = text;
    b.setAttribute('aria-label', label); b.title = label; b.disabled = !!disabled;
    b.addEventListener('click', onClick);
    return b;
  }

  function renderList() {
    if (!list) return;
    list.textContent = '';
    listBox.hidden = points.length === 0;
    points.forEach(function (p, i) {
      var li = document.createElement('li');
      if (i === selected) li.className = 'is-selected';
      var num = document.createElement('span');
      num.className = 'wp-pin' + (i === 0 ? ' is-start' : '') + (i === selected ? ' is-selected' : '');
      num.textContent = i + 1;
      var main = document.createElement('button');
      main.type = 'button'; main.className = 'link wp-main';
      var leg = i === 0 ? T.start : T.leg.replace('{km}', fmtKm(distance([points[i - 1][1], points[i - 1][0]], [p[1], p[0]])));
      if (i > 0 && i === points.length - 1) leg += ' · ' + T.finish;
      var legText = document.createElement('span');
      legText.textContent = leg;
      var coords = document.createElement('small');
      coords.textContent = p[0].toFixed(5) + ', ' + p[1].toFixed(5);
      main.appendChild(legText); main.appendChild(coords);
      main.addEventListener('click', function () { select(i, true); });
      li.appendChild(num);
      li.appendChild(main);
      li.appendChild(listButton('▲', T.up + ' (' + (i + 1) + ')', function () { movePoint(i, -1); }, i === 0));
      li.appendChild(listButton('▼', T.down + ' (' + (i + 1) + ')', function () { movePoint(i, 1); }, i === points.length - 1));
      li.appendChild(listButton('✕', T.remove + ' (' + (i + 1) + ')', function () { removePoint(i); }, false, 'danger'));
      list.appendChild(li);
    });
  }

  // Changes that would create a leg longer than allowed are refused, like clicks on the map
  function legsOk(candidate) {
    for (var i = 1; i < candidate.length; i++) if (!legOk(candidate[i - 1], candidate[i])) return false;
    return true;
  }

  function apply(candidate, newSelected) {
    if (!legsOk(candidate)) { tooFar(); return; }
    points = candidate;
    selected = newSelected;
    calculate();
  }

  function movePoint(i, delta) {
    var j = i + delta;
    if (j < 0 || j >= points.length) return;
    var c = points.slice();
    var tmp = c[i]; c[i] = c[j]; c[j] = tmp;
    apply(c, j);
  }

  function removePoint(i) {
    map.closePopup();
    var c = points.slice();
    c.splice(i, 1);
    points = c;
    selected = -1;
    calculate();
  }

  // Insert where the detour is smallest: between the two waypoints the click lies "between"
  function insertOnRoute(p) {
    if (points.length < 2 || points.length >= MAX) return;
    var best = 1, bestCost = Infinity;
    for (var i = 0; i < points.length - 1; i++) {
      var a = [points[i][1], points[i][0]], b = [points[i + 1][1], points[i + 1][0]], q = [p[1], p[0]];
      var cost = distance(a, q) + distance(q, b) - distance(a, b);
      if (cost < bestCost) { bestCost = cost; best = i + 1; }
    }
    var c = points.slice();
    c.splice(best, 0, p);
    apply(c, best);
  }

  // Suggestions from the server (places along the track, length, climb): name, description, difficulty, style.
  // Fields the user filled in or changed are never overwritten; the dice (force) always rolls a new name.
  function suggestName(force) {
    if (!current || !fieldTitle || !nameButton) return;
    var untouched = function () { return fieldTitle.value.trim() === '' || fieldTitle.value === autoName; };
    var descFree = function () { return fieldDesc && (fieldDesc.value.trim() === '' || fieldDesc.value === autoDesc); };
    if (!force && !untouched() && !descFree() && !(isNew && (!touched.difficulty || !touched.style))) return;
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
      if (j.name && (force || untouched())) { autoName = j.name; fieldTitle.value = j.name; }
      if (j.description && descFree()) { autoDesc = j.description; fieldDesc.value = j.description; }
      if (isNew) {
        ['difficulty', 'style'].forEach(function (id) {
          var sel = document.getElementById(id);
          if (sel && j[id] && !touched[id]) sel.value = j[id];
        });
      }
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
      body: JSON.stringify({ points: points, rule_set: fieldRules ? fieldRules.value : 'ekfv', vehicle: fieldVehicle ? parseInt(fieldVehicle.value, 10) : 2 })
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

  // Place search: our server asks the geocoder – only on Enter/click, never while typing
  var searchForm = document.getElementById('place-search');
  var searchInput = document.getElementById('place-q');
  var resultList = document.getElementById('place-results');
  var searchPin = null;

  function showPlace(r) {
    resultList.hidden = true;
    if (el.getBoundingClientRect().top < 0 || el.getBoundingClientRect().top > window.innerHeight * 0.6) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (r.bbox) map.fitBounds([[r.bbox[0], r.bbox[1]], [r.bbox[2], r.bbox[3]]], { maxZoom: 16 });
    else map.setView([r.lat, r.lng], 15);
    if (searchPin) map.removeLayer(searchPin);
    searchPin = L.circleMarker([r.lat, r.lng], { radius: 9, color: '#D7A845', weight: 3, fillColor: '#14263F', fillOpacity: 0.6 }).addTo(map);
  }

  function listResults(items, message) {
    resultList.textContent = '';
    if (message) {
      var info = document.createElement('li');
      info.className = 'muted-item';
      info.textContent = message;
      resultList.appendChild(info);
    }
    items.forEach(function (r) {
      var li = document.createElement('li');
      var go = document.createElement('button');
      go.type = 'button'; go.className = 'link'; go.textContent = r.label;
      go.addEventListener('click', function () { showPlace(r); });
      var add = document.createElement('button');
      add.type = 'button'; add.className = 'link'; add.textContent = T.add_point;
      add.addEventListener('click', function () {
        var p = [r.lat, r.lng];
        showPlace(r);
        if (points.length >= MAX) return;
        if (!legOk(points[points.length - 1], p)) { tooFar(); return; }
        points.push(p);
        calculate();
      });
      li.appendChild(go);
      li.appendChild(add);
      resultList.appendChild(li);
    });
    resultList.hidden = false;
  }

  if (searchForm) searchForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = searchInput.value.trim();
    if (q.length < 2) return;
    listResults([], T.searching);
    fetch('/api/place-search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
      .then(function (x) {
        if (x.status === 429) return listResults([], T.search_slow);
        if (x.status !== 200 || !x.j.results) return listResults([], T.search_error);
        if (!x.j.results.length) return listResults([], T.search_none);
        // One clear hit: go there; several: let the rider choose
        if (x.j.results.length === 1) showPlace(x.j.results[0]);
        else listResults(x.j.results, '');
      })
      .catch(function () { listResults([], T.search_error); });
  });

  map.on('click', function (e) {
    if (points.length >= MAX) return;
    var p = [e.latlng.lat, e.latlng.lng];
    if (!legOk(points[points.length - 1], p)) { tooFar(); return; }
    points.push(p);
    selected = points.length - 1;
    calculate();
  });

  document.getElementById('pl-undo').addEventListener('click', function () { points.pop(); calculate(); });
  document.getElementById('pl-clear').addEventListener('click', function () { points = []; selected = -1; calculate(); });
  document.getElementById('pl-reverse').addEventListener('click', function () {
    if (points.length < 2) return;
    points = points.slice().reverse();
    selected = -1;
    calculate();
  });
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
  if (fieldVehicle) fieldVehicle.addEventListener('change', function () {
    if (bullrunHint) bullrunHint.hidden = fieldVehicle.value !== '4';
    if (points.length >= 2) calculate();
  });

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
