<?php
/** /drive/<id> – one recorded ride as a keepsake: ridden track, planned route of that day, figures, "save as tour". */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rides_lib.php';
require_once __DIR__ . '/drive_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$drive = loadOwnDrive((int)($_GET['id'] ?? 0), $uid);
if ($drive === null) {
    notFound();
}
$id = (int)$drive['id'];

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        deleteTrack($id, $uid);
        flash(t('tour.ride_deleted'));
        redirect('/drives');
    }
    if ($action === 'save_tour' && $drive['saved_tour_id'] === null) {
        $title = postField('title', 120) ?: t('drive.default_title', ['date' => formatRideTime($drive['started_at'])]);
        $tid = saveDriveAsTour($drive, $uid, $title);
        flash($tid ? t('drive.saved') : t('drive.save_failed'), $tid ? 'ok' : 'error');
        redirect($tid ? '/tour/' . $tid : '/drive/' . $id);
    }
    redirect('/drive/' . $id);
}

$pts = drivePoints($id);
$min = (int)round((int)$drive['moving_s'] / 60);
$avg = (int)$drive['moving_s'] > 0 ? number_format((int)$drive['distance_m'] / (int)$drive['moving_s'] * 3.6, 1, t('common.decimal_point'), '') : '–';
$done = isset($_GET['done']);
pageHeader(t('drive.title'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<p class="breadcrumb"><a href="/drives"><?= te('drives.title') ?></a> ›</p>
<div class="title-row"><h1><?= te('drive.title') ?></h1></div>
<p class="muted"><?= e(formatRideTime($drive['started_at'])) ?> · <?= $drive['tour_title'] ? '<a href="/tour/' . (int)$drive['tour_id'] . '">' . e($drive['tour_title']) . '</a>' : te('drive.free') ?></p>

<div id="drive-map" class="map-large" <?= mapData() ?>
     data-ridden="<?= e(driveGeoJson($pts)) ?>"
     data-planned="<?= e((string)($drive['planned_geojson'] ?? '')) ?>"
     data-confetti="<?= $done ? '1' : '0' ?>"></div>
<?php if ($drive['planned_geojson']): ?><p class="hint legend"><span class="lg lg-ridden"></span> <?= te('drive.legend_ridden') ?> · <span class="lg lg-planned"></span> <?= te('drive.legend_planned') ?></p><?php endif; ?>

<dl class="facts facts-key">
  <div><dt><?= te('tour.length') ?></dt><dd><?= e(formatKm((int)$drive['distance_m'])) ?></dd></div>
  <div><dt><?= te('drive.time') ?></dt><dd><?= $min ?> min</dd></div>
  <div><dt><?= te('drive.avg') ?></dt><dd><?= $avg ?> km/h</dd></div>
  <div><dt><?= te('drive.max') ?></dt><dd><?= number_format((float)$drive['max_speed_kmh'], 1, t('common.decimal_point'), '') ?> km/h</dd></div>
</dl>

<section class="panel">
  <h2><?= te('drive.keep_title') ?></h2>
  <?php if ($drive['saved_tour_id'] !== null): ?>
    <p><?= te('drive.saved_as') ?> <a class="btn secondary" href="/tour/<?= (int)$drive['saved_tour_id'] ?>"><?= te('drive.open_tour') ?></a></p>
  <?php else: ?>
    <p class="muted"><?= te('drive.keep_text') ?></p>
    <form method="post" class="form">
      <?= csrfField() ?><input type="hidden" name="action" value="save_tour">
      <div class="field"><label for="title"><?= te('tour.name') ?></label>
        <input id="title" name="title" maxlength="120" value="<?= e(t('drive.default_title', ['date' => formatRideTime($drive['started_at'])])) ?>"></div>
      <button type="submit"><?= te('drive.save_tour') ?></button>
    </form>
  <?php endif; ?>
</section>
<form method="post" class="spaced" data-confirm="<?= te('drive.delete_confirm') ?>"><?= csrfField() ?><input type="hidden" name="action" value="delete">
  <button class="link danger"><?= te('tour.ride_delete') ?></button></form>
<p class="hint"><?= te('tour.my_rides_hint') ?></p>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/confetti.js?v=1"></script>
<script src="/assets/drive_map.js?v=1"></script>
<?php pageFooter();
