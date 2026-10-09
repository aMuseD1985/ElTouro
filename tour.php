<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rides_lib.php';
require __DIR__ . '/share_lib.php';
require __DIR__ . '/rewards_lib.php';
require __DIR__ . '/track_lib.php';
require __DIR__ . '/poi_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$tour = loadTour((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($tour === null || !canSeeTour($tour, $uid)) {
    notFound();
}
$canEdit = canEditTour($tour, $uid);
$canShare = canShareTour($tour, $uid);

if (isPost() && in_array($_POST['action'] ?? '', ['share', 'unshare'], true)) {
    checkCsrf();
    if ($_POST['action'] === 'share' && $canShare) {
        $token = enableTourShare((int)$tour['id'], $uid);
        recordEvent($uid, 'share_link_created', 'tour', (int)$tour['id'], null, null, ['token' => $token]);
        flash(t('share.created'));
    } elseif ($_POST['action'] === 'unshare' && $canEdit) {
        disableTourShare((int)$tour['id']);
        flash(t('share.revoked'));
    }
    redirect('/tour/' . (int)$tour['id'] . '#share');
}

if (isPost() && ($_POST['action'] ?? '') === 'delete_track') {
    checkCsrf();
    if (deleteTrack((int)($_POST['track_id'] ?? 0), $uid)) {
        flash(t('tour.ride_deleted'));
    }
    redirect('/tour/' . (int)$tour['id'] . '#my-rides');
}

