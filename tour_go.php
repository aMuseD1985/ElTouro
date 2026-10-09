<?php
/**
 * Ride mode: /tour/<id>/go – navigation with voice prompts along the saved track; /tour/<id>/go?sim=1 rides it in time-lapse.
 * Everything runs in the browser (assets/navigate.js). The position only leaves the device when the rider switches
 * recording on (track.php).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
require __DIR__ . '/poi_lib.php';
$me = requireLogin();
$tour = loadTour((int)($_GET['id'] ?? 0));
if ($tour === null || !canSeeTour($tour, (int)$me['id'])) {
    notFound();
}

$keys = ['start', 'start_sim', 'arrive', 'halfway', 'offroute', 'back', 'speed', 'gps_wait', 'gps_error', 'gps_denied', 'wakelock',
         'in_m', 'in_km', 'now', 'remaining', 'eta_min', 'eta_h', 'speed_unit', 'sim_clock', 'follow', 'voice_on', 'voice_off', 'voice_pick', 'voice_changed', 'voice_sample',
         'view_heading', 'view_3d', 'view_north', 'far_stop', 'now_stop_charge', 'now_stop_food', 'now_stop_break', 'now_stop_sight', 'label_stop', 'done_title', 'done_arrived', 'done_text', 'done_recorded', 'done_not_recorded', 'saving', 'no_hints'];
foreach (['left', 'slight_left', 'sharp_left', 'right', 'slight_right', 'sharp_right', 'keep_left', 'keep_right', 'uturn',
          'roundabout', 'exit_left', 'exit_right', 'straight'] as $m) {
    array_push($keys, 'far_' . $m, 'now_' . $m, 'label_' . $m);
}
$texts = [];
foreach ($keys as $k) {
    $texts[$k] = t('nav.' . $k);
}
$sim = isset($_GET['sim']);
pageHeader(t('nav.title', ['title' => $tour['title']]));
?>
<link rel="stylesheet" href="/assets/vendor/maplibre/maplibre-gl.css">
<div class="ride-mode" id="ride"
     data-geojson="<?= e($tour['geojson']) ?>"
     data-guidance="<?= e($tour['guidance_json'] ?? '[]') ?>"
     data-stops="<?= e(json_encode(array_map(fn($s) => ['lat' => $s['lat'], 'lng' => $s['lng'], 'type' => $s['type'], 'name' => $s['name'] !== '' ? $s['name'] : t('stop.type_' . $s['type'])], tourStops($tour)), JSON_UNESCAPED_UNICODE)) ?>"
     <?= mapData() ?>
     data-csrf="<?= e(csrfToken()) ?>"
     data-lang="<?= e($LANG) ?>"
     data-tour="<?= (int)$tour['id'] ?>"
     data-sim="<?= $sim ? '1' : '0' ?>"
     data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>">
  <div id="ride-map" class="ride-map"></div>

  <div class="ride-banner" id="ride-banner" aria-live="polite">
    <div class="ride-arrow" id="ride-arrow" aria-hidden="true"><span>⬆</span></div>
    <div class="ride-next">
      <strong id="ride-dist">–</strong>
      <span id="ride-text"><?= te($sim ? 'nav.ready_sim' : 'nav.ready') ?></span>
    </div>
  </div>
  <p class="ride-subtitle" id="ride-subtitle" hidden></p>

  <div class="ride-panel">
    <div class="ride-stats">
      <div><span id="ride-remaining">–</span><small><?= te('nav.stat_remaining') ?></small></div>
      <div><span id="ride-eta">–</span><small><?= te('nav.stat_eta') ?></small></div>
      <div><span id="ride-speed">–</span><small><?= te($sim ? 'nav.stat_clock' : 'nav.stat_speed') ?></small></div>
    </div>
    <div class="ride-controls">
      <?php if ($sim): ?>
        <label class="ride-factor"><?= te('nav.factor') ?>
          <select id="ride-factor"><option value="10">×10</option><option value="30" selected>×30</option><option value="60">×60</option><option value="120">×120</option></select></label>
        <button type="button" id="ride-start" class="btn"><?= te('nav.start_sim_button') ?></button>
      <?php else: ?>
        <label class="ride-record"><input type="checkbox" id="ride-record"> <?= te('nav.record') ?></label>
        <label class="ride-record"><input type="checkbox" id="ride-live"> <?= te('nav.live') ?>
          <select id="ride-live-scope" aria-label="<?= te('nav.live_scope') ?>">
            <option value="crews"><?= te('nav.live_crews') ?></option><option value="all"><?= te('nav.live_all') ?></option></select></label>
        <button type="button" id="ride-start" class="btn"><?= te('nav.start_button') ?></button>
      <?php endif; ?>
      <button type="button" id="ride-stop" class="btn secondary" hidden><?= te('nav.stop_button') ?></button>
      <div class="ride-tools">
        <button type="button" id="ride-voice" class="rt" aria-pressed="true"><span class="ico">🔊</span><span class="lbl"><?= te('nav.lbl_voice') ?></span></button>
        <button type="button" id="ride-voice-pick" class="rt" title="<?= te('nav.voice_pick') ?>" aria-label="<?= te('nav.voice_pick') ?>" hidden><span class="ico">🎙</span><span class="lbl"><?= te('nav.lbl_pick') ?></span></button>
        <button type="button" id="ride-view" class="rt"><span class="ico">➤</span><span class="lbl"><?= te('nav.lbl_view') ?></span></button>
        <button type="button" id="ride-style" class="rt" title="<?= te('map.style_choose') ?>" aria-label="<?= te('map.style_choose') ?>"><span class="ico">🗺</span><span class="lbl"><?= te('nav.lbl_map') ?></span></button>
        <button type="button" id="ride-follow" class="rt" title="<?= te('nav.follow') ?>" aria-label="<?= te('nav.follow') ?>"><span class="ico">⌖</span><span class="lbl"><?= te('nav.lbl_follow') ?></span></button>
        <a class="rt ride-close" id="ride-close" href="/tour/<?= (int)$tour['id'] ?>" aria-label="<?= te('nav.close') ?>"><span class="ico">✕</span><span class="lbl"><?= te('nav.lbl_close') ?></span></a>
      </div>
    </div>
    <?php if (!$sim): ?><p class="ride-hint"><?= te('nav.record_hint') ?> <?= te('nav.live_hint') ?></p><?php endif; ?>
  </div>

  <div class="ride-done" id="ride-confirm" hidden role="dialog" aria-modal="true" aria-labelledby="ride-confirm-title">
    <div class="ride-done-box">
      <h2 id="ride-confirm-title"><?= te('nav.confirm_title') ?></h2>
      <p><?= te('nav.confirm_text') ?></p>
      <p class="confirm-buttons"><button type="button" class="btn" id="ride-confirm-stay"><?= te('nav.confirm_stay') ?></button>
        <button type="button" class="btn secondary" id="ride-confirm-end"><?= te('nav.confirm_end') ?></button></p>
    </div>
  </div>

  <div class="ride-done" id="ride-intro" hidden role="dialog" aria-modal="true" aria-labelledby="ride-intro-title">
    <div class="ride-done-box">
      <h2 id="ride-intro-title"><?= te('nav.intro_title') ?></h2>
      <ul class="intro-list">
        <li>▶ <?= te('nav.intro_1') ?></li><li>🔊 🎙 <?= te('nav.intro_2') ?></li><li>➤ 🗺 <?= te('nav.intro_3') ?></li><li>⌖ <?= te('nav.intro_4') ?></li><li>✕ <?= te('nav.intro_5') ?></li>
      </ul>
      <p><button type="button" class="btn" id="ride-intro-ok"><?= te('nav.intro_ok') ?></button></p>
    </div>
  </div>

  <div class="ride-done" id="ride-done" hidden>
    <div class="ride-done-box">
      <img class="ride-done-mascot" src="/assets/img/mascot/look-back-large.webp?v=2" alt="">
      <h2 id="ride-done-title"></h2>
      <p id="ride-done-text"></p>
      <p><a class="btn" href="/tour/<?= (int)$tour['id'] ?>"><?= te('nav.back_to_tour') ?></a></p>
    </div>
  </div>
</div>
<script src="/assets/vendor/maplibre/maplibre-gl-csp.js"></script>
<script src="/assets/voice.js?v=2"></script>
<script src="/assets/navigate.js?v=18"></script>
<?php pageFooter();
