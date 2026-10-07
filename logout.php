<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (isPost()) {
    checkCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    flash(t('logout.ok'));
}
redirect('/login.php');
