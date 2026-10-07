<?php
/**
 * Entry point for every page. This file is deliberately written in "old" PHP so that it
 * still runs on a PHP version that is too old and shows an understandable message instead
 * of an empty 500. The actual core lives in core.php.
 */

function eltouroFatal($title, $text)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>ElTouro – Fehler</title>'
       . '<div style="font-family:Arial,sans-serif;max-width:640px;margin:48px auto;padding:0 16px;color:#14263F">'
       . '<h1 style="font-style:italic">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
       . '<p style="line-height:1.5">' . $text . '</p></div>';
    exit;
}

if (PHP_VERSION_ID < 80100) {
    eltouroFatal('PHP-Version zu alt',
        'ElTouro braucht PHP 8.1 oder neuer, hier läuft PHP ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '. '
        . 'Im KAS unter Domain → diese Subdomain → PHP-Version auf 8.2 oder 8.3 stellen.');
}

if (!is_file(__DIR__ . '/config.php')) {
    eltouroFatal('config.php fehlt', 'Bitte <code>config.example.php</code> nach <code>config.php</code> kopieren und ausfüllen.');
}
$CONFIG = require __DIR__ . '/config.php';
if (!is_array($CONFIG)) {
    eltouroFatal('config.php ist fehlerhaft', 'Die Datei muss mit <code>return [ … ];</code> ein Array zurückgeben.');
}

/**
 * Configs on the servers may still use the German keys from before the English refactor
 * (config.php is never deployed). Map them so both work; the English key wins.
 */
function eltouroNormalizeConfig($c)
{
    $sections = array(
        'zugang'       => array('access', array('aktiv' => 'enabled', 'passwort_hash' => 'password_hash')),
        'karte'        => array('map', array('kacheln' => 'tiles')),
        'ops'          => array('ops', array('aktiv' => 'enabled')),
        'brouter'      => array('brouter', array('profil' => 'profile', 'profil_2027' => 'profile_2027')),
    );
    foreach ($sections as $old => $map) {
        $new = $map[0];
        if (!isset($c[$old]) || !is_array($c[$old])) {
            continue;
        }
        $merged = isset($c[$new]) && is_array($c[$new]) ? $c[$new] : array();
        foreach ($c[$old] as $k => $v) {
            $nk = isset($map[1][$k]) ? $map[1][$k] : $k;
            if (!array_key_exists($nk, $merged)) {
                $merged[$nk] = $v;
            }
        }
        $c[$new] = $merged;
    }
    if (!isset($c['first_admin']) && isset($c['erster_admin'])) {
        $c['first_admin'] = $c['erster_admin'];
    }
    return $c;
}
$CONFIG = eltouroNormalizeConfig($CONFIG);

$ELTOURO_DEBUG = !empty($CONFIG['debug']) && (($CONFIG['env'] ?? 'live') !== 'live');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/** Turns typical error messages into a concrete hint. */
function eltouroHint($message)
{
    $rules = [
        'Base table or view not found' => 'Eine Tabelle fehlt: einmal <code>/migrate.php?key=…</code> aufrufen.',
        '[1045]'                       => 'Datenbank-Benutzer oder Passwort in <code>config.php</code> stimmen nicht.',
        '[1044]'                       => 'Der Datenbank-Benutzer hat keine Rechte auf diese Datenbank – Datenbankname in der DSN prüfen.',
        '[1049]'                       => 'Die Datenbank in der DSN gibt es nicht – Namen im KAS nachsehen.',
        '[2002]'                       => 'Datenbank-Server nicht erreichbar – bei all-inkl ist der Host <code>localhost</code>.',
        'could not find driver'        => 'PDO-MySQL fehlt – im KAS eine PHP-Version mit MySQL-Unterstützung wählen.',
        'SMTP'                         => 'Mailversand fehlgeschlagen – SMTP-Daten in <code>config.php</code> prüfen (Server, Port 587 + tls).',
    ];
    foreach ($rules as $pattern => $hint) {
        if (strpos($message, $pattern) !== false) {
            return $hint;
        }
    }
    return '';
}

set_exception_handler(function ($ex) use (&$ELTOURO_DEBUG) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log('ElTouro [' . $ref . '] ' . get_class($ex) . ': ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    $hint = eltouroHint($ex->getMessage());
    if ($ELTOURO_DEBUG) {
        eltouroFatal('Fehler (Debug-Modus)',
            ($hint !== '' ? '<strong>' . $hint . '</strong><br><br>' : '')
            . '<code style="white-space:pre-wrap">' . htmlspecialchars(get_class($ex) . ': ' . $ex->getMessage() . "\n" . basename($ex->getFile()) . ':' . $ex->getLine(), ENT_QUOTES, 'UTF-8') . '</code>'
            . '<br><br>Referenz ' . $ref);
    }
    eltouroFatal('Da ist etwas schiefgelaufen', 'Bitte versuch es gleich noch einmal. Referenz: ' . $ref);
});

// Make "hard" errors visible too (e.g. a parse error in an included file)
register_shutdown_function(function () use (&$ELTOURO_DEBUG) {
    $f = error_get_last();
    if ($f && in_array($f['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('ElTouro fatal: ' . $f['message'] . ' in ' . $f['file'] . ':' . $f['line']);
        if ($ELTOURO_DEBUG) {
            eltouroFatal('Schwerer Fehler (Debug-Modus)', '<code style="white-space:pre-wrap">'
                . htmlspecialchars($f['message'] . "\n" . basename($f['file']) . ':' . $f['line'], ENT_QUOTES, 'UTF-8') . '</code>');
        }
    }
});

require __DIR__ . '/core.php';
