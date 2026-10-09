/* Share panel: copy the link, and the phone's own share sheet (Web Share API) where available. */
(function () {
  'use strict';
  var copy = document.getElementById('share-copy');
  var field = document.getElementById('share-url');
  if (copy && field) {
    copy.addEventListener('click', function () {
      var done = function () { var t = copy.textContent; copy.textContent = copy.dataset.done; setTimeout(function () { copy.textContent = t; }, 2000); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(field.value).then(done, function () { field.select(); });
      } else {
        field.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* the selected field is the fallback */ }
      }
    });
  }
  var native = document.getElementById('share-native');
  if (native && navigator.share) {
    native.hidden = false;
    native.addEventListener('click', function () {
      navigator.share({ title: native.dataset.title, text: native.dataset.text, url: native.dataset.url }).catch(function () {});
    });
  }
})();

// "Teilen" in the action bar opens the share panel
(function () {
  var panel = document.getElementById('share');
  if (!panel || panel.tagName !== 'DETAILS') return;
  if (location.hash === '#share') panel.open = true;
  Array.prototype.forEach.call(document.querySelectorAll('a[data-open="share"]'), function (a) {
    a.addEventListener('click', function () { panel.open = true; });
  });
})();
