/* Runs in <head>, before the first paint: applies the rider's "allow selecting" choice and marks the installed app. */
(function () {
  'use strict';
  var c = document.documentElement.classList;
  try { if (localStorage.getItem('eltouro.allowSelect') === '1') c.add('allow-select'); } catch (e) { /* storage blocked */ }
  if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone) c.add('standalone');
})();
