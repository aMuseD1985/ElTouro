<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/google_lib.php';

if (currentUser()) {
    redirect('/');
}
$error = '';
$email = '';
$next = cleanNext((string)($_GET['next'] ?? $_POST['next'] ?? '/'));

if (isPost()) {
    checkCsrf();
    $email = strtolower(postField('email', 254));
    if (random_int(1, 20) === 1) {   // occasional cleanup: keep attempts for at most 24 h
        dbExec('DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
    $attempts = (int)dbOne('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [clientIp()])['n'];
    if ($attempts >= 10) {
        $error = t('login.too_many');
    } else {
        $u = dbOne("SELECT id, password_hash FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$email]);
        // Verify a hash even without a match so the response time reveals nothing
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        if (password_verify((string)($_POST['password'] ?? ''), $hash) && $u) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                dbExec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $u['id']]);
            }
            logIn((int)$u['id']);
            redirect($next);
        }
        dbExec('INSERT INTO login_attempts (ip) VALUES (?)', [clientIp()]);
        $error = t('login.error');
    }
}

pageHeader(t('login.title'));
?>
<section class="narrow">
  <h1><?= te('login.title') ?></h1>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?= googleButton($next) ?>
  <form method="post" class="form">
    <?= csrfField() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="field"><label for="email"><?= te('register.email') ?></label>
      <input id="email" name="email" type="email" required autocomplete="email" value="<?= e($email) ?>"></div>
    <div class="field"><label for="password"><?= te('register.password') ?></label>
      <input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <button type="submit"><?= te('login.button') ?></button>
  </form>
  <p class="muted"><?= te('welcome.no_account') ?> <a href="/register<?= $next !== '/' ? '?next=' . e(rawurlencode($next)) : '' ?>"><?= te('welcome.register_short') ?></a></p>
  <p><a href="/password/forgot"><?= te('login.forgot') ?></a> · <a href="/verify/resend"><?= te('login.resend') ?></a></p>
</section>
<?php pageFooter();
