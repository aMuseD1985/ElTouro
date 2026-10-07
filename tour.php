<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/touren_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];

$tour = ladeTour((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($tour === null || !darfTourSehen($tour, $uid)) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}
$bearbeiten = darfTourBearbeiten($tour, $uid);

if (istPost() && ($_POST['aktion'] ?? '') === 'loeschen' && $bearbeiten) {
    pruefeCsrf();
    ausfuehren('UPDATE tours SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tour['id']]);
    meldung(t('tour.geloescht'));
    weiterleiten('/touren.php');
}

seitenKopf($tour['title']);
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<div class="kopfzeile">
  <h1><?= e($tour['title']) ?></h1>
  <?php if ($bearbeiten): ?><a class="knopf zweit" href="/tour_planen.php?id=<?= (int)$tour['id'] ?>"><?= te('tour.bearbeiten') ?></a><?php endif; ?>
</div>
<p class="leise">
  <?= te('tour.von', ['name' => $tour['ersteller']]) ?>
  <?php if ($tour['herde_name']): ?> · <a href="/herde.php?s=<?= e(rawurlencode($tour['herde_slug'])) ?>"><?= e($tour['herde_name']) ?></a><?php endif; ?>
  · <?= te('tour.v_' . $tour['visibility']) ?>
</p>

<div id="tour-karte" class="karte-gross"
     data-geojson="<?= e($tour['geojson']) ?>"
     data-kacheln="<?= e((string)$CONFIG['karte']['kacheln']) ?>"
     data-attribution="<?= e((string)$CONFIG['karte']['attribution']) ?>"
     data-start="<?= te('tour.start') ?>" data-ziel="<?= te('tour.ziel') ?>"></div>

<dl class="fakten">
  <div><dt><?= te('tour.laenge') ?></dt><dd><?= e(formatiereKm((int)$tour['distance_m'])) ?></dd></div>
  <?php if ($tour['ascent_m'] !== null): ?><div><dt><?= te('tour.anstieg') ?></dt><dd><?= (int)$tour['ascent_m'] ?> m</dd></div><?php endif; ?>
  <div><dt><?= te('tour.schwierigkeit') ?></dt><dd><?= te('tour.d_' . $tour['difficulty']) ?></dd></div>
  <div><dt><?= te('tour.stil') ?></dt><dd><?= te('tour.s_' . $tour['style']) ?></dd></div>
  <div><dt><?= te('tour.regelwerk') ?></dt><dd><?= te('tour.r_' . $tour['rule_set']) ?></dd></div>
</dl>
<p class="hinweis"><?= te('tour.einschaetzung') ?></p>

<?php if ((int)$tour['freehand_share_pct'] > 0): ?>
  <p class="meldung meldung-info"><?= te('tour.freihand_warnung', ['p' => (int)$tour['freehand_share_pct']]) ?></p>
<?php endif; ?>
<p class="meldung meldung-info"><?= te('tour.vor_ort') ?></p>

<?php if ($tour['description']): ?><div class="beschreibung"><?= formatiereText($tour['description']) ?></div><?php endif; ?>

<p class="aktionsleiste">
  <a class="knopf zweit" href="/tour_gpx.php?id=<?= (int)$tour['id'] ?>"><?= te('tour.gpx') ?></a>
  <a href="/melden.php?typ=tour&amp;id=<?= (int)$tour['id'] ?>"><?= te('melden.link') ?></a>
</p>

<?php if ($bearbeiten): ?>
<form method="post" class="abseits"><?= csrfFeld() ?><input type="hidden" name="id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="aktion" value="loeschen">
  <button class="link gefahr"><?= te('tour.loeschen') ?></button></form>
<?php endif; ?>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/tourkarte.js?v=1"></script>
<?php seitenFuss();
