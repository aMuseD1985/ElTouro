<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
require __DIR__ . '/poi_lib.php';
require __DIR__ . '/rewards_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$tour = $id ? loadTour($id) : null;
if ($id && ($tour === null || !canEditTour($tour, $uid))) {
    notFound();
}

$crews = activeCrewsOf($uid);
$crewIds = array_map('intval', array_column($crews, 'id'));
$errors = [];
$w = [
    'title'       => $tour['title'] ?? '',
    'description' => $tour['description'] ?? '',
    'visibility'  => $tour['visibility'] ?? 'private',
    'group_id'    => (int)($tour['owner_group_id'] ?? ($_GET['crew'] ?? 0)),
    'difficulty'  => $tour['difficulty'] ?? 'easy',
    'style'       => $tour['style'] ?? 'social',
    'rule_set'    => $tour['rule_set'] ?? currentRuleSet(),
    'vehicle'     => vehicleClass($tour['vehicle_class'] ?? VEHICLE_CLASS_DEFAULT),
    'waypoints'   => $tour['waypoints_json'] ?? '[]',
    'stops'       => $tour['stops_json'] ?? '[]',
    'geojson'     => $tour['geojson'] ?? '',
    'guidance'    => $tour['guidance_json'] ?? '[]',
];

if (isPost()) {
    checkCsrf();
    $w = [
        'title'       => postField('title', 120),
        'description' => postField('description', 4000),
        'visibility'  => postField('visibility'),
        'group_id'    => (int)($_POST['group_id'] ?? 0),
        'difficulty'  => postField('difficulty'),
        'style'       => postField('style'),
        'rule_set'    => postField('rule_set') === 'ekfv2027' ? 'ekfv2027' : 'ekfv',
        'vehicle'     => vehicleClass($_POST['vehicle_class'] ?? VEHICLE_CLASS_DEFAULT),
        'waypoints'   => (string)($_POST['waypoints_json'] ?? '[]'),
        'stops'       => (string)($_POST['stops_json'] ?? '[]'),
        'geojson'     => (string)($_POST['geojson'] ?? ''),
        'guidance'    => (string)($_POST['guidance_json'] ?? '[]'),
    ];
    $points = validateWaypoints(json_decode($w['waypoints'], true));
    $stops = $points !== null ? validateStops(json_decode($w['stops'], true), count($points)) : null;
    $geo = validateGeometry($w['geojson']);
    // Turn instructions belong to exactly this track; if they don't fit, the navigation works them out itself
    $guidance = $geo !== null ? validateGuidance(json_decode($w['guidance'], true), $geo['geojson']) : null;

    if (mb_strlen($w['title']) < 3) $errors[] = t('tour.error_name');
    if ($points === null || $geo === null) $errors[] = t('tour.error_route');
    if (!in_array($w['visibility'], ['private', 'group', 'public'], true)) $w['visibility'] = 'private';
    if (!in_array($w['difficulty'], ['easy', 'moderate', 'demanding'], true)) $w['difficulty'] = 'easy';
    if (!in_array($w['style'], ['relaxed', 'social', 'sporty'], true)) $w['style'] = 'social';
    $group = in_array($w['group_id'], $crewIds, true) ? $w['group_id'] : null;
    // When a crew lead edits, the previous crew is kept
    if ($group === null && $tour && $tour['owner_group_id'] && (int)$tour['owner_group_id'] === $w['group_id']) {
        $group = (int)$tour['owner_group_id'];
    }
    if ($w['visibility'] === 'group' && $group === null) $errors[] = t('tour.error_crew');

    if (!$errors) {
        $values = [$w['title'], $w['description'] ?: null, $LANG, $w['visibility'], $group, $w['difficulty'], $w['style'], $w['rule_set'], $w['vehicle'],
                   $geo['distance'], computeAscent($geo['geojson']), $geo['freehand_pct'], json_encode($points), $stops ? json_encode($stops, JSON_UNESCAPED_UNICODE) : null, $geo['geojson'],
                   $guidance ? json_encode($guidance) : null,
                   $geo['start'][0], $geo['start'][1], $geo['bbox'][0], $geo['bbox'][1], $geo['bbox'][2], $geo['bbox'][3]];
        if ($tour) {
            dbExec('UPDATE tours SET title = ?, description = ?, content_lang = ?, visibility = ?, owner_group_id = ?, difficulty = ?, style = ?,
                           rule_set = ?, vehicle_class = ?, distance_m = ?, ascent_m = ?, freehand_share_pct = ?, waypoints_json = ?, stops_json = ?, geojson = ?, guidance_json = ?,
                           start_lat = ?, start_lng = ?, bbox_min_lat = ?, bbox_min_lng = ?, bbox_max_lat = ?, bbox_max_lng = ?
                     WHERE id = ?', [...$values, $tour['id']]);
            $newId = (int)$tour['id'];
        } else {
            dbExec('INSERT INTO tours (title, description, content_lang, visibility, owner_group_id, difficulty, style, rule_set, vehicle_class,
                           distance_m, ascent_m, freehand_share_pct, waypoints_json, stops_json, geojson, guidance_json,
                           start_lat, start_lng, bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng, owner_user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $uid]);
            $newId = (int)db()->lastInsertId();
            recordEvent($uid, 'tour_created', 'tour', $newId, null, (float)round($geo['distance'] / 1000, 2),
                ['visibility' => $w['visibility'], 'group_id' => $group]);
        }
        flash(t('tour.saved'));
        redirect('/tour/' . $newId);
    }
}

