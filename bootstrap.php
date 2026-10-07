<?php
/**
 * Einstieg für jede Seite. Diese Datei ist absichtlich in "altem" PHP geschrieben,
 * damit sie auch auf einer zu alten PHP-Version noch läuft und eine verständliche
 * Meldung ausgibt, statt mit einem leeren 500er abzubrechen. Der eigentliche Kern liegt in kern.php.
 */

function eltouroNotfall($titel, $text)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>ElTouro – Fehler</title>'
       . '<div style="font-family:Arial,sans-serif;max-width:640px;margin:48px auto;padding:0 16px;color:#14263F">'
       . '<h1 style="font-style:italic">' . htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') . '</h1>'
       . '<p style="line-height:1.5">' . $text . '</p></div>';
    exit;
}

if (PHP_VERSION_ID < 80100) {
    eltouroNotfall('PHP-Version zu alt',
        'ElTouro braucht PHP 8.1 oder neuer, hier läuft PHP ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '. '
        . 'Im KAS unter Domain → diese Subdomain → PHP-Version auf 8.2 oder 8.3 stellen.');
}

if (!is_file(__DIR__ . '/config.php')) {
    eltouroNotfall('config.php fehlt', 'Bitte <code>config.example.php</code> nach <code>config.php</code> kopieren und ausfüllen.');
}
$CONFIG = require __DIR__ . '/config.php';
if (!is_array($CONFIG)) {
    eltouroNotfall('config.php ist fehlerhaft', 'Die Datei muss mit <code>return [ … ];</code> ein Array zurückgeben.');
}

$ELTOURO_DEBUG = !empty($CONFIG['debug']) && (($CONFIG['env'] ?? 'live') !== 'live');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/** Übersetzt typische Fehlermeldungen in einen konkreten Hinweis. */
function eltouroHinweis($meldung)
{
    $regeln = [
        'Base table or view not found' => 'Eine Tabelle fehlt: einmal <code>/migrate.php?key=…</code> aufrufen.',
        '[1045]'                       => 'Datenbank-Benutzer oder Passwort in <code>config.php</code> stimmen nicht.',
        '[1044]'                       => 'Der Datenbank-Benutzer hat keine Rechte auf diese Datenbank – Datenbankname in der DSN prüfen.',
        '[1049]'                       => 'Die Datenbank in der DSN gibt es nicht – Namen im KAS nachsehen.',
        '[2002]'                       => 'Datenbank-Server nicht erreichbar – bei all-inkl ist der Host <code>localhost</code>.',
        'could not find driver'        => 'PDO-MySQL fehlt – im KAS eine PHP-Version mit MySQL-Unterstützung wählen.',
        'SMTP'                         => 'Mailversand fehlgeschlagen – SMTP-Daten in <code>config.php</code> prüfen (Server, Port 587 + tls).',
    ];
    foreach ($regeln as $muster => $hinweis) {
        if (strpos($meldung, $muster) !== false) {
            return $hinweis;
        }
    }
    return '';
}

set_exception_handler(function ($ex) use (&$ELTOURO_DEBUG) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log('ElTouro [' . $ref . '] ' . get_class($ex) . ': ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    $hinweis = eltouroHinweis($ex->getMessage());
    if ($ELTOURO_DEBUG) {
        eltouroNotfall('Fehler (Debug-Modus)',
            ($hinweis !== '' ? '<strong>' . $hinweis . '</strong><br><br>' : '')
            . '<code style="white-space:pre-wrap">' . htmlspecialchars(get_class($ex) . ': ' . $ex->getMessage() . "\n" . basename($ex->getFile()) . ':' . $ex->getLine(), ENT_QUOTES, 'UTF-8') . '</code>'
            . '<br><br>Referenz ' . $ref);
    }
    eltouroNotfall('Da ist etwas schiefgelaufen', 'Bitte versuch es gleich noch einmal. Referenz: ' . $ref);
});

// Auch "harte" Fehler (z. B. Parse-Fehler in einer eingebundenen Datei) sichtbar machen
register_shutdown_function(function () use (&$ELTOURO_DEBUG) {
    $f = error_get_last();
    if ($f && in_array($f['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('ElTouro fatal: ' . $f['message'] . ' in ' . $f['file'] . ':' . $f['line']);
        if ($ELTOURO_DEBUG) {
            eltouroNotfall('Schwerer Fehler (Debug-Modus)', '<code style="white-space:pre-wrap">'
                . htmlspecialchars($f['message'] . "\n" . basename($f['file']) . ':' . $f['line'], ENT_QUOTES, 'UTF-8') . '</code>');
        }
    }
});

require __DIR__ . '/kern.php';
