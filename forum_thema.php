<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];
$mod = istModerator($ich);

$thema = einzeln('SELECT t.*, c.slug AS kat_slug, c.name_de, c.name_en FROM forum_threads t
                    JOIN forum_categories c ON c.id = t.category_id WHERE t.id = ? AND t.deleted_at IS NULL', [(int)($_GET['t'] ?? 0)]);
if ($thema === null) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}
$tid = (int)$thema['id'];
$selbst = '/forum_thema.php?t=' . $tid;
$fehler = '';
$entwurf = '';

if (istPost()) {
    pruefeCsrf();
    $aktion = (string)($_POST['aktion'] ?? '');
    $pid = (int)($_POST['post'] ?? 0);

    if ($aktion === 'antworten') {
        $entwurf = trim(str_replace("\r", '', (string)($_POST['text'] ?? '')));
        if ($thema['is_locked'] && !$mod) {
            $fehler = t('forum.gesperrt_text');
        } elseif (mb_strlen($entwurf) < 2 || mb_strlen($entwurf) > BEITRAG_MAX) {
            $fehler = t('forum.fehler_text', ['max' => BEITRAG_MAX]);
        } elseif (!darfJetztSchreiben($uid)) {
            $fehler = t('forum.zu_schnell');
        } else {
            ausfuehren('INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)', [$tid, $uid, $entwurf]);
            $neu = (int)db()->lastInsertId();
            ausfuehren('UPDATE forum_threads SET post_count = post_count + 1, last_post_at = UTC_TIMESTAMP() WHERE id = ?', [$tid]);
            $anzahl = (int)einzeln('SELECT COUNT(*) AS n FROM forum_posts WHERE thread_id = ?', [$tid])['n'];
            weiterleiten($selbst . '&s=' . (int)ceil($anzahl / BEITRAEGE_PRO_SEITE) . '#b' . $neu);
        }
    } elseif ($aktion === 'post_loeschen') {
        // Eigene Beiträge darf man löschen, Moderatoren alle
        ausfuehren('UPDATE forum_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND thread_id = ? AND (user_id = ? OR ? = 1)',
            [$pid, $tid, $uid, $mod ? 1 : 0]);
        weiterleiten($selbst . '&s=' . max(1, (int)($_POST['s'] ?? 1)));
    } elseif ($mod && in_array($aktion, ['anpinnen', 'sperren', 'thema_loeschen'], true)) {
        match ($aktion) {
            'anpinnen'       => ausfuehren('UPDATE forum_threads SET is_pinned = 1 - is_pinned WHERE id = ?', [$tid]),
            'sperren'        => ausfuehren('UPDATE forum_threads SET is_locked = 1 - is_locked WHERE id = ?', [$tid]),
            'thema_loeschen' => ausfuehren('UPDATE forum_threads SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$tid]),
        };
        weiterleiten($aktion === 'thema_loeschen' ? '/forum_kategorie.php?k=' . rawurlencode($thema['kat_slug']) : $selbst);
    }
}

$gesamt = (int)einzeln('SELECT COUNT(*) AS n FROM forum_posts WHERE thread_id = ?', [$tid])['n'];
$seiten = max(1, (int)ceil($gesamt / BEITRAEGE_PRO_SEITE));
$seite = min($seiten, max(1, (int)($_GET['s'] ?? 1)));
$beitraege = alle('SELECT p.id, p.user_id, p.body, p.created_at, p.deleted_at, u.display_name
                     FROM forum_posts p JOIN users u ON u.id = p.user_id
                    WHERE p.thread_id = ? ORDER BY p.created_at, p.id LIMIT ' . BEITRAEGE_PRO_SEITE . ' OFFSET ' . (($seite - 1) * BEITRAEGE_PRO_SEITE), [$tid]);

seitenKopf($thema['title']);
?>
<p class="brotkrumen"><a href="/forum.php"><?= te('forum.titel') ?></a> › <a href="/forum_kategorie.php?k=<?= e(rawurlencode($thema['kat_slug'])) ?>"><?= e(kategorieName($thema)) ?></a> ›</p>
<h1><?= e($thema['title']) ?></h1>
<?php if ($thema['is_locked']): ?><p class="meldung meldung-info"><?= te('forum.gesperrt_text') ?></p><?php endif; ?>

<?php if ($mod): ?>
<div class="modleiste">
  <?php foreach (['anpinnen' => $thema['is_pinned'] ? 'forum.m_lospinnen' : 'forum.m_anpinnen',
                  'sperren' => $thema['is_locked'] ? 'forum.m_entsperren' : 'forum.m_sperren',
                  'thema_loeschen' => 'forum.m_loeschen'] as $a => $k): ?>
    <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="aktion" value="<?= $a ?>"><button class="link<?= $a === 'thema_loeschen' ? ' gefahr' : '' ?>"><?= te($k) ?></button></form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<ol class="beitraege">
<?php foreach ($beitraege as $b): ?>
  <li class="beitrag" id="b<?= (int)$b['id'] ?>">
    <header><strong><?= e($b['display_name']) ?></strong> <span class="leise">· <?= e(zeitAnzeige($b['created_at'])) ?></span></header>
    <?php if ($b['deleted_at']): ?>
      <p class="leise"><em><?= te('forum.geloescht') ?></em></p>
    <?php else: ?>
      <div class="beitrag-text"><?= formatiereText($b['body']) ?></div>
      <footer>
        <a href="/melden.php?typ=post&amp;id=<?= (int)$b['id'] ?>"><?= te('melden.link') ?></a>
        <?php if ((int)$b['user_id'] === $uid || $mod): ?>
          <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="aktion" value="post_loeschen"><input type="hidden" name="post" value="<?= (int)$b['id'] ?>"><input type="hidden" name="s" value="<?= $seite ?>">
            <button class="link gefahr"><?= te('forum.loeschen') ?></button></form>
        <?php endif; ?>
      </footer>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ol>

<?php if ($seiten > 1): ?>
<nav class="blaettern" aria-label="<?= te('forum.seiten') ?>">
  <?php for ($i = 1; $i <= $seiten; $i++): ?>
    <a href="<?= e($selbst) ?>&amp;s=<?= $i ?>" <?= $i === $seite ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>

<?php if (!$thema['is_locked'] || $mod): ?>
<section id="antworten">
  <h2><?= te('forum.antworten_titel') ?></h2>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
  <form method="post" class="formular breit">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="antworten">
    <div class="feld"><label for="text" class="unsichtbar"><?= te('forum.text') ?></label>
      <textarea id="text" name="text" rows="6" required maxlength="<?= BEITRAG_MAX ?>"><?= e($entwurf) ?></textarea>
      <p class="hinweis"><?= te('forum.format') ?></p></div>
    <button type="submit"><?= te('forum.senden') ?></button>
  </form>
</section>
<?php endif; ?>
<?php seitenFuss();
