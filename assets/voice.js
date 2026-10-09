/*
 * ElTouro voice for ride mode. Two layers:
 *   1. Recorded clips (assets/voice/<lang>/manifest.json: exact spoken text -> audio file). If the text of an
 *      announcement is in the manifest, the clip is played – that is how the bull gets his own voice.
 *   2. The device voice (speechSynthesis) for everything else (stop names, texts without a clip, no manifest at all).
 * Sound cues (assets/voice/sfx/<name>.mp3, listed in the manifest) play before an announcement, if present.
 * Clips are fetched as blobs, not streamed: that works with the service worker cache and without range requests.
 * Make the clips with tools/voice/phrases.php (see tools/voice/README.md).
 */
(function () {
  'use strict';
  var SILENCE = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';

  function create(opts) {
    var lang = opts.lang, locale = opts.locale;
    var on = true, clips = {}, cues = {}, ready = null;
    var player = new Audio();
    var blobs = {};            // url -> object URL
    var queue = [], loopGen = -1, gen = 0;   // loopGen = the generation whose loop is running (-1: none)
    var voice = null;

    // ---- manifest
    ready = fetch('/assets/voice/' + lang + '/manifest.json', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (m) {
        if (!m) return;
        clips = m.clips || {};
        (m.cues || []).forEach(function (c) { cues[c] = true; });
      })
      .catch(function () { /* no recorded voice: device voice only */ });

    // Male voices first (the bull is a guy), cheeky ones at the top; the rider can cycle through them (cycleVoice)
    var MALE = /rocko|eddy|reed|grandpa|conrad|markus|yannick|viktor|stefan|jonas|florian|daniel|thomas|martin|\balex\b|fred|ralph|albert|junior|oliver|arthur|\bguy\b|ryan|george|david|mark\b|james|male|männlich/i;
    var FEMALE = /anna|petra|helena|sandy|shelley|\bflo\b|grandma|katja|marlene|vicki|hedda|amala|female|weiblich|samantha|kathy|linda|zira|susan|karen|moira|fiona|tessa|ava|allison|carmit|hanna/i;
    var CHEEKY = ['rocko', 'eddy', 'reed', 'conrad', 'markus', 'yannick', 'viktor', 'stefan', 'daniel', 'arthur', 'guy', 'ryan'];
    var KEY = 'eltouro.voice';
    function candidates() {
      if (!window.speechSynthesis) return [];
      var vs = speechSynthesis.getVoices().filter(function (v) { return v.lang && v.lang.toLowerCase().replace('_', '-').indexOf(lang) === 0; });
      function score(v) {
        var n = v.name.toLowerCase(), s = 0, c = -1;
        if (MALE.test(n) && !FEMALE.test(n)) s += 100;
        else if (FEMALE.test(n)) s -= 50;
        CHEEKY.forEach(function (k, i) { if (c < 0 && n.indexOf(k) >= 0) c = i; });
        if (c >= 0) s += 40 - c;
        if (/premium|enhanced|neural|natural/i.test(n)) s += 15;
        if (/google/i.test(n)) s += 5;
        return s;
      }
      return vs.sort(function (a, b) { return score(b) - score(a); });
    }
    function isMale(v) { return !!v && MALE.test(v.name) && !FEMALE.test(v.name); }
    function pickVoice() {
      var list = candidates(), saved = null;
      try { saved = localStorage.getItem(KEY); } catch (e) { /* ignore */ }
      voice = list.filter(function (v) { return v.name === saved; })[0] || list[0] || null;
      return voice;
    }
    if (window.speechSynthesis) speechSynthesis.onvoiceschanged = function () { voice = null; };

    function blobFor(url) {
      if (blobs[url]) return Promise.resolve(blobs[url]);
      return fetch(url, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('clip'); return r.blob(); })
        .then(function (b) { blobs[url] = URL.createObjectURL(b); return blobs[url]; });
    }

    function playUrl(url, myGen) {
      return blobFor(url).then(function (src) {
        if (myGen !== gen) return;
        return new Promise(function (resolve) {
          var done = false;
          function end() { if (done) return; done = true; player.onended = player.onerror = null; clearTimeout(t); resolve(); }
          var t = setTimeout(end, 20000);
          player.onended = end; player.onerror = end;
          player.src = src;
          var p = player.play();
          if (p && p.catch) p.catch(end);
        });
      });
    }

    function speakDevice(text, myGen) {
      return new Promise(function (resolve) {
        if (!window.speechSynthesis || myGen !== gen) return resolve();
        var u = new SpeechSynthesisUtterance(text);
        u.lang = locale;
        var v = voice || pickVoice();
        if (v) u.voice = v;
        u.rate = 1.08;                       // a bit brisk
        u.pitch = isMale(v) ? 0.9 : 1;       // and a bit gravelly
        var t = setTimeout(resolve, 20000);
        u.onend = u.onerror = function () { clearTimeout(t); resolve(); };
        speechSynthesis.speak(u);
      });
    }

    function run() {
      if (loopGen === gen) return;
      loopGen = gen;
      var myGen = gen;
      (function next() {
        if (myGen !== gen) return;                       // cancelled: a newer loop took over
        var item = queue.shift();
        if (!item) { loopGen = -1; return; }
        var job = Promise.resolve();
        if (item.cue && cues[item.cue]) job = job.then(function () { return playUrl('/assets/voice/sfx/' + item.cue + '.mp3', myGen); }).catch(function () {});
        if (item.text) {
          job = job.then(function () {
            return ready.then(function () {
              var file = clips[item.text];
              if (file) return playUrl('/assets/voice/' + lang + '/' + file, myGen).catch(function () { return speakDevice(item.text, myGen); });
              return speakDevice(item.text, myGen);
            });
          });
        }
        job.then(next, next);
      })();
    }

    return {
      /** Call from a click: lets later announcements play on iOS (first sound must come from a gesture). */
      unlock: function () {
        try { player.src = SILENCE; var p = player.play(); if (p && p.catch) p.catch(function () {}); } catch (e) { /* ignore */ }
        if (window.speechSynthesis) { try { speechSynthesis.getVoices(); } catch (e) { /* ignore */ } }
      },
      /** queue = wait for the current announcement instead of cutting it off; cue = sound cue name played first. */
      say: function (text, enqueue, cue) {
        if (!on) return;
        if (!enqueue) this.cancel();
        queue.push({ text: text, cue: cue });
        run();
      },
      cancel: function () {
        gen++;
        queue = [];
        try { player.pause(); } catch (e) { /* ignore */ }
        if (window.speechSynthesis) speechSynthesis.cancel();
      },
      /** Next voice of this language (male ones first); returns its name, null if there is no choice. */
      cycleVoice: function () {
        var list = candidates();
        if (list.length < 2) return null;
        var cur = voice || pickVoice(), i = list.indexOf(cur);
        voice = list[(i + 1) % list.length];
        try { localStorage.setItem(KEY, voice.name); } catch (e) { /* ignore */ }
        return voice.name.replace(/\s*\(.*\)\s*$/, '');
      },
      voiceCount: function () { return candidates().length; },
      setOn: function (v) { on = !!v; if (!on) this.cancel(); },
      hasClips: function () { return ready.then(function () { return Object.keys(clips).length > 0; }); }
    };
  }

  window.ElTouroVoice = { create: create };
})();
