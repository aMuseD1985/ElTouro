<?php
/**
 * Sign-up after "Sign in with Google": Google confirmed the email address, we still need a display name,
 * the date of birth (minimum age) and the acceptance of the terms. No password – riders can set one later
 * via "Passwort vergessen".
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';
require __DIR__ . '/google_lib.php';
require_once __DIR__ . '/rewards_lib.php';

$pending = $_SESSION['google_pending'] ?? null;
if (!is_array($pending) || $pending['at'] < time() - GOOGLE_PENDING_TTL || currentUser()) {
    unset($_SESSION['google_pending']);
    redirect('/login');
}
$errors = [];
$values = ['name' => suggestDisplayName($pending['name'], $pending['email']), 'birth' => ''];

if (isPost()) {
    checkCsrf();
    $values = ['name' => postField('name', 30), 'birth' => postField('birth', 10)];
    if ($nameError = displayNameError($values['name'])) $errors[] = $nameError;
    $birth = validBirthDate($values['birth']);
    if ($birth === null) $errors[] = t('register.error_age', ['age' => MIN_AGE]);
    if (($_POST['terms'] ?? '') !== '1') $errors[] = t('register.error_terms');

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);   // no password until the rider sets one
        $existing = dbOne('SELECT id, email_verified_at FROM users WHERE email = ? FOR UPDATE', [$pending['email']]);
        if ($existing !== null && $existing['email_verified_at'] === null) {
            // Unconfirmed account with this address: whoever created it never proved the address – take it over cleanly
            dbExec('UPDATE users SET password_hash = ?, display_name = ?, birth_date = ?, locale = ?, terms_accepted_at = UTC_TIMESTAMP(),
                           email_verified_at = UTC_TIMESTAMP() WHERE id = ?',
                [$unusable, $values['name'], $birth->format('Y-m-d'), $LANG, $existing['id']]);
            dbExec('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$existing['id']]);
            $uid = (int)$existing['id'];
            storeReferral($uid);   // replaces an origin recorded for whoever created the unconfirmed account
            $isNew = true;
        } elseif ($existing !== null) {
            $uid = (int)$existing['id'];   // confirmed in the meantime – just link
            $isNew = false;
        } else {
            dbExec('INSERT INTO users (email, password_hash, display_name, birth_date, locale, terms_accepted_at, email_verified_at)
                    VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$pending['email'], $unusable, $values['name'], $birth->format('Y-m-d'), $LANG]);
            $uid = (int)$pdo->lastInsertId();
            dbExec('INSERT INTO user_profiles (user_id) VALUES (?)', [$uid]);
            storeReferral($uid);
            $isNew = true;
        }
        dbExec("INSERT IGNORE INTO user_identities (provider, subject, user_id, email, last_login_at) VALUES ('google', ?, ?, ?, UTC_TIMESTAMP())",
            [$pending['sub'], $uid, $pending['email']]);
        $pdo->commit();
        unset($_SESSION['google_pending']);
        promoteFirstAdmin($uid);
        if ($isNew) {
            recordVerification($uid);
        }
        logIn($uid);
        flash(t('verify.ok'));
        redirect($pending['next']);
    }
}

$termsLink = '<a href="/terms" target="_blank">' . te('footer.terms') . '</a>';
$privacyLink = '<a href="/privacy" target="_blank">' . te('footer.privacy') . '</a>';
pageHeader(t('google.complete_title'));
?>
<section class="narrow">
  <h1><?= te('google.complete_title') ?></h1>
  <p><?= te('google.complete_text', ['email' => $pending['email']]) ?></p>
  <?php foreach ($errors as $err): ?><p class="alert alert-error" role="alert"><?= e($err) ?></p><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrfField() ?>
    <div class="field"><label for="name"><?= te('register.name') ?></label>
      <input id="name" name="name" required maxlength="30" autocomplete="username" value="<?= e($values['name']) ?>" aria-describedby="name-h">
      <p class="hint" id="name-h"><?= te('register.name_hint') ?></p></div>
    <div class="field"><label for="birth"><?= te('register.birth') ?></label>
      <input id="birth" name="birth" type="date" required value="<?= e($values['birth']) ?>" aria-describedby="birth-h">
      <p class="hint" id="birth-h"><?= te('register.birth_hint', ['age' => MIN_AGE]) ?></p></div>
    <div class="field check"><input id="terms" name="terms" type="checkbox" value="1" required>
      <label for="terms"><?= str_replace(['{terms}', '{privacy}'], [$termsLink, $privacyLink], te('register.terms')) ?></label></div>
    <button type="submit"><?= te('register.button') ?></button>
  </form>
</section>
<?php pageFooter();
