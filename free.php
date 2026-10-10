<?php
/** /free – record a ride without a planned tour; afterwards it can be saved as a tour. Closing the page does not end it. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/track_lib.php';
$me = requireLogin();
$resume = activeRecording((int)$me['id'], null);
pageHeader(t('free.title'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<h1><?= te('free.title') ?></h1>
<p class="muted"><?= te($resume ? 'free.resumed' : 'free.intro') ?></p>
<section class="panel free-ride" id="free" data-csrf="<?= e(csrfToken()) ?>" data-running="<?= te('nav.ride_running') ?>" data-resumed="<?= te('free.resumed') ?>" data-decimal="<?= te('common.decimal_point') ?>" data-resume="<?= $resume ? (int)$resume['id'] : '' ?>"
         data-started="<?= $resume ? e(gmdate('c', (int)strtotime($resume['started_at'] . ' UTC'))) : '' ?>">
  <div id="free-map" class="map-large" <?= mapData() ?> data-lat="51.4" data-lng="7.0"></div>
  <p class="hint legend"><span class="lg lg-ridden"></span> <?= te('free.legend_track') ?> · <span class="dot-wp"></span> <?= te('free.legend_wp') ?> · <span class="dot-me"></span> <?= te('free.legend_me') ?></p>
  <div class="free-stats">
    <div><span id="f-km">0,0</span><small>km</small></div>
    <div><span id="f-time">0:00</span><small><?= te('free.time') ?></small></div>
    <div><span id="f-speed">0</span><small>km/h</small></div>
  </div>
  <p id="f-msg" class="hint" role="status"></p>
  <button id="f-start" type="button"><?= te('free.start') ?></button>
  <button id="f-stop" type="button" class="btn hold-btn" hidden><span class="hold-fill"></span><?= te('nav.stop_button') ?><small><?= te('nav.stop_hold') ?></small></button>
</section>
<p class="hint"><?= te('free.privacy') ?> <?= te('nav.screen_hint') ?></p>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/free_ride.js?v=2"></script>
<?php pageFooter();
