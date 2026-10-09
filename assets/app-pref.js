/* Runs in <head>, before the first paint: applies the rider's "allow selecting" choice and marks the installed app. */
(function () {
  'use strict';
  var c = document.documentElement.classList;
  try { if (localStorage.getItem('eltouro.allowSelect') === '1') c.add('allow-select'); } catch (e) { /* storage blocked */ }
  // Colour scheme: auto (follows the device), light or dark – chosen in the profile, kept on this device only
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function theme() {
    var pref = 'auto';
    try { pref = localStorage.getItem('eltouro.theme') || 'auto'; } catch (e) { /* storage blocked */ }
    return pref === 'dark' || (pref === 'auto' && mq && mq.matches) ? 'dark' : 'light';
  }
  function applyTheme() { document.documentElement.setAttribute('data-theme', theme()); }
  applyTheme();
  if (mq && mq.addEventListener) mq.addEventListener('change', applyTheme);
  window.elApplyTheme = applyTheme;
  if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone) c.add('standalone');
})();
