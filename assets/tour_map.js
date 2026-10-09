/* Read-only tour map. Optional data-meeting="lat,lng" marks a ride's meeting point. */
(function () {
  'use strict';
  var el = document.getElementById('tour-map');
  if (!el || !window.L) return;
  var gj = JSON.parse(el.dataset.geojson);
  var map = L.map(el, { scrollWheelZoom: false });
  ElTouroMaps.leaflet(map, el);
  var route = L.geoJSON(gj, {
    style: function (f) {
      return f.properties && f.properties.freehand
        ? { color: '#A3261B', weight: 4, dashArray: '6 6' }
        : { color: '#2F5E8C', weight: 4 };
    }
  });

  var meeting = null;
  if (el.dataset.meeting) {
    var mp = el.dataset.meeting.split(',').map(Number);
    if (mp.length === 2 && !isNaN(mp[0]) && !isNaN(mp[1])) meeting = mp;
  }

  // The view must be set before any layer is added – otherwise Leaflet fails to clip the paths
  var bounds = route.getBounds();
  if (meeting) bounds.extend(meeting);
  map.fitBounds(bounds, { padding: [30, 30] });

  route.addTo(map);
  var first = gj.features[0].geometry.coordinates[0];
  var lastLine = gj.features[gj.features.length - 1].geometry.coordinates;
  var last = lastLine[lastLine.length - 1];
  var startMarker = L.circleMarker([first[1], first[0]], { radius: 8, color: '#14263F', fillColor: '#D7A845', fillOpacity: 1, weight: 3 }).addTo(map);
  var finishMarker = L.circleMarker([last[1], last[0]], { radius: 8, color: '#14263F', fillColor: '#14263F', fillOpacity: 1, weight: 3 }).addTo(map);
  if (el.dataset.start) startMarker.bindTooltip(el.dataset.start);    // shared tours: no labels, the ends are cut off
  if (el.dataset.finish) finishMarker.bindTooltip(el.dataset.finish);
  // Stops (charging, food, break, sight) as pins with name and kind
  var ICON = { charge: '⚡', food: '🍽', break: '☕', sight: '👁' };
  try {
    JSON.parse(el.dataset.stops || '[]').forEach(function (s) {
      if (!ICON[s.type]) return;
      var m = L.marker([s.lat, s.lng], {
        icon: L.divIcon({ className: '', html: '<div class="poi-pin poi-' + s.type + '">' + ICON[s.type] + '</div>', iconSize: [26, 26], iconAnchor: [13, 13] }),
        keyboard: true, title: s.name || s.label
      }).addTo(map);
      var box = document.createElement('div');
      var t = document.createElement('strong'); t.textContent = s.name || s.label; box.appendChild(t);
      if (s.name && s.label) { var l = document.createElement('div'); l.textContent = s.label; box.appendChild(l); }
      m.bindPopup(box, { closeButton: false, offset: [0, -8] });
    });
  } catch (e) { /* no stops */ }
  if (meeting) {
    L.marker(meeting, { title: el.dataset.meetingLabel || '' }).addTo(map)
      .bindTooltip(el.dataset.meetingLabel || '', { permanent: true, direction: 'top', offset: [0, -30] });
  }
})();
