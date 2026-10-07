/* ElTouro Tränke – Discourse-light: Nachladen beim Scrollen, neue Beiträge ankündigen,
   Antworten ohne Neuladen, Herz-Reaktionen, Gelesen-Stand. Kein Framework, nur fetch + IntersectionObserver. */
(function () {
  'use strict';

  function api(aktion, daten, csrf) {
    return fetch('/traenke_api.php?aktion=' + aktion, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify(daten || {})
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { var f = new Error(j.fehler || ('HTTP ' + r.status)); f.nutzer = j.fehler; throw f; }
        return j;
      });
    });
  }

  function fragment(html) {
    var t = document.createElement('template');
    t.innerHTML = html;
    return t.content;
  }

  /* ---------------- Themenliste ---------------- */
  var liste = document.getElementById('themen');
  if (liste) {
    var csrfListe = (document.querySelector('input[name="csrf"]') || {}).value;
    var lader = document.getElementById('themen-mehr');
    var laedt = false;
    var beobachter = new IntersectionObserver(function (e) {
      if (!e[0].isIntersecting || laedt || liste.dataset.mehr !== '1') return;
      laedt = true;
      lader.classList.add('laedt');
      api('themen', { herde: liste.dataset.herde, offset: +liste.dataset.offset }, csrfListe).then(function (j) {
        liste.appendChild(fragment(j.html));
        liste.dataset.offset = j.offset;
        liste.dataset.mehr = j.mehr ? '1' : '0';
      }).catch(function () {}).then(function () { laedt = false; lader.classList.remove('laedt'); });
    }, { rootMargin: '400px' });
    beobachter.observe(lader);
  }

  /* ---------------- Beitragsstrom ---------------- */
  var strom = document.getElementById('strom');
  if (!strom) return;

  var d = strom.dataset;
  var T = JSON.parse(d.texte || '{}');
  var thema = +d.thema;
  var csrf = d.csrf;
  var kiste = document.getElementById('beitraege');
  var obenLader = document.getElementById('lade-oben');
  var untenLader = document.getElementById('lade-unten');
  var hinweis = document.getElementById('neue-hinweis');
  var formular = document.getElementById('verfasser');
  var feld = document.getElementById('verfasser-text');
  var bezugBox = document.getElementById('antwort-auf');
  var bezugId = 0;
  var mehrOben = d.mehrOben === '1';
  var mehrUnten = d.mehrUnten === '1';
  var laedtOben = false, laedtUnten = false;
  var gelesenBis = 0, gelesenGemeldet = 0, gelesenTimer = null;

  function ersteId() { var a = kiste.querySelector('.tb'); return a ? +a.dataset.id : 0; }
  function letzteId() { var a = kiste.querySelectorAll('.tb'); return a.length ? +a[a.length - 1].dataset.id : 0; }

  /* Gelesen-Stand: sichtbare Beiträge sammeln, gebündelt melden */
  var sichtbar = new IntersectionObserver(function (eintraege) {
    eintraege.forEach(function (e) {
      if (e.isIntersecting) {
        gelesenBis = Math.max(gelesenBis, +e.target.dataset.id);
        sichtbar.unobserve(e.target);
      }
    });
    if (gelesenBis > gelesenGemeldet && !gelesenTimer) {
      gelesenTimer = setTimeout(function () {
        gelesenTimer = null;
        var bis = gelesenBis;
        api('gelesen', { thema: thema, bis: bis }, csrf).then(function () { gelesenGemeldet = Math.max(gelesenGemeldet, bis); }).catch(function () {});
      }, 1500);
    }
  }, { threshold: 0.6 });
  function beobachteBeitraege(wurzel) {
    (wurzel.querySelectorAll ? wurzel : document).querySelectorAll('.tb').forEach(function (b) { sichtbar.observe(b); });
  }
  beobachteBeitraege(kiste);

  /* Ältere nachladen – Scrollposition halten, damit nichts springt */
  function ladeAeltere() {
    if (laedtOben || !mehrOben) return;
    laedtOben = true;
    obenLader.classList.add('laedt');
    api('beitraege', { thema: thema, vor: ersteId() }, csrf).then(function (j) {
      var vorher = document.documentElement.scrollHeight;
      var neu = fragment(j.html);
      beobachteBeitraege(neu);
      kiste.insertBefore(neu, kiste.firstChild);
      window.scrollBy(0, document.documentElement.scrollHeight - vorher);
      mehrOben = j.mehr;
    }).catch(function () {}).then(function () { laedtOben = false; obenLader.classList.remove('laedt'); });
  }

  /* Neuere nachladen */
  function ladeNeuere() {
    if (laedtUnten || !mehrUnten) return Promise.resolve();
    laedtUnten = true;
    untenLader.classList.add('laedt');
    return api('beitraege', { thema: thema, nach: letzteId() }, csrf).then(function (j) {
      var neu = fragment(j.html);
      beobachteBeitraege(neu);
      kiste.appendChild(neu);
      mehrUnten = j.mehr;
    }).catch(function () {}).then(function () { laedtUnten = false; untenLader.classList.remove('laedt'); });
  }

  new IntersectionObserver(function (e) { if (e[0].isIntersecting) ladeAeltere(); }, { rootMargin: '300px' }).observe(obenLader);
  new IntersectionObserver(function (e) { if (e[0].isIntersecting) ladeNeuere(); }, { rootMargin: '300px' }).observe(untenLader);

  /* Neue Beiträge anderer: alle 20 s nachfragen, nur wenn der Tab sichtbar ist */
  function pruefeNeue() {
    if (document.hidden || mehrUnten) return;
    api('neue', { thema: thema, seit: letzteId() }, csrf).then(function (j) {
      if (j.anzahl > 0) {
        hinweis.textContent = T.neue_beitraege.replace('{n}', j.anzahl);
        hinweis.hidden = false;
      }
    }).catch(function () {});
  }
  setInterval(pruefeNeue, 20000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) pruefeNeue(); });
  hinweis.addEventListener('click', function () {
    hinweis.hidden = true;
    mehrUnten = true;
    ladeNeuere().then(function () { var l = kiste.querySelector('.tb:last-of-type'); if (l) l.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
  });

  /* Aktionen an Beiträgen (Event-Delegation, funktioniert auch für nachgeladene) */
  kiste.addEventListener('click', function (ev) {
    var sprung = ev.target.closest('[data-springe]');
    if (sprung) {
      var ziel = document.getElementById('p' + sprung.dataset.springe);
      if (ziel) { ev.preventDefault(); ziel.scrollIntoView({ behavior: 'smooth', block: 'center' }); ziel.classList.add('blinkt'); setTimeout(function () { ziel.classList.remove('blinkt'); }, 1600); }
      return;
    }
    var k = ev.target.closest('[data-aktion]');
    if (!k) return;
    var beitrag = k.closest('.tb');
    var id = +beitrag.dataset.id;

    if (k.dataset.aktion === 'like') {
      k.disabled = true;
      api('like', { beitrag: id }, csrf).then(function (j) {
        k.classList.toggle('aktiv', j.mag);
        k.setAttribute('aria-pressed', j.mag ? 'true' : 'false');
        k.querySelector('.tb-zahl').textContent = j.anzahl || '';
      }).catch(function () {}).then(function () { k.disabled = false; });
    }
    if (k.dataset.aktion === 'antworten' && formular) {
      bezugId = id;
      bezugBox.querySelector('span').textContent = T.antwort_an.replace('{name}', k.dataset.name);
      bezugBox.hidden = false;
      feld.focus();
    }
    if (k.dataset.aktion === 'loeschen') {
      if (!window.confirm(T.loeschen_frage)) return;
      api('loeschen', { beitrag: id }, csrf).then(function (j) {
        beitrag.replaceWith(fragment(j.html));
      }).catch(function () { window.alert(T.fehler); });
    }
  });

  /* Verfassen: Strg/Cmd + Enter schickt ab */
  if (formular) {
    document.getElementById('antwort-weg').addEventListener('click', function () { bezugId = 0; bezugBox.hidden = true; });
    feld.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); formular.requestSubmit(); }
    });
    formular.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var text = feld.value.trim();
      if (!text) return;
      var knopf = formular.querySelector('button[type="submit"]');
      knopf.disabled = true;
      api('antworten', { thema: thema, text: text, bezug: bezugId }, csrf).then(function (j) {
        if (mehrUnten) { window.location = '/traenke_thema.php?id=' + thema + '&ende=1#p' + j.id; return; }
        kiste.appendChild(fragment(j.html));
        feld.value = '';
        bezugId = 0;
        bezugBox.hidden = true;
        var neu = document.getElementById('p' + j.id);
        if (neu) neu.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }).catch(function (f) { window.alert(f.nutzer || T.fehler); }).then(function () { knopf.disabled = false; });
    });
  }

  /* Einstieg: zur Ungelesen-Linie bzw. zum Sprungziel scrollen */
  var anker = window.location.hash ? document.querySelector(window.location.hash) : document.getElementById('ungelesen');
  if (anker) { anker.scrollIntoView({ block: 'center' }); }
})();
