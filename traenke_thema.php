<?php
/**
 * Ein Thema der Tränke. Einstieg beim ersten ungelesenen Beitrag (wie Discourse),
 * ältere und neuere Beiträge werden beim Scrollen nachgeladen, neue Antworten per Abfrage alle 20 s angekündigt.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/traenke_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];

$thema = ladeTraenkeThema((int)($_GET['id'] ?? $_POST['id'] ?? 0));
$herde = $thema ? ladeHerde($thema['herde_slug']) : null;
[$darf, $moderator] = $herde ? traenkeZugang($herde, $ich) : [false, false];
if (!$darf) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}
$tid = (int)$thema['id'];

// Moderation ohne JavaScript über normale Formulare
if (istPost() && $moderator) {
    pruefeCsrf();
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'anpinnen') ausfuehren('UPDATE herd_topics SET is_pinned = 1 - is_pinned WHERE id = ?', [$tid]);
    if ($aktion === 'sperren') ausfuehren('UPDATE herd_topics SET is_locked = 1 - is_locked WHERE id = ?', [$tid]);
    if ($aktion === 'thema_loeschen') {
        ausfuehren('UPDATE herd_topics SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tid]);
        weiterleiten('/traenke.php?s=' . rawurlencode($thema['herde_slug']));
    }
    weiterleiten('/traenke_thema.php?id=' . $tid);
}

$gelesen = einzeln('SELECT last_read_post_id FROM herd_reads WHERE topic_id = ? AND user_id = ?', [$tid, $uid]);
$zumEnde = isset($_GET['ende']);
$ersterUngelesen = null;

if ($zumEnde) {
    $beitraege = ladeTraenkeBeitraege($tid, $uid, '1=1', [], true);
} elseif ($gelesen !== null) {
    $ersterUngelesen = einzeln('SELECT MIN(id) AS id FROM herd_posts WHERE topic_id = ? AND id > ? AND user_id <> ?', [$tid, $gelesen['last_read_post_id'], $uid])['id'];
    if ($ersterUngelesen !== null) {
        // ein paar gelesene Beiträge als Zusammenhang davor, dann die ungelesenen
        $davor = ladeTraenkeBeitraege($tid, $uid, 'p.id < ?', [$ersterUngelesen], true, 3);
        $danach = ladeTraenkeBeitraege($tid, $uid, 'p.id >= ?', [$ersterUngelesen]);
        $beitraege = [...$davor, ...$danach];
    } else {
        $beitraege = ladeTraenkeBeitraege($tid, $uid, '1=1', [], true);   // alles gelesen: die neuesten
    }
} else {
    $beitraege = ladeTraenkeBeitraege($tid, $uid);   // erster Besuch: von Anfang an
}

$ersteId = $beitraege ? (int)$beitraege[0]['id'] : 0;
$letzteId = $beitraege ? (int)end($beitraege)['id'] : 0;
$mehrOben = $ersteId && einzeln('SELECT 1 AS x FROM herd_posts WHERE topic_id = ? AND id < ? LIMIT 1', [$tid, $ersteId]) !== null;
$mehrUnten = $letzteId && einzeln('SELECT 1 AS x FROM herd_posts WHERE topic_id = ? AND id > ? LIMIT 1', [$tid, $letzteId]) !== null;
$jsTexte = [];
foreach (['neue_beitraege', 'antwort_an', 'loeschen_frage', 'fehler', 'laedt', 'keine_mehr'] as $k) {
    $jsTexte[$k] = t('traenke.js_' . $k);
}

seitenKopf($thema['title']);
?>
<p class="brotkrumen"><a href="/herde.php?s=<?= e(rawurlencode($thema['herde_slug'])) ?>"><?= e($thema['herde_name']) ?></a> ›
  <a href="/traenke.php?s=<?= e(rawurlencode($thema['herde_slug'])) ?>"><?= te('traenke.titel') ?></a> ›</p>
<h1><?= e($thema['title']) ?></h1>
<?php if ($thema['is_locked']): ?><p class="meldung meldung-info"><?= te('forum.gesperrt_text') ?></p><?php endif; ?>

<?php if ($moderator): ?>
<div class="modleiste">
  <?php foreach (['anpinnen' => $thema['is_pinned'] ? 'forum.m_lospinnen' : 'forum.m_anpinnen',
                  'sperren' => $thema['is_locked'] ? 'forum.m_entsperren' : 'forum.m_sperren',
                  'thema_loeschen' => 'forum.m_loeschen'] as $a => $k): ?>
    <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="id" value="<?= $tid ?>"><input type="hidden" name="aktion" value="<?= $a ?>">
      <button class="link<?= $a === 'thema_loeschen' ? ' gefahr' : '' ?>"><?= te($k) ?></button></form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div id="strom" class="strom"
     data-thema="<?= $tid ?>" data-csrf="<?= e(csrfToken()) ?>"
     data-mehr-oben="<?= $mehrOben ? '1' : '0' ?>" data-mehr-unten="<?= $mehrUnten ? '1' : '0' ?>"
     data-texte="<?= e(json_encode($jsTexte, JSON_UNESCAPED_UNICODE)) ?>">
  <div id="lade-oben" class="nachlader"></div>
  <div id="beitraege">
  <?php foreach ($beitraege as $p):
      if ($ersterUngelesen !== null && (int)$p['id'] === (int)$ersterUngelesen): ?>
      <div class="ungelesen-linie" id="ungelesen"><span><?= te('traenke.ungelesen_ab') ?></span></div>
  <?php endif;
      echo renderBeitrag($p, $uid, $moderator);
  endforeach; ?>
  </div>
  <div id="lade-unten" class="nachlader"></div>
</div>

<button type="button" id="neue-hinweis" class="neue-hinweis" hidden></button>

<?php if (!$thema['is_locked'] || $moderator): ?>
<form id="verfasser" class="verfasser" method="post" action="/traenke_api.php?aktion=antworten">
  <div id="antwort-auf" class="antwort-auf" hidden><span></span> <button type="button" class="link" id="antwort-weg" aria-label="<?= te('traenke.antwort_weg') ?>">✕</button></div>
  <label for="verfasser-text" class="unsichtbar"><?= te('forum.text') ?></label>
  <textarea id="verfasser-text" rows="3" maxlength="<?= TRAENKE_MAX ?>" placeholder="<?= te('traenke.platzhalter') ?>" required></textarea>
  <div class="verfasser-leiste"><span class="hinweis"><?= te('traenke.senden_hint') ?></span><button type="submit"><?= te('forum.senden') ?></button></div>
</form>
<?php endif; ?>
<script src="/assets/traenke.js?v=1"></script>
<?php seitenFuss();
