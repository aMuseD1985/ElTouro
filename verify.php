<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/account_lib.php';
require_once __DIR__ . '/rewards_lib.php';

$uid = redeemToken((string)($_GET['t'] ?? ''), 'verify');
if ($uid === null) {
    flash(t('verify.error'), 'error');
    redirect('/login');
}
dbExec("UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ? AND status = 'active'", [$uid]);

promoteFirstAdmin($uid);
recordVerification($uid);
logIn($uid);
flash(t('verify.ok'));
redirect('/');
