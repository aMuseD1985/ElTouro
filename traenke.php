<?php
/** Tränke einer Herde: Themenliste mit Nachladen beim Scrollen, neues Thema anlegen. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/traenke_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];

$herde = ladeHerde((string)($_GET['s'] ?? $_POST['s'] ?? ''));
[$darf, $moderator] = $herde ? traenkeZugang($herde, $ich) : [false, false];
if (!$darf) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1><p>' . te('traenke.nur_mitglieder') . '</p>';
    seitenFuss();
    exit;
}
$fehler = '';
$titel = '';
$text = '';

if (istPost()) {
    pruefeCsrf();
    $titel = feld('titel', 150);
    $text = trim(str_replace("\r", '', (string)($_POST['text'] ?? '')));
    if (mb_strlen($titel) < 3) {
        $fehler = t('traenke.fehler_titel');
    } elseif (mb_strlen($text) < 1 || mb_strlen($text) > TRAENKE_MAX) {
        $fehler = t('forum.fehler_text', ['max' => TRAENKE_MAX]);
    } elseif (!darfJetztInTraenkeSchreiben($uid)) {
        $fehler = t('forum.zu_schnell');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        ausfuehren('INSERT INTO herd_topics (group_id, user_id, title, post_count, last_post_at, last_post_user_id) VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), ?)',
            [$herde['id'], $uid, $titel, $uid]);
        $tid = (int)$pdo->lastInsertId();
        ausfuehren('INSERT INTO herd_posts (topic_id, user_id, body) VALUES (?, ?, ?)', [$tid, $uid, $text]);
        $pid = (int)$pdo->lastInsertId();
        ausfuehren('UPDATE herd_topics SET last_post_id = ? WHERE id = ?', [$pid, $tid]);
        ausfuehren('INSERT INTO herd_reads (topic_id, user_id, last_read_post_id) VALUES (?, ?, ?)', [$tid, $uid, $pid]);
        $pdo->commit();
        weiterleiten('/traenke_thema.php?id=' . $tid);
    }
}

$themen = ladeTraenkeThemen((int)$herde['id'], $uid, 0);
seitenKopf(t('traenke.titel') . ' · ' . $herde['name']);
?>
<p class="brotkrumen"><a href="/herde.php?s=<?= e(rawurlencode($herde['slug'])) ?>"><?= e($herde['name']) ?></a> ›</p>
<div class="kopfzeile">
  <h1><?= te('traenke.titel') ?></h1>
</div>
<p class="leise"><?= te('traenke.intro') ?></p>

<details class="verfassen" <?= $fehler ? 'open' : '' ?>>
  <summary class="knopf"><?= te('traenke.neues_thema') ?></summary>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
  <form method="post" class="formular breit">
    <?= csrfFeld() ?><input type="hidden" name="s" value="<?= e($herde['slug']) ?>">
    <div class="feld"><label for="titel"><?= te('forum.betreff') ?></label><input id="titel" name="titel" required minlength="3" maxlength="150" value="<?= e($titel) ?>"></div>
    <div class="feld"><label for="text"><?= te('forum.text') ?></label><textarea id="text" name="text" rows="6" required maxlength="<?= TRAENKE_MAX ?>"><?= e($text) ?></textarea>
      <p class="hinweis"><?= te('forum.format') ?></p></div>
    <button type="submit"><?= te('forum.veroeffentlichen') ?></button>
  </form>
</details>

<?php if (!$themen): ?><p class="leise"><?= te('traenke.leer') ?></p><?php endif; ?>
<ul class="themenliste" id="themen"
    data-herde="<?= e($herde['slug']) ?>" data-offset="<?= count($themen) ?>"
    data-mehr="<?= count($themen) === TRAENKE_THEMEN_SEITE ? '1' : '0' ?>">
  <?php foreach ($themen as $t) echo renderThemaZeile($t); ?>
</ul>
<div id="themen-mehr" class="nachlader" aria-live="polite"></div>
<script src="/assets/traenke.js?v=1"></script>
<?php seitenFuss();
