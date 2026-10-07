<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/crews_lib.php';
$me = requireLogin();

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$mine = dbAll("SELECT g.slug, g.name, g.region, m.role, m.status
                 FROM group_members m JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                WHERE m.user_id = ? ORDER BY g.name", [$me['id']]);

// Only listed crews – secret ones never show up here
$sql = "SELECT g.slug, g.name, g.region, g.description, g.join_policy,
               (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'active') AS members
          FROM rider_groups g
         WHERE g.discoverability = 'listed' AND g.deleted_at IS NULL";
$p = [];
if ($q !== '') {
    $sql .= ' AND (g.name LIKE ? OR g.region LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $p = [$like, $like];
}
$list = dbAll($sql . ' ORDER BY members DESC, g.name LIMIT 50', $p);

pageHeader(t('crews.title'));
?>
<div class="title-row">
  <h1><?= te('crews.title') ?></h1>
  <a class="btn" href="/crew_new.php"><?= te('crews.new') ?></a>
</div>

<?php if ($mine): ?>
<section>
  <h2><?= te('crews.mine') ?></h2>
  <ul class="list">
  <?php foreach ($mine as $c): ?>
    <li><a href="/crew.php?s=<?= e(rawurlencode($c['slug'])) ?>"><?= e($c['name']) ?></a>
      <?php if ($c['role'] === 'admin'): ?><span class="badge"><?= te('crew.lead') ?></span><?php endif; ?>
      <?php if ($c['status'] === 'pending'): ?><span class="badge muted"><?= te('crew.request_pending') ?></span><?php endif; ?>
      <?php if ($c['region']): ?><span class="muted"> · <?= e($c['region']) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section>
  <h2><?= te('crews.discover') ?></h2>
  <form method="get" class="search" role="search">
    <label for="q" class="visually-hidden"><?= te('crews.search') ?></label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= te('crews.search') ?>">
    <button type="submit"><?= te('crews.search_button') ?></button>
  </form>
  <?php if (!$list): ?><p class="muted"><?= te('crews.none') ?></p><?php endif; ?>
  <ul class="cards">
  <?php foreach ($list as $c): ?>
    <li class="card">
      <h3><a href="/crew.php?s=<?= e(rawurlencode($c['slug'])) ?>"><?= e($c['name']) ?></a></h3>
      <p class="muted"><?= $c['region'] ? e($c['region']) . ' · ' : '' ?><?= te('crews.members', ['n' => (int)$c['members']]) ?></p>
      <?php if ($c['description']): ?><p><?= e(mb_strimwidth($c['description'], 0, 160, '…')) ?></p><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php pageFooter();
