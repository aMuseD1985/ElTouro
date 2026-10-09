<?php
/** POST /account/export – the complete data export of the signed-in rider as a ZIP (account_data_lib.php). */
declare(strict_types=1);
const NO_CONSENT_NEEDED = true;
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rewards_lib.php';
require __DIR__ . '/account_data_lib.php';
$me = requireLogin();
if (!isPost()) {
    redirect('/profile#data');
}
checkCsrf();
set_time_limit(120);
$zip = buildExportZip((int)$me['id']);
if ($zip === null) {
    flash(t('privacy.export_failed'), 'error');
    redirect('/profile#data');
}
recordEvent((int)$me['id'], 'data_exported', 'user', (int)$me['id']);
$name = 'eltouro-daten-' . preg_replace('/[^a-z0-9]+/i', '-', (string)$me['display_name']) . '-' . gmdate('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($zip));
header('Cache-Control: no-store');
readfile($zip);
@unlink($zip);
