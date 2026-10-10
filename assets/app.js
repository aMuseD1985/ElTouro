/* ElTouro as an app: service worker, install prompt, "app lock" (no text selection / long-press menu / context menu
   in the app interface, with exceptions), back button in the installed app, toasts. */
(function () {
  'use strict';
  var html = document.documentElement, body = document.body;
  var T = {};
  try { T = JSON.parse((document.getElementById('app-texts') || {}).dataset.texts || '{}'); } catch (e) { T = {}; }
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  };

  // ---- Toast
  function toast(text) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.textContent = text;
    body.appendChild(t);
    setTimeout(function () { t.classList.add('show'); }, 10);
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2600);
  }
  window.elToast = toast;

  // ---- Show/hide password on every password field
  Array.prototype.forEach.call(document.querySelectorAll('input[type=password]'), function (input) {
    var wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'pw-toggle';
    b.textContent = '👁';
    b.setAttribute('aria-label', T.pw_show || 'Show password');
    b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? (T.pw_hide || 'Hide password') : (T.pw_show || 'Show password'));
    });
    wrap.appendChild(b);
  });

  // ---- Forms asking "really?" (data-confirm) and menus that close on an outside click
  document.addEventListener('submit', function (e) {
    var msg = e.target && e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    Array.prototype.forEach.call(document.querySelectorAll('details.more-menu[open]'), function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  // ---- App lock: the context menu follows the same exceptions as the CSS (input fields, code, posts, messages, .selectable)
  var SELECTABLE = 'input, textarea, select, [contenteditable], pre, code, kbd, .post-text, .tb-text, .alert, .selectable';
  document.addEventListener('contextmenu', function (e) {
    if (!body.classList.contains('app-ui') || html.classList.contains('allow-select')) return;
    if (e.target.closest && e.target.closest(SELECTABLE)) return;
    e.preventDefault();
  });
  var themePick = document.getElementById('theme-pick');
  if (themePick) {
    themePick.value = store.get('eltouro.theme') || 'auto';
    themePick.addEventListener('change', function () {
      store.set('eltouro.theme', themePick.value);
      if (window.elApplyTheme) window.elApplyTheme();
    });
  }
  var allow = document.getElementById('allow-select');
  if (allow) {
    allow.checked = html.classList.contains('allow-select');
    allow.addEventListener('change', function () {
      html.classList.toggle('allow-select', allow.checked);
      store.set('eltouro.allowSelect', allow.checked ? '1' : '0');
      toast(allow.checked ? T.select_on : T.select_off);
    });
  }

  // ---- Service worker (installability, offline page)
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () { /* not essential */ }); });
  }

  // ---- Back button in the installed app (no browser bar there; Android has its own back gesture, iOS does not)
  if (html.classList.contains('standalone') && location.pathname !== '/') {
    var header = document.querySelector('.site-header');
    if (header) {
      var back = document.createElement('button');
      back.type = 'button';
      back.className = 'app-back';
      back.textContent = '‹';
      back.setAttribute('aria-label', T.back || 'Back');
      // up to the page above (tour → list, editor → tour …); without a known parent one step back in the history
      back.addEventListener('click', function () {
        var up = document.body.dataset.parent;
        if (up) location.href = up; else history.back();
      });
      header.insertBefore(back, header.firstChild);
    }
  }

  // ---- Install prompt: Android/Chrome/Edge ask via beforeinstallprompt, iPhone/iPad get instructions
  var standalone = html.classList.contains('standalone');
  var dismissed = parseInt(store.get('eltouro.installDismissed') || '0', 10);
  var quiet = Date.now() - dismissed < 14 * 86400 * 1000;   // after "later" we ask again in two weeks
  var appPage = body.classList.contains('app-ui') && !document.getElementById('ride');
  var deferred = null;

  function banner(ios) {
    if (standalone || quiet || !appPage || document.querySelector('.install-banner')) return;
    var b = document.createElement('div');
    b.className = 'install-banner';
    b.setAttribute('role', 'dialog');
    b.setAttribute('aria-label', T.install_title || 'Install');
    var img = document.createElement('img'); img.src = '/assets/img/icon-192.png'; img.alt = '';
    var text = document.createElement('div');
    var h = document.createElement('strong'); h.textContent = T.install_title;
    var p = document.createElement('p'); p.textContent = ios ? T.ios_text : T.install_text;
    text.appendChild(h); text.appendChild(p);
    var actions = document.createElement('div'); actions.className = 'install-actions';
    if (!ios) {
      var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'btn'; yes.textContent = T.install_button;
      yes.addEventListener('click', function () {
        b.remove();
        if (!deferred) return;
        deferred.prompt();
        deferred.userChoice.then(function (r) { if (r.outcome !== 'accepted') store.set('eltouro.installDismissed', String(Date.now())); deferred = null; });
      });
      actions.appendChild(yes);
    }
    var later = document.createElement('button'); later.type = 'button'; later.className = 'link'; later.textContent = T.later;
    later.addEventListener('click', function () { store.set('eltouro.installDismissed', String(Date.now())); b.remove(); });
    actions.appendChild(later);
    b.appendChild(img); b.appendChild(text); b.appendChild(actions);
    body.appendChild(b);
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    setTimeout(function () { banner(false); }, 1500);
  });
  window.addEventListener('appinstalled', function () {
    var b = document.querySelector('.install-banner'); if (b) b.remove();
    toast(T.installed);
  });
  var ua = navigator.userAgent;
  var iOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  // Only Safari can add to the home screen on older iOS; other iOS browsers show the same hint in their share menu
  if (iOS && !standalone) setTimeout(function () { banner(true); }, 2500);
})();
