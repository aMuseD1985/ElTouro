<?php
/** /spot/new – a rider adds a place that is missing. It waits for the admin; the rider sees it already. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/spots_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$error = '';
$v = ['type' => 'food', 'kind' => 'cafe', 'name' => '', 'website' => '', 'opening_hours' => '', 'note' => '', 'lat' => '', 'lng' => ''];

if (isPost()) {
    checkCsrf();
    foreach (['name' => 80, 'website' => 255, 'opening_hours' => 200, 'note' => 500] as $f => $max) {
        $v[$f] = postField($f, $max);
    }
    $v['type'] = (string)($_POST['type'] ?? '');
    $v['kind'] = (string)($_POST['kind'] ?? '');
    $v['lat'] = (string)($_POST['lat'] ?? ''); $v['lng'] = (string)($_POST['lng'] ?? '');
    $website = $v['website'] !== '' ? cleanWebsite($v['website']) : '';
    $recent = (int)dbOne("SELECT COUNT(*) AS n FROM spots WHERE created_by = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY", [$uid])['n'];
    if (!isset(SPOT_KINDS[$v['type']]) || !in_array($v['kind'], SPOT_KINDS[$v['type']], true)) {
        $error = t('spot.error_kind');
    } elseif (!is_numeric($v['lat']) || !is_numeric($v['lng']) || !isValidCoordinate((float)$v['lat'], (float)$v['lng'])) {
        $error = t('spot.error_pos');
    } elseif (mb_strlen($v['name']) < 2 && $v['type'] !== 'break') {
        $error = t('spot.error_name');
    } elseif ($v['website'] !== '' && $website === '') {
        $error = t('spot.error_website');
    } elseif ($recent >= 10) {
        $error = t('forum.too_fast');
    } else {
        dbExec("INSERT INTO spots (source, status, type, kind, name, lat, lng, website, opening_hours, note, created_by) VALUES ('community', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$v['type'], $v['kind'], $v['name'], round((float)$v['lat'], 6), round((float)$v['lng'], 6), $website !== '' ? $website : null,
             $v['opening_hours'] !== '' ? $v['opening_hours'] : null, $v['note'] !== '' ? $v['note'] : null, $uid]);
        $sid = (int)db()->lastInsertId();
        recordEvent($uid, 'spot_added', 'spot', $sid);
        flash(t('spot.added'));
        redirect('/spot/' . $sid);
    }
}
pageHeader(t('spot.new_title'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<h1><?= te('spot.new_title') ?></h1>
<p class="muted"><?= te('spot.new_intro') ?></p>
<?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form">
  <?= csrfField() ?>
  <div class="field"><label><?= te('spot.pick_position') ?></label>
    <div id="spot-pick" class="map-medium" data-lat="<?= e($v['lat'] ?: '51.4') ?>" data-lng="<?= e($v['lng'] ?: '7.0') ?>" <?= mapData() ?>></div>
    <input type="hidden" name="lat" id="spot-lat" value="<?= e($v['lat']) ?>" required><input type="hidden" name="lng" id="spot-lng" value="<?= e($v['lng']) ?>" required></div>
  <div class="field"><label for="type"><?= te('spot.type') ?></label>
    <select id="type" name="type"><?php foreach (array_keys(SPOT_KINDS) as $ty): ?><option value="<?= $ty ?>"<?= $v['type'] === $ty ? ' selected' : '' ?>><?= te('stop.type_' . $ty) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="kind"><?= te('spot.kind') ?></label>
    <select id="kind" name="kind"><?php foreach (SPOT_KINDS as $ty => $ks): foreach ($ks as $k): ?><option value="<?= $k ?>" data-type="<?= $ty ?>"<?= $v['kind'] === $k ? ' selected' : '' ?>><?= te('stop.kind_' . $k) ?></option><?php endforeach; endforeach; ?></select></div>
  <div class="field"><label for="name"><?= te('spot.field_name') ?></label><input id="name" name="name" maxlength="80" value="<?= e($v['name']) ?>"></div>
  <div class="field"><label for="website"><?= te('spot.field_website') ?></label><input id="website" name="website" maxlength="255" value="<?= e($v['website']) ?>" placeholder="https://"></div>
  <div class="field"><label for="opening_hours"><?= te('spot.field_opening_hours') ?></label><input id="opening_hours" name="opening_hours" maxlength="200" value="<?= e($v['opening_hours']) ?>"><p class="hint"><?= te('spot.hours_hint') ?></p></div>
  <div class="field"><label for="note"><?= te('spot.field_note') ?></label><textarea id="note" name="note" rows="3" maxlength="500"><?= e($v['note']) ?></textarea></div>
  <button type="submit"><?= te('spot.add_send') ?></button>
</form>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/spot_map.js?v=2"></script>
<script src="/assets/spot_form.js?v=1"></script>
<?php pageFooter();
