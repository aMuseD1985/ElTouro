/* Small map for one place (spot page) and the position picker for a new place (spot/new). */
(function () {
  'use strict';
  var el = document.getElementById('spot-map') || document.getElementById('spot-pick');
  if (!el || !window.L) return;
  var pick = el.id === 'spot-pick';
  var lat = parseFloat(el.dataset.lat), lng = parseFloat(el.dataset.lng);
  var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], pick ? 14 : 16);
  ElTouroMaps.leaflet(map, el);
  var marker = L.marker([lat, lng], { draggable: pick }).addTo(map);
  if (!pick) return;
  var fLat = document.getElementById('spot-lat'), fLng = document.getElementById('spot-lng');
  function set(ll) { marker.setOpacity(1); marker.setLatLng(ll); fLat.value = ll.lat.toFixed(6); fLng.value = ll.lng.toFixed(6); }
  map.on('click', function (e) { set(e.latlng); });
  marker.on('dragend', function () { set(marker.getLatLng()); });
  marker.setOpacity(0.35);   // not chosen yet: tap the map
  if (!el.dataset.fixed && navigator.geolocation) {
    // only to move the map – the position is not stored
    navigator.geolocation.getCurrentPosition(function (p) { var ll = L.latLng(p.coords.latitude, p.coords.longitude); map.setView(ll, 16); marker.setLatLng(ll); }, function () {}, { timeout: 6000, maximumAge: 600000 });
  }
})();
