/* Ride form: show the tour, click/drag sets the meeting point; § 29 StVO confirmation only for large groups.
   Without JavaScript the form still works: no pin (meeting point = text only) and the § 29 box is always shown. */
(function () {
  'use strict';
  var cap = document.getElementById('capacity');
  var stvo = document.getElementById('stvo29-field');
  if (cap && stvo) {
    var threshold = +cap.dataset.stvo;
    var toggle = function () { stvo.hidden = (+cap.value || 0) < threshold; };
    cap.addEventListener('input', toggle);
    toggle();
  }

  var el = document.getElementById('ride-map');
  if (!el || !window.L) return;
  var T = JSON.parse(el.dataset.texts || '{}');
  var gj = JSON.parse(el.dataset.geojson);
  var fieldLat = document.getElementById('meeting_lat');
  var fieldLng = document.getElementById('meeting_lng');

  var map = L.map(el, { scrollWheelZoom: false });
  ElTouroMaps.leaflet(map, el);
  var route = L.geoJSON(gj, {
    style: function (f) {
      return f.properties && f.properties.freehand
        ? { color: '#A3261B', weight: 5, dashArray: '8 8' }
        : { color: '#2F5E8C', weight: 5 };
    }
  }).addTo(map);
  map.fitBounds(route.getBounds(), { padding: [30, 30] });

  var marker = null;
  function setPoint(lat, lng) {
    fieldLat.value = lat.toFixed(6);
    fieldLng.value = lng.toFixed(6);
    if (!marker) {
      marker = L.marker([lat, lng], { draggable: true, title: T.meeting }).addTo(map);
      marker.bindTooltip(T.meeting, { permanent: true, direction: 'top', offset: [0, -30] });
      marker.on('dragend', function (e) { var ll = e.target.getLatLng(); setPoint(ll.lat, ll.lng); });
    } else {
      marker.setLatLng([lat, lng]);
    }
  }
  if (fieldLat.value && fieldLng.value) {
    setPoint(+fieldLat.value, +fieldLng.value);
  }
  map.on('click', function (e) { setPoint(e.latlng.lat, e.latlng.lng); });
  document.getElementById('meeting-start').addEventListener('click', function () {
    var c = gj.features[0].geometry.coordinates[0];
    setPoint(c[1], c[0]);
    map.setView([c[1], c[0]], Math.max(map.getZoom(), 15));
  });
  document.getElementById('meeting-clear').addEventListener('click', function () {
    fieldLat.value = '';
    fieldLng.value = '';
    if (marker) { map.removeLayer(marker); marker = null; }
  });
})();
