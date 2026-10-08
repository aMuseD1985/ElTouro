<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$mod = isForumModerator($me);

$topic = dbOne('SELECT t.*, c.slug AS cat_slug, c.name_de, c.name_en FROM forum_threads t
                  JOIN forum_categories c ON c.id = t.category_id WHERE t.id = ? AND t.deleted_at IS NULL', [(int)($_GET['t'] ?? 0)]);
if ($topic === null) {
    notFound();
}
$tid = (int)$topic['id'];
$self = '/forum/topic/' . $tid;
$error = '';
$draft = '';

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    $pid = (int)($_POST['post'] ?? 0);

    if ($action === 'reply') {
        $draft = trim(str_replace("\r", '', (string)($_POST['text'] ?? '')));
        if ($topic['is_locked'] && !$mod) {
            $error = t('forum.locked_text');
        } elseif (mb_strlen($draft) < 2 || mb_strlen($draft) > FORUM_POST_MAX) {
            $error = t('forum.error_text', ['max' => FORUM_POST_MAX]);
        } elseif (!canPostNow($uid)) {
            $error = t('forum.too_fast');
        } else {
            dbExec('INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)', [$tid, $uid, $draft]);
            $new = (int)db()->lastInsertId();
            dbExec('UPDATE forum_threads SET post_count = post_count + 1, last_post_at = UTC_TIMESTAMP() WHERE id = ?', [$tid]);
            $count = (int)dbOne('SELECT COUNT(*) AS n FROM forum_posts WHERE thread_id = ?', [$tid])['n'];
            redirect($self . '?p=' . (int)ceil($count / FORUM_POSTS_PER_PAGE) . '#b' . $new);
        }
    } elseif ($action === 'delete_post') {
        // Own posts may be deleted, moderators may delete all
        dbExec('UPDATE forum_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND thread_id = ? AND (user_id = ? OR ? = 1)',
            [$pid, $tid, $uid, $mod ? 1 : 0]);
        redirect($self . '?p=' . max(1, (int)($_POST['p'] ?? 1)));
    } elseif ($mod && in_array($action, ['pin', 'lock', 'delete_topic'], true)) {
        match ($action) {
            'pin'          => dbExec('UPDATE forum_threads SET is_pinned = 1 - is_pinned WHERE id = ?', [$tid]),
            'lock'         => dbExec('UPDATE forum_threads SET is_locked = 1 - is_locked WHERE id = ?', [$tid]),
            'delete_topic' => dbExec('UPDATE forum_threads SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tid]),
        };
        redirect($action === 'delete_topic' ? '/forum/' . rawurlencode($topic['cat_slug']) : $self);
    }
}

$total = (int)dbOne('SELECT COUNT(*) AS n FROM forum_posts WHERE thread_id = ?', [$tid])['n'];
$pages = max(1, (int)ceil($total / FORUM_POSTS_PER_PAGE));
$page = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$posts = dbAll('SELECT p.id, p.user_id, p.body, p.created_at, p.deleted_at, u.display_name
                  FROM forum_posts p JOIN users u ON u.id = p.user_id
                 WHERE p.thread_id = ? ORDER BY p.created_at, p.id LIMIT ' . FORUM_POSTS_PER_PAGE . ' OFFSET ' . (($page - 1) * FORUM_POSTS_PER_PAGE), [$tid]);

pageHeader($topic['title']);
?>
<p class="breadcrumbs"><a href="/forum"><?= te('forum.title') ?></a> › <a href="/forum/<?= e(rawurlencode($topic['cat_slug'])) ?>"><?= e(categoryName($topic)) ?></a> ›</p>
<h1><?= e($topic['title']) ?></h1>
<?php if ($topic['is_locked']): ?><p class="alert alert-info"><?= te('forum.locked_text') ?></p><?php endif; ?>

<?php if ($mod): ?>
<div class="mod-bar">
  <?php foreach (['pin' => $topic['is_pinned'] ? 'forum.m_unpin' : 'forum.m_pin',
                  'lock' => $topic['is_locked'] ? 'forum.m_unlock' : 'forum.m_lock',
                  'delete_topic' => 'forum.m_delete'] as $a => $k): ?>
    <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="<?= $a ?>"><button class="link<?= $a === 'delete_topic' ? ' danger' : '' ?>"><?= te($k) ?></button></form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<ol class="posts">
<?php foreach ($posts as $b): ?>
  <li class="post" id="b<?= (int)$b['id'] ?>">
    <header><strong><?= e($b['display_name']) ?></strong> <span class="muted">· <?= e(formatDateTime($b['created_at'])) ?></span></header>
    <?php if ($b['deleted_at']): ?>
      <p class="muted"><em><?= te('forum.deleted') ?></em></p>
    <?php else: ?>
      <div class="post-text"><?= formatText($b['body']) ?></div>
      <footer>
        <a href="/report?type=post&amp;id=<?= (int)$b['id'] ?>"><?= te('report.link') ?></a>
        <?php if ((int)$b['user_id'] === $uid || $mod): ?>
          <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post" value="<?= (int)$b['id'] ?>"><input type="hidden" name="p" value="<?= $page ?>">
            <button class="link danger"><?= te('forum.delete') ?></button></form>
        <?php endif; ?>
      </footer>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ol>

<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="<?= te('forum.pages') ?>">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="<?= e($self) ?>?p=<?= $i ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>

<?php if (!$topic['is_locked'] || $mod): ?>
<section id="reply">
  <h2><?= te('forum.reply_title') ?></h2>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form wide">
    <?= csrfField() ?><input type="hidden" name="action" value="reply">
    <div class="field"><label for="text" class="visually-hidden"><?= te('forum.text') ?></label>
      <textarea id="text" name="text" rows="6" required maxlength="<?= FORUM_POST_MAX ?>"><?= e($draft) ?></textarea>
      <p class="hint"><?= te('forum.format') ?></p></div>
    <button type="submit"><?= te('forum.send') ?></button>
  </form>
</section>
<?php endif; ?>
<?php pageFooter();
