<?php
/** Inhalte melden (DSA Art. 16). Meldungen landen bei den Admins unter „Meldungen“. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$ich = mussEingeloggtSein();

$typ = in_array($_GET['typ'] ?? $_POST['typ'] ?? '', ['post', 'thread', 'tour', 'group', 'user', 'herdpost'], true) ? ($_GET['typ'] ?? $_POST['typ']) : null;
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($typ === null || $id <= 0) {
    weiterleiten('/');
}
$fehler = '';

if (istPost()) {
    pruefeCsrf();
    $grund = trim((string)($_POST['grund'] ?? ''));
    if (mb_strlen($grund) < 10) {
        $fehler = t('melden.fehler');
    } else {
        $offen = (int)einzeln("SELECT COUNT(*) AS n FROM reports WHERE reporter_user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [$ich['id']])['n'];
        if ($offen < 10) {
            ausfuehren('INSERT INTO reports (reporter_user_id, target_type, target_id, reason) VALUES (?, ?, ?, ?)',
                [$ich['id'], $typ, $id, mb_substr($grund, 0, 2000)]);
        }
        meldung(t('melden.danke'));
        weiterleiten('/');
    }
}

seitenKopf(t('melden.titel'));
?>
<section class="schmal">
  <h1><?= te('melden.titel') ?></h1>
  <p><?= te('melden.text') ?></p>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
  <form method="post" class="formular">
    <?= csrfFeld() ?><input type="hidden" name="typ" value="<?= e($typ) ?>"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="feld"><label for="grund"><?= te('melden.grund') ?></label><textarea id="grund" name="grund" rows="5" required minlength="10" maxlength="2000"></textarea></div>
    <button type="submit"><?= te('melden.senden') ?></button>
  </form>
</section>
<?php seitenFuss();
