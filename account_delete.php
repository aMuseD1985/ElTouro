<?php
/** /account/delete – deletes the account and all its data for good (account_data_lib.php). Asks again for the password. */
declare(strict_types=1);
const NO_CONSENT_NEEDED = true;
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rewards_lib.php';
require __DIR__ . '/account_data_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$blockers = deletionBlockers($uid);
$blocked = $blockers['admin'] || $blockers['crews'];
$byGoogle = dbOne("SELECT 1 AS x FROM user_identities WHERE provider = 'google' AND user_id = ?", [$uid]) !== null;
$error = null;

if (isPost() && !$blocked) {
    checkCsrf();
    $row = dbOne('SELECT email, password_hash FROM users WHERE id = ?', [$uid]);
    $proof = (string)($_POST['confirm'] ?? '');
    $ok = $byGoogle ? hash_equals(mb_strtolower((string)$row['email']), mb_strtolower(trim($proof)))
                    : password_verify($proof, (string)$row['password_hash']);
    if (($_POST['understood'] ?? '') !== '1') {
        $error = t('delete.need_check');
    } elseif (!$ok) {
        $error = t('delete.wrong');
        usleep(400000);
    } else {
        deleteAccount($uid);
        $_SESSION = [];
        session_regenerate_id(true);
        flash(t('delete.done'));
        redirect('/login');
    }
}
pageHeader(t('delete.title'));
?>
<article class="text narrow-text">
  <h1><?= te('delete.title') ?></h1>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p><?= te('delete.intro') ?></p>
  <ul>
    <?php for ($i = 1; $i <= 5; $i++): ?><li><?= e(t('delete.l' . $i, ['logdays' => setting('log_days', '7')])) ?></li><?php endfor; ?>
  </ul>
  <?php if ($blockers['admin']): ?><p class="alert alert-error"><?= te('delete.blocked_admin') ?></p><?php endif; ?>
  <?php if ($blockers['crews']): ?><p class="alert alert-error"><?= te('delete.blocked_crews', ['crews' => implode(', ', $blockers['crews'])]) ?></p><?php endif; ?>
  <?php if (!$blocked): ?>
    <form method="post" class="form" autocomplete="off">
      <?= csrfField() ?>
      <div class="field">
        <label for="confirm"><?= te($byGoogle ? 'delete.confirm_email' : 'delete.confirm_password') ?></label>
        <input id="confirm" name="confirm" type="<?= $byGoogle ? 'email' : 'password' ?>" required autocomplete="<?= $byGoogle ? 'off' : 'current-password' ?>">
      </div>
      <label class="check"><input type="checkbox" name="understood" value="1" required> <?= te('delete.confirm_check') ?></label>
      <div class="consent-buttons">
        <button type="submit" class="danger-submit"><?= te('delete.button') ?></button>
        <a class="secondary-submit" href="/profile#data"><?= te('delete.cancel') ?></a>
      </div>
    </form>
  <?php else: ?>
    <p><a href="/profile#data"><?= te('delete.cancel') ?></a></p>
  <?php endif; ?>
</article>
<?php pageFooter();
