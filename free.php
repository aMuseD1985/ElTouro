<?php
/** /free – record a ride without a planned tour; afterwards it can be saved as a tour. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/track_lib.php';
$me = requireLogin();
pageHeader(t('free.title'));
?>
<h1><?= te('free.title') ?></h1>
<p class="muted"><?= te('free.intro') ?></p>
<section class="panel free-ride" id="free" data-csrf="<?= e(csrfToken()) ?>" data-decimal="<?= te('common.decimal_point') ?>">
  <div class="free-stats">
    <div><span id="f-km">0,0</span><small>km</small></div>
    <div><span id="f-time">0:00</span><small><?= te('free.time') ?></small></div>
    <div><span id="f-speed">0</span><small>km/h</small></div>
  </div>
  <p id="f-msg" class="hint" role="status"></p>
  <button id="f-start" type="button"><?= te('free.start') ?></button>
  <button id="f-stop" type="button" class="btn hold-btn" hidden><span class="hold-fill"></span><?= te('nav.stop_button') ?><small><?= te('nav.stop_hold') ?></small></button>
</section>
<p class="hint"><?= te('free.privacy') ?></p>
<script src="/assets/free_ride.js?v=1"></script>
<?php pageFooter();
