<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
$ich = mussEingeloggtSein();

$kat = einzeln('SELECT * FROM forum_categories WHERE slug = ?', [(string)($_GET['k'] ?? $_POST['k'] ?? '')]);
if ($kat === null) {
    weiterleiten('/forum.php');
}
$fehler = '';
$titel = '';
$text = '';

if (istPost()) {
    pruefeCsrf();
    $titel = feld('titel', 150);
    $text = trim(str_replace("\r", '', (string)($_POST['text'] ?? '')));
    if (mb_strlen($titel) < 5) {
        $fehler = t('forum.fehler_titel');
    } elseif (mb_strlen($text) < 2 || mb_strlen($text) > BEITRAG_MAX) {
        $fehler = t('forum.fehler_text', ['max' => BEITRAG_MAX]);
    } elseif (!darfJetztSchreiben((int)$ich['id'])) {
        $fehler = t('forum.zu_schnell');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        ausfuehren('INSERT INTO forum_threads (category_id, user_id, title, post_count, last_post_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())',
            [$kat['id'], $ich['id'], $titel]);
        $tid = (int)$pdo->lastInsertId();
        ausfuehren('INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)', [$tid, $ich['id'], $text]);
        $pdo->commit();
        weiterleiten('/forum_thema.php?t=' . $tid);
    }
}

seitenKopf(t('forum.neues_thema'));
?>
<p class="brotkrumen"><a href="/forum.php"><?= te('forum.titel') ?></a> › <a href="/forum_kategorie.php?k=<?= e(rawurlencode($kat['slug'])) ?>"><?= e(kategorieName($kat)) ?></a> ›</p>
<h1><?= te('forum.neues_thema') ?></h1>
<?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
<form method="post" class="formular breit">
  <?= csrfFeld() ?><input type="hidden" name="k" value="<?= e($kat['slug']) ?>">
  <div class="feld"><label for="titel"><?= te('forum.betreff') ?></label><input id="titel" name="titel" required minlength="5" maxlength="150" value="<?= e($titel) ?>"></div>
  <div class="feld"><label for="text"><?= te('forum.text') ?></label><textarea id="text" name="text" rows="10" required maxlength="<?= BEITRAG_MAX ?>"><?= e($text) ?></textarea>
    <p class="hinweis"><?= te('forum.format') ?></p></div>
  <button type="submit"><?= te('forum.veroeffentlichen') ?></button>
</form>
<?php seitenFuss();
