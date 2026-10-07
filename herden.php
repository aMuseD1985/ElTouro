<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/herden_lib.php';
$ich = mussEingeloggtSein();

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$meine = alle("SELECT g.slug, g.name, g.region, m.role, m.status
                 FROM group_members m JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                WHERE m.user_id = ? ORDER BY g.name", [$ich['id']]);

// Nur auffindbare Herden – geheime tauchen hier nie auf
$sql = "SELECT g.slug, g.name, g.region, g.description, g.join_policy,
               (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'active') AS mitglieder
          FROM rider_groups g
         WHERE g.discoverability = 'listed' AND g.deleted_at IS NULL";
$p = [];
if ($q !== '') {
    $sql .= ' AND (g.name LIKE ? OR g.region LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $p = [$like, $like];
}
$liste = alle($sql . ' ORDER BY mitglieder DESC, g.name LIMIT 50', $p);

seitenKopf(t('herden.titel'));
?>
<div class="kopfzeile">
  <h1><?= te('herden.titel') ?></h1>
  <a class="knopf" href="/herde_neu.php"><?= te('herden.neu') ?></a>
</div>

<?php if ($meine): ?>
<section>
  <h2><?= te('herden.meine') ?></h2>
  <ul class="liste">
  <?php foreach ($meine as $h): ?>
    <li><a href="/herde.php?s=<?= e(rawurlencode($h['slug'])) ?>"><?= e($h['name']) ?></a>
      <?php if ($h['role'] === 'admin'): ?><span class="marke-klein"><?= te('herde.leitstier') ?></span><?php endif; ?>
      <?php if ($h['status'] === 'pending'): ?><span class="marke-klein leise"><?= te('herde.anfrage_offen') ?></span><?php endif; ?>
      <?php if ($h['region']): ?><span class="leise"> · <?= e($h['region']) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section>
  <h2><?= te('herden.entdecken') ?></h2>
  <form method="get" class="suche" role="search">
    <label for="q" class="unsichtbar"><?= te('herden.suche') ?></label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= te('herden.suche') ?>">
    <button type="submit"><?= te('herden.suchen') ?></button>
  </form>
  <?php if (!$liste): ?><p class="leise"><?= te('herden.keine') ?></p><?php endif; ?>
  <ul class="karten">
  <?php foreach ($liste as $h): ?>
    <li class="karte">
      <h3><a href="/herde.php?s=<?= e(rawurlencode($h['slug'])) ?>"><?= e($h['name']) ?></a></h3>
      <p class="leise"><?= $h['region'] ? e($h['region']) . ' · ' : '' ?><?= te('herden.mitglieder', ['n' => (int)$h['mitglieder']]) ?></p>
      <?php if ($h['description']): ?><p><?= e(mb_strimwidth($h['description'], 0, 160, '…')) ?></p><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php seitenFuss();
