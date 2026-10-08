<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
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
    'waypoints'   => $tour['waypoints_json'] ?? '[]',
    'geojson'     => $tour['geojson'] ?? '',
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
        'waypoints'   => (string)($_POST['waypoints_json'] ?? '[]'),
        'geojson'     => (string)($_POST['geojson'] ?? ''),
    ];
    $points = validateWaypoints(json_decode($w['waypoints'], true));
    $geo = validateGeometry($w['geojson']);

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
        $values = [$w['title'], $w['description'] ?: null, $LANG, $w['visibility'], $group, $w['difficulty'], $w['style'], $w['rule_set'],
                   $geo['distance'], computeAscent($geo['geojson']), $geo['freehand_pct'], json_encode($points), $geo['geojson'],
                   $geo['start'][0], $geo['start'][1], $geo['bbox'][0], $geo['bbox'][1], $geo['bbox'][2], $geo['bbox'][3]];
        if ($tour) {
            dbExec('UPDATE tours SET title = ?, description = ?, content_lang = ?, visibility = ?, owner_group_id = ?, difficulty = ?, style = ?,
                           rule_set = ?, distance_m = ?, ascent_m = ?, freehand_share_pct = ?, waypoints_json = ?, geojson = ?,
                           start_lat = ?, start_lng = ?, bbox_min_lat = ?, bbox_min_lng = ?, bbox_max_lat = ?, bbox_max_lng = ?
                     WHERE id = ?', [...$values, $tour['id']]);
            $newId = (int)$tour['id'];
        } else {
            dbExec('INSERT INTO tours (title, description, content_lang, visibility, owner_group_id, difficulty, style, rule_set,
                           distance_m, ascent_m, freehand_share_pct, waypoints_json, geojson,
                           start_lat, start_lng, bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng, owner_user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $uid]);
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
foreach (['point', 'calculating', 'done', 'error', 'notice_no_router', 'notice_partly_freehand', 'empty', 'stats', 'freehand_share', 'locate_error'] as $k) {
    $jsTexts[$k] = t('planner.' . $k);
}

pageHeader($tour ? t('tour.edit') : t('tour.new'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<h1><?= te($tour ? 'tour.edit' : 'tour.new') ?></h1>
<?php foreach ($errors as $err): ?><p class="alert alert-error" role="alert"><?= e($err) ?></p><?php endforeach; ?>

<p class="hint"><?= te('planner.instructions') ?></p>
<div class="planner-bar">
  <button type="button" class="link" id="pl-undo"><?= te('planner.undo') ?></button>
  <button type="button" class="link" id="pl-loop"><?= te('planner.loop') ?></button>
  <button type="button" class="link" id="pl-locate"><?= te('planner.locate') ?></button>
  <button type="button" class="link danger" id="pl-clear"><?= te('planner.clear') ?></button>
</div>
<div id="planner-map" class="map-large"
     data-tiles="<?= e((string)$CONFIG['map']['tiles']) ?>"
     data-attribution="<?= e((string)$CONFIG['map']['attribution']) ?>"
     data-waypoints="<?= e($w['waypoints']) ?>"
     data-geojson="<?= e($w['geojson']) ?>"
     data-csrf="<?= e(csrfToken()) ?>"
     data-lang="<?= e($LANG) ?>"
     data-texts="<?= e(json_encode($jsTexts, JSON_UNESCAPED_UNICODE)) ?>"></div>
<p id="planner-status" class="muted" role="status" aria-live="polite"></p>
<p id="planner-info" class="stats"></p>

<form method="post" class="form wide" id="tour-form">
  <?= csrfField() ?>
  <input type="hidden" name="id" value="<?= (int)($tour['id'] ?? 0) ?>">
  <input type="hidden" name="waypoints_json" id="waypoints_json" value="<?= e($w['waypoints']) ?>">
  <input type="hidden" name="geojson" id="geojson" value="<?= e($w['geojson']) ?>">

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
<script src="/assets/planner.js?v=3"></script>
<?php pageFooter();
