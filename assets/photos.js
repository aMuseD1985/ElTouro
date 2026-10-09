/* Photo wall: like without reloading, tap a photo to see it large (arrow keys / swipe-free: Esc closes). */
(function () {
  'use strict';
  var grid = document.getElementById('photo-grid');
  if (!grid) return;
  var csrf = grid.dataset.csrf;

  grid.addEventListener('submit', function (e) {
    var f = e.target.closest('.photo-like-form');
    if (!f) return;
    e.preventDefault();
    var btn = f.querySelector('.like-btn');
    fetch('/api/photos', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify({ action: 'like', photo: parseInt(f.querySelector('[name=photo]').value, 10) }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (typeof j.count !== 'number') return;
        btn.classList.toggle('is-on', j.liked);
        btn.setAttribute('aria-pressed', j.liked ? 'true' : 'false');
        btn.querySelector('.like-n').textContent = String(j.count);
      }).catch(function () { f.submit(); });
  });

  var dlg = document.getElementById('photo-view');
  if (!dlg || typeof dlg.showModal !== 'function') return;   // old browser: the link just opens the picture
  var img = dlg.querySelector('img'), cap = dlg.querySelector('.photo-view-caption');
  grid.addEventListener('click', function (e) {
    var a = e.target.closest('.photo-link');
    if (!a) return;
    e.preventDefault();
    img.src = a.href;
    img.alt = a.dataset.caption || '';
    cap.textContent = (a.dataset.caption ? a.dataset.caption + ' – ' : '') + a.dataset.by;
    dlg.showModal();
  });
  dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.closest('.photo-close')) dlg.close(); });
  dlg.addEventListener('close', function () { img.removeAttribute('src'); });
})();
