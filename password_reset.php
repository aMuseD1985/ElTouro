<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
if (peekToken($token, 'reset') === null) {
    flash(t('verify.error'), 'error');
    redirect('/password/forgot');
}
$error = '';

if (isPost()) {
    checkCsrf();
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 10) {
        $error = t('register.error_password');
    } else {
        $uid = redeemToken($token, 'reset');
        if ($uid === null) {
            flash(t('verify.error'), 'error');
            redirect('/password/forgot');
        }
        dbExec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
        logIn($uid);
        flash(t('password.new_ok'));
        redirect('/');
    }
}

pageHeader(t('password.new_title'));
?>
<section class="narrow">
  <h1><?= te('password.new_title') ?></h1>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= csrfField() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="field"><label for="password"><?= te('register.password') ?></label>
      <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" aria-describedby="pw-h">
      <p class="hint" id="pw-h"><?= te('register.password_hint') ?></p></div>
    <button type="submit"><?= te('password.new_button') ?></button>
  </form>
</section>
<?php pageFooter();
