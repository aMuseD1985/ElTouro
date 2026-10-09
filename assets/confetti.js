/* A short burst of confetti in the brand colours (skipped when the rider prefers reduced motion). */
(function () {
  'use strict';
  function burst(count) {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var cv = document.createElement('canvas');
    cv.setAttribute('aria-hidden', 'true');
    cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:6000';
    document.body.appendChild(cv);
    var w = cv.width = window.innerWidth, h = cv.height = window.innerHeight, ctx = cv.getContext('2d');
    var colors = ['#D7A845', '#2F5E8C', '#E8ECF1', '#E6BE62', '#C2553F', '#4F8A6B'];
    var parts = [];
    var n = count || 140;
    for (var i = 0; i < n; i++) {
      parts.push({ x: w / 2 + (Math.random() - .5) * 120, y: h * .35, vx: (Math.random() - .5) * 14, vy: -Math.random() * 13 - 4,
                   s: 6 + Math.random() * 6, r: Math.random() * 6, vr: (Math.random() - .5) * .4, c: colors[i % colors.length] });
    }
    var start = performance.now();
    (function frame(now) {
      var t = now - start;
      ctx.clearRect(0, 0, w, h);
      parts.forEach(function (p) {
        p.vy += .35; p.vx *= .99; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r); ctx.fillStyle = p.c; ctx.globalAlpha = Math.max(0, 1 - t / 3600);
        ctx.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2); ctx.restore();
      });
      if (t < 3600) requestAnimationFrame(frame); else cv.remove();
    })(start);
  }
  // A sad party horn ("tröööt") for a ride that ended early: two falling notes with a little wobble
  function horn() {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    try {
      var ctx = new AC(), t = ctx.currentTime;
      [[330, 0, 0.38], [262, 0.42, 0.7]].forEach(function (n) {
        var o = ctx.createOscillator(), g = ctx.createGain(), lfo = ctx.createOscillator(), lg = ctx.createGain();
        o.type = 'sawtooth'; o.frequency.setValueAtTime(n[0], t + n[1]); o.frequency.exponentialRampToValueAtTime(n[0] * 0.93, t + n[1] + n[2]);
        lfo.frequency.value = 9; lg.gain.value = 6; lfo.connect(lg); lg.connect(o.frequency);
        g.gain.setValueAtTime(0.0001, t + n[1]); g.gain.exponentialRampToValueAtTime(0.28, t + n[1] + 0.04);
        g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + n[2]);
        o.connect(g); g.connect(ctx.destination);
        o.start(t + n[1]); lfo.start(t + n[1]); o.stop(t + n[1] + n[2] + 0.05); lfo.stop(t + n[1] + n[2] + 0.05);
      });
      setTimeout(function () { ctx.close(); }, 1600);
    } catch (e) { /* no sound */ }
  }
  window.ElTouroConfetti = { burst: burst, horn: horn };
})();
