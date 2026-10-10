/* Flyer: QR code (generated here, no service involved), scale the A4 sheet to the screen, print, toggle the organiser's name. */
(function () {
  'use strict';
  var qrEl = document.getElementById('flyer-qr');
  if (qrEl && window.qrcode) {
    var qr = window.qrcode(0, 'M');   // medium error correction: robust when printed and a little worn
    qr.addData(qrEl.dataset.url);
    qr.make();
    qrEl.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
  }

  var flyer = document.getElementById('flyer'), stage = document.getElementById('flyer-stage');
  function fit() {
    if (window.matchMedia('print').matches) return;
    var w = flyer.offsetWidth, scale = Math.min(1, (stage.clientWidth - 24) / w);
    flyer.style.transform = 'scale(' + scale + ')';
    stage.style.height = (flyer.offsetHeight * scale + 24) + 'px';
  }
  window.addEventListener('resize', fit);
  window.addEventListener('beforeprint', function () { flyer.style.transform = 'none'; });
  window.addEventListener('afterprint', fit);
  fit();
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);

  document.getElementById('flyer-print').addEventListener('click', function () { window.print(); });
  document.getElementById('flyer-name').addEventListener('change', function (e) {
    location.href = '/ride/' + e.target.dataset.id + '/flyer' + (e.target.checked ? '' : '?name=0');
  });
})();
