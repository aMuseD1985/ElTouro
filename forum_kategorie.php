<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
mussEingeloggtSein();

$kat = einzeln('SELECT * FROM forum_categories WHERE slug = ?', [(string)($_GET['k'] ?? '')]);
if ($kat === null) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}
$themen = alle('SELECT t.id, t.title, t.is_pinned, t.is_locked, t.post_count, t.last_post_at, u.display_name
                  FROM forum_threads t JOIN users u ON u.id = t.user_id
                 WHERE t.category_id = ? AND t.deleted_at IS NULL
                 ORDER BY t.is_pinned DESC, t.last_post_at DESC LIMIT 100', [$kat['id']]);

seitenKopf(kategorieName($kat));
?>
<p class="brotkrumen"><a href="/forum.php"><?= te('forum.titel') ?></a> ›</p>
<div class="kopfzeile">
  <h1><?= e(kategorieName($kat)) ?></h1>
  <a class="knopf" href="/forum_neu.php?k=<?= e(rawurlencode($kat['slug'])) ?>"><?= te('forum.neues_thema') ?></a>
</div>
<?php if (!$themen): ?><p class="leise"><?= te('forum.keine_themen') ?></p><?php endif; ?>
<ul class="forumliste">
<?php foreach ($themen as $t): ?>
  <li>
    <div>
      <a href="/forum_thema.php?t=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
      <?php if ($t['is_pinned']): ?><span class="marke-klein"><?= te('forum.angepinnt') ?></span><?php endif; ?>
      <?php if ($t['is_locked']): ?><span class="marke-klein leise"><?= te('forum.gesperrt') ?></span><?php endif; ?>
      <p class="leise"><?= te('forum.von', ['name' => $t['display_name']]) ?></p>
    </div>
    <div class="zahl"><?= te('forum.antworten', ['n' => max(0, (int)$t['post_count'] - 1)]) ?><br>
      <span class="leise"><?= e(zeitAnzeige($t['last_post_at'])) ?></span></div>
  </li>
<?php endforeach; ?>
</ul>
<?php seitenFuss();
