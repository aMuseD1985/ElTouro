<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';

if (currentUser()) {
    redirect('/');
}
$open = setting('registration_open', '1') === '1';
$errors = [];
$values = ['email' => '', 'name' => '', 'birth' => ''];

if ($open && isPost()) {
    checkCsrf();
    $values = ['email' => strtolower(postField('email', 254)), 'name' => postField('name', 30), 'birth' => postField('birth', 10)];
    $pw = (string)($_POST['password'] ?? '');

    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('register.error_email');
    if (!preg_match('/^[\p{L}\p{N}._-]{3,30}$/u', $values['name'])) $errors[] = t('register.error_name');
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $values['birth']);
    if (!$birth || $birth > new DateTimeImmutable('-' . MIN_AGE . ' years') || $birth < new DateTimeImmutable('-110 years')) {
        $errors[] = t('register.error_age', ['age' => MIN_AGE]);
    }
    if (mb_strlen($pw) < 10) $errors[] = t('register.error_password');
    if (($_POST['terms'] ?? '') !== '1') $errors[] = t('register.error_terms');

    if (!$errors && dbOne('SELECT id FROM users WHERE display_name = ?', [$values['name']])) {
        $errors[] = t('register.error_name_taken');
    }

    if (!$errors) {
        try {
            $existing = dbOne('SELECT id, email_verified_at FROM users WHERE email = ?', [$values['email']]);
            if ($existing === null) {
                dbExec('INSERT INTO users (email, password_hash, display_name, birth_date, locale, terms_accepted_at)
                        VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$values['email'], password_hash($pw, PASSWORD_DEFAULT), $values['name'], $birth->format('Y-m-d'), $LANG]);
                $uid = (int)db()->lastInsertId();
                dbExec('INSERT INTO user_profiles (user_id) VALUES (?)', [$uid]);
                $token = createToken($uid, 'verify', 48);
                sendAccountMail($values['email'], 'mail.verify_subject', 'mail.verify_text',
                    ['name' => $values['name'], 'link' => baseUrl() . '/verify.php?t=' . $token]);
            }
            // Same answer for an existing address – nobody should learn who is registered
            flash(t('register.done'));
            redirect('/login.php');
        } catch (Throwable $ex) {
            error_log('ElTouro register: ' . $ex->getMessage());
            $errors[] = t('error.general');
        }
    }
}

$termsLink = '<a href="/terms" target="_blank">' . te('footer.terms') . '</a>';
$privacyLink = '<a href="/privacy" target="_blank">' . te('footer.privacy') . '</a>';

pageHeader(t('register.title'));
?>
<section class="narrow">
  <h1><?= te('register.title') ?></h1>
  <?php if (!$open): ?>
    <p><?= te('register.closed') ?></p>
  <?php else: ?>
    <?php foreach ($errors as $err): ?><p class="alert alert-error" role="alert"><?= e($err) ?></p><?php endforeach; ?>
    <form method="post" class="form">
      <?= csrfField() ?>
      <div class="field"><label for="email"><?= te('register.email') ?></label>
        <input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= e($values['email']) ?>"></div>
      <div class="field"><label for="name"><?= te('register.name') ?></label>
        <input id="name" name="name" required maxlength="30" autocomplete="username" value="<?= e($values['name']) ?>" aria-describedby="name-h">
        <p class="hint" id="name-h"><?= te('register.name_hint') ?></p></div>
      <div class="field"><label for="birth"><?= te('register.birth') ?></label>
        <input id="birth" name="birth" type="date" required value="<?= e($values['birth']) ?>" aria-describedby="birth-h">
        <p class="hint" id="birth-h"><?= te('register.birth_hint', ['age' => MIN_AGE]) ?></p></div>
      <div class="field"><label for="password"><?= te('register.password') ?></label>
        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" aria-describedby="pw-h">
        <p class="hint" id="pw-h"><?= te('register.password_hint') ?></p></div>
      <div class="field check"><input id="terms" name="terms" type="checkbox" value="1" required>
        <label for="terms"><?= str_replace(['{terms}', '{privacy}'], [$termsLink, $privacyLink], te('register.terms')) ?></label></div>
      <button type="submit"><?= te('register.button') ?></button>
    </form>
  <?php endif; ?>
</section>
<?php pageFooter();
