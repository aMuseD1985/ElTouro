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
  // A stop (charging, food, break, sight) travels with its waypoint as third element: [lat, lng, {type, name}]
  try {
    (JSON.parse(d.stops || '[]') || []).forEach(function (s) { if (points[s.i]) points[s.i][2] = { type: s.type, name: s.name || '' }; });
  } catch (e) { /* no stops */ }
  var P = {};
  try { P = JSON.parse(d.poiTexts || '{}'); } catch (e) { P = {}; }
  var STOP_ICON = { charge: '⚡', food: '🍽', break: '☕', sight: '👁' };
  function clean(list) { return list.map(function (p) { return [p[0], p[1]]; }); }
  try { current = d.geojson ? JSON.parse(d.geojson) : null; } catch (e) { current = null; }

  var fieldWp = document.getElementById('waypoints_json');
  var fieldGj = document.getElementById('geojson');
  var fieldGuidance = document.getElementById('guidance_json');
  var fieldStops = document.getElementById('stops_json');
  var stopsButton = document.getElementById('pl-stops');
  var uturnBox = document.getElementById('pl-uturns');
  try { if (uturnBox && localStorage.getItem('eltouro.avoidUturns') === '0') uturnBox.checked = false; } catch (e) { /* ignore */ }
  var fieldRules = document.getElementById('rule_set');
  var fieldVehicle = document.getElementById('vehicle_class');
  var bullrunHint = document.getElementById('bullrun-hint');
  var saveButton = document.getElementById('tour-save');
  var status = document.getElementById('planner-status');
  var info = document.getElementById('planner-info');
  var fieldTitle = document.getElementById('title');
  var nameButton = document.getElementById('tour-name-suggest');
  var fieldDesc = document.getElementById('description');
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
    }, 1000);   // only when it takes a while – quick routes don't flash an animation
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
  ElTouroMaps.leaflet(map, el);
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
      interactive: false,
      style: function (f) {
        return f.properties && f.properties.freehand
          ? { color: '#A3261B', weight: 4, opacity: 0.9, dashArray: '6 6' }
          : { color: '#2F5E8C', weight: 4, opacity: 0.9 };
      }
    }).addTo(routeLayer);
    // An invisible, wider twin of the line takes the clicks – the thin visible line would be hard to hit
    L.geoJSON(gj, { bubblingMouseEvents: false, style: function () { return { color: '#000', weight: 18, opacity: 0 }; } })
      .on('click', function (e) { insertOnRoute([e.latlng.lat, e.latlng.lng]); }).addTo(routeLayer);
  }

  // ---- Waypoint editor: numbered pins on the map and the list below it
  var selected = -1;
  var pins = [];

  // Speaking names for the waypoints ("Königsallee 1, Düsseldorf"), asked one at a time – the geocoder allows one request per second
  var names = {};
  var nameQueue = [];
  var naming = false;
  function nameKey(p) { return p[0].toFixed(4) + ',' + p[1].toFixed(4); }
  function pointName(i) { var n = names[nameKey(points[i])]; return n || (T.point + ' ' + (i + 1)); }
  function requestNames() {
    points.forEach(function (p) {
      var k = nameKey(p);
      if (names[k] === undefined && nameQueue.indexOf(k) < 0) nameQueue.push(k);
    });
    nextName();
  }
  function nextName() {
    if (naming || !nameQueue.length) return;
    var k = nameQueue.shift();
    if (names[k] !== undefined) { nextName(); return; }
    naming = true;
    var ll = k.split(',');
    fetch('/api/place-name?lat=' + ll[0] + '&lng=' + ll[1], { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { label: null }; })
      .then(function (j) { names[k] = j.label || ''; })
      .catch(function () { names[k] = ''; })
      .then(function () {
        naming = false;
        renderList();
        pins.forEach(function (m, i) { if (points[i]) m.options.title = pointName(i); });
        nextName();
      });
  }
  var list = document.getElementById('waypoint-list');
  var listBox = document.getElementById('waypoints');

  function pinIcon(i) {
    var stop = points[i] && points[i][2];
    var cls = 'wp-pin' + (i === selected ? ' is-selected' : '') + (i === 0 ? ' is-start' : '') + (stop ? ' is-stop' : '');
    var badge = stop ? '<span class="wp-badge">' + STOP_ICON[stop.type] + '</span>' : '';
    return L.divIcon({ className: '', html: '<div class="' + cls + '">' + (i + 1) + badge + '</div>', iconSize: [30, 30], iconAnchor: [15, 15] });
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
    title.textContent = (i + 1) + ' · ' + pointName(i);
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
        if (points[i][2]) moved.push(points[i][2]);   // a moved stop stays a stop
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
    requestNames();
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
      var main = document.createElement('div');
      main.className = 'wp-main';
      var leg = i === 0 ? T.start : T.leg.replace('{km}', fmtKm(distance([points[i - 1][1], points[i - 1][0]], [p[1], p[0]])));
      if (i > 0 && i === points.length - 1) leg += ' · ' + T.finish;
      var nameBtn = document.createElement('button');
      nameBtn.type = 'button'; nameBtn.className = 'link wp-name';
      nameBtn.textContent = p[2] ? STOP_ICON[p[2].type] + ' ' + (p[2].name || pointName(i)) : pointName(i);
      nameBtn.addEventListener('click', function () { select(i, true); });
      // Second line: distance on the left, coordinates on the right (link to Google Maps in a new window – only here in the planner)
      var sub = document.createElement('div');
      sub.className = 'wp-sub';
      var legBtn = document.createElement('button');
      legBtn.type = 'button'; legBtn.className = 'link wp-leg';
      legBtn.textContent = leg;
      legBtn.addEventListener('click', function () { select(i, true); });
      var lat = p[0].toFixed(5), lng = p[1].toFixed(5);
      var coords = document.createElement('a');
      coords.className = 'wp-coords';
      coords.href = 'https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng;
      coords.target = '_blank';
      coords.rel = 'noopener noreferrer';
      coords.textContent = lat + ', ' + lng;
      coords.title = T.open_maps;
      sub.appendChild(legBtn); sub.appendChild(coords);
      main.appendChild(nameBtn); main.appendChild(sub);
      li.appendChild(num);
      li.appendChild(main);
      li.appendChild(stopSelect(i));
      li.appendChild(listButton('▲', T.up + ' (' + (i + 1) + ')', function () { movePoint(i, -1); }, i === 0));
      li.appendChild(listButton('▼', T.down + ' (' + (i + 1) + ')', function () { movePoint(i, 1); }, i === points.length - 1));
      li.appendChild(listButton('✕', T.remove + ' (' + (i + 1) + ')', function () { removePoint(i); }, false, 'danger'));
      list.appendChild(li);
    });
  }

  // Per waypoint: is it a stop, and what kind? Choosing a kind keeps the address as the stop's name.
  function stopSelect(i) {
    var sel = document.createElement('select');
    sel.className = 'wp-stop';
    sel.setAttribute('aria-label', P.stop_label + ' (' + (i + 1) + ')');
    [''].concat(Object.keys(STOP_ICON)).forEach(function (type) {
      var o = document.createElement('option');
      o.value = type;
      o.textContent = type ? STOP_ICON[type] + ' ' + P['type_' + type] : P.no_stop;
      sel.appendChild(o);
    });
    sel.value = points[i][2] ? points[i][2].type : '';
    sel.addEventListener('change', function () {
      if (sel.value) points[i][2] = { type: sel.value, name: points[i][2] ? points[i][2].name : (names[nameKey(points[i])] || '') };
      else points[i].length = 2;
      selected = i;
      drawMarkers();
      fieldStops.value = JSON.stringify(points.map(function (p, k) { return p[2] ? { i: k, type: p[2].type, name: p[2].name } : null; })
                                              .filter(function (x) { return x; }));
    });
    return sel;
  }

  // ---- Suggestions for stops along the route (server asks OpenStreetMap; see poi_lib.php)
  var stopBox = document.getElementById('stop-suggestions');
  var poiLayer = L.layerGroup().addTo(map);

  function poiFacts(it) {
    var f = [P['kind_' + it.kind] || it.kind, P.at_km.replace('{km}', it.km.toLocaleString(d.lang === 'de' ? 'de-DE' : 'en-GB'))];
    f.push(it.off < 30 ? P.on_route : P.off_route.replace('{m}', Math.round(it.off / 10) * 10));
    it.facts.forEach(function (x) { if (P['fact_' + x]) f.push(P['fact_' + x]); });
    return f.join(' · ');
  }

  function planStop(it) {
    if (points.length >= MAX) return;
    poiLayer.clearLayers();
    stopBox.hidden = true;
    insertOnRoute([it.lat, it.lng, { type: it.type, name: it.name || P['kind_' + it.kind] || '' }]);
  }

  function poiRow(it, reason) {
    var li = document.createElement('li');
    var text = document.createElement('div');
    var title = document.createElement('strong');
    title.textContent = STOP_ICON[it.type] + ' ' + (it.name || P['kind_' + it.kind] || it.kind);
    var small = document.createElement('small');
    small.textContent = (reason ? P['reason_' + reason] + ' · ' : '') + poiFacts(it);
    text.appendChild(title); text.appendChild(document.createElement('br')); text.appendChild(small);
    if (it.opening_hours) {
      var oh = document.createElement('small');
      oh.className = 'muted-item';
      oh.textContent = ' · ' + it.opening_hours;
      text.appendChild(oh);
    }
    var show = listButton(P.show, P.show, function () { map.setView([it.lat, it.lng], 17); el.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    var add = listButton(P.add, P.add, function () { planStop(it); });
    li.appendChild(text); li.appendChild(show); li.appendChild(add);
    return li;
  }

  function poiMarker(it) {
    var m = L.marker([it.lat, it.lng], { icon: L.divIcon({ className: '', html: '<div class="poi-pin poi-' + it.type + '">' + STOP_ICON[it.type] + '</div>',
                                                            iconSize: [26, 26], iconAnchor: [13, 13] }), keyboard: true,
                                         title: it.name || P['kind_' + it.kind] || '' });
    m.bindPopup(function () {
      var box = document.createElement('div');
      var t = document.createElement('strong'); t.textContent = it.name || P['kind_' + it.kind] || '';
      var f = document.createElement('div'); f.textContent = poiFacts(it);
      var b = document.createElement('button'); b.type = 'button'; b.className = 'link'; b.textContent = P.add;
      b.addEventListener('click', function () { map.closePopup(); planStop(it); });
      box.appendChild(t); box.appendChild(f); box.appendChild(b);
      return box;
    });
    m.addTo(poiLayer);
  }

  function showSuggestions(j) {
    stopBox.textContent = '';
    poiLayer.clearLayers();
    var h = document.createElement('h2'); h.textContent = P.title; stopBox.appendChild(h);
    var note = document.createElement('p'); note.className = 'hint'; note.textContent = P.note; stopBox.appendChild(note);
    var any = false;
    if (j.plan && j.plan.length) {
      var h3 = document.createElement('h3'); h3.textContent = P.plan; stopBox.appendChild(h3);
      var ul = document.createElement('ul'); ul.className = 'poi-list';
      j.plan.forEach(function (it) { ul.appendChild(poiRow(it, it.reason)); poiMarker(it); });
      stopBox.appendChild(ul);
      any = true;
    }
    Object.keys(STOP_ICON).forEach(function (type) {
      var list = (j.by_type && j.by_type[type]) || [];
      var det = document.createElement('details');
      var sum = document.createElement('summary');
      sum.textContent = STOP_ICON[type] + ' ' + P['type_' + type] + ' (' + list.length + ')';
      det.appendChild(sum);
      if (!list.length) {
        var none = document.createElement('p'); none.className = 'hint';
        none.textContent = type === 'charge' ? P.no_charge : P.none;
        det.appendChild(none);
      } else {
        var ul2 = document.createElement('ul'); ul2.className = 'poi-list';
        list.forEach(function (it) { ul2.appendChild(poiRow(it, '')); poiMarker(it); });
        det.appendChild(ul2);
        any = true;
      }
      stopBox.appendChild(det);
    });
    if (!any) { var p = document.createElement('p'); p.textContent = P.none_at_all; stopBox.appendChild(p); }
    stopBox.hidden = false;
  }

  // Lunch scenes while the stops are searched – only if it takes longer than a second
  var pauseBox = document.getElementById('stops-loading');
  var pauseTimer = null, pauseShow = null, pauseIdx = 0;
  function pauseStart() {
    if (!pauseBox) return;
    var scenes = pauseBox.querySelectorAll('.pause-scene'), saying = document.getElementById('pause-saying');
    function show(i) {
      pauseIdx = i % scenes.length;
      Array.prototype.forEach.call(scenes, function (s, k) { s.classList.toggle('is-on', k === pauseIdx); });
      saying.textContent = T['pause_' + (pauseIdx + 1)] || '';
    }
    pauseShow = setTimeout(function () {
      show(Math.floor(Math.random() * scenes.length));
      pauseBox.hidden = false;
      pauseTimer = setInterval(function () { show(pauseIdx + 1); }, 3200);
    }, 1000);
  }
  function pauseStop() {
    clearTimeout(pauseShow); clearInterval(pauseTimer);
    if (pauseBox) pauseBox.hidden = true;
  }

  if (stopsButton) stopsButton.addEventListener('click', function () {
    if (!current) return;
    pauseStart();
    stopsButton.disabled = true;
    stopBox.hidden = false;
    stopBox.textContent = P.loading;
    fetch('/api/stops', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf },
      body: JSON.stringify({ geojson: current })
    }).then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
      .then(function (x) {
        if (x.status === 429) stopBox.textContent = P.slow;
        else if (x.status !== 200) stopBox.textContent = P.error;
        else showSuggestions(x.j);
      })
      .catch(function () { stopBox.textContent = P.error; })
      .then(function () { pauseStop(); stopsButton.disabled = !current; });
  });

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
    // Automatically only into empty fields; the dice (force) fills name, description, difficulty and style anew
    var untouched = function () { return fieldTitle.value.trim() === ''; };
    var descFree = function () { return fieldDesc && fieldDesc.value.trim() === ''; };
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
      if (j.name && (force || untouched())) fieldTitle.value = j.name;
      if (j.description && fieldDesc && (force || descFree())) fieldDesc.value = j.description;
      ['difficulty', 'style'].forEach(function (id) {
        var sel = document.getElementById(id);
        if (sel && j[id] && (force || (isNew && !touched[id]))) sel.value = j[id];
      });
    }).catch(function () { /* no suggestion – the field simply stays as it is */ }).then(function () {
      nameButton.disabled = !current;
    });
  }

  function calculate() {
    hideOptimizeNote();
    drawMarkers();
    fieldWp.value = JSON.stringify(clean(points));
    if (fieldStops) {
      var stops = [];
      points.forEach(function (p, i) { if (p[2]) stops.push({ i: i, type: p[2].type, name: p[2].name }); });
      fieldStops.value = JSON.stringify(stops);
    }
    if (stopsButton) stopsButton.disabled = true;
    if (points.length < 2) {
      current = null;
      fieldGj.value = '';
      if (fieldGuidance) fieldGuidance.value = '[]';
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
      body: JSON.stringify({ points: clean(points), rule_set: fieldRules ? fieldRules.value : 'ekfv', vehicle: fieldVehicle ? parseInt(fieldVehicle.value, 10) : 2,
                             avoid_uturns: !!(uturnBox && uturnBox.checked),
                             stops: points.map(function (p, i) { return p[2] ? i : -1; }).filter(function (i) { return i >= 0; }) })
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) {
      if (no !== requestNo) return;          // ignore outdated responses
      stopLoading(false);
      current = j.geojson;
      fieldGj.value = JSON.stringify(current);
      // Waypoints the server moved to avoid a U-turn: the pins follow, the stop info stays with them
      if (j.waypoints && j.waypoints.length === points.length) {
        points = points.map(function (p, i) { var q = [j.waypoints[i][0], j.waypoints[i][1]]; if (p[2]) q.push(p[2]); return q; });
        fieldWp.value = JSON.stringify(clean(points));
        drawMarkers();
      }
      if (fieldGuidance) fieldGuidance.value = JSON.stringify(j.guidance || []);
      drawRoute(current);
      showStats(current);
      saveButton.disabled = false;
      if (stopsButton) stopsButton.disabled = false;
      var msg = j.notice ? T['notice_' + j.notice] : T.done;
      if (j.uturns && j.uturns.avoided) msg += ' ' + T.uturns_avoided.replace('{n}', j.uturns.avoided);
      if (j.uturns && j.uturns.left) msg += ' ' + T.uturns_left.replace('{n}', j.uturns.left);
      setStatus(msg);
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
  // ---- Route optimieren: shortest order of the points between start and finish (straight lines), stops travel with their point
  var optNote = document.getElementById('optimize-note');
  var optText = document.getElementById('optimize-text');
  var optBefore = null;

  function pathLength(order) {
    var sum = 0;
    for (var i = 1; i < order.length; i++) sum += distance([order[i - 1][1], order[i - 1][0]], [order[i][1], order[i][0]]);
    return sum;
  }

  /** Best order of the inner points between fixed first and last (exact up to 11 inner points, else nearest neighbour + 2-opt). */
  function bestOrder(pts) {
    var first = pts[0], last = pts[pts.length - 1], inner = pts.slice(1, -1), n = inner.length;
    function dd(a, b) { return distance([a[1], a[0]], [b[1], b[0]]); }
    if (n <= 1) return pts.slice();
    if (n <= 11) {   // Held-Karp
      var size = 1 << n, INF = 1e18, cost = [], from = [], k, m, mask;
      for (mask = 0; mask < size; mask++) { cost.push(new Array(n).fill(INF)); from.push(new Array(n).fill(-1)); }
      for (k = 0; k < n; k++) cost[1 << k][k] = dd(first, inner[k]);
      for (mask = 1; mask < size; mask++) for (k = 0; k < n; k++) {
        if (!(mask & (1 << k)) || cost[mask][k] >= INF) continue;
        for (m = 0; m < n; m++) {
          if (mask & (1 << m)) continue;
          var nm = mask | (1 << m), c = cost[mask][k] + dd(inner[k], inner[m]);
          if (c < cost[nm][m]) { cost[nm][m] = c; from[nm][m] = k; }
        }
      }
      var full = size - 1, bestK = 0, best = INF;
      for (k = 0; k < n; k++) { var c2 = cost[full][k] + dd(inner[k], last); if (c2 < best) { best = c2; bestK = k; } }
      var seq = [], cur = bestK, mk = full;
      while (cur >= 0) { seq.push(inner[cur]); var prev = from[mk][cur]; mk &= ~(1 << cur); cur = prev; }
      return [first].concat(seq.reverse(), [last]);
    }
    var route = [first], left = inner.slice();
    while (left.length) {
      var at = route[route.length - 1], bi = 0;
      for (var q = 1; q < left.length; q++) if (dd(at, left[q]) < dd(at, left[bi])) bi = q;
      route.push(left.splice(bi, 1)[0]);
    }
    route.push(last);
    var improved = true;
    while (improved) {
      improved = false;
      for (var i = 1; i < route.length - 2; i++) for (var j = i + 1; j < route.length - 1; j++) {
        if (dd(route[i - 1], route[j]) + dd(route[i], route[j + 1]) < dd(route[i - 1], route[i]) + dd(route[j], route[j + 1]) - 1) {
          route = route.slice(0, i).concat(route.slice(i, j + 1).reverse(), route.slice(j + 1));
          improved = true;
        }
      }
    }
    return route;
  }

  function hideOptimizeNote() { if (optNote) optNote.hidden = true; optBefore = null; }

  var optButton = document.getElementById('pl-optimize');
  if (optButton) optButton.addEventListener('click', function () {
    if (points.length < 4) { setStatus(T.optimize_few); return; }
    var closed = distance([points[0][1], points[0][0]], [points[points.length - 1][1], points[points.length - 1][0]]) < 150;
    var before = points.slice(), oldLen = pathLength(before);
    var candidate = bestOrder(before);
    var newLen = pathLength(candidate);
    if (newLen > oldLen * 0.97) { setStatus(T.optimize_none); return; }
    if (!legsOk(candidate)) { tooFar(); return; }
    points = candidate;
    selected = -1;
    calculate();
    optBefore = before;
    optText.textContent = T.optimize_done.replace('{new}', fmtKm(newLen)).replace('{old}', fmtKm(oldLen));
    optNote.hidden = false;
  });
  var optUndo = document.getElementById('pl-opt-undo');
  if (optUndo) optUndo.addEventListener('click', function () {
    if (!optBefore) return;
    points = optBefore;
    selected = -1;
    calculate();
  });

  document.getElementById('pl-loop').addEventListener('click', function () {
    if (points.length < 2 || points.length >= MAX) return;
    if (!legOk(points[points.length - 1], points[0])) { tooFar(); return; }
    points.push(points[0].slice(0, 2));
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
  if (uturnBox) uturnBox.addEventListener('change', function () {
    try { localStorage.setItem('eltouro.avoidUturns', uturnBox.checked ? '1' : '0'); } catch (e) { /* ignore */ }
    if (points.length >= 2) calculate();
  });
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
    if (stopsButton) stopsButton.disabled = false;
  } else {
    saveButton.disabled = true;
    setStatus(T.empty);
  }
})();
