<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/konto.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
if (pruefeToken($token, 'reset') === null) {
    meldung(t('best.fehler'), 'fehler');
    weiterleiten('/passwort_vergessen.php');
}
$fehler = '';

if (istPost()) {
    pruefeCsrf();
    $pw = (string)($_POST['passwort'] ?? '');
    if (mb_strlen($pw) < 10) {
        $fehler = t('reg.fehler_passwort');
    } else {
        $uid = loeseTokenEin($token, 'reset');
        if ($uid === null) {
            meldung(t('best.fehler'), 'fehler');
            weiterleiten('/passwort_vergessen.php');
        }
        ausfuehren('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
        einloggen($uid);
        meldung(t('pw.neu_ok'));
        weiterleiten('/');
    }
}

seitenKopf(t('pw.neu_titel'));
?>
<section class="schmal">
  <h1><?= te('pw.neu_titel') ?></h1>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
  <form method="post" class="formular">
    <?= csrfFeld() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="feld"><label for="passwort"><?= te('reg.passwort') ?></label>
      <input id="passwort" name="passwort" type="password" required minlength="10" autocomplete="new-password" aria-describedby="pw-h">
      <p class="hinweis" id="pw-h"><?= te('reg.passwort_hint') ?></p></div>
    <button type="submit"><?= te('pw.neu_knopf') ?></button>
  </form>
</section>
<?php seitenFuss();
