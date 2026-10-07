/* ElTouro Routenplaner – Wegpunkte setzen, Route über /route.php berechnen, Ergebnis ins Formular. */
(function () {
  'use strict';
  var el = document.getElementById('planer-karte');
  if (!el || !window.L) return;

  var d = el.dataset;
  var T = JSON.parse(d.texte || '{}');
  var MAX = 60;
  var punkte = [];
  var aktuell = null;
  var anfrageNr = 0;
  try { punkte = JSON.parse(d.wegpunkte || '[]') || []; } catch (e) { punkte = []; }
  try { aktuell = d.geojson ? JSON.parse(d.geojson) : null; } catch (e) { aktuell = null; }

  var feldWp = document.getElementById('waypoints_json');
  var feldGj = document.getElementById('geojson');
  var feldRegel = document.getElementById('rule_set');
  var knopfSpeichern = document.getElementById('tour-speichern');
  var status = document.getElementById('planer-status');
  var info = document.getElementById('planer-info');

  var karte = L.map(el, { zoomControl: true }).setView([51.2, 10.4], 6);
  L.tileLayer(d.kacheln, { maxZoom: 19, attribution: d.attribution }).addTo(karte);
  var routenEbene = L.layerGroup().addTo(karte);
  var markerEbene = L.layerGroup().addTo(karte);

  function setzeStatus(text) { status.textContent = text || ''; }

  function abstand(a, b) { // [lng,lat]
    var r = 6371008.8, rad = Math.PI / 180;
    var dp = (b[1] - a[1]) * rad, dl = (b[0] - a[0]) * rad;
    var x = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a[1] * rad) * Math.cos(b[1] * rad) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(x)));
  }

  function zeigeKennzahlen(gj) {
    if (!gj) { info.textContent = ''; return; }
    var gesamt = 0, frei = 0;
    gj.features.forEach(function (f) {
      var k = f.geometry.coordinates;
      for (var i = 1; i < k.length; i++) {
        var s = abstand(k[i - 1], k[i]);
        gesamt += s;
        if (f.properties && f.properties.freihand) frei += s;
      }
    });
    var km = (gesamt / 1000).toLocaleString(d.sprache === 'de' ? 'de-DE' : 'en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var text = T.kennzahl.replace('{km}', km).replace('{n}', punkte.length);
    if (frei > 0) text += ' · ' + T.freihand_anteil.replace('{p}', Math.round(frei / gesamt * 100));
    info.textContent = text;
  }

  function zeichneRoute(gj) {
    routenEbene.clearLayers();
    if (!gj) return;
    L.geoJSON(gj, {
      style: function (f) {
        return f.properties && f.properties.freihand
          ? { color: '#A3261B', weight: 5, opacity: 0.9, dashArray: '8 8' }
          : { color: '#2F5E8C', weight: 5, opacity: 0.9 };
      }
    }).addTo(routenEbene);
  }

  function zeichneMarker() {
    markerEbene.clearLayers();
    punkte.forEach(function (p, i) {
      var m = L.marker(p, { draggable: true, keyboard: true, title: T.punkt + ' ' + (i + 1) });
      m.on('dragend', function (e) {
        var ll = e.target.getLatLng();
        punkte[i] = [ll.lat, ll.lng];
        berechne();
      });
      m.on('click', function () {
        punkte.splice(i, 1);
        berechne();
      });
      m.addTo(markerEbene);
    });
  }

  function berechne() {
    zeichneMarker();
    feldWp.value = JSON.stringify(punkte);
    if (punkte.length < 2) {
      aktuell = null;
      feldGj.value = '';
      zeichneRoute(null);
      zeigeKennzahlen(null);
      knopfSpeichern.disabled = true;
      setzeStatus(T.leer);
      return;
    }
    var nr = ++anfrageNr;
    knopfSpeichern.disabled = true;
    setzeStatus(T.rechne);
    fetch('/route.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': d.csrf },
      body: JSON.stringify({ punkte: punkte, regelwerk: feldRegel ? feldRegel.value : 'ekfv' })
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) {
      if (nr !== anfrageNr) return;          // veraltete Antwort ignorieren
      aktuell = j.geojson;
      feldGj.value = JSON.stringify(aktuell);
      zeichneRoute(aktuell);
      zeigeKennzahlen(aktuell);
      knopfSpeichern.disabled = false;
      setzeStatus(j.hinweis ? T['hinweis_' + j.hinweis] : T.fertig);
    }).catch(function () {
      if (nr !== anfrageNr) return;
      setzeStatus(T.fehler);
    });
  }

  karte.on('click', function (e) {
    if (punkte.length >= MAX) return;
    punkte.push([e.latlng.lat, e.latlng.lng]);
    berechne();
  });

  document.getElementById('pl-rueck').addEventListener('click', function () { punkte.pop(); berechne(); });
  document.getElementById('pl-leeren').addEventListener('click', function () { punkte = []; berechne(); });
  document.getElementById('pl-rund').addEventListener('click', function () {
    if (punkte.length >= 2 && punkte.length < MAX) { punkte.push(punkte[0].slice()); berechne(); }
  });
  document.getElementById('pl-standort').addEventListener('click', function () {
    if (!navigator.geolocation) { setzeStatus(T.standort_fehler); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      karte.setView([pos.coords.latitude, pos.coords.longitude], 14);
    }, function () { setzeStatus(T.standort_fehler); }, { enableHighAccuracy: false, timeout: 8000 });
  });
  if (feldRegel) feldRegel.addEventListener('change', function () { if (punkte.length >= 2) berechne(); });

  // Startzustand: gespeicherte Tour anzeigen, ohne neu zu rechnen
  zeichneMarker();
  if (aktuell) {
    zeichneRoute(aktuell);
    zeigeKennzahlen(aktuell);
    karte.fitBounds(L.geoJSON(aktuell).getBounds(), { padding: [30, 30] });
    knopfSpeichern.disabled = false;
  } else {
    knopfSpeichern.disabled = true;
    setzeStatus(T.leer);
  }
})();
