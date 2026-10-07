<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/touren_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$tour = $id ? ladeTour($id) : null;
if ($id && ($tour === null || !darfTourBearbeiten($tour, $uid))) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}

$herden = meineHerdenFuerTouren($uid);
$herdenIds = array_map('intval', array_column($herden, 'id'));
$fehler = [];
$w = [
    'title'       => $tour['title'] ?? '',
    'description' => $tour['description'] ?? '',
    'visibility'  => $tour['visibility'] ?? 'private',
    'group_id'    => (int)($tour['owner_group_id'] ?? ($_GET['herde'] ?? 0)),
    'difficulty'  => $tour['difficulty'] ?? 'easy',
    'style'       => $tour['style'] ?? 'social',
    'rule_set'    => $tour['rule_set'] ?? regelwerkHeute(),
    'waypoints'   => $tour['waypoints_json'] ?? '[]',
    'geojson'     => $tour['geojson'] ?? '',
];

if (istPost()) {
    pruefeCsrf();
    $w = [
        'title'       => feld('title', 120),
        'description' => feld('description', 4000),
        'visibility'  => feld('visibility'),
        'group_id'    => (int)($_POST['group_id'] ?? 0),
        'difficulty'  => feld('difficulty'),
        'style'       => feld('style'),
        'rule_set'    => feld('rule_set') === 'ekfv2027' ? 'ekfv2027' : 'ekfv',
        'waypoints'   => (string)($_POST['waypoints_json'] ?? '[]'),
        'geojson'     => (string)($_POST['geojson'] ?? ''),
    ];
    $punkte = pruefeWegpunkte(json_decode($w['waypoints'], true));
    $geo = pruefeGeometrie($w['geojson']);

    if (mb_strlen($w['title']) < 3) $fehler[] = t('tour.fehler_titel');
    if ($punkte === null || $geo === null) $fehler[] = t('tour.fehler_route');
    if (!in_array($w['visibility'], ['private', 'group', 'public'], true)) $w['visibility'] = 'private';
    if (!in_array($w['difficulty'], ['easy', 'moderate', 'demanding'], true)) $w['difficulty'] = 'easy';
    if (!in_array($w['style'], ['relaxed', 'social', 'sporty'], true)) $w['style'] = 'social';
    $gruppe = in_array($w['group_id'], $herdenIds, true) ? $w['group_id'] : null;
    // Beim Bearbeiten durch den Leitstier bleibt die bisherige Herde erhalten
    if ($gruppe === null && $tour && $tour['owner_group_id'] && (int)$tour['owner_group_id'] === $w['group_id']) {
        $gruppe = (int)$tour['owner_group_id'];
    }
    if ($w['visibility'] === 'group' && $gruppe === null) $fehler[] = t('tour.fehler_herde');

    if (!$fehler) {
        $werte = [$w['title'], $w['description'] ?: null, $LANG, $w['visibility'], $gruppe, $w['difficulty'], $w['style'], $w['rule_set'],
                  $geo['distanz'], berechneAnstieg($geo['geojson']), $geo['freihand_pct'], json_encode($punkte), $geo['geojson'],
                  $geo['start'][0], $geo['start'][1], $geo['bbox'][0], $geo['bbox'][1], $geo['bbox'][2], $geo['bbox'][3]];
        if ($tour) {
            ausfuehren('UPDATE tours SET title = ?, description = ?, content_lang = ?, visibility = ?, owner_group_id = ?, difficulty = ?, style = ?,
                               rule_set = ?, distance_m = ?, ascent_m = ?, freehand_share_pct = ?, waypoints_json = ?, geojson = ?,
                               start_lat = ?, start_lng = ?, bbox_min_lat = ?, bbox_min_lng = ?, bbox_max_lat = ?, bbox_max_lng = ?
                         WHERE id = ?', [...$werte, $tour['id']]);
            $neuId = (int)$tour['id'];
        } else {
            ausfuehren('INSERT INTO tours (title, description, content_lang, visibility, owner_group_id, difficulty, style, rule_set,
                               distance_m, ascent_m, freehand_share_pct, waypoints_json, geojson,
                               start_lat, start_lng, bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng, owner_user_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$werte, $uid]);
            $neuId = (int)db()->lastInsertId();
        }
        meldung(t('tour.gespeichert'));
        weiterleiten('/tour.php?id=' . $neuId);
    }
}

// Texte für das Skript (i18n im Browser)
$jsTexte = [];
foreach (['punkt', 'rechne', 'fertig', 'fehler', 'hinweis_ohne_router', 'hinweis_teilweise_freihand', 'leer', 'kennzahl', 'freihand_anteil', 'standort_fehler'] as $k) {
    $jsTexte[$k] = t('planer.' . $k);
}

