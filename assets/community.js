/* ElTouro community: emoji picker for text fields, forum reactions without reload, quoting, photo upload on choice. */
(function () {
  'use strict';
  var box = document.getElementById('community');
  var T = {};
  try { T = JSON.parse((box && box.dataset.texts) || '{}'); } catch (e) { T = {}; }
  var csrf = box ? box.dataset.csrf : '';

  // Ours first, then the everyday ones
  var EMOJIS = ['🐂', '🛴', '⚡', '🔋', '🏁', '🗺️', '📍', '🚦', '☕', '🍺', '🍦', '🍕', '☀️', '🌧️', '🌙', '😎',
                '😂', '😅', '😍', '🥳', '🤘', '🙌', '👍', '👎', '👏', '💪', '🔥', '❤️', '🎉', '🤔', '😬', '🙈', '😴', '📸', '🚧', '🛑'];

  function insertAt(field, text) {
    var start = field.selectionStart != null ? field.selectionStart : field.value.length;
    var end = field.selectionEnd != null ? field.selectionEnd : field.value.length;
    field.value = field.value.slice(0, start) + text + field.value.slice(end);
    var pos = start + text.length;
    field.focus();
    try { field.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
  }

  // ---- Emoji picker under every textarea[data-emoji]
  Array.prototype.forEach.call(document.querySelectorAll('textarea[data-emoji]'), function (field) {
    var bar = document.createElement('div');
    bar.className = 'emoji-bar';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'emoji-toggle';
    toggle.textContent = '😀';
    toggle.setAttribute('aria-label', T.label || 'Emoji');
    toggle.setAttribute('aria-expanded', 'false');
    var grid = document.createElement('div');
    grid.className = 'emoji-grid';
    grid.hidden = true;
    EMOJIS.forEach(function (em) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = em;
      b.addEventListener('click', function () { insertAt(field, em); });
      grid.appendChild(b);
    });
    toggle.addEventListener('click', function () {
      grid.hidden = !grid.hidden;
      toggle.setAttribute('aria-expanded', grid.hidden ? 'false' : 'true');
    });
    bar.appendChild(toggle);
    bar.appendChild(grid);
    field.parentNode.insertBefore(bar, field.nextSibling);
  });

  // ---- Reactions: toggle via the API, the form posts only without JavaScript
  function paint(bar, reactions) {
    Array.prototype.forEach.call(bar.querySelectorAll('button[data-emoji]'), function (b) {
      var r = reactions[b.dataset.emoji];
      b.classList.toggle('mine', !!(r && r.mine));
      b.classList.toggle('empty', !r);
      b.setAttribute('aria-pressed', r && r.mine ? 'true' : 'false');
      b.querySelector('.reaction-n').textContent = r ? r.n : '';
      if (r) b.title = r.names.join(', ') + (r.n > r.names.length ? ' …' : ''); else b.removeAttribute('title');
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.reactions button[data-emoji]') : null;
    if (!b || !csrf) return;
    e.preventDefault();
    var bar = b.closest('.reactions');
    b.disabled = true;
    fetch('/api/forum/react', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify({ post: parseInt(bar.dataset.post, 10), emoji: b.dataset.emoji })
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j) paint(bar, j.reactions || {}); })
      .catch(function () { /* stays as it was */ })
      .then(function () { b.disabled = false; });
  });

  // ---- Quote: the post's text as "> " lines at the end of the reply field
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-quote]') : null;
    if (!b) return;
    var src = document.querySelector('[data-source="' + b.dataset.quote + '"]');
    var field = document.getElementById('text');
    if (!src || !field) return;
    var text = src.textContent.trim();
    // Quotes in the quoted post are left out, long posts are shortened
    text = text.split('\n').filter(function (l) { return l.charAt(0) !== '>'; }).join('\n').trim();
    if (text.length > 500) text = text.slice(0, 500).replace(/\s+\S*$/, '') + ' …';
    var quote = '> ' + (box.dataset.quoteIntro || '{name}:').replace('{name}', '**' + b.dataset.name + '**') + '\n'
              + text.split('\n').map(function (l) { return '> ' + l; }).join('\n') + '\n\n';
    field.value = (field.value.trim() ? field.value.replace(/\s*$/, '\n\n') : '') + quote;
    field.focus();
    field.setSelectionRange(field.value.length, field.value.length);
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  // ---- Profile photo: upload as soon as a file is chosen
  Array.prototype.forEach.call(document.querySelectorAll('input[type=file][data-autosubmit]'), function (input) {
    input.addEventListener('change', function () { if (input.files.length) input.form.submit(); });
  });
})();
