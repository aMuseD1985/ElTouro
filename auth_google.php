<?php
/**
 * /auth/google           → off to Google
 * /auth/google/callback  → back from Google: sign in, link, or continue to the short sign-up form
 *
 * Matching: 1. a linked Google account (by Google's account id, never by name),
 * 2. an existing CONFIRMED account with the same email – Google has confirmed the address, so it is linked,
 * 3. otherwise sign-up (display name, date of birth, terms) in register_google.php.
 * An UNCONFIRMED account with the same email is not taken over as it is: whoever registered it never proved
 * the address, so its password could belong to someone else (pre-hijacking). register_google.php resets it.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';
require __DIR__ . '/google_lib.php';

if (!googleEnabled()) {
    notFound();
}
if (!isset($_GET['callback'])) {
    if (currentUser()) {
        redirect('/');
    }
    googleStart((string)($_GET['next'] ?? '/'));
}

try {
    $g = googleFinish($_GET);
} catch (RuntimeException $ex) {
    flash(t($ex->getMessage() === 'cancelled' ? 'google.cancelled' : 'google.error'), 'error');
    redirect('/login');
}

$identity = dbOne("SELECT i.user_id, u.status FROM user_identities i JOIN users u ON u.id = i.user_id WHERE i.provider = 'google' AND i.subject = ?", [$g['sub']]);
if ($identity !== null) {
    if ($identity['status'] !== 'active') {
        flash(t('google.blocked'), 'error');
        redirect('/login');
    }
    dbExec("UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?", [$identity['user_id']]);
    dbExec("UPDATE user_identities SET last_login_at = UTC_TIMESTAMP(), email = ? WHERE provider = 'google' AND subject = ?", [$g['email'], $g['sub']]);
    logIn((int)$identity['user_id']);
    redirect($g['next']);
}

$user = dbOne('SELECT id, status, email_verified_at FROM users WHERE email = ?', [$g['email']]);
if ($user !== null && $user['email_verified_at'] !== null) {
    if ($user['status'] !== 'active') {
        flash(t('google.blocked'), 'error');
        redirect('/login');
    }
    dbExec("INSERT INTO user_identities (provider, subject, user_id, email, last_login_at) VALUES ('google', ?, ?, ?, UTC_TIMESTAMP())",
        [$g['sub'], $user['id'], $g['email']]);
    logIn((int)$user['id']);
    flash(t('google.linked_now'));
    redirect($g['next']);
}

if (setting('registration_open', '1') !== '1') {
    flash(t('register.closed'), 'error');
    redirect('/login');
}
$_SESSION['google_pending'] = $g + ['at' => time()];
redirect('/register/google');
