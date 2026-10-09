/* New place: only the kinds that belong to the chosen type are selectable. */
(function () {
  'use strict';
  var type = document.getElementById('type'), kind = document.getElementById('kind');
  if (!type || !kind) return;
  function sync() {
    var first = null;
    Array.prototype.forEach.call(kind.options, function (o) {
      var ok = o.dataset.type === type.value;
      o.hidden = !ok; o.disabled = !ok;
      if (ok && !first) first = o;
    });
    if (kind.selectedOptions[0] && kind.selectedOptions[0].disabled && first) kind.value = first.value;
  }
  type.addEventListener('change', sync);
  sync();
})();
