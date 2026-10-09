<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rides_lib.php';
require_once __DIR__ . '/share_lib.php';
require_once __DIR__ . '/rewards_lib.php';
require_once __DIR__ . '/track_lib.php';
require_once __DIR__ . '/poi_lib.php';
require_once __DIR__ . '/drive_lib.php';
require_once __DIR__ . '/spots_lib.php';
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
$myTracks = ownDrives($uid, 12, (int)$tour['id']);
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
  · <a href="#ratings"><?= starsHtml(ratingSummary('tour', (int)$tour['id'])) ?></a>
</p>

<div class="tour-actions">
  <a class="btn" href="/tour/<?= (int)$tour['id'] ?>/go"><?= te('tour.go') ?></a>
  <?php if ($canShare): ?><a class="btn secondary" href="#share" data-open="share"><?= te('tour.share_jump') ?></a><?php endif; ?>
  <?php if ($tour['visibility'] !== 'private'): ?><a class="btn secondary" href="/ride/new?tour=<?= (int)$tour['id'] ?>"><?= te('tour.offer_ride') ?></a><?php endif; ?>
  <details class="more-menu">
    <summary class="btn secondary" aria-label="<?= te('tour.more') ?>" title="<?= te('tour.more') ?>">⋯</summary>
    <div class="more-list">
      <a href="/tour/<?= (int)$tour['id'] ?>/gpx"><?= te('tour.gpx') ?></a>
      <a href="/tour/<?= (int)$tour['id'] ?>/go?sim=1"><?= te('tour.simulate') ?></a>
      <?php if (!$canEdit): ?><a href="/report?type=tour&amp;id=<?= (int)$tour['id'] ?>"><?= te('report.link') ?></a><?php endif; ?>
      <?php if ($canEdit): ?>
        <form method="post" data-confirm="<?= te('tour.delete_confirm') ?>"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="action" value="delete">
          <button class="link danger"><?= te('tour.delete') ?></button></form>
      <?php endif; ?>
    </div>
  </details>
</div>
<p class="hint tour-hint"><?= te('tour.on_site') ?> <?= te('tour.assessment') ?></p>
<?php if ((int)$tour['freehand_share_pct'] > 0): ?>
  <p class="alert alert-info"><?= te('tour.freehand_warning', ['p' => (int)$tour['freehand_share_pct']]) ?></p>
<?php endif; ?>

<div id="tour-map" class="map-large"
     data-geojson="<?= e($tour['geojson']) ?>"
     <?= mapData() ?>
     data-stops="<?= e(stopsForMap(tourStops($tour))) ?>"
     data-start="<?= te('tour.start') ?>" data-finish="<?= te('tour.finish') ?>"></div>

<?php $profile = elevationProfile($tour['geojson']); if ($profile): ?>
<figure class="profile" role="group" aria-label="<?= te('tour.profile_alt', ['min' => $profile['min'], 'max' => $profile['max']]) ?>">
  <?= $profile['svg'] ?>
  <figcaption><?= te('tour.profile_title') ?> · <?= te('tour.profile_range', ['min' => $profile['min'], 'max' => $profile['max']]) ?></figcaption>
</figure>
<?php endif; ?>

<dl class="facts facts-key">
  <div><dt><?= te('tour.length') ?></dt><dd><?= e(formatKm((int)$tour['distance_m'])) ?></dd></div>
  <?php if ($tour['ascent_m'] !== null): ?><div><dt><?= te('tour.ascent') ?></dt><dd><?= (int)$tour['ascent_m'] ?> m</dd></div><?php endif; ?>
  <div><dt><?= te('tour.duration') ?></dt><dd><?= e(formatDuration(tourDurationMinutes((int)$tour['distance_m']))) ?></dd></div>
  <div><dt><?= te('tour.difficulty') ?></dt><dd><?= te('tour.d_' . $tour['difficulty']) ?></dd></div>
</dl>
<ul class="chips">
  <li><?= te('tour.style') ?>: <strong><?= te('tour.s_' . $tour['style']) ?></strong></li>
  <li><?= te('tour.rule_set') ?>: <strong><?= te('tour.r_' . $tour['rule_set']) ?></strong></li>
  <li><?= te('tour.vehicle_class') ?>: <strong><?= te('tour.vc_' . VEHICLE_CLASSES[vehicleClass($tour['vehicle_class'] ?? 2)]) ?></strong></li>
</ul>

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

<?php if ($tour['description']): ?><div class="description"><?= formatText($tour['description']) ?></div><?php endif; ?>

<?= ratingBlock('tour', (int)$tour['id'], $uid, (int)$tour['owner_user_id'] !== $uid, '/tour/' . (int)$tour['id']) ?>

<section>
  <h2><?= te('tour.rides') ?></h2>
  <?php rideCards($tourRides, 'tour.no_rides'); ?>
  <?php if ($tour['visibility'] === 'private'): ?><p class="hint"><?= te('tour.private_no_ride') ?></p><?php endif; ?>
</section>

<?php if ($canShare): $shareUrl = shareUrl($tour); ?>
<details class="panel share-panel" id="share"<?= shareUrl($tour) !== null ? ' open' : '' ?>>
  <summary><?= te('share.title') ?></summary>
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
</details>
<?php endif; ?>





<?php if ($myTracks): ?>
<section class="panel" id="my-rides">
  <h2><?= te('tour.my_rides') ?></h2>
  <?= driveCards($myTracks) ?>
  <p class="hint"><?= te('tour.my_rides_hint') ?></p>
</section>
<?php endif; ?>


<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/tour_map.js?v=7"></script>
<script src="/assets/share.js?v=2"></script>
<?php pageFooter();
