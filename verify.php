<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';

$uid = redeemToken((string)($_GET['t'] ?? ''), 'verify');
if ($uid === null) {
    flash(t('verify.error'), 'error');
    redirect('/login.php');
}
dbExec("UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ? AND status = 'active'", [$uid]);

// The address configured in the config automatically becomes platform admin
$u = dbOne('SELECT email FROM users WHERE id = ?', [$uid]);
if ($u && ($CONFIG['first_admin'] ?? '') !== '' && strcasecmp($u['email'], (string)$CONFIG['first_admin']) === 0) {
    dbExec('UPDATE users SET is_admin = 1 WHERE id = ?', [$uid]);
}

logIn($uid);
flash(t('verify.ok'));
redirect('/');
