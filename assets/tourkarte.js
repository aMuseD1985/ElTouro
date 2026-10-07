/* Schreibgeschützte Tourkarte */
(function () {
  'use strict';
  var el = document.getElementById('tour-karte');
  if (!el || !window.L) return;
  var gj = JSON.parse(el.dataset.geojson);
  var karte = L.map(el, { scrollWheelZoom: false });
  L.tileLayer(el.dataset.kacheln, { maxZoom: 19, attribution: el.dataset.attribution }).addTo(karte);
  var ebene = L.geoJSON(gj, {
    style: function (f) {
      return f.properties && f.properties.freihand
        ? { color: '#A3261B', weight: 5, dashArray: '8 8' }
        : { color: '#2F5E8C', weight: 5 };
    }
  }).addTo(karte);
  karte.fitBounds(ebene.getBounds(), { padding: [30, 30] });
  var k = gj.features[0].geometry.coordinates[0];
  var letzte = gj.features[gj.features.length - 1].geometry.coordinates;
  var z = letzte[letzte.length - 1];
  L.circleMarker([k[1], k[0]], { radius: 8, color: '#14263F', fillColor: '#D7A845', fillOpacity: 1, weight: 3 }).addTo(karte).bindTooltip(el.dataset.start);
  L.circleMarker([z[1], z[0]], { radius: 8, color: '#14263F', fillColor: '#14263F', fillOpacity: 1, weight: 3 }).addTo(karte).bindTooltip(el.dataset.ziel);
})();
