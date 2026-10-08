<?php
/**
 * Setup diagnostics: /check.php?key=<migrate_key>
 * Shows what is missing – without printing passwords or credentials.
 * Optional: &mail=you@example.com sends a test mail through the SMTP configuration.
 */
declare(strict_types=1);
const SKIP_ACCESS_GATE = true;
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');

if (($CONFIG['migrate_key'] ?? '') === '' || !hash_equals((string)$CONFIG['migrate_key'], (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Kein Zugriff. Aufruf mit ?key=<migrate_key aus config.php>');
}

$results = [];
function check(string $what, bool $ok, string $info = '', bool $infoOnlyOnFailure = false): void
{
    global $results;
    $results[] = [$what, $ok, ($infoOnlyOnFailure && $ok) ? '' : $info];
}

check('PHP-Version ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'zip', 'zlib'] as $ext) {
    check("Erweiterung $ext", extension_loaded($ext));
}
check('HTTPS aktiv', isHttps(), isHttps() ? '' : 'SSL-Zertifikat für die Subdomain im KAS aktivieren (Let’s Encrypt)');
check('env gesetzt', in_array($CONFIG['env'] ?? '', ['beta', 'test', 'live'], true), (string)($CONFIG['env'] ?? '–'));
check('base_url passt zur Adresse', parse_url((string)$CONFIG['base_url'], PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? ''),
    'config: ' . parse_url((string)$CONFIG['base_url'], PHP_URL_HOST) . ' / aufgerufen: ' . ($_SERVER['HTTP_HOST'] ?? ''));
check('app_secret gesetzt (≥ 32 Zeichen)', strlen((string)($CONFIG['app_secret'] ?? '')) >= 32);
if (!empty($CONFIG['access']['enabled'])) {
    $h = (string)($CONFIG['access']['password_hash'] ?? '');
    check('Tester-Passwort-Hash gültig', $h !== '' && password_get_info($h)['algo'] !== null && password_get_info($h)['algoName'] !== 'unknown',
        'Hash mit password_hash() erzeugen, nicht das Klartext-Passwort eintragen', true);
}
$rawConfig = require __DIR__ . '/config.php';   // as written on the server, before the key mapping
$legacy = array_intersect(['zugang', 'karte', 'erster_admin'], array_keys($rawConfig));
check('config.php nutzt die neuen (englischen) Schlüssel', !$legacy,
    'Noch alte Schlüssel: ' . implode(', ', $legacy) . ' – funktioniert weiter, bitte bei Gelegenheit nach config.example.php umstellen', true);
check('Anmeldung mit Google konfiguriert', !empty($CONFIG['google']['client_id']) && !empty($CONFIG['google']['client_secret']),
    'Optional. Redirect-URI in der Google Cloud Console: ' . baseUrl() . '/auth/google/callback', true);
check('GD für Vorschaubilder beim Teilen', function_exists('imagecreatetruecolor'), 'Ohne GD erscheinen geteilte Touren ohne Vorschaubild', true);
check('Routing (BRouter) konfiguriert', !empty($CONFIG['brouter']['url']), 'Ohne BRouter verbindet der Planer Punkte gerade (Freihand) – zum Testen ok', true);
check('Kartenkacheln in config.php', !empty($rawConfig['map']['tiles'] ?? $rawConfig['karte']['kacheln'] ?? ''),
    'Nicht konfiguriert – es werden die OSM-Kacheln verwendet. Vor live einen Anbieter wie MapTiler eintragen (Block map)', true);
$opsEnabled = $CONFIG['ops']['enabled'] ?? !isLive();
check('Betriebswerkzeuge (Admin → Betrieb) aktiv', (bool)$opsEnabled, 'In config.php \'ops\' => [\'enabled\' => true] setzen', true);
$backupDir = dataDir('backups');
check('Backup-Ordner beschreibbar', is_dir($backupDir) && is_writable($backupDir), 'Ordner data/backups per FTP anlegen und beschreibbar machen', true);
check('data/ ist gesperrt (.htaccess)', is_file(DATA_DIR . '/.htaccess'), 'Datei data/.htaccess mit „Require all denied“ anlegen', true);
check('Upload-Grenze für Deployments', true, 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'));
check('first_admin gesetzt', filter_var($CONFIG['first_admin'] ?? '', FILTER_VALIDATE_EMAIL) !== false);

try {
    db()->query('SELECT 1');
    check('Datenbankverbindung', true, (string)db()->getAttribute(PDO::ATTR_SERVER_VERSION));
    $present = array_column(dbAll('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't');
    $missing = array_diff(['users', 'user_profiles', 'auth_tokens', 'login_attempts', 'rider_groups', 'group_members', 'pages', 'settings',
                           'tours', 'rides', 'ride_signups'], $present);
    check('Tabellen angelegt', !$missing, $missing ? 'fehlen: ' . implode(', ', $missing) . ' → /migrate.php?key=… aufrufen' : '');
} catch (Throwable $ex) {
    check('Datenbankverbindung', false, eltouroHint($ex->getMessage()) ?: $ex->getMessage());
}

// Sessions: directory writable? Is the session kept between two requests?
$sessionDir = DATA_DIR . '/sessions';
check('Sitzungsordner beschreibbar', is_dir($sessionDir) && is_writable($sessionDir),
    'Ordner data/sessions anlegen und per FTP Schreibrechte geben (chmod 700 oder 755)', true);
check('Sitzungen werden dort gespeichert', session_save_path() === $sessionDir, 'aktuell: ' . session_save_path(), true);
$counter = (int)($_SESSION['check_counter'] ?? 0);
$_SESSION['check_counter'] = $counter + 1;
check('Sitzung bleibt erhalten', $counter > 0,
    $counter > 0 ? "Aufruf Nr. " . ($counter + 1) . " in derselben Sitzung" : 'Seite einmal neu laden – dann muss hier ein Haken stehen. Bleibt es rot, speichert der Server die Sitzung nicht oder der Browser schickt das Cookie nicht zurück.');
check('Sitzungs-Cookie kommt an', isset($_COOKIE[session_name()]), 'Erst nach dem Neuladen aussagekräftig', true);

$smtp = $CONFIG['smtp'] ?? [];
check('SMTP konfiguriert', !empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass']),
    empty($smtp) ? 'Der Block \'smtp\' fehlt in config.php – aus config.example.php übernehmen und ausfüllen'
                 : ($smtp['host'] ?: '–') . ':' . ($smtp['port'] ?? '–') . ' ' . ($smtp['encryption'] ?? ''));

$testTo = (string)($_GET['mail'] ?? '');
if ($testTo !== '' && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
    require_once __DIR__ . '/mailer.php';
    try {
        sendMail($testTo, 'ElTouro Testmail (' . environment() . ')', '<p>SMTP funktioniert.</p>', 'SMTP funktioniert.');
        check("Testmail an $testTo", true, 'Posteingang und Spam-Ordner prüfen');
    } catch (Throwable $ex) {
        check("Testmail an $testTo", false, $ex->getMessage());
    }
}
?>
<!doctype html>
<meta charset="utf-8"><meta name="robots" content="noindex">
<title>ElTouro – Prüfung</title>
<div style="font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;color:#14263F">
<h1 style="font-style:italic">Einrichtung prüfen (<?= e(environment()) ?>)</h1>
<table style="border-collapse:collapse;width:100%">
<?php foreach ($results as [$what, $ok, $info]): ?>
  <tr style="border-bottom:1px solid #D5DCE5">
    <td style="padding:8px;width:28px;font-size:20px"><?= $ok ? '✅' : '❌' ?></td>
    <td style="padding:8px"><strong><?= e($what) ?></strong><?php if ($info !== ''): ?><br><span style="color:#46566C"><?= $info === strip_tags($info) ? e($info) : $info ?></span><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</table>
<p style="color:#46566C">Testmail: diese Adresse mit <code>&amp;mail=deine@adresse.de</code> aufrufen.</p>
</div>
