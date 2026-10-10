/*
 * ElTouro map styles: the switcher on every map (Leaflet) and in ride mode (MapLibre).
 * The styles come from the server (data-map-styles, see mapStyles() in core.php). The choice is remembered on this
 * device only. "auto" uses the dark style while the sun is down at the map's position.
 */
(function () {
  'use strict';
  var KEY = 'eltouro.mapStyle';
  var RAD = Math.PI / 180;

  function read(el) {
    try { return JSON.parse(el.dataset.mapStyles || 'null'); } catch (e) { return null; }
  }
  function choice() {
    try { return localStorage.getItem(KEY) || 'auto'; } catch (e) { return 'auto'; }
  }
  function remember(id) {
    try { localStorage.setItem(KEY, id); } catch (e) { /* private mode: only this page */ }
  }

  /** Is the sun up (above -3°, i.e. still twilight-bright)? Good to a few minutes – enough for a map colour. */
  function sunUp(lat, lng, date) {
    var d = date || new Date();
    var day = (d.getTime() - Date.UTC(d.getUTCFullYear(), 0, 0)) / 864e5;
    var decl = -23.44 * Math.cos(RAD * 360 / 365 * (day + 10));
    var hourAngle = (d.getUTCHours() + d.getUTCMinutes() / 60 + lng / 15 - 12) * 15;
    var elevation = Math.asin(Math.sin(lat * RAD) * Math.sin(decl * RAD)
      + Math.cos(lat * RAD) * Math.cos(decl * RAD) * Math.cos(hourAngle * RAD)) / RAD;
    return elevation > -3;
  }

  /** The style to show for the current choice; centre = [lat, lng] for "auto". */
  function resolve(cfg, id, centre) {
    var styles = cfg.styles, i;
    if (id !== 'auto') {
      for (i = 0; i < styles.length; i++) if (styles[i].id === id) return styles[i];
    }
    var c = centre || [51.2, 10.4];
    if (!sunUp(c[0], c[1])) {
      for (i = 0; i < styles.length; i++) if (styles[i].night) return styles[i];
    }
    return styles[0];
  }

  function choices(cfg) {
    return [{ id: 'auto', label: cfg.texts.auto }].concat(cfg.styles.map(function (s) { return { id: s.id, label: s.label }; }));
  }

  // ---------------------------------------------------------------- Leaflet
  function leaflet(map, el) {
    var cfg = read(el);
    if (!cfg || !cfg.styles.length) return null;
    var layer = null, shown = null, select;

    function centre() {
      try { var c = map.getCenter(); return [c.lat, c.lng]; } catch (e) { return null; }   // no view set yet
    }
    function apply() {
      var s = resolve(cfg, choice(), centre());
      if (select) select.value = choice();
      if (shown === s.id) return;
      if (layer) map.removeLayer(layer);
      layer = L.tileLayer(s.tiles, { maxZoom: 19, maxNativeZoom: Math.min(19, s.maxzoom), attribution: s.attribution }).addTo(map);
      map.getContainer().classList.toggle('map-invert', !!s.invert);
      shown = s.id;
    }

    var Control = L.Control.extend({
      options: { position: 'topright' },
      onAdd: function () {
        var box = L.DomUtil.create('div', 'leaflet-bar map-style-control');
        select = L.DomUtil.create('select', '', box);
        select.setAttribute('aria-label', cfg.texts.choose);
        select.title = cfg.texts.choose;
        choices(cfg).forEach(function (c) {
          var o = document.createElement('option');
          o.value = c.id;
          o.textContent = c.label;
          select.appendChild(o);
        });
        select.value = choice();
        L.DomEvent.disableClickPropagation(box);
        L.DomEvent.disableScrollPropagation(box);
        select.addEventListener('change', function () {
          remember(select.value);
          window.dispatchEvent(new CustomEvent('eltouro-mapstyle'));
        });
        return box;
      }
    });
    if (cfg.styles.length > 1) new Control().addTo(map);
    apply();
    window.addEventListener('eltouro-mapstyle', apply);
    map.on('moveend', function () { if (choice() === 'auto') apply(); });   // auto follows the sun at the new place
    setInterval(function () { if (choice() === 'auto') apply(); }, 5 * 60 * 1000);
    return { apply: apply };
  }

  // ---------------------------------------------------------------- MapLibre (ride mode)
  function source(s) {
    var r = window.devicePixelRatio > 1 ? '@2x' : '';
    var urls = s.tiles.indexOf('{s}') < 0 ? [s.tiles.replace('{r}', r)]
      : ['a', 'b', 'c'].map(function (x) { return s.tiles.replace('{s}', x).replace('{r}', r); });
    return { type: 'raster', tiles: urls, tileSize: 256, maxzoom: s.maxzoom, attribution: s.attribution };
  }
  function layerSpec(s) {
    // MapLibre has no CSS filter: swapping the brightness range inverts the picture, the hue turn keeps water blue
    var paint = s.invert ? { 'raster-brightness-min': 1, 'raster-brightness-max': 0, 'raster-hue-rotate': 180,
                             'raster-saturation': -0.3, 'raster-contrast': -0.1 } : {};
    return { id: 'tiles', type: 'raster', source: 'tiles', paint: paint };
  }

  function maplibre(el, centre) {
    var cfg = read(el);
    if (!cfg || !cfg.styles.length) return null;
    var map = null, shown = resolve(cfg, choice(), centre), listeners = [];
    function night(s) { return !!(s.night || s.id === 'satellite'); }

    function swap(s) {
      if (!map || s.id === shown.id) return;
      var layers = map.getStyle().layers;
      var before = layers.length > 1 ? layers[1].id : undefined;   // tiles stay the bottom layer
      map.removeLayer('tiles');
      map.removeSource('tiles');
      map.addSource('tiles', source(s));
      map.addLayer(layerSpec(s), before);
      shown = s;
      listeners.forEach(function (fn) { fn(night(s)); });
    }
    function where() {
      if (!map) return centre;
      var c = map.getCenter();
      return [c.lat, c.lng];
    }
    return {
      style: function () { return { version: 8, sources: { tiles: source(shown) }, layers: [layerSpec(shown)] }; },
      attach: function (m) {
        map = m;
        setInterval(function () { if (choice() === 'auto') swap(resolve(cfg, 'auto', where())); }, 5 * 60 * 1000);
      },
      /** Next style (auto → each style → auto); returns the text for a toast. */
      next: function () {
        var list = choices(cfg), cur = choice(), i;
        for (i = 0; i < list.length && list[i].id !== cur; i++) { /* find */ }
        var nextChoice = list[(i + 1) % list.length];
        remember(nextChoice.id);
        swap(resolve(cfg, nextChoice.id, where()));
        return cfg.texts.switched.replace('{name}', nextChoice.id === 'auto' ? cfg.texts.auto + ' · ' + shown.label : nextChoice.label);
      },
      multiple: cfg.styles.length > 1,
      /** Is the shown map dark (night style, satellite)? */
      isNight: function () { return night(shown); },
      onStyleChange: function (fn) { listeners.push(fn); }
    };
  }

  // Pages with a map: the page itself must not zoom (pinch, double-tap) – zoom the map with its + / − buttons or by pinching on the map
  function lockPageZoom() {
    var v = document.querySelector('meta[name=viewport]');
    if (v && v.content.indexOf('user-scalable') < 0) v.content += ', maximum-scale=1, user-scalable=no';
    document.documentElement.style.touchAction = 'manipulation';
  }
  // only where a map element exists (they all carry data-map-styles) – other pages keep normal page zoom
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { if (document.querySelector('[data-map-styles]')) lockPageZoom(); });
  else if (document.querySelector('[data-map-styles]')) lockPageZoom();

  window.ElTouroMaps = { leaflet: leaflet, maplibre: maplibre, sunUp: sunUp };
})();
