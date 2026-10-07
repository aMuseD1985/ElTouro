<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rides_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$tour = loadTour((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($tour === null || !canSeeTour($tour, $uid)) {
    notFound();
}
$canEdit = canEditTour($tour, $uid);

if (isPost() && ($_POST['action'] ?? '') === 'delete' && $canEdit) {
    checkCsrf();
    // Riders signed up for this route – the tour stays until those rides are over or cancelled
    if (dbOne("SELECT 1 AS x FROM rides WHERE tour_id = ? AND deleted_at IS NULL AND status = 'planned' AND starts_at > UTC_TIMESTAMP() LIMIT 1", [$tour['id']])) {
        flash(t('tour.delete_has_rides'), 'error');
        redirect('/tour.php?id=' . (int)$tour['id']);
    }
    dbExec('UPDATE tours SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tour['id']]);
    flash(t('tour.deleted'));
    redirect('/tours.php');
}

$tourRides = visibleRides($uid, true, 'r.tour_id = ?', [(int)$tour['id']], 10);
pageHeader($tour['title']);
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<div class="title-row">
  <h1><?= e($tour['title']) ?></h1>
  <?php if ($canEdit): ?><a class="btn secondary" href="/tour_plan.php?id=<?= (int)$tour['id'] ?>"><?= te('tour.edit') ?></a><?php endif; ?>
</div>
<p class="muted">
  <?= te('tour.by', ['name' => $tour['creator']]) ?>
  <?php if ($tour['crew_name']): ?> · <a href="/crew.php?s=<?= e(rawurlencode($tour['crew_slug'])) ?>"><?= e($tour['crew_name']) ?></a><?php endif; ?>
  · <?= te('tour.v_' . $tour['visibility']) ?>
</p>

<div id="tour-map" class="map-large"
     data-geojson="<?= e($tour['geojson']) ?>"
     data-tiles="<?= e((string)$CONFIG['map']['tiles']) ?>"
     data-attribution="<?= e((string)$CONFIG['map']['attribution']) ?>"
     data-start="<?= te('tour.start') ?>" data-finish="<?= te('tour.finish') ?>"></div>

<dl class="facts">
  <div><dt><?= te('tour.length') ?></dt><dd><?= e(formatKm((int)$tour['distance_m'])) ?></dd></div>
  <?php if ($tour['ascent_m'] !== null): ?><div><dt><?= te('tour.ascent') ?></dt><dd><?= (int)$tour['ascent_m'] ?> m</dd></div><?php endif; ?>
  <div><dt><?= te('tour.difficulty') ?></dt><dd><?= te('tour.d_' . $tour['difficulty']) ?></dd></div>
  <div><dt><?= te('tour.style') ?></dt><dd><?= te('tour.s_' . $tour['style']) ?></dd></div>
  <div><dt><?= te('tour.rule_set') ?></dt><dd><?= te('tour.r_' . $tour['rule_set']) ?></dd></div>
</dl>
<p class="hint"><?= te('tour.assessment') ?></p>

<?php if ((int)$tour['freehand_share_pct'] > 0): ?>
  <p class="alert alert-info"><?= te('tour.freehand_warning', ['p' => (int)$tour['freehand_share_pct']]) ?></p>
<?php endif; ?>
<p class="alert alert-info"><?= te('tour.on_site') ?></p>

<?php if ($tour['description']): ?><div class="description"><?= formatText($tour['description']) ?></div><?php endif; ?>

<section>
  <h2><?= te('tour.rides') ?></h2>
  <?php rideCards($tourRides, 'tour.no_rides'); ?>
  <?php if ($tour['visibility'] !== 'private'): ?><p><a class="btn" href="/ride_edit.php?tour=<?= (int)$tour['id'] ?>"><?= te('tour.offer_ride') ?></a></p>
  <?php else: ?><p class="hint"><?= te('tour.private_no_ride') ?></p><?php endif; ?>
</section>

<p class="action-bar">
  <a class="btn secondary" href="/tour_gpx.php?id=<?= (int)$tour['id'] ?>"><?= te('tour.gpx') ?></a>
  <a href="/report.php?type=tour&amp;id=<?= (int)$tour['id'] ?>"><?= te('report.link') ?></a>
</p>

<?php if ($canEdit): ?>
<form method="post" class="spaced"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="action" value="delete">
  <button class="link danger"><?= te('tour.delete') ?></button></form>
<?php endif; ?>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/tour_map.js?v=3"></script>
<?php pageFooter();
