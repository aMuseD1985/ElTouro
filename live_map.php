<?php
/** /live – who is riding right now? Riders who share their live location with the viewer (see live_lib.php). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tours_lib.php';
$me = requireLogin();
// Start where the viewer last planned, otherwise in the middle of Germany
$last = dbOne('SELECT start_lat, start_lng FROM tours WHERE owner_user_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 1', [(int)$me['id']]);
$texts = [];
foreach (['zoom_in', 'none', 'riders', 'ago', 'locate_error'] as $k) {
    $texts[$k] = t('live.' . $k);
}
pageHeader(t('live.title'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<h1><?= te('live.title') ?></h1>
<p class="hint"><?= te('live.intro') ?></p>
<p class="planner-bar"><button type="button" class="link" id="live-locate"><?= te('planner.locate') ?></button></p>
<div id="live-map" class="map-large"
     <?= mapData() ?>
     data-center="<?= e($last ? $last['start_lat'] . ',' . $last['start_lng'] : '51.2,10.4') ?>" data-zoom="<?= $last ? 12 : 6 ?>"
     data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>"></div>
<p id="live-status" class="muted" role="status" aria-live="polite"></p>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/live_map.js?v=3"></script>
<?php pageFooter();