// Texts for the script (i18n in the browser)
$jsTexts = [];
foreach (['point', 'calculating', 'done', 'error', 'notice_no_router', 'notice_partly_freehand', 'empty', 'stats', 'freehand_share', 'locate_error',
          'leg_too_long', 'uturns_avoided', 'uturns_left', 'remove', 'up', 'down', 'leg', 'start', 'finish', 'searching', 'search_none', 'search_error', 'search_slow', 'add_point', 'loading_1', 'loading_2', 'loading_3', 'loading_4', 'loading_5', 'loading_6', 'loading_7', 'loading_8', 'loading_9', 'loading_10'] as $k) {
    $jsTexts[$k] = t('planner.' . $k);
}

// Texts for stops and suggestions (stop.* in lang.php)
$poiTexts = [];
foreach ($TEXTS[$LANG] as $k => $v) {
    if (str_starts_with($k, 'stop.')) {
        $poiTexts[substr($k, 5)] = $v;
    }
}

pageHeader($tour ? t('tour.edit') : t('tour.new'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<h1><?= te($tour ? 'tour.edit' : 'tour.new') ?></h1>
<?php foreach ($errors as $err): ?><p class="alert alert-error" role="alert"><?= e($err) ?></p><?php endforeach; ?>

<p class="hint"><?= te('planner.instructions') ?></p>
<form class="place-search" id="place-search" role="search">
  <label for="place-q" class="visually-hidden"><?= te('planner.search_label') ?></label>
  <input id="place-q" type="search" maxlength="120" autocomplete="off" placeholder="<?= te('planner.search_placeholder') ?>">
  <button type="submit" class="secondary-submit"><?= te('planner.search_button') ?></button>
</form>
<ul id="place-results" class="place-results" hidden></ul>
<div class="planner-bar">
  <button type="button" class="link" id="pl-undo"><?= te('planner.undo') ?></button>
  <button type="button" class="link" id="pl-loop"><?= te('planner.loop') ?></button>
  <button type="button" class="link" id="pl-reverse"><?= te('planner.reverse') ?></button>
  <button type="button" class="link" id="pl-stops" disabled><?= te('planner.suggest_stops') ?></button>
  <label class="planner-check"><input type="checkbox" id="pl-uturns" checked> <?= te('planner.avoid_uturns') ?></label>
  <button type="button" class="link" id="pl-locate"><?= te('planner.locate') ?></button>
  <button type="button" class="link danger" id="pl-clear"><?= te('planner.clear') ?></button>
</div>
<div class="planner-wrap">
<div id="planner-map" class="map-large"
     data-tiles="<?= e((string)$CONFIG['map']['tiles']) ?>"
     data-attribution="<?= e((string)$CONFIG['map']['attribution']) ?>"
     data-waypoints="<?= e($w['waypoints']) ?>"
     data-stops="<?= e($w['stops']) ?>"
     data-poi-texts="<?= e(json_encode($poiTexts, JSON_UNESCAPED_UNICODE)) ?>"
     data-geojson="<?= e($w['geojson']) ?>"
     data-csrf="<?= e(csrfToken()) ?>"
     data-lang="<?= e($LANG) ?>"
     data-max-leg-km="<?= e((string)maxLegKm()) ?>"
     data-texts="<?= e(json_encode($jsTexts, JSON_UNESCAPED_UNICODE)) ?>"></div>
  <!-- Shown while the route is calculated; the status line below says the same for screen readers -->
  <div id="planner-loading" class="planner-loading" hidden aria-hidden="true">
    <div class="loading-orbit">
      <img class="loading-logo" src="/assets/img/apple-touch-icon.png" alt="">
      <div class="orbit">
        <?php for ($i = 0; $i < 3; $i++): ?><div class="slot"><img class="rider" src="/assets/img/scooter-bull.svg" alt=""></div><?php endfor; ?>
      </div>
    </div>
    <p class="loading-saying" id="planner-saying"></p>
    <div class="loading-bar"><span id="planner-progress"></span></div>
  </div>
</div>
<p id="planner-status" class="muted" role="status" aria-live="polite"></p>
<p id="planner-info" class="stats"></p>
<section id="stop-suggestions" class="stop-suggestions" hidden aria-live="polite"></section>
<section id="waypoints" class="waypoints" hidden>
  <h2><?= te('planner.waypoints') ?></h2>
  <ol id="waypoint-list" class="waypoint-list"></ol>
</section>

<form method="post" class="form wide" id="tour-form">
  <?= csrfField() ?>
  <input type="hidden" name="id" value="<?= (int)($tour['id'] ?? 0) ?>">
  <input type="hidden" name="waypoints_json" id="waypoints_json" value="<?= e($w['waypoints']) ?>">
  <input type="hidden" name="geojson" id="geojson" value="<?= e($w['geojson']) ?>">
  <input type="hidden" name="guidance_json" id="guidance_json" value="<?= e($w['guidance']) ?>">
  <input type="hidden" name="stops_json" id="stops_json" value="<?= e($w['stops']) ?>">

  <div class="field"><label for="title"><?= te('tour.name') ?></label>
    <div class="input-with-button">
      <input id="title" name="title" required minlength="3" maxlength="120" value="<?= e($w['title']) ?>">
      <button type="button" class="secondary-submit" id="tour-name-suggest" disabled><?= te('planner.suggest_name') ?></button>
    </div></div>
  <div class="field"><label for="description"><?= te('tour.description') ?></label>
    <textarea id="description" name="description" rows="4" maxlength="4000"><?= e($w['description']) ?></textarea></div>

  <div class="row3">
    <div class="field"><label for="difficulty"><?= te('tour.difficulty') ?></label>
      <select id="difficulty" name="difficulty"><?php foreach (['easy', 'moderate', 'demanding'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['difficulty'] === $o ? 'selected' : '' ?>><?= te('tour.d_' . $o) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= te('tour.difficulty_hint') ?></p></div>
    <div class="field"><label for="style"><?= te('tour.style') ?></label>
      <select id="style" name="style"><?php foreach (['relaxed', 'social', 'sporty'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['style'] === $o ? 'selected' : '' ?>><?= te('tour.s_' . $o) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="rule_set"><?= te('tour.rule_set') ?></label>
      <select id="rule_set" name="rule_set">
        <option value="ekfv" <?= $w['rule_set'] === 'ekfv' ? 'selected' : '' ?>><?= te('tour.r_ekfv') ?></option>
        <option value="ekfv2027" <?= $w['rule_set'] === 'ekfv2027' ? 'selected' : '' ?>><?= te('tour.r_ekfv2027') ?></option></select></div>
  </div>
  <div class="field"><label for="vehicle_class"><?= te('tour.vehicle_class') ?></label>
    <select id="vehicle_class" name="vehicle_class"><?php foreach (VEHICLE_CLASSES as $n => $key): ?>
      <option value="<?= $n ?>" <?= $w['vehicle'] === $n ? 'selected' : '' ?>><?= te('tour.vc_' . $key) ?></option><?php endforeach; ?></select>
    <p class="hint" id="vehicle-hint"><?= te('tour.vehicle_hint') ?></p>
    <p class="alert alert-info" id="bullrun-hint" <?= $w['vehicle'] === 4 ? '' : 'hidden' ?>><?= te('tour.bullrun_hint') ?></p></div>

  <div class="row">
    <div class="field"><label for="visibility"><?= te('tour.visibility') ?></label>
      <select id="visibility" name="visibility"><?php foreach (['private', 'group', 'public'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['visibility'] === $o ? 'selected' : '' ?>><?= te('tour.v_' . $o) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="group_id"><?= te('tour.crew') ?></label>
      <select id="group_id" name="group_id"><option value="0">–</option>
        <?php foreach ($crews as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $w['group_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  </div>

  <button type="submit" id="tour-save"><?= te('tour.save') ?></button>
</form>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/planner.js?v=14"></script>
<?php pageFooter();
