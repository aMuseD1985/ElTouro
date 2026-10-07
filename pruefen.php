<?php
/**
 * Diagnose für die Einrichtung: /pruefen.php?key=<migrate_key>
 * Zeigt, was fehlt – ohne Passwörter oder Zugangsdaten auszugeben.
 * Optional: &mail=deine@adresse.de schickt eine Testmail über die SMTP-Konfiguration.
 */
declare(strict_types=1);
const OHNE_ZUGANGSSCHUTZ = true;
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');

if (($CONFIG['migrate_key'] ?? '') === '' || !hash_equals((string)$CONFIG['migrate_key'], (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Kein Zugriff. Aufruf mit ?key=<migrate_key aus config.php>');
}

$ergebnisse = [];
function pruefung(string $was, bool $ok, string $info = '', bool $infoNurBeiFehler = false): void
{
    global $ergebnisse;
    $ergebnisse[] = [$was, $ok, ($infoNurBeiFehler && $ok) ? '' : $info];
}

pruefung('PHP-Version ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'zip', 'zlib'] as $ext) {
    pruefung("Erweiterung $ext", extension_loaded($ext));
}
pruefung('HTTPS aktiv', istHttps(), istHttps() ? '' : 'SSL-Zertifikat für die Subdomain im KAS aktivieren (Let’s Encrypt)');
pruefung('env gesetzt', in_array($CONFIG['env'] ?? '', ['beta', 'test', 'live'], true), (string)($CONFIG['env'] ?? '–'));
pruefung('base_url passt zur Adresse', parse_url((string)$CONFIG['base_url'], PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? ''),
    'config: ' . parse_url((string)$CONFIG['base_url'], PHP_URL_HOST) . ' / aufgerufen: ' . ($_SERVER['HTTP_HOST'] ?? ''));
pruefung('app_secret gesetzt (≥ 32 Zeichen)', strlen((string)($CONFIG['app_secret'] ?? '')) >= 32);
if (!empty($CONFIG['zugang']['aktiv'])) {
    $h = (string)($CONFIG['zugang']['passwort_hash'] ?? '');
    pruefung('Tester-Passwort-Hash gültig', $h !== '' && password_get_info($h)['algo'] !== null && password_get_info($h)['algoName'] !== 'unknown',
        'Hash mit password_hash() erzeugen, nicht das Klartext-Passwort eintragen', true);
}
pruefung('Routing (BRouter) konfiguriert', !empty($CONFIG['brouter']['url']), 'Ohne BRouter verbindet der Planer Punkte gerade (Freihand) – zum Testen ok', true);
pruefung('Kartenkacheln konfiguriert', !empty($CONFIG['karte']['kacheln']), 'Block \'karte\' aus config.example.php übernehmen', true);
$opsAktiv = $CONFIG['ops']['aktiv'] ?? !istLive();
pruefung('Betriebswerkzeuge (Admin → Betrieb) aktiv', (bool)$opsAktiv, 'In config.php \'ops\' => [\'aktiv\' => true] setzen', true);
$bOrdner = __DIR__ . '/daten/backups';
if (!is_dir($bOrdner)) { @mkdir($bOrdner, 0700, true); }
pruefung('Backup-Ordner beschreibbar', is_dir($bOrdner) && is_writable($bOrdner), 'Ordner daten/backups per FTP anlegen und beschreibbar machen', true);
pruefung('Upload-Grenze für Deployments', true, 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'));
pruefung('erster_admin gesetzt', filter_var($CONFIG['erster_admin'] ?? '', FILTER_VALIDATE_EMAIL) !== false);

try {
    db()->query('SELECT 1');
    pruefung('Datenbankverbindung', true, (string)db()->getAttribute(PDO::ATTR_SERVER_VERSION));
    $vorhanden = array_column(alle('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't');
    $fehlend = array_diff(['users', 'user_profiles', 'auth_tokens', 'login_attempts', 'rider_groups', 'group_members', 'pages', 'settings'], $vorhanden);
    pruefung('Tabellen angelegt', !$fehlend, $fehlend ? 'fehlen: ' . implode(', ', $fehlend) . ' → /migrate.php?key=… aufrufen' : '');
} catch (Throwable $ex) {
    pruefung('Datenbankverbindung', false, eltouroHinweis($ex->getMessage()) ?: $ex->getMessage());
}

// Sitzungen: Ordner beschreibbar? Wird die Sitzung zwischen zwei Aufrufen gehalten?
$ordner = __DIR__ . '/daten/sitzungen';
pruefung('Sitzungsordner beschreibbar', is_dir($ordner) && is_writable($ordner),
    'Ordner daten/sitzungen anlegen und per FTP Schreibrechte geben (chmod 700 oder 755)', true);
pruefung('Sitzungen werden dort gespeichert', session_save_path() === $ordner, 'aktuell: ' . session_save_path(), true);
$zaehler = (int)($_SESSION['pruef_zaehler'] ?? 0);
$_SESSION['pruef_zaehler'] = $zaehler + 1;
pruefung('Sitzung bleibt erhalten', $zaehler > 0,
    $zaehler > 0 ? "Aufruf Nr. " . ($zaehler + 1) . " in derselben Sitzung" : 'Seite einmal neu laden – dann muss hier ein Haken stehen. Bleibt es rot, speichert der Server die Sitzung nicht oder der Browser schickt das Cookie nicht zurück.');
pruefung('Sitzungs-Cookie kommt an', isset($_COOKIE[session_name()]), 'Erst nach dem Neuladen aussagekräftig', true);

$smtp = $CONFIG['smtp'] ?? [];
pruefung('SMTP konfiguriert', !empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass']),
    empty($smtp) ? 'Der Block \'smtp\' fehlt in config.php – aus config.example.php übernehmen und ausfüllen'
                 : ($smtp['host'] ?: '–') . ':' . ($smtp['port'] ?? '–') . ' ' . ($smtp['encryption'] ?? ''));

$testAn = (string)($_GET['mail'] ?? '');
if ($testAn !== '' && filter_var($testAn, FILTER_VALIDATE_EMAIL)) {
    require_once __DIR__ . '/mailer.php';
    try {
        sendeMail($testAn, 'ElTouro Testmail (' . umgebung() . ')', '<p>SMTP funktioniert.</p>', 'SMTP funktioniert.');
        pruefung("Testmail an $testAn", true, 'Posteingang und Spam-Ordner prüfen');
    } catch (Throwable $ex) {
        pruefung("Testmail an $testAn", false, $ex->getMessage());
    }
}
?>
<!doctype html>
<meta charset="utf-8"><meta name="robots" content="noindex">
<title>ElTouro – Prüfung</title>
<div style="font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;color:#14263F">
<h1 style="font-style:italic">Einrichtung prüfen (<?= e(umgebung()) ?>)</h1>
<table style="border-collapse:collapse;width:100%">
<?php foreach ($ergebnisse as [$was, $ok, $info]): ?>
  <tr style="border-bottom:1px solid #D5DCE5">
    <td style="padding:8px;width:28px;font-size:20px"><?= $ok ? '✅' : '❌' ?></td>
    <td style="padding:8px"><strong><?= e($was) ?></strong><?php if ($info !== ''): ?><br><span style="color:#46566C"><?= $info === strip_tags($info) ? e($info) : $info ?></span><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</table>
<p style="color:#46566C">Testmail: diese Adresse mit <code>&amp;mail=deine@adresse.de</code> aufrufen.</p>
</div>
