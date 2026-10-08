<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
requireLogin();

$categories = dbAll("SELECT c.*,
                       (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS topics,
                       (SELECT MAX(t.last_post_at) FROM forum_threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS latest
                     FROM forum_categories c ORDER BY c.sort, c.id");
pageHeader(t('forum.title'));
?>
<h1><?= te('forum.title') ?></h1>
<p class="muted"><?= te('forum.intro') ?></p>
<ul class="forum-list">
<?php foreach ($categories as $c): ?>
  <li>
    <div><h2 class="h3"><a href="/forum/<?= e(rawurlencode($c['slug'])) ?>"><?= e(categoryName($c)) ?></a></h2>
      <p class="muted"><?= e(categoryDescription($c)) ?></p></div>
    <div class="count"><?= te('forum.topics', ['n' => (int)$c['topics']]) ?>
      <?php if ($c['latest']): ?><br><span class="muted"><?= e(formatDateTime($c['latest'])) ?></span><?php endif; ?></div>
  </li>
<?php endforeach; ?>
</ul>
<?php pageFooter();
