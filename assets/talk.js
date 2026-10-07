/* ElTouro crew talk – Discourse-light: load while scrolling, announce new posts,
   reply without reloading, heart reactions, read state. No framework, just fetch + IntersectionObserver. */
(function () {
  'use strict';

  function api(action, data, csrf) {
    return fetch('/talk_api.php?action=' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { var err = new Error(j.error || ('HTTP ' + r.status)); err.userMessage = j.error; throw err; }
        return j;
      });
    });
  }

  function fragment(html) {
    var t = document.createElement('template');
    t.innerHTML = html;
    return t.content;
  }

  /* ---------------- Topic list ---------------- */
  var list = document.getElementById('topics');
  if (list) {
    var csrfList = (document.querySelector('input[name="csrf"]') || {}).value;
    var loader = document.getElementById('topics-more');
    var loading = false;
    var observer = new IntersectionObserver(function (e) {
      if (!e[0].isIntersecting || loading || list.dataset.more !== '1') return;
      loading = true;
      loader.classList.add('loading');
      api('topics', { crew: list.dataset.crew, offset: +list.dataset.offset }, csrfList).then(function (j) {
        list.appendChild(fragment(j.html));
        list.dataset.offset = j.offset;
        list.dataset.more = j.more ? '1' : '0';
      }).catch(function () {}).then(function () { loading = false; loader.classList.remove('loading'); });
    }, { rootMargin: '400px' });
    observer.observe(loader);
  }

  /* ---------------- Post stream ---------------- */
  var stream = document.getElementById('stream');
  if (!stream) return;

  var d = stream.dataset;
  var T = JSON.parse(d.texts || '{}');
  var topic = +d.topic;
  var csrf = d.csrf;
  var box = document.getElementById('posts');
  var loaderAbove = document.getElementById('load-above');
  var loaderBelow = document.getElementById('load-below');
  var notice = document.getElementById('new-notice');
  var form = document.getElementById('composer');
  var field = document.getElementById('composer-text');
  var replyBox = document.getElementById('reply-to');
  var replyId = 0;
  var moreAbove = d.moreAbove === '1';
  var moreBelow = d.moreBelow === '1';
  var loadingAbove = false, loadingBelow = false;
  var readUntil = 0, readReported = 0, readTimer = null;

  function firstId() { var a = box.querySelector('.tb'); return a ? +a.dataset.id : 0; }
  function lastId() { var a = box.querySelectorAll('.tb'); return a.length ? +a[a.length - 1].dataset.id : 0; }

  /* Read state: collect visible posts, report in batches */
  var visible = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) {
        readUntil = Math.max(readUntil, +e.target.dataset.id);
        visible.unobserve(e.target);
      }
    });
    if (readUntil > readReported && !readTimer) {
      readTimer = setTimeout(function () {
        readTimer = null;
        var until = readUntil;
        api('read', { topic: topic, until: until }, csrf).then(function () { readReported = Math.max(readReported, until); }).catch(function () {});
      }, 1500);
    }
  }, { threshold: 0.6 });
  function observePosts(root) {
    (root.querySelectorAll ? root : document).querySelectorAll('.tb').forEach(function (b) { visible.observe(b); });
  }
  observePosts(box);

  /* Load older posts – keep the scroll position so nothing jumps */
  function loadOlder() {
    if (loadingAbove || !moreAbove) return;
    loadingAbove = true;
    loaderAbove.classList.add('loading');
    api('posts', { topic: topic, before: firstId() }, csrf).then(function (j) {
      var before = document.documentElement.scrollHeight;
      var frag = fragment(j.html);
      observePosts(frag);
      box.insertBefore(frag, box.firstChild);
      window.scrollBy(0, document.documentElement.scrollHeight - before);
      moreAbove = j.more;
    }).catch(function () {}).then(function () { loadingAbove = false; loaderAbove.classList.remove('loading'); });
  }

  /* Load newer posts */
  function loadNewer() {
    if (loadingBelow || !moreBelow) return Promise.resolve();
    loadingBelow = true;
    loaderBelow.classList.add('loading');
    return api('posts', { topic: topic, after: lastId() }, csrf).then(function (j) {
      var frag = fragment(j.html);
      observePosts(frag);
      box.appendChild(frag);
      moreBelow = j.more;
    }).catch(function () {}).then(function () { loadingBelow = false; loaderBelow.classList.remove('loading'); });
  }

  new IntersectionObserver(function (e) { if (e[0].isIntersecting) loadOlder(); }, { rootMargin: '300px' }).observe(loaderAbove);
  new IntersectionObserver(function (e) { if (e[0].isIntersecting) loadNewer(); }, { rootMargin: '300px' }).observe(loaderBelow);

  /* Other people's new posts: ask every 20 s, only while the tab is visible */
  function checkNew() {
    if (document.hidden || moreBelow) return;
    api('new', { topic: topic, since: lastId() }, csrf).then(function (j) {
      if (j.count > 0) {
        notice.textContent = T.new_posts.replace('{n}', j.count);
        notice.hidden = false;
      }
    }).catch(function () {});
  }
  setInterval(checkNew, 20000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) checkNew(); });
  notice.addEventListener('click', function () {
    notice.hidden = true;
    moreBelow = true;
    loadNewer().then(function () { var l = box.querySelector('.tb:last-of-type'); if (l) l.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
  });

  /* Actions on posts (event delegation, also works for loaded posts) */
  box.addEventListener('click', function (ev) {
    var jump = ev.target.closest('[data-jump]');
    if (jump) {
      var target = document.getElementById('p' + jump.dataset.jump);
      if (target) { ev.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'center' }); target.classList.add('highlight'); setTimeout(function () { target.classList.remove('highlight'); }, 1600); }
      return;
    }
    var k = ev.target.closest('[data-action]');
    if (!k) return;
    var post = k.closest('.tb');
    var id = +post.dataset.id;

    if (k.dataset.action === 'like') {
      k.disabled = true;
      api('like', { post: id }, csrf).then(function (j) {
        k.classList.toggle('active', j.liked);
        k.setAttribute('aria-pressed', j.liked ? 'true' : 'false');
        k.querySelector('.tb-count').textContent = j.count || '';
      }).catch(function () {}).then(function () { k.disabled = false; });
    }
    if (k.dataset.action === 'reply' && form) {
      replyId = id;
      replyBox.querySelector('span').textContent = T.reply_to.replace('{name}', k.dataset.name);
      replyBox.hidden = false;
      field.focus();
    }
    if (k.dataset.action === 'delete') {
      if (!window.confirm(T.delete_confirm)) return;
      api('delete', { post: id }, csrf).then(function (j) {
        post.replaceWith(fragment(j.html));
      }).catch(function () { window.alert(T.error); });
    }
  });

  /* Composing: Ctrl/Cmd + Enter sends */
  if (form) {
    document.getElementById('reply-clear').addEventListener('click', function () { replyId = 0; replyBox.hidden = true; });
    field.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var text = field.value.trim();
      if (!text) return;
      var button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      api('reply', { topic: topic, text: text, reply_to: replyId }, csrf).then(function (j) {
        if (moreBelow) { window.location = '/talk_topic.php?id=' + topic + '&end=1#p' + j.id; return; }
        box.appendChild(fragment(j.html));
        field.value = '';
        replyId = 0;
        replyBox.hidden = true;
        var created = document.getElementById('p' + j.id);
        if (created) created.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }).catch(function (err) { window.alert(err.userMessage || T.error); }).then(function () { button.disabled = false; });
    });
  }

  /* Entry: scroll to the unread line or to the jump target */
  var anchor = window.location.hash ? document.querySelector(window.location.hash) : document.getElementById('unread');
  if (anchor) { anchor.scrollIntoView({ block: 'center' }); }
})();
