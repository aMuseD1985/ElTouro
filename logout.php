<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (istPost()) {
    pruefeCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    meldung(t('logout.ok'));
}
weiterleiten('/login.php');
