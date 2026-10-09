/* Map of a recorded ride: the ridden track (blue) over the route as planned on that day (gold, dashed). */
(function () {
  'use strict';
  var el = document.getElementById('drive-map');
  if (!el || !window.L) return;
  var ridden = JSON.parse(el.dataset.ridden);
  var map = L.map(el, { scrollWheelZoom: false });
  var track = L.geoJSON(ridden, { style: { color: '#2F5E8C', weight: 5, opacity: .95 } });
  var planned = null;
  try { if (el.dataset.planned) planned = L.geoJSON(JSON.parse(el.dataset.planned), { style: { color: '#D7A845', weight: 4, dashArray: '8 8', opacity: .95 } }); } catch (e) { /* none */ }
  var b = track.getBounds();
  if (planned) b.extend(planned.getBounds());
  map.fitBounds(b, { padding: [30, 30] });
  ElTouroMaps.leaflet(map, el);
  if (planned) planned.addTo(map);
  track.addTo(map);
  var c = ridden.features[0].geometry.coordinates;
  if (c.length > 1) {
    L.circleMarker([c[0][1], c[0][0]], { radius: 8, color: '#14263F', fillColor: '#D7A845', fillOpacity: 1, weight: 3 }).addTo(map);
    L.circleMarker([c[c.length - 1][1], c[c.length - 1][0]], { radius: 8, color: '#14263F', fillColor: '#14263F', fillOpacity: 1, weight: 3 }).addTo(map);
  }
  if (el.dataset.confetti === '1' && window.ElTouroConfetti) setTimeout(window.ElTouroConfetti.burst, 400);
})();
