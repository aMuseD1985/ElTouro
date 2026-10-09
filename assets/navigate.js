/* ElTouro ride mode: turn-by-turn navigation with voice prompts along the saved track, a time-lapse simulation,
   and – only if the rider switches it on – recording of the ride (track.php). */
(function () {
  'use strict';
  var el = document.getElementById('ride');
  if (!el || !window.maplibregl) return;
  var d = el.dataset;
  var T = JSON.parse(d.texts || '{}');
  var SIM = d.sim === '1';
  var rideStartedAt = Date.now();
  var LOCALE = d.lang === 'de' ? 'de-DE' : 'en-GB';
  var CRUISE_KMH = 18;          // planning speed of an e-scooter ride incl. lights and corners
  var FAST_KMH = 22;            // the speed display blinks red from here – a hint, no announcement, no consequence

  // ---------------------------------------------------------------- track geometry
  var gj = JSON.parse(d.geojson);
  var line = [];                // [lat, lng] of all features in order – guidance indices count the same way
  gj.features.forEach(function (f) { f.geometry.coordinates.forEach(function (c) { line.push([c[1], c[0]]); }); });
  var n = line.length;

  function dist(a, b) {
    var r = 6371008.8, rad = Math.PI / 180;
    var dp = (b[0] - a[0]) * rad, dl = (b[1] - a[1]) * rad;
    var x = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a[0] * rad) * Math.cos(b[0] * rad) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(x)));
  }
  function bearing(a, b) {
    var rad = Math.PI / 180;
    var y = Math.sin((b[1] - a[1]) * rad) * Math.cos(b[0] * rad);
    var x = Math.cos(a[0] * rad) * Math.sin(b[0] * rad) - Math.sin(a[0] * rad) * Math.cos(b[0] * rad) * Math.cos((b[1] - a[1]) * rad);
    return (Math.atan2(y, x) / rad + 360) % 360;
  }
  var cum = [0];
  for (var i = 1; i < n; i++) cum[i] = cum[i - 1] + dist(line[i - 1], line[i]);
  var total = cum[n - 1] || 1;

  // Point at a distance along the track, and the segment it lies on
  function segAt(s) {
    var lo = 0, hi = n - 1;
    while (hi - lo > 1) { var mid = (lo + hi) >> 1; if (cum[mid] <= s) lo = mid; else hi = mid; }
    return lo;
  }
  function pointAt(s) {
    s = Math.max(0, Math.min(total, s));
    var k = segAt(s), len = cum[k + 1] - cum[k];
    var r = len > 0 ? (s - cum[k]) / len : 0;
    return [line[k][0] + (line[k + 1][0] - line[k][0]) * r, line[k][1] + (line[k + 1][1] - line[k][1]) * r];
  }

  // ---------------------------------------------------------------- manoeuvres
  // BRouter VoiceHint commands -> our manoeuvre names (see GUIDANCE_COMMANDS in tours_lib.php)
  var CMD = { 2: 'left', 3: 'slight_left', 4: 'sharp_left', 5: 'right', 6: 'slight_right', 7: 'sharp_right', 8: 'keep_left',
              9: 'keep_right', 10: 'uturn', 11: 'uturn', 13: 'roundabout', 14: 'roundabout', 15: 'uturn', 17: 'exit_left', 18: 'exit_right' };
  var ARROW = { left: -90, slight_left: -45, sharp_left: -135, right: 90, slight_right: 45, sharp_right: 135, keep_left: -20,
                keep_right: 20, uturn: 180, exit_left: -45, exit_right: 45, straight: 0 };

  var hints = [];
  try {
    JSON.parse(d.guidance || '[]').forEach(function (g) {
      if (CMD[g[1]] && g[0] < n) hints.push({ at: cum[g[0]], kind: CMD[g[1]], exit: g[2] });
    });
  } catch (e) { hints = []; }

  // Tours without BRouter instructions (older tours, freehand): find the turns in the geometry itself
  if (!hints.length) {
    var last = -1e9;
    for (var j = 1; j < n - 1; j++) {
      if (cum[j] < 15 || total - cum[j] < 15) continue;
      var turn = (bearing(pointAt(cum[j]), pointAt(cum[j] + 20)) - bearing(pointAt(cum[j] - 20), pointAt(cum[j])) + 540) % 360 - 180;
      var a = Math.abs(turn);
      if (a < 35 || cum[j] - last < 30) continue;
      var side = turn > 0 ? 'right' : 'left';
      hints.push({ at: cum[j], kind: a >= 150 ? 'uturn' : a >= 110 ? 'sharp_' + side : a >= 55 ? side : 'slight_' + side, exit: 0 });
      last = cum[j];
    }
  }
  // Planned stops are announced like manoeuvres
  var STOP_ICON = { charge: '⚡', food: '🍽', break: '☕', sight: '👁' };
  function alongOf(p) {
    var best = Infinity, at = 0, kx = Math.cos(p[0] * Math.PI / 180) * 111320, ky = 110540;
    for (var s = 0; s < n - 1; s++) {
      var ax = line[s][1] * kx, ay = line[s][0] * ky, vx = line[s + 1][1] * kx - ax, vy = line[s + 1][0] * ky - ay;
      var l2 = vx * vx + vy * vy, r = l2 > 0 ? Math.max(0, Math.min(1, ((p[1] * kx - ax) * vx + (p[0] * ky - ay) * vy) / l2)) : 0;
      var dd = Math.hypot(ax + vx * r - p[1] * kx, ay + vy * r - p[0] * ky);
      if (dd < best) { best = dd; at = cum[s] + (cum[s + 1] - cum[s]) * r; }
    }
    return at;
  }
  try {
    JSON.parse(d.stops || '[]').forEach(function (s) {
      hints.push({ at: alongOf([s.lat, s.lng]), kind: 'stop', stop: s.type, name: s.name, exit: 0 });
    });
  } catch (e) { /* no stops */ }
  hints.sort(function (x, y) { return x.at - y.at; });

  // ---------------------------------------------------------------- map (MapLibre: it can turn and tilt, Leaflet can't)
  maplibregl.setWorkerUrl('/assets/vendor/maplibre/maplibre-gl-csp-worker.js');
  var lngLats = line.map(function (p) { return [p[1], p[0]]; });
  var bounds = lngLats.reduce(function (b, c) {
    return [[Math.min(b[0][0], c[0]), Math.min(b[0][1], c[1])], [Math.max(b[1][0], c[0]), Math.max(b[1][1], c[1])]];
  }, [[180, 90], [-180, -90]]);
  // Map style (standard, dark, …) from map_styles.js; "auto" follows the sun at the start of the route
  var styles = ElTouroMaps.maplibre(document.getElementById('ride'), [line[0][0], line[0][1]]);
  var map = new maplibregl.Map({
    container: 'ride-map',
    style: styles.style(),
    bounds: bounds, fitBoundsOptions: { padding: 50 }, maxPitch: 65, attributionControl: { compact: true }
  });
  styles.attach(map);
  var ready = false;
  map.on('load', function () {
    var round = { 'line-cap': 'round', 'line-join': 'round' };
    map.addSource('route', { type: 'geojson', data: gj });
    map.addLayer({ id: 'route-casing', type: 'line', source: 'route', layout: round, paint: { 'line-color': '#14263F', 'line-width': 8, 'line-opacity': 0.45 } });
    map.addLayer({ id: 'route', type: 'line', source: 'route', layout: round, filter: ['!=', ['get', 'freehand'], true],
                   paint: { 'line-color': '#E6BE62', 'line-width': 5 } });   // ahead: sand yellow
    map.addLayer({ id: 'route-free', type: 'line', source: 'route', filter: ['==', ['get', 'freehand'], true],
                   paint: { 'line-color': '#A3261B', 'line-width': 5, 'line-dasharray': [1.5, 1.5] } });
    map.addSource('done', { type: 'geojson', data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } } });
    map.addLayer({ id: 'done', type: 'line', source: 'done', layout: round, paint: { 'line-color': '#3779B8', 'line-width': 5 } });   // ridden: blue
    ready = true;
  });

  // Planned stops as small pins
  try {
    JSON.parse(d.stops || '[]').forEach(function (st) {
      var e = document.createElement('div');
      e.className = 'poi-pin poi-' + st.type;
      e.textContent = { charge: '⚡', food: '🍽', break: '☕', sight: '👁' }[st.type] || '•';
      e.title = st.name;
      new maplibregl.Marker({ element: e }).setLngLat([st.lng, st.lat]).addTo(map);
    });
  } catch (e) { /* no stops */ }

  // The rider is the mascot (assets/img/mascot): with the map turned we look over his shoulder (from behind, leaning
  // into bends); north up we see him from the side the riding direction shows – always with his sunglasses.
  var riderEl = document.createElement('div');
  riderEl.className = 'ride-rider';
  var riderImg = document.createElement('img');
  riderImg.alt = '';
  riderEl.appendChild(riderImg);
  var rider = null, riderSprite = '';
  // Which side of the mascot we see depends on his direction on the SCREEN: riding direction minus the map's rotation.
  // So it also fits when the rider turns the map by hand or the camera lags behind a turn.
  function spriteFor(heading, lean) {
    var rel = (heading - map.getBearing() + 720) % 360;          // 0 = up the screen (we see his back), 90 = to the right
    if (rel > 180) rel -= 360;                                    // -180…180, negative = to the left
    if (view !== 'north' && Math.abs(rel) < 22) {                 // camera follows him: only the upcoming bend leans him
      return lean > 18 ? 'rear-right' : lean < -18 ? 'rear-left' : 'rear';
    }
    var a = Math.abs(rel), left = rel < 0;
    if (a < 22) return 'rear';
    if (a < 67) return left ? 'rear-left' : 'rear-right';
    if (a < 120) return left ? 'side-left' : 'side';
    return 'front';
  }
  function placeRider(pos, heading, lean) {
    var sprite = spriteFor(heading, lean || 0);
    if (sprite !== riderSprite) { riderSprite = sprite; riderImg.src = '/assets/img/mascot/' + sprite + '.webp?v=2'; }
    if (!rider) rider = new maplibregl.Marker({ element: riderEl, anchor: 'bottom', rotationAlignment: 'viewport', pitchAlignment: 'viewport' })
      .setLngLat([pos[1], pos[0]]).addTo(map);
    rider.setLngLat([pos[1], pos[0]]);
  }

  // Views: north up, in riding direction, 3D (tilted, riding direction). The rider sits in the lower third when it turns.
  var VIEWS = ['heading', '3d', 'north'];
  var view = 'heading';
  try { if (VIEWS.indexOf(localStorage.getItem('eltouro.view')) >= 0) view = localStorage.getItem('eltouro.view'); } catch (e) { /* ignore */ }
  var follow = true;
  ['dragstart', 'rotatestart', 'pitchstart'].forEach(function (ev) {
    map.on(ev, function (e) { if (e.originalEvent) follow = false; });   // only the rider's own gestures, not our camera moves
  });
  function camera(pos, heading, instant) {
    if (!follow) return;
    var h = el.clientHeight;
    var opts = { center: [pos[1], pos[0]], duration: instant ? 0 : (SIM ? 240 : 900), easing: function (t) { return t; }, essential: true };
    if (view === 'north') {
      opts.bearing = 0; opts.pitch = 0; opts.zoom = Math.max(map.getZoom(), 16);
      opts.padding = { top: 0, bottom: ((document.querySelector('.ride-panel') || {}).offsetHeight || 0), left: 0, right: 0 };
    } else {
      opts.bearing = heading; opts.pitch = view === '3d' ? 60 : 0;
      opts.zoom = view === '3d' ? 17.5 : Math.max(map.getZoom(), 16.5);
      // keep the rider above the control panel, in the lower part of the free map area
      var panel = (document.querySelector('.ride-panel') || {}).offsetHeight || 0;
      opts.padding = { top: Math.round(h * (view === '3d' ? 0.32 : 0.25)), bottom: panel + 20, left: 0, right: 0 };
    }
    map.easeTo(opts);
  }
  var lastPos = null, lastHeading = 0, lastLean = 0;
  // Turning the map by hand changes how we look at him
  map.on('rotate', function () { if (lastPos && rider) placeRider(lastPos, lastHeading, lastLean); });

  // ---------------------------------------------------------------- UI helpers
  var ui = {
    arrow: document.querySelector('#ride-arrow span'), dist: document.getElementById('ride-dist'), text: document.getElementById('ride-text'),
    remaining: document.getElementById('ride-remaining'), eta: document.getElementById('ride-eta'), speed: document.getElementById('ride-speed'),
    subtitle: document.getElementById('ride-subtitle'), start: document.getElementById('ride-start'), stop: document.getElementById('ride-stop'),
    voice: document.getElementById('ride-voice'), follow: document.getElementById('ride-follow'), record: document.getElementById('ride-record'),
    factor: document.getElementById('ride-factor'), done: document.getElementById('ride-done'), view: document.getElementById('ride-view'),
    live: document.getElementById('ride-live'), liveScope: document.getElementById('ride-live-scope')
  };
  function fmt(x, digits) { return x.toLocaleString(LOCALE, { minimumFractionDigits: digits, maximumFractionDigits: digits }); }
  function spokenDistance(m) {
    if (m >= 1000) return T.in_km.replace('{n}', fmt(Math.round(m / 100) / 10, 1));
    return T.in_m.replace('{n}', m >= 300 ? Math.round(m / 100) * 100 : m >= 100 ? Math.round(m / 50) * 50 : Math.max(10, Math.round(m / 10) * 10));
  }
  function shownDistance(m) { return m >= 1000 ? fmt(m / 1000, 1) + ' km' : (m >= 100 ? Math.round(m / 10) * 10 : Math.round(m)) + ' m'; }

  // Recorded bull voice if clips exist, the device voice otherwise (assets/voice.js)
  var voice = ElTouroVoice.create({ lang: d.lang, locale: LOCALE });
  var subtitleTimer = null;
  // queue: wait for the current prompt instead of cutting it off (a stop right after a turn); cue: sound played first
  function say(text, queue, cue) {
    ui.subtitle.textContent = text;
    ui.subtitle.hidden = false;
    clearTimeout(subtitleTimer);
    subtitleTimer = setTimeout(function () { ui.subtitle.hidden = true; }, 5000);
    voice.say(text, queue, cue);
  }
  function hintText(h, prefix) {
    if (h.kind === 'stop') {
      var key = prefix === 'now' ? 'now_stop_' + h.stop : prefix + '_stop';
      return (T[key] || '').replace('{name}', h.name);
    }
    var t = T[prefix + '_' + h.kind] || T[prefix + '_straight'];
    return t.replace('{n}', h.exit || 1);
  }

  // ---------------------------------------------------------------- following the track
  var state = { along: 0, seg: 0, off: 0, offRoute: false, halfway: false, arrived: false, lastSpeedWarn: 0, running: false };
  if (/[?&]debug=1/.test(location.search)) window.__nav = { hints: hints, state: state, total: total, voice: voice };   // for testing only

  // Nearest point of the track to p, searched around the last position (a loop crosses itself – don't jump ahead)
  function project(p, wide) {
    var from = wide ? 0 : Math.max(0, state.seg - 30), to = wide ? n - 2 : Math.min(n - 2, state.seg + 300);
    var best = { d: Infinity, seg: state.seg, along: state.along };
    var kx = Math.cos(p[0] * Math.PI / 180) * 111320, ky = 110540;
    for (var s = from; s <= to; s++) {
      var ax = line[s][1] * kx, ay = line[s][0] * ky, bx = line[s + 1][1] * kx, by = line[s + 1][0] * ky, px = p[1] * kx, py = p[0] * ky;
      var vx = bx - ax, vy = by - ay, l2 = vx * vx + vy * vy;
      var r = l2 > 0 ? Math.max(0, Math.min(1, ((px - ax) * vx + (py - ay) * vy) / l2)) : 0;
      var qx = ax + vx * r - px, qy = ay + vy * r - py, dd = Math.sqrt(qx * qx + qy * qy);
      if (dd < best.d) best = { d: dd, seg: s, along: cum[s] + (cum[s + 1] - cum[s]) * r };
    }
    return best;
  }

  // Off the track: ask the router for the way back to the track a little further on and ride on along that way
  var rerouting = false, lastReroute = 0;
  function reroute(p) {
    if (rerouting || SIM || Date.now() - lastReroute < 25000 || navigator.onLine === false) return;
    rerouting = true; lastReroute = Date.now();
    var w = project(p, true);
    var targetAlong = total - w.along < 350 ? total : w.along + 220;
    var target = pointAt(targetAlong);
    fetch('/api/route', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf },
      body: JSON.stringify({ points: [[p[0], p[1]], [target[0], target[1]]], rule_set: d.rules || 'ekfv', vehicle: parseInt(d.vehicle, 10) || 2, avoid_uturns: false, stops: [] }) })
      .then(function (r) { if (!r.ok) throw new Error('route'); return r.json(); })
      .then(function (j) {
        var detour = [], allFree = true;
        (j.geojson.features || []).forEach(function (f) {
          if (!(f.properties && f.properties.freehand)) allFree = false;
          f.geometry.coordinates.forEach(function (c) { detour.push([c[1], c[0]]); });
        });
        if (detour.length < 2 || allFree) throw new Error('no way');
        var keep = hints.filter(function (h) { return h.at > targetAlong + 5; });
        var restFrom = Math.min(n - 1, segAt(targetAlong) + 1);
        var oldAlong = targetAlong;
        line = detour.concat(targetAlong >= total ? [] : line.slice(restFrom));
        n = line.length;
        cum = [0];
        for (var q = 1; q < n; q++) cum[q] = cum[q - 1] + dist(line[q - 1], line[q]);
        total = cum[n - 1] || 1;
        var detourLen = cum[detour.length - 1];
        keep.forEach(function (h) { h.at = detourLen + (h.at - oldAlong); });
        var fresh = [];
        (j.guidance || []).forEach(function (g) { if (CMD[g[1]] && g[0] < detour.length) fresh.push({ at: cum[g[0]], kind: CMD[g[1]], exit: g[2] }); });
        hints = fresh.concat(keep).sort(function (x, y) { return x.at - y.at; });
        state.along = 0; state.seg = 0; state.off = 0; state.offRoute = false;
        var src = map.getSource('route');
        if (src) src.setData({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: line.map(function (c) { return [c[1], c[0]]; }) } });
        var done = map.getSource('done');
        if (done) done.setData({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } });
        say(T.rerouted, true);
      })
      .catch(function () { /* stays as it was: the rider is asked to head back to the line */ })
      .then(function () { rerouting = false; });
  }

  function onFix(fix) {
    if (!state.running) return;
    var p = [fix.lat, fix.lng];
    var m = project(p, false);
    if (m.d > 60) { var w = project(p, true); if (w.d < m.d) m = w; }   // after a long detour
    var limit = Math.max(35, Math.min(80, (fix.acc || 10) * 1.5));
    if (m.d > limit) {
      if (++state.off >= 3 && !state.offRoute) { state.offRoute = true; say(T.offroute, false, 'offroute'); }
      if (state.offRoute) reroute(p);
    } else {
      if (state.offRoute) say(T.back, false, 'back');
      state.off = 0; state.offRoute = false;
      state.along = m.along; state.seg = m.seg;
    }

    // Off the track the GPS heading (if the device has one) is better than the track's direction
    var heading = state.offRoute && fix.heading != null && !isNaN(fix.heading) ? fix.heading : bearing(pointAt(state.along), pointAt(state.along + 15));
    var shown = state.offRoute ? p : pointAt(state.along);
    // How the track bends in the next metres – the mascot leans into it
    var lean = state.offRoute ? 0 : (bearing(pointAt(state.along), pointAt(state.along + 30)) - bearing(pointAt(state.along - 15), pointAt(state.along)) + 540) % 360 - 180;
    lastLean = lean;
    placeRider(shown, heading, lean);
    if (ready) {
      var here = pointAt(state.along);
      map.getSource('done').setData({ type: 'Feature', properties: {},
        geometry: { type: 'LineString', coordinates: lngLats.slice(0, state.seg + 1).concat([[here[1], here[0]]]) } });
    }
    camera(shown, heading, false);
    lastPos = shown; lastHeading = heading;
    if (sharing && fix.t - lastShared >= 10000) {
      lastShared = fix.t;
      api('/api/live', { action: 'update', lat: +fix.lat.toFixed(5), lng: +fix.lng.toFixed(5), heading: Math.round(heading),
                         speed: fix.speedMs != null ? +(fix.speedMs * 3.6).toFixed(1) : null, scope: ui.liveScope.value,
                         tour_id: parseInt(d.tour, 10) }).catch(function () { /* next round */ });
    }

    var speedMs = fix.speedMs != null ? fix.speedMs : CRUISE_KMH / 3.6;
    guide(speedMs, fix);
    stats(fix);
    if (record) record.add(fix);
  }

  function guide(speedMs, fix) {
    var left = total - state.along;
    if (!state.arrived && left < 25) { state.arrived = true; say(T.arrive, false, 'arrive'); finish(true); return; }
    if (!state.halfway && state.along > total / 2 && total > 4000) {
      state.halfway = true;
      say(T.halfway.replace('{km}', Math.max(1, Math.round(left / 1000))), false, 'halfway');
    }
    var next = null, nextIdx = -1;
    for (var k = 0; k < hints.length; k++) if (hints[k].at > state.along + 3) { next = hints[k]; nextIdx = k; break; }
    // Announce by time, not distance – that works at 20 km/h and in the time-lapse alike
    var v = Math.max(speedMs, 2);
    if (next) {
      var togo = next.at - state.along;
      ui.arrow.textContent = next.kind === 'stop' ? STOP_ICON[next.stop] : next.kind === 'roundabout' ? '⟳' : '⬆';
      ui.arrow.style.transform = next.kind === 'roundabout' || next.kind === 'stop' ? 'none' : 'rotate(' + (ARROW[next.kind] || 0) + 'deg)';
      ui.dist.textContent = shownDistance(togo);
      ui.text.textContent = hintText(next, 'label');
      if (!next.saidFar && togo > Math.max(60, 9 * v) && togo <= Math.max(160, 25 * v)) {
        next.saidFar = true;
        coverFollowing(nextIdx, 'saidFar');
        say(hintText(next, 'far').replace('{d}', spokenDistance(togo)), next.kind === 'stop');
      } else if (!next.saidNow && togo <= Math.max(30, 6 * v)) {
        next.saidNow = next.saidFar = true;
        coverFollowing(nextIdx, 'saidNow');
        say(hintText(next, 'now'), next.kind === 'stop', next.kind === 'stop' ? 'stop' : 'turn');
      }
    } else {
      ui.arrow.textContent = '🏁'; ui.arrow.style.transform = 'none';
      ui.dist.textContent = shownDistance(left);
      ui.text.textContent = T.label_straight;
    }
    // Stops get their early notice even while a turn comes first – the rider wants to know a few hundred metres ahead
    for (var q = 0; q < hints.length; q++) {
      var h = hints[q], ahead = h.at - state.along;
      if (h.kind !== 'stop') continue;
      if (ahead > Math.max(450, 70 * v)) break;
      if (!h.saidFar && ahead > 100) {
        h.saidFar = true;
        say(hintText(h, 'far').replace('{d}', spokenDistance(ahead)), true);
      }
      // ...and are announced on arrival even when a turn sits on the same spot (a via point at the end of a cul-de-sac)
      if (!h.saidNow && ahead <= Math.max(30, 6 * v) && ahead > -50) {
        h.saidNow = h.saidFar = true;
        say(hintText(h, 'now'), true, 'stop');
      }
    }
  }

  // Manoeuvres a few metres apart (two lefts across a junction) are one prompt for the rider, not two
  function coverFollowing(idx, flag) {
    for (var k = idx + 1; k < hints.length && hints[k].at - hints[idx].at < 25; k++) {
      if (hints[k].kind === 'stop' || hints[idx].kind === 'stop') continue;   // a stop always gets its own prompt
      hints[k][flag] = true;
      if (flag === 'saidNow') hints[k].saidFar = true;
    }
  }

  function stats(fix) {
    var left = total - state.along;
    ui.remaining.textContent = fmt(left / 1000, 1) + ' km';
    var minutes = Math.round(left / 1000 / CRUISE_KMH * 60);
    ui.eta.textContent = minutes >= 60 ? T.eta_h.replace('{h}', Math.floor(minutes / 60)).replace('{m}', ('0' + minutes % 60).slice(-2)) : T.eta_min.replace('{m}', minutes);
    if (!SIM) {
      ui.speed.textContent = fix.speedMs != null ? Math.round(fix.speedMs * 3.6) + ' ' + T.speed_unit : '–';
      ui.speed.classList.toggle('speed-hot', fix.speedMs != null && fix.speedMs * 3.6 >= FAST_KMH);
    }
  }

  // ---------------------------------------------------------------- sources: GPS or simulation
  var watchId = null, simTimer = null, wakeLock = null;

  function keepAwake() {
    if (!('wakeLock' in navigator)) return;
    navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && state.running) keepAwake();
    if (document.visibilityState === 'hidden' && record) record.flush(true);
  });

  function startGps() {
    if (!navigator.geolocation) { say(T.gps_error); return false; }
    ui.text.textContent = T.gps_wait;
    watchId = navigator.geolocation.watchPosition(function (pos) {
      var c = pos.coords;
      onFix({ lat: c.latitude, lng: c.longitude, acc: c.accuracy, speedMs: c.speed != null && !isNaN(c.speed) ? c.speed : null,
              heading: c.heading, t: pos.timestamp || Date.now() });
    }, function (err) {
      ui.text.textContent = err.code === 1 ? T.gps_denied : T.gps_error;
    }, { enableHighAccuracy: true, maximumAge: 1000, timeout: 20000 });
    return true;
  }

  function startSim() {
    var factor = parseInt(ui.factor.value, 10) || 30, along = 0, simSeconds = 0, lastTick = Date.now();
    ui.factor.addEventListener('change', function () { factor = parseInt(ui.factor.value, 10) || 30; });
    simTimer = setInterval(function () {
      var now = Date.now(), dt = (now - lastTick) / 1000 * factor;
      lastTick = now;
      simSeconds += dt;
      along = Math.min(total, along + CRUISE_KMH / 3.6 * dt);
      var p = pointAt(along);
      // speed reported to the guidance is the time-lapse speed, so prompts keep their spacing in real seconds
      onFix({ lat: p[0], lng: p[1], acc: 5, speedMs: CRUISE_KMH / 3.6 * factor, t: now });
      var h = Math.floor(simSeconds / 3600), mi = Math.floor(simSeconds % 3600 / 60);
      ui.speed.textContent = T.sim_clock.replace('{t}', h + ':' + ('0' + mi).slice(-2));
    }, 250);
  }

  // ---------------------------------------------------------------- live: share my position, see the others
  var sharing = false, lastShared = 0, others = {};
  function stopSharing() {
    if (!sharing) return;
    sharing = false;
    api('/api/live', { action: 'stop' }, true).catch(function () { /* the server forgets us after two minutes anyway */ });
  }
  window.addEventListener('pagehide', stopSharing);

  function otherMarker(r) {
    var box = document.createElement('div');
    box.className = 'live-rider';
    var img = document.createElement('img'); img.src = '/assets/img/mascot/front.webp?v=2'; img.alt = '';
    var label = document.createElement('span');
    box.appendChild(img); box.appendChild(label);
    return { el: box, label: label, marker: new maplibregl.Marker({ element: box, anchor: 'bottom' }) };
  }
  function loadOthers() {
    if (!ready || document.visibilityState !== 'visible') return;
    // A fixed area of about 15 km around the map centre – the visible part of a tilted map is no good measure
    var c = lastPos ? { lat: lastPos[0], lng: lastPos[1] } : map.getCenter();
    var box = [c.lat - 0.14, c.lng - 0.22, c.lat + 0.14, c.lng + 0.22];
    fetch('/api/live?box=' + box.map(function (x) { return x.toFixed(4); }).join(','), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { riders: [] }; })
      .then(function (j) {
        var seen = {};
        j.riders.forEach(function (r) {
          seen[r.id] = true;
          var o = others[r.id] || (others[r.id] = otherMarker(r));
          o.label.textContent = r.name + (r.crew ? ' · ' + r.crew : '');
          o.marker.setLngLat([r.lng, r.lat]).addTo(map);
        });
        Object.keys(others).forEach(function (id) { if (!seen[id]) { others[id].marker.remove(); delete others[id]; } });
      }).catch(function () { /* next round */ });
  }
  map.on('load', loadOthers);
  setInterval(loadOthers, 10000);

  // ---------------------------------------------------------------- recording (opt-in, GPS only)
  var record = null;
  function api(url, body, keepalive) {
    if (typeof url !== 'string') { keepalive = body; body = url; url = '/api/track'; }
    return fetch(url, { method: 'POST', credentials: 'same-origin', keepalive: !!keepalive,
      headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf }, body: JSON.stringify(body) })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  function Recorder() {
    var self = this, buf = [], session = null, lastP = null, lastT = 0, timer = null;
    this.ready = api({ action: 'start', tour_id: parseInt(d.tour, 10) }).then(function (j) { session = j.session_id; });
    this.add = function (fix) {
      var p = [fix.lat, fix.lng];
      // at most one point every 2 s, and only when we have moved – standing at lights fills nothing
      if (fix.t - lastT < 2000 || (lastP && dist(lastP, p) < 5)) return;
      lastP = p; lastT = fix.t;
      buf.push([Math.round(fix.t), +fix.lat.toFixed(6), +fix.lng.toFixed(6), Math.round(fix.acc || 0), fix.speedMs != null ? +(fix.speedMs * 3.6).toFixed(1) : null]);
      if (buf.length >= 30) self.flush(false);
    };
    this.flush = function (keepalive) {
      if (!session || !buf.length) return Promise.resolve();
      var batch = buf.splice(0, buf.length);
      return api({ action: 'points', session_id: session, points: batch }, keepalive).catch(function () { buf = batch.concat(buf); });
    };
    this.finish = function () {
      clearInterval(timer);
      return self.ready.then(function () { return self.flush(false); }).then(function () { return api({ action: 'finish', session_id: session }); });
    };
    timer = setInterval(function () { self.flush(false); }, 15000);
  }

  // ---------------------------------------------------------------- start / stop
  try { if (ui.record && localStorage.getItem('eltouro.record') === '1') ui.record.checked = true; } catch (e) { /* private mode */ }
  try { if (ui.liveScope && localStorage.getItem('eltouro.liveScope')) ui.liveScope.value = localStorage.getItem('eltouro.liveScope'); } catch (e) { /* ignore */ }
  if (ui.liveScope) ui.liveScope.addEventListener('change', function () {
    try { localStorage.setItem('eltouro.liveScope', ui.liveScope.value); } catch (e) { /* ignore */ }
  });
  if (ui.record) ui.record.addEventListener('change', function () {
    try { localStorage.setItem('eltouro.record', ui.record.checked ? '1' : '0'); } catch (e) { /* ignore */ }
  });

  ui.start.addEventListener('click', function () {
    state.running = true;
    ui.start.hidden = true;
    ui.stop.hidden = false;
    if (ui.record) ui.record.disabled = true;
    keepAwake();
    follow = true;
    // The first utterance must come from this click – browsers (iOS in particular) only allow speech after a gesture
    rideStartedAt = Date.now();
    voice.unlock();
    say((SIM ? T.start_sim : T.start).replace('{km}', Math.max(1, Math.round(total / 1000))), false, 'start');
    if (!hints.length) say(T.no_hints, true);
    if (SIM) startSim();
    else {
      if (ui.record && ui.record.checked) record = new Recorder();
      // Live sharing is asked for on every ride – it is never switched on from an earlier one
      if (ui.live && ui.live.checked) sharing = true;
      if (ui.live) { ui.live.disabled = true; ui.liveScope.disabled = true; }
      startGps();
    }
  });
  // Ending a ride takes a deliberate 2-second press
  (function () {
    var timer = null, HOLD = 2000;
    function start(ev) {
      if (ev.type === 'keydown' && ev.key !== 'Enter' && ev.key !== ' ') return;
      if (ev.type === 'keydown' && ev.repeat) return;
      ev.preventDefault();
      ui.stop.classList.add('holding');
      clearTimeout(timer);
      timer = setTimeout(function () { ui.stop.classList.remove('holding'); finish(false); }, HOLD);
    }
    function cancel() { clearTimeout(timer); ui.stop.classList.remove('holding'); }
    ui.stop.addEventListener('pointerdown', start);
    ['pointerup', 'pointerleave', 'pointercancel', 'blur'].forEach(function (e) { ui.stop.addEventListener(e, cancel); });
    ui.stop.addEventListener('keydown', start);
    ui.stop.addEventListener('keyup', cancel);
    ui.stop.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  })();
  // (i): the explanations about recording and live sharing only when asked for
  var infoBtn = document.getElementById('ride-info-btn'), infoBox = document.getElementById('ride-info');
  if (infoBtn && infoBox) infoBtn.addEventListener('click', function () {
    infoBox.hidden = !infoBox.hidden;
    infoBtn.setAttribute('aria-expanded', infoBox.hidden ? 'false' : 'true');
  });
  ui.voice.addEventListener('click', function () {
    var voiceOn = ui.voice.getAttribute('aria-pressed') !== 'true';
    voice.setOn(voiceOn);
    ui.voice.querySelector('.ico').textContent = voiceOn ? '🔊' : '🔇';
    ui.voice.setAttribute('aria-pressed', voiceOn ? 'true' : 'false');
    ui.voice.title = voiceOn ? T.voice_on : T.voice_off;
  });
  // Cycle through the device's voices (male ones first); the rider hears a sample line with each
  var pickBtn = document.getElementById('ride-voice-pick');
  if (pickBtn) {
    var showPick = function () { pickBtn.hidden = voice.voiceCount() < 2; };
    showPick();
    if (window.speechSynthesis) speechSynthesis.addEventListener('voiceschanged', showPick);
    pickBtn.addEventListener('click', function () {
      var name = voice.cycleVoice();
      if (!name) return;
      if (window.elToast) window.elToast(T.voice_changed.replace('{name}', name));
      say(T.voice_sample);
    });
  }
  ui.follow.addEventListener('click', function () {
    follow = true;
    if (lastPos) camera(lastPos, lastHeading, false);
  });
  function showView() {
    ui.view.querySelector('.ico').textContent = { heading: '➤', '3d': '3D', north: 'N' }[view];
    ui.view.title = T['view_' + view];
    ui.view.setAttribute('aria-label', T['view_' + view]);
  }
  showView();
  ui.view.addEventListener('click', function () {
    view = VIEWS[(VIEWS.indexOf(view) + 1) % VIEWS.length];
    try { localStorage.setItem('eltouro.view', view); } catch (e) { /* ignore */ }
    showView();
    follow = true;
    if (lastPos) camera(lastPos, lastHeading, false);
    else map.easeTo({ pitch: view === '3d' ? 60 : 0, bearing: 0 });
  });

  var styleBtn = document.getElementById('ride-style');
  if (styleBtn && styles.multiple) {
    styleBtn.addEventListener('click', function () {
      var text = styles.next();
      if (window.elToast) window.elToast(text);
    });
  } else if (styleBtn) {
    styleBtn.hidden = true;
  }

  // Leaving while the ride runs asks first – a stray tap on ✕ must not end the ride
  var closeLink = document.getElementById('ride-close'), confirmBox = document.getElementById('ride-confirm');
  if (closeLink && confirmBox) {
    closeLink.addEventListener('click', function (ev) {
      if (!state.running) return;
      ev.preventDefault();
      confirmBox.hidden = false;
      document.getElementById('ride-confirm-stay').focus();
    });
    document.getElementById('ride-confirm-stay').addEventListener('click', function () { confirmBox.hidden = true; });
    document.getElementById('ride-confirm-end').addEventListener('click', function () { confirmBox.hidden = true; finish(false); });
  }
  window.addEventListener('beforeunload', function (ev) {
    if (!state.running) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // First time here: a short tour of the buttons
  var introBox = document.getElementById('ride-intro');
  if (introBox) {
    var seen = false;
    try { seen = localStorage.getItem('eltouro.rideIntro') === '1'; } catch (e) { /* ignore */ }
    if (!seen) {
      introBox.hidden = false;
      document.getElementById('ride-intro-ok').addEventListener('click', function () {
        introBox.hidden = true;
        try { localStorage.setItem('eltouro.rideIntro', '1'); } catch (e) { /* ignore */ }
      });
    }
  }

  // After the ride: share it, post it in a crew's talk (only for real rides, the simulation is no ride)
  function showAfter(minutes) {
    var box = document.getElementById('ride-after');
    if (!box || SIM) return;
    box.hidden = false;
    var msg = document.getElementById('after-msg');
    var km = Math.max(0, state.along / 1000);
    document.getElementById('after-share').addEventListener('click', function () {
      var text = T.share_text.replace('{km}', fmt(km, 1));
      if (navigator.share) {
        navigator.share({ title: 'ElTouro', text: text, url: d.invite + '?via=native' }).catch(function () { /* cancelled */ });
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text + ' ' + d.invite + '?via=copy').then(function () { msg.textContent = T.link_copied; });
      }
    });
    var crewSel = document.getElementById('after-crew'), postBox = document.getElementById('after-post');
    function call(payload) {
      return fetch('/api/ride-report', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf },
                                         body: JSON.stringify(payload) }).then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); });
    }
    call({ action: 'crews' }).then(function (x) {
      if (x.status !== 200 || !x.j.crews || !x.j.crews.length) return;
      x.j.crews.forEach(function (c) { var o = document.createElement('option'); o.value = c.slug; o.textContent = c.name; crewSel.appendChild(o); });
      postBox.hidden = false;
    }).catch(function () { /* no crews offered */ });
    document.getElementById('after-post-btn').addEventListener('click', function () {
      var b = this; b.disabled = true;
      call({ action: 'post', crew: crewSel.value, tour_id: parseInt(d.tour, 10), km: Math.round(km * 10) / 10, min: minutes })
        .then(function (x) { msg.textContent = x.status === 200 ? T.posted : (x.status === 429 ? T.post_slow : T.post_error); if (x.status !== 200) b.disabled = false; })
        .catch(function () { msg.textContent = T.post_error; b.disabled = false; });
    });
  }

  function finish(arrived) {
    if (!state.running) return;
    state.running = false;
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    clearInterval(simTimer);
    if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
    stopSharing();
    ui.stop.hidden = true;
    document.getElementById('ride-done-title').textContent = arrived ? T.done_arrived : T.done_title;
    var text = document.getElementById('ride-done-text');
    text.textContent = T.done_text.replace('{km}', fmt(state.along / 1000, 1));
    ui.done.hidden = false;
    showAfter(Math.round((Date.now() - rideStartedAt) / 60000));
    if (record) {
      text.textContent += ' ' + T.saving;
      record.finish().then(function (j) {
        text.textContent = T.done_recorded.replace('{km}', fmt(j.distance_m / 1000, 1)).replace('{min}', Math.round(j.moving_s / 60))
                                          .replace('{max}', fmt(j.max_speed_kmh, 1));
        if (j.session_id && j.distance_m >= 100) {
          var a = document.getElementById('ride-view-drive');
          if (a) { a.href = '/drive/' + j.session_id + '?done=1'; a.hidden = false; }
        }
        if (window.ElTouroConfetti) window.ElTouroConfetti.burst();
      }).catch(function () { text.textContent = T.done_not_recorded; });
      record = null;
    }
  }
})();
