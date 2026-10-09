<?php
declare(strict_types=1);
const NO_CONSENT_NEEDED = true;
require __DIR__ . '/bootstrap.php';

if (isPost()) {
    checkCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    flash(t('logout.ok'));
}
redirect('/login');
