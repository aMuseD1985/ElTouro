<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
requireLogin();

// Search in titles and posts
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$results = null;
if (mb_strlen($q) >= 2) {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $results = dbAll("SELECT t.id, t.title, t.post_count, t.last_post_at, c.slug, c.name_de, c.name_en
                        FROM forum_threads t JOIN forum_categories c ON c.id = t.category_id
                       WHERE t.deleted_at IS NULL AND (t.title LIKE ? OR EXISTS (SELECT 1 FROM forum_posts p WHERE p.thread_id = t.id AND p.deleted_at IS NULL AND p.body LIKE ?))
                       ORDER BY t.last_post_at DESC LIMIT 30", [$like, $like]);
}

$categories = dbAll("SELECT c.*,
                       (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS topics,
                       (SELECT MAX(t.last_post_at) FROM forum_threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS latest
                     FROM forum_categories c ORDER BY c.sort, c.id");
pageHeader(t('forum.title'));
?>
<h1><?= te('forum.title') ?></h1>
<p class="muted"><?= te('forum.intro') ?></p>
<form class="filters" method="get" role="search">
  <div class="filters-row">
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="<?= te('forum.search') ?>" aria-label="<?= te('forum.search') ?>">
    <button type="submit" class="secondary-submit" aria-label="<?= te('forum.search') ?>">🔍</button>
  </div>
</form>
<?php if ($results !== null): ?>
  <h2><?= te('forum.results', ['q' => $q]) ?></h2>
  <?php if (!$results): ?><p class="muted"><?= te('forum.no_results') ?></p><?php else: ?>
  <ul class="forum-list">
    <?php foreach ($results as $r): ?>
      <li><div><h3 class="h3"><a href="/forum/topic/<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></h3>
          <p class="muted"><?= e(categoryName($r)) ?></p></div>
        <div class="count"><?= te('forum.replies', ['n' => max(0, (int)$r['post_count'] - 1)]) ?><br><span class="muted"><?= e(formatDateTime($r['last_post_at'])) ?></span></div></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
<?php endif; ?>
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
