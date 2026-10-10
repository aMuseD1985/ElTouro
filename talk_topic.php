<?php
/**
 * One crew-talk topic. Opens at the first unread post (like Discourse); older and newer posts
 * are loaded while scrolling, new replies are announced via polling every 20 s.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/talk_lib.php';
require_once __DIR__ . '/rides_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$topic = loadTalkTopic((int)($_GET['id'] ?? $_POST['id'] ?? 0));
$crew = $topic ? loadCrew($topic['crew_slug']) : null;
[$allowed, $moderator] = $crew ? talkAccess($crew, $me) : [false, false];
if (!$allowed) {
    notFound();
}
$tid = (int)$topic['id'];

// Moderation without JavaScript via plain forms
if (isPost() && $moderator) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'pin') dbExec('UPDATE herd_topics SET is_pinned = 1 - is_pinned WHERE id = ?', [$tid]);
    if ($action === 'lock') dbExec('UPDATE herd_topics SET is_locked = 1 - is_locked WHERE id = ?', [$tid]);
    if ($action === 'delete_topic') {
        dbExec('UPDATE herd_topics SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tid]);
        redirect('/crew/' . rawurlencode($topic['crew_slug']) . '/talk');
    }
    redirect('/talk/' . $tid);
}

$read = dbOne('SELECT last_read_post_id FROM herd_reads WHERE topic_id = ? AND user_id = ?', [$tid, $uid]);
$toEnd = isset($_GET['end']);
$firstUnread = null;

if ($toEnd) {
    $posts = loadTalkPosts($tid, $uid, '1=1', [], true);
} elseif ($read !== null) {
    $firstUnread = dbOne('SELECT MIN(id) AS id FROM herd_posts WHERE topic_id = ? AND id > ? AND user_id <> ?', [$tid, $read['last_read_post_id'], $uid])['id'];
    if ($firstUnread !== null) {
        // a few read posts as context, then the unread ones
        $before = loadTalkPosts($tid, $uid, 'p.id < ?', [$firstUnread], true, 3);
        $after = loadTalkPosts($tid, $uid, 'p.id >= ?', [$firstUnread]);
        $posts = [...$before, ...$after];
    } else {
        $posts = loadTalkPosts($tid, $uid, '1=1', [], true);   // everything read: the newest
    }
} else {
    $posts = loadTalkPosts($tid, $uid);   // first visit: from the start
}

$firstId = $posts ? (int)$posts[0]['id'] : 0;
$lastId = $posts ? (int)end($posts)['id'] : 0;
$moreAbove = $firstId && dbOne('SELECT 1 AS x FROM herd_posts WHERE topic_id = ? AND id < ? LIMIT 1', [$tid, $firstId]) !== null;
$moreBelow = $lastId && dbOne('SELECT 1 AS x FROM herd_posts WHERE topic_id = ? AND id > ? LIMIT 1', [$tid, $lastId]) !== null;
$jsTexts = [];
foreach (['new_posts', 'reply_to', 'delete_confirm', 'error', 'loading', 'no_more'] as $k) {
    $jsTexts[$k] = t('talk.js_' . $k);
}
$ride = dbOne("SELECT id FROM rides WHERE talk_topic_id = ? AND deleted_at IS NULL", [$tid]);

$GLOBALS['PARENT_PAGE'] = '/crew/' . rawurlencode((string)$topic['crew_slug']) . '/talk';
pageHeader($topic['title']);
?>
<p class="breadcrumbs"><a href="/crew/<?= e(rawurlencode($topic['crew_slug'])) ?>"><?= e($topic['crew_name']) ?></a> ›
  <a href="/crew/<?= e(rawurlencode($topic['crew_slug'])) ?>/talk"><?= te('talk.title') ?></a> ›</p>
<h1><?= e($topic['title']) ?></h1>
<?php if ($ride): ?><p><a class="btn secondary" href="<?= rideUrl($ride) ?>"><?= te('ride.to_ride') ?></a></p><?php endif; ?>
<?php if ($topic['is_locked']): ?><p class="alert alert-info"><?= te('forum.locked_text') ?></p><?php endif; ?>

<?php if ($moderator): ?>
<div class="mod-bar">
  <?php foreach (['pin' => $topic['is_pinned'] ? 'forum.m_unpin' : 'forum.m_pin',
                  'lock' => $topic['is_locked'] ? 'forum.m_unlock' : 'forum.m_lock',
                  'delete_topic' => 'forum.m_delete'] as $a => $k): ?>
    <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= $tid ?>"><input type="hidden" name="action" value="<?= $a ?>">
      <button class="link<?= $a === 'delete_topic' ? ' danger' : '' ?>"><?= te($k) ?></button></form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div id="stream" class="stream"
     data-topic="<?= $tid ?>" data-csrf="<?= e(csrfToken()) ?>"
     data-more-above="<?= $moreAbove ? '1' : '0' ?>" data-more-below="<?= $moreBelow ? '1' : '0' ?>"
     data-texts="<?= e(json_encode($jsTexts, JSON_UNESCAPED_UNICODE)) ?>">
  <div id="load-above" class="loader"></div>
  <div id="posts">
  <?php foreach ($posts as $p):
      if ($firstUnread !== null && (int)$p['id'] === (int)$firstUnread): ?>
      <div class="unread-line" id="unread"><span><?= te('talk.unread_since') ?></span></div>
  <?php endif;
      echo renderTalkPost($p, $uid, $moderator);
  endforeach; ?>
  </div>
  <div id="load-below" class="loader"></div>
</div>

<button type="button" id="new-notice" class="new-notice" hidden></button>

<?php if (!$topic['is_locked'] || $moderator): ?>
<form id="composer" class="composer" method="post" action="/api/talk?action=reply">
  <div id="reply-to" class="reply-to" hidden><span></span> <button type="button" class="link" id="reply-clear" aria-label="<?= te('talk.reply_clear') ?>">✕</button></div>
  <label for="composer-text" class="visually-hidden"><?= te('forum.text') ?></label>
  <textarea id="composer-text" rows="3" maxlength="<?= TALK_POST_MAX ?>" placeholder="<?= te('talk.placeholder') ?>" required data-emoji></textarea>
  <div class="composer-bar"><span class="hint"><?= te('talk.send_hint') ?></span><button type="submit"><?= te('forum.send') ?></button></div>
</form>
<?php endif; ?>
<script src="/assets/talk.js?v=2"></script>
<?= communityAssets() ?>
<?php pageFooter();
