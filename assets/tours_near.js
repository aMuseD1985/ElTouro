/* "In meiner Nähe": sorts the tour tiles by distance to the rider's position, in the browser.
   The position is not sent anywhere (the start coordinates of the listed tours are already on the page). */
(function () {
  'use strict';
  var btn = document.getElementById('near-me');
  if (!btn) return;
  function km(a, b, c, d) {
    var r = 6371, p = Math.PI / 180, dLat = (c - a) * p, dLng = (d - b) * p;
    var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a * p) * Math.cos(c * p) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(x)));
  }
  btn.addEventListener('click', function () {
    if (!navigator.geolocation) { window.elToast ? window.elToast(btn.dataset.error) : alert(btn.dataset.error); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      Array.prototype.forEach.call(document.querySelectorAll('ul.tour-cards'), function (ul) {
        var items = Array.prototype.slice.call(ul.children);
        items.forEach(function (li) {
          li.__km = km(pos.coords.latitude, pos.coords.longitude, parseFloat(li.dataset.lat), parseFloat(li.dataset.lng));
          var tag = li.querySelector('.tour-dist');
          if (tag) { tag.hidden = false; tag.textContent = ' · ' + btn.dataset.km.replace('{km}', Math.round(li.__km)); }
        });
        items.sort(function (a, b) { return a.__km - b.__km; }).forEach(function (li) { ul.appendChild(li); });
      });
      btn.setAttribute('aria-pressed', 'true');
    }, function () { window.elToast ? window.elToast(btn.dataset.error) : alert(btn.dataset.error); }, { enableHighAccuracy: false, timeout: 8000, maximumAge: 600000 });
  });
})();
