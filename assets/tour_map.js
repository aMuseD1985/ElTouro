/* Read-only tour map. Optional data-meeting="lat,lng" marks a ride's meeting point. */
(function () {
  'use strict';
  var el = document.getElementById('tour-map');
  if (!el || !window.L) return;
  var gj = JSON.parse(el.dataset.geojson);
  var map = L.map(el, { scrollWheelZoom: false });
  L.tileLayer(el.dataset.tiles, { maxZoom: 19, attribution: el.dataset.attribution }).addTo(map);
  var route = L.geoJSON(gj, {
    style: function (f) {
      return f.properties && f.properties.freehand
        ? { color: '#A3261B', weight: 5, dashArray: '8 8' }
        : { color: '#2F5E8C', weight: 5 };
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
  L.circleMarker([first[1], first[0]], { radius: 8, color: '#14263F', fillColor: '#D7A845', fillOpacity: 1, weight: 3 }).addTo(map).bindTooltip(el.dataset.start);
  L.circleMarker([last[1], last[0]], { radius: 8, color: '#14263F', fillColor: '#14263F', fillOpacity: 1, weight: 3 }).addTo(map).bindTooltip(el.dataset.finish);
  if (meeting) {
    L.marker(meeting, { title: el.dataset.meetingLabel || '' }).addTo(map)
      .bindTooltip(el.dataset.meetingLabel || '', { permanent: true, direction: 'top', offset: [0, -30] });
  }
})();
