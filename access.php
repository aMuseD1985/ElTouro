<?php
declare(strict_types=1);
const SKIP_ACCESS_GATE = true;
require __DIR__ . '/bootstrap.php';

$wrong = false;
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/');

if (isPost()) {
    // Simple brake against brute forcing
    $attempts = (int)dbOne('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [clientIp()])['n'];
    if ($attempts < 10 && password_verify((string)($_POST['password'] ?? ''), (string)($CONFIG['access']['password_hash'] ?? ''))) {
        setAccessCookie();
        redirect($next);
    }
    dbExec('INSERT INTO login_attempts (ip) VALUES (?)', [clientIp()]);
    $wrong = true;
}

pageHeader(t('access.title'));
?>
<section class="narrow">
  <h1><?= te('access.title') ?></h1>
  <p><?= te('access.text') ?></p>
  <?php if ($wrong): ?><p class="alert alert-error" role="alert"><?= te('access.wrong') ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="field">
      <label for="password"><?= te('access.password') ?></label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <button type="submit"><?= te('access.button') ?></button>
  </form>
</section>
<?php pageFooter();
