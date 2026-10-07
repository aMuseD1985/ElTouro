<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/konto.php';

$uid = loeseTokenEin((string)($_GET['t'] ?? ''), 'verify');
if ($uid === null) {
    meldung(t('best.fehler'), 'fehler');
    weiterleiten('/login.php');
}
ausfuehren("UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ? AND status = 'active'", [$uid]);

// Die in der Config hinterlegte Adresse wird automatisch Plattform-Admin
$u = einzeln('SELECT email FROM users WHERE id = ?', [$uid]);
if ($u && ($CONFIG['erster_admin'] ?? '') !== '' && strcasecmp($u['email'], (string)$CONFIG['erster_admin']) === 0) {
    ausfuehren('UPDATE users SET is_admin = 1 WHERE id = ?', [$uid]);
}

einloggen($uid);
meldung(t('best.ok'));
weiterleiten('/');
