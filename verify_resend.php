<?php
/**
 * /verify/resend – sends the confirmation mail again. The answer is the same whether the address exists or not, and a
 * new mail goes out at most every two minutes per account (and the page is limited per session).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';

if (currentUser()) {
    redirect('/');
}
$sent = false;
$email = '';
if (isPost()) {
    checkCsrf();
    $email = strtolower(postField('email', 254));
    $last = (int)($_SESSION['resend_at'] ?? 0);
    if (time() - $last >= 20) {
        $_SESSION['resend_at'] = time();
        $u = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? dbOne("SELECT id, display_name FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NULL", [$email]) : null;
        if ($u !== null && (int)dbOne("SELECT COUNT(*) AS n FROM auth_tokens WHERE user_id = ? AND purpose = 'verify' AND created_at > UTC_TIMESTAMP() - INTERVAL 2 MINUTE", [$u['id']])['n'] === 0) {
            try {
                $token = createToken((int)$u['id'], 'verify', 48);
                sendAccountMail($email, 'mail.verify_subject', 'mail.verify_text', ['name' => $u['display_name'], 'link' => baseUrl() . '/verify?t=' . $token]);
            } catch (Throwable $ex) {
                error_log('ElTouro resend: ' . $ex->getMessage());
            }
        }
    }
    $sent = true;
}
pageHeader(t('resend.title'));
?>
<section class="narrow">
  <h1><?= te('resend.title') ?></h1>
  <?php if ($sent): ?>
    <p class="alert alert-info" role="status"><?= te('resend.done') ?></p>
    <p><a href="/login"><?= te('login.title') ?></a></p>
  <?php else: ?>
    <p><?= te('resend.text') ?></p>
    <form method="post" class="form">
      <?= csrfField() ?>
      <div class="field"><label for="email"><?= te('register.email') ?></label>
        <input id="email" name="email" type="email" required autocomplete="email" value="<?= e($email) ?>"></div>
      <button type="submit"><?= te('resend.button') ?></button>
    </form>
  <?php endif; ?>
</section>
<?php pageFooter();