if (isPost() && ($_POST['action'] ?? '') === 'delete' && $canEdit) {
    checkCsrf();
    // Riders signed up for this route – the tour stays until those rides are over or cancelled
    if (dbOne("SELECT 1 AS x FROM rides WHERE tour_id = ? AND deleted_at IS NULL AND status = 'planned' AND starts_at > UTC_TIMESTAMP() LIMIT 1", [$tour['id']])) {
        flash(t('tour.delete_has_rides'), 'error');
        redirect('/tour/' . (int)$tour['id']);
    }
    dbExec('UPDATE tours SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tour['id']]);
    flash(t('tour.deleted'));
    redirect('/tours');
}

$tourRides = visibleRides($uid, true, 'r.tour_id = ?', [(int)$tour['id']], 10);
$myTracks = ownTracksOfTour($uid, (int)$tour['id']);
$stops = tourStops($tour);
$stopIcons = ['charge' => '⚡', 'food' => '🍽', 'break' => '☕', 'sight' => '👁'];
pageHeader($tour['title']);
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<div class="title-row">
  <h1><?= e($tour['title']) ?></h1>
  <?php if ($canEdit): ?><a class="btn secondary" href="/tour/<?= (int)$tour['id'] ?>/edit"><?= te('tour.edit') ?></a><?php endif; ?>
</div>
<p class="muted">
  <?= te('tour.by', ['name' => $tour['creator']]) ?>
  <?php if ($tour['crew_name']): ?> · <a href="/crew/<?= e(rawurlencode($tour['crew_slug'])) ?>"><?= e($tour['crew_name']) ?></a><?php endif; ?>
  · <?= te('tour.v_' . $tour['visibility']) ?>
</p>

<div id="tour-map" class="map-large"
     data-geojson="<?= e($tour['geojson']) ?>"
     <?= mapData() ?>
     data-start="<?= te('tour.start') ?>" data-finish="<?= te('tour.finish') ?>"></div>

<dl class="facts">
  <div><dt><?= te('tour.length') ?></dt><dd><?= e(formatKm((int)$tour['distance_m'])) ?></dd></div>
  <?php if ($tour['ascent_m'] !== null): ?><div><dt><?= te('tour.ascent') ?></dt><dd><?= (int)$tour['ascent_m'] ?> m</dd></div><?php endif; ?>
  <div><dt><?= te('tour.difficulty') ?></dt><dd><?= te('tour.d_' . $tour['difficulty']) ?></dd></div>
  <div><dt><?= te('tour.style') ?></dt><dd><?= te('tour.s_' . $tour['style']) ?></dd></div>
  <div><dt><?= te('tour.rule_set') ?></dt><dd><?= te('tour.r_' . $tour['rule_set']) ?></dd></div>
  <div><dt><?= te('tour.vehicle_class') ?></dt><dd><?= te('tour.vc_' . VEHICLE_CLASSES[vehicleClass($tour['vehicle_class'] ?? 2)]) ?></dd></div>
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
  <?php if ($tour['visibility'] !== 'private'): ?><p><a class="btn" href="/ride/new?tour=<?= (int)$tour['id'] ?>"><?= te('tour.offer_ride') ?></a></p>
  <?php else: ?><p class="hint"><?= te('tour.private_no_ride') ?></p><?php endif; ?>
</section>

<?php if ($canShare): $shareUrl = shareUrl($tour); ?>
<section class="panel share-panel" id="share">
  <h2><?= te('share.title') ?></h2>
  <p class="muted"><?= te('share.what_is_visible', ['m' => SHARE_PRIVACY_METERS]) ?></p>
  <?php if ($tour['visibility'] !== 'public'): ?><p class="hint"><?= te('share.private_hint') ?></p><?php endif; ?>
  <?php if ($shareUrl === null): ?>
    <form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="action" value="share">
      <button type="submit"><?= te('share.create') ?></button></form>
  <?php else: $text = $tour['title'] . ' – ' . shareSummary($tour); ?>
    <?= shareBox($shareUrl, $tour['title'], $text) ?>
    <?php if ($canEdit): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="action" value="unshare">
        <button class="link danger"><?= te('share.revoke') ?></button></form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($stops): ?>
<section class="panel">
  <h2><?= te('tour.stops') ?></h2>
  <ul class="list">
    <?php foreach ($stops as $st): ?>
      <li><?= $stopIcons[$st['type']] ?> <?= te('tour.stop_line', ['km' => number_format($st['km'], 1, t('common.decimal_point'), ''), 'name' => $st['name'] !== '' ? $st['name'] : t('stop.type_' . $st['type'])]) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<p class="action-bar">
  <a class="btn" href="/tour/<?= (int)$tour['id'] ?>/go"><?= te('tour.go') ?></a>
  <a class="btn secondary" href="/tour/<?= (int)$tour['id'] ?>/go?sim=1"><?= te('tour.simulate') ?></a>
  <a class="btn secondary" href="/tour/<?= (int)$tour['id'] ?>/gpx"><?= te('tour.gpx') ?></a>
  <a href="/report?type=tour&amp;id=<?= (int)$tour['id'] ?>"><?= te('report.link') ?></a>
</p>

<?php if ($myTracks): ?>
<section class="panel" id="my-rides">
  <h2><?= te('tour.my_rides') ?></h2>
  <ul class="list">
    <?php foreach ($myTracks as $tr): ?>
      <li><?= te('tour.my_ride_line', ['date' => formatRideTime($tr['started_at']), 'km' => formatKm((int)$tr['distance_m']),
                                        'min' => (int)round((int)$tr['moving_s'] / 60), 'max' => number_format((float)$tr['max_speed_kmh'], 1, t('common.decimal_point'), '')]) ?>
        <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>">
          <input type="hidden" name="action" value="delete_track"><input type="hidden" name="track_id" value="<?= (int)$tr['id'] ?>">
          <button class="link danger"><?= te('tour.ride_delete') ?></button></form></li>
    <?php endforeach; ?>
  </ul>
  <p class="hint"><?= te('tour.my_rides_hint') ?></p>
</section>
<?php endif; ?>

<?php if ($canEdit): ?>
<form method="post" class="spaced"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="action" value="delete">
  <button class="link danger"><?= te('tour.delete') ?></button></form>
<?php endif; ?>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/tour_map.js?v=6"></script>
<script src="/assets/share.js?v=1"></script>
<?php pageFooter();
