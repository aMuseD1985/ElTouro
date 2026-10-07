<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
mussEingeloggtSein();

$kategorien = alle("SELECT c.*,
                      (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS themen,
                      (SELECT MAX(t.last_post_at) FROM forum_threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS zuletzt
                    FROM forum_categories c ORDER BY c.sort, c.id");
seitenKopf(t('forum.titel'));
?>
<h1><?= te('forum.titel') ?></h1>
<p class="leise"><?= te('forum.intro') ?></p>
<ul class="forumliste">
<?php foreach ($kategorien as $k): ?>
  <li>
    <div><h2 class="h3"><a href="/forum_kategorie.php?k=<?= e(rawurlencode($k['slug'])) ?>"><?= e(kategorieName($k)) ?></a></h2>
      <p class="leise"><?= e(kategorieText($k)) ?></p></div>
    <div class="zahl"><?= te('forum.themen', ['n' => (int)$k['themen']]) ?>
      <?php if ($k['zuletzt']): ?><br><span class="leise"><?= e(zeitAnzeige($k['zuletzt'])) ?></span><?php endif; ?></div>
  </li>
<?php endforeach; ?>
</ul>
<?php seitenFuss();