seitenKopf($tour ? t('tour.bearbeiten') : t('tour.neu'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<h1><?= te($tour ? 'tour.bearbeiten' : 'tour.neu') ?></h1>
<?php foreach ($fehler as $f): ?><p class="meldung meldung-fehler" role="alert"><?= e($f) ?></p><?php endforeach; ?>

<p class="hinweis"><?= te('planer.anleitung') ?></p>
<div class="planer-leiste">
  <button type="button" class="link" id="pl-rueck"><?= te('planer.rueckgaengig') ?></button>
  <button type="button" class="link" id="pl-rund"><?= te('planer.rundtour') ?></button>
  <button type="button" class="link" id="pl-standort"><?= te('planer.standort') ?></button>
  <button type="button" class="link gefahr" id="pl-leeren"><?= te('planer.leeren') ?></button>
</div>
<div id="planer-karte" class="karte-gross"
     data-kacheln="<?= e((string)$CONFIG['karte']['kacheln']) ?>"
     data-attribution="<?= e((string)$CONFIG['karte']['attribution']) ?>"
     data-wegpunkte="<?= e($w['waypoints']) ?>"
     data-geojson="<?= e($w['geojson']) ?>"
     data-csrf="<?= e(csrfToken()) ?>"
     data-sprache="<?= e($LANG) ?>"
     data-texte="<?= e(json_encode($jsTexte, JSON_UNESCAPED_UNICODE)) ?>"></div>
<p id="planer-status" class="leise" role="status" aria-live="polite"></p>
<p id="planer-info" class="kennzahlen"></p>

<form method="post" class="formular breit" id="tour-formular">
  <?= csrfFeld() ?>
  <input type="hidden" name="id" value="<?= (int)($tour['id'] ?? 0) ?>">
  <input type="hidden" name="waypoints_json" id="waypoints_json" value="<?= e($w['waypoints']) ?>">
  <input type="hidden" name="geojson" id="geojson" value="<?= e($w['geojson']) ?>">

  <div class="feld"><label for="title"><?= te('tour.titel') ?></label>
    <input id="title" name="title" required minlength="3" maxlength="120" value="<?= e($w['title']) ?>"></div>
  <div class="feld"><label for="description"><?= te('tour.beschreibung') ?></label>
    <textarea id="description" name="description" rows="4" maxlength="4000"><?= e($w['description']) ?></textarea></div>

  <div class="zeile3">
    <div class="feld"><label for="difficulty"><?= te('tour.schwierigkeit') ?></label>
      <select id="difficulty" name="difficulty"><?php foreach (['easy', 'moderate', 'demanding'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['difficulty'] === $o ? 'selected' : '' ?>><?= te('tour.d_' . $o) ?></option><?php endforeach; ?></select>
      <p class="hinweis"><?= te('tour.schwierigkeit_hint') ?></p></div>
    <div class="feld"><label for="style"><?= te('tour.stil') ?></label>
      <select id="style" name="style"><?php foreach (['relaxed', 'social', 'sporty'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['style'] === $o ? 'selected' : '' ?>><?= te('tour.s_' . $o) ?></option><?php endforeach; ?></select></div>
    <div class="feld"><label for="rule_set"><?= te('tour.regelwerk') ?></label>
      <select id="rule_set" name="rule_set">
        <option value="ekfv" <?= $w['rule_set'] === 'ekfv' ? 'selected' : '' ?>><?= te('tour.r_ekfv') ?></option>
        <option value="ekfv2027" <?= $w['rule_set'] === 'ekfv2027' ? 'selected' : '' ?>><?= te('tour.r_ekfv2027') ?></option></select></div>
  </div>

  <div class="zeile">
    <div class="feld"><label for="visibility"><?= te('tour.sichtbarkeit') ?></label>
      <select id="visibility" name="visibility"><?php foreach (['private', 'group', 'public'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['visibility'] === $o ? 'selected' : '' ?>><?= te('tour.v_' . $o) ?></option><?php endforeach; ?></select></div>
    <div class="feld"><label for="group_id"><?= te('tour.herde') ?></label>
      <select id="group_id" name="group_id"><option value="0">–</option>
        <?php foreach ($herden as $h): ?><option value="<?= (int)$h['id'] ?>" <?= $w['group_id'] === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?></select></div>
  </div>

  <button type="submit" id="tour-speichern"><?= te('tour.speichern') ?></button>
</form>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/planer.js?v=1"></script>
<?php seitenFuss();
