<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
requireLogin();

$cat = dbOne('SELECT * FROM forum_categories WHERE slug = ?', [(string)($_GET['c'] ?? '')]);
if ($cat === null) {
    notFound();
}
$topics = dbAll('SELECT t.id, t.title, t.is_pinned, t.is_locked, t.post_count, t.last_post_at, u.display_name
                   FROM forum_threads t JOIN users u ON u.id = t.user_id
                  WHERE t.category_id = ? AND t.deleted_at IS NULL
                  ORDER BY t.is_pinned DESC, t.last_post_at DESC LIMIT 100', [$cat['id']]);

pageHeader(categoryName($cat));
?>
<p class="breadcrumbs"><a href="/forum"><?= te('forum.title') ?></a> ›</p>
<div class="title-row">
  <h1><?= e(categoryName($cat)) ?></h1>
  <a class="btn" href="/forum/<?= e(rawurlencode($cat['slug'])) ?>/new"><?= te('forum.new_topic') ?></a>
</div>
<?php if (!$topics): ?><p class="muted"><?= te('forum.no_topics') ?></p><?php endif; ?>
<ul class="forum-list">
<?php foreach ($topics as $t): ?>
  <li>
    <div>
      <a href="/forum/topic/<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
      <?php if ($t['is_pinned']): ?><span class="badge"><?= te('forum.pinned') ?></span><?php endif; ?>
      <?php if ($t['is_locked']): ?><span class="badge muted"><?= te('forum.locked') ?></span><?php endif; ?>
      <p class="muted"><?= te('forum.by', ['name' => $t['display_name']]) ?></p>
    </div>
    <div class="count"><?= te('forum.replies', ['n' => max(0, (int)$t['post_count'] - 1)]) ?><br>
      <span class="muted"><?= e(formatDateTime($t['last_post_at'])) ?></span></div>
  </li>
<?php endforeach; ?>
</ul>
<?php pageFooter();
