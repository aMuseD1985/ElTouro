<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';

if (isPost()) {
    checkCsrf();
    $email = strtolower(postField('email', 254));
    $attempts = (int)dbOne('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [clientIp()])['n'];
    dbExec('INSERT INTO login_attempts (ip) VALUES (?)', [clientIp()]);
    $u = $attempts < 10 ? dbOne("SELECT id, display_name, locale FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$email]) : null;
    if ($u) {
        try {
            $token = createToken((int)$u['id'], 'reset', 1);
            sendAccountMail($email, 'mail.reset_subject', 'mail.reset_text',
                ['name' => $u['display_name'], 'link' => baseUrl() . '/password_reset.php?t=' . $token]);
        } catch (Throwable $ex) {
            error_log('ElTouro reset: ' . $ex->getMessage());
        }
    }
    // Always the same answer
    flash(t('password.sent'));
    redirect('/login.php');
}

pageHeader(t('password.title'));
?>
<section class="narrow">
  <h1><?= te('password.title') ?></h1>
  <p><?= te('password.text') ?></p>
  <form method="post" class="form">
    <?= csrfField() ?>
    <div class="field"><label for="email"><?= te('register.email') ?></label>
      <input id="email" name="email" type="email" required autocomplete="email"></div>
    <button type="submit"><?= te('password.button') ?></button>
  </form>
</section>
<?php pageFooter();
