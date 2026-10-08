/* ElTouro ride mode: turn-by-turn navigation with voice prompts along the saved track, a time-lapse simulation,
   and – only if the rider switches it on – recording of the ride (track.php). */
(function () {
  'use strict';
  var el = document.getElementById('ride');
  if (!el || !window.L) return;
  var d = el.dataset;
  var T = JSON.parse(d.texts || '{}');
  var SIM = d.sim === '1';
  var LOCALE = d.lang === 'de' ? 'de-DE' : 'en-GB';
  var CRUISE_KMH = 18;          // planning speed of an e-scooter ride incl. lights and corners
  var LEGAL_KMH = 20;

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
  hints.sort(function (x, y) { return x.at - y.at; });

  // ---------------------------------------------------------------- map
  var map = L.map('ride-map', { zoomControl: false, attributionControl: true });
  map.fitBounds(L.geoJSON(gj).getBounds(), { padding: [40, 40] });   // view first, then layers (Leaflet _clipPoints)
  L.tileLayer(d.tiles, { maxZoom: 19, attribution: d.attribution }).addTo(map);
  L.geoJSON(gj, { style: function (f) {
    return f.properties && f.properties.freehand ? { color: '#A3261B', weight: 7, opacity: 0.85, dashArray: '8 8' } : { color: '#2F5E8C', weight: 7, opacity: 0.85 };
  } }).addTo(map);
  var doneLine = L.polyline([], { color: '#8A96A8', weight: 7, opacity: 0.9 }).addTo(map);
  var rider = null;
  var follow = true;
  map.on('dragstart', function () { follow = false; });

  function riderIcon(heading) {
    // The bull faces right (east); heading west it is mirrored instead of standing on its head
    var west = heading > 180;
    var rot = west ? heading + 90 : heading - 90;
    return L.divIcon({ className: '', iconSize: [54, 54], iconAnchor: [27, 40],
      html: '<div class="ride-rider" style="transform: rotate(' + rot.toFixed(0) + 'deg)' + (west ? ' scaleX(-1)' : '') + '">'
          + '<img src="/assets/img/scooter-bull.svg" alt=""></div>' });
  }

  // ---------------------------------------------------------------- UI helpers
  var ui = {
    arrow: document.querySelector('#ride-arrow span'), dist: document.getElementById('ride-dist'), text: document.getElementById('ride-text'),
    remaining: document.getElementById('ride-remaining'), eta: document.getElementById('ride-eta'), speed: document.getElementById('ride-speed'),
    subtitle: document.getElementById('ride-subtitle'), start: document.getElementById('ride-start'), stop: document.getElementById('ride-stop'),
    voice: document.getElementById('ride-voice'), follow: document.getElementById('ride-follow'), record: document.getElementById('ride-record'),
    factor: document.getElementById('ride-factor'), done: document.getElementById('ride-done')
  };
  function fmt(x, digits) { return x.toLocaleString(LOCALE, { minimumFractionDigits: digits, maximumFractionDigits: digits }); }
  function spokenDistance(m) {
    if (m >= 1000) return T.in_km.replace('{n}', fmt(Math.round(m / 100) / 10, 1));
    return T.in_m.replace('{n}', m >= 300 ? Math.round(m / 100) * 100 : m >= 100 ? Math.round(m / 50) * 50 : Math.max(10, Math.round(m / 10) * 10));
  }
  function shownDistance(m) { return m >= 1000 ? fmt(m / 1000, 1) + ' km' : (m >= 100 ? Math.round(m / 10) * 10 : Math.round(m)) + ' m'; }

  var voiceOn = true;
  var voice = null;
  function pickVoice() {
    if (voice || !window.speechSynthesis) return voice;
    var vs = speechSynthesis.getVoices().filter(function (v) { return v.lang && v.lang.toLowerCase().indexOf(d.lang) === 0; });
    voice = vs.filter(function (v) { return /premium|enhanced|neural|google/i.test(v.name); })[0] || vs[0] || null;
    return voice;
  }
  if (window.speechSynthesis) speechSynthesis.onvoiceschanged = function () { voice = null; pickVoice(); };
  var subtitleTimer = null;
  function say(text) {
    ui.subtitle.textContent = text;
    ui.subtitle.hidden = false;
    clearTimeout(subtitleTimer);
    subtitleTimer = setTimeout(function () { ui.subtitle.hidden = true; }, 5000);
    if (!voiceOn || !window.speechSynthesis) return;
    speechSynthesis.cancel();
    var u = new SpeechSynthesisUtterance(text);
    u.lang = LOCALE;
    var v = pickVoice();
    if (v) u.voice = v;
    u.rate = 1.05;
    speechSynthesis.speak(u);
  }
  function hintText(h, prefix) {
    var t = T[prefix + '_' + h.kind] || T[prefix + '_straight'];
    return t.replace('{n}', h.exit || 1);
  }

  // ---------------------------------------------------------------- following the track
  var state = { along: 0, seg: 0, off: 0, offRoute: false, halfway: false, arrived: false, lastSpeedWarn: 0, running: false };

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

  function onFix(fix) {
    if (!state.running) return;
    var p = [fix.lat, fix.lng];
    var m = project(p, false);
    if (m.d > 60) { var w = project(p, true); if (w.d < m.d) m = w; }   // after a long detour
    var limit = Math.max(35, Math.min(80, (fix.acc || 10) * 1.5));
    if (m.d > limit) {
      if (++state.off >= 3 && !state.offRoute) { state.offRoute = true; say(T.offroute); }
    } else {
      if (state.offRoute) say(T.back);
      state.off = 0; state.offRoute = false;
      state.along = m.along; state.seg = m.seg;
    }

    var heading = bearing(pointAt(state.along), pointAt(state.along + 15));
    var shown = state.offRoute ? p : pointAt(state.along);
    if (!rider) rider = L.marker(shown, { icon: riderIcon(heading), interactive: false, zIndexOffset: 1000 }).addTo(map);
    else { rider.setLatLng(shown); rider.setIcon(riderIcon(heading)); }
    doneLine.setLatLngs(line.slice(0, state.seg + 1).concat([pointAt(state.along)]));
    if (follow) map.setView(shown, Math.max(map.getZoom(), 16), { animate: false });

    var speedMs = fix.speedMs != null ? fix.speedMs : CRUISE_KMH / 3.6;
    guide(speedMs, fix);
    stats(fix);
    if (record) record.add(fix);
  }

  function guide(speedMs, fix) {
    var left = total - state.along;
    if (!state.arrived && left < 25) { state.arrived = true; say(T.arrive); finish(true); return; }
    if (!state.halfway && state.along > total / 2 && total > 4000) {
      state.halfway = true;
      say(T.halfway.replace('{km}', fmt(left / 1000, 1)));
    }
    var next = null, nextIdx = -1;
    for (var k = 0; k < hints.length; k++) if (hints[k].at > state.along + 3) { next = hints[k]; nextIdx = k; break; }
    // Announce by time, not distance – that works at 20 km/h and in the time-lapse alike
    var v = Math.max(speedMs, 2);
    if (next) {
      var togo = next.at - state.along;
      ui.arrow.textContent = next.kind === 'roundabout' ? '⟳' : '⬆';
      ui.arrow.style.transform = next.kind === 'roundabout' ? 'none' : 'rotate(' + (ARROW[next.kind] || 0) + 'deg)';
      ui.dist.textContent = shownDistance(togo);
      ui.text.textContent = hintText(next, 'label');
      if (!next.saidFar && togo > Math.max(60, 9 * v) && togo <= Math.max(160, 25 * v)) {
        next.saidFar = true;
        coverFollowing(nextIdx, 'saidFar');
        say(hintText(next, 'far').replace('{d}', spokenDistance(togo)));
      } else if (!next.saidNow && togo <= Math.max(30, 6 * v)) {
        next.saidNow = next.saidFar = true;
        coverFollowing(nextIdx, 'saidNow');
        say(hintText(next, 'now'));
      }
    } else {
      ui.arrow.textContent = '🏁'; ui.arrow.style.transform = 'none';
      ui.dist.textContent = shownDistance(left);
      ui.text.textContent = T.label_straight;
    }
    if (!SIM && fix.speedMs != null && fix.speedMs * 3.6 > LEGAL_KMH + 4 && Date.now() - state.lastSpeedWarn > 120000) {
      state.lastSpeedWarn = Date.now();
      say(T.speed);
    }
  }

  // Manoeuvres a few metres apart (two lefts across a junction) are one prompt for the rider, not two
  function coverFollowing(idx, flag) {
    for (var k = idx + 1; k < hints.length && hints[k].at - hints[idx].at < 25; k++) {
      hints[k][flag] = true;
      if (flag === 'saidNow') hints[k].saidFar = true;
    }
  }

  function stats(fix) {
    var left = total - state.along;
    ui.remaining.textContent = fmt(left / 1000, 1) + ' km';
    var minutes = Math.round(left / 1000 / CRUISE_KMH * 60);
    ui.eta.textContent = minutes >= 60 ? T.eta_h.replace('{h}', Math.floor(minutes / 60)).replace('{m}', ('0' + minutes % 60).slice(-2)) : T.eta_min.replace('{m}', minutes);
    if (!SIM) ui.speed.textContent = fix.speedMs != null ? Math.round(fix.speedMs * 3.6) + ' ' + T.speed_unit : '–';
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
      onFix({ lat: c.latitude, lng: c.longitude, acc: c.accuracy, speedMs: c.speed != null && !isNaN(c.speed) ? c.speed : null, t: pos.timestamp || Date.now() });
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

  // ---------------------------------------------------------------- recording (opt-in, GPS only)
  var record = null;
  function api(body, keepalive) {
    return fetch('/api/track', { method: 'POST', credentials: 'same-origin', keepalive: !!keepalive,
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
    say((SIM ? T.start_sim : T.start).replace('{km}', fmt(total / 1000, 1)) + (hints.length ? '' : ' ' + T.no_hints));
    if (SIM) startSim();
    else {
      if (ui.record && ui.record.checked) record = new Recorder();
      startGps();
    }
  });
  ui.stop.addEventListener('click', function () { finish(false); });
  ui.voice.addEventListener('click', function () {
    voiceOn = !voiceOn;
    ui.voice.textContent = voiceOn ? '🔊' : '🔇';
    ui.voice.setAttribute('aria-pressed', voiceOn ? 'true' : 'false');
    ui.voice.title = voiceOn ? T.voice_on : T.voice_off;
    if (!voiceOn && window.speechSynthesis) speechSynthesis.cancel();
  });
  ui.follow.addEventListener('click', function () {
    follow = true;
    if (rider) map.setView(rider.getLatLng(), Math.max(map.getZoom(), 16));
  });

  function finish(arrived) {
    if (!state.running) return;
    state.running = false;
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    clearInterval(simTimer);
    if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
    ui.stop.hidden = true;
    document.getElementById('ride-done-title').textContent = arrived ? T.done_arrived : T.done_title;
    var text = document.getElementById('ride-done-text');
    text.textContent = T.done_text.replace('{km}', fmt(state.along / 1000, 1));
    ui.done.hidden = false;
    if (record) {
      text.textContent += ' ' + T.saving;
      record.finish().then(function (j) {
        text.textContent = T.done_recorded.replace('{km}', fmt(j.distance_m / 1000, 1)).replace('{min}', Math.round(j.moving_s / 60))
                                          .replace('{max}', fmt(j.max_speed_kmh, 1));
      }).catch(function () { text.textContent = T.done_not_recorded; });
      record = null;
    }
  }
})();
