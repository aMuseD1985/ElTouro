<?php
/**
 * ElTouro App – gemeinsamer Einstieg für jede Seite.
 */
declare(strict_types=1);

// $CONFIG wird in bootstrap.php geladen und geprüft

const SPRACHEN = ['de', 'en'];
const MINDESTALTER = 16;

/* ---------- Datenbank ---------- */

function db(): PDO
{
    global $CONFIG;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($CONFIG['db']['dsn'], $CONFIG['db']['user'], $CONFIG['db']['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function einzeln(string $sql, array $p = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function alle(string $sql, array $p = []): array
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function ausfuehren(string $sql, array $p = []): int
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->rowCount();
}

/* ---------- Umgebung & Zugangsschutz ---------- */

function umgebung(): string
{
    global $CONFIG;
    return (string)($CONFIG['env'] ?? 'live');
}

function istLive(): bool
{
    return umgebung() === 'live';
}

function signiere(string $wert): string
{
    global $CONFIG;
    return hash_hmac('sha256', $wert, (string)$CONFIG['app_secret']);
}

/**
 * Beta/Test: Wer das Tester-Passwort nicht kennt, sieht nur die Zugangsseite.
 * Cookie ist mit app_secret signiert und hängt am Passwort-Hash – Passwort ändern
 * sperrt damit automatisch alle bisherigen Tester aus.
 */
function zugangsschutz(): void
{
    global $CONFIG;
    if (!istLive()) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    $z = $CONFIG['zugang'] ?? [];
    if (empty($z['aktiv'])) {
        return;
    }
    $erwartet = signiere('zugang|' . ($z['passwort_hash'] ?? ''));
    if (hash_equals($erwartet, (string)($_COOKIE['et_zugang'] ?? ''))) {
        return;
    }
    // Seiten, die ohne Tester-Passwort erreichbar sein müssen, setzen vor dem require die Konstante.
    // (Nicht über SCRIPT_NAME prüfen – der ist bei PHP als CGI je nach Hoster unzuverlässig
    //  und führt dann zur Endlosschleife zugang.php → zugang.php.)
    if (defined('OHNE_ZUGANGSSCHUTZ')) {
        return;
    }
    $weiter = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (str_starts_with($weiter, '/zugang.php')) {
        $weiter = '/';   // nie "weiter" auf die Zugangsseite selbst verketten
    }
    header('Location: /zugang.php?weiter=' . rawurlencode($weiter));
    exit;
}

function setzeZugangsCookie(): void
{
    global $CONFIG;
    setcookie('et_zugang', signiere('zugang|' . ($CONFIG['zugang']['passwort_hash'] ?? '')), [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => istHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function istHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/* ---------- Session ---------- */

/*
 * Sitzungen liegen in einem eigenen Ordner der App statt im gemeinsamen Temp-Verzeichnis des
 * Hosters. Das ist auf Shared Hosting zuverlässiger (Rechte, Aufräumjobs anderer Kunden) und sicherer.
 * Der Ordner ist per .htaccess für den Browser gesperrt.
 */
$sitzungsOrdner = __DIR__ . '/daten/sitzungen';
if (!is_dir($sitzungsOrdner)) {
    @mkdir($sitzungsOrdner, 0700, true);
}
if (is_dir($sitzungsOrdner) && is_writable($sitzungsOrdner)) {
    session_save_path($sitzungsOrdner);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', '28800');   // 8 Stunden ohne Aktivität
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('eltouro_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => istHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

zugangsschutz();
sendeSicherheitsHeader();

/**
 * Content-Security-Policy aus PHP, weil der Kachel-Server aus der Config kommt.
 * Alles andere nur vom eigenen Server.
 */
function sendeSicherheitsHeader(): void
{
    global $CONFIG;
    $kachelHost = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], (string)($CONFIG['karte']['kacheln'] ?? '')), PHP_URL_HOST);
    $bilder = "'self' data:" . ($kachelHost ? ' https://' . $kachelHost : '');
    header("Content-Security-Policy: default-src 'self'; img-src $bilder; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

/* ---------- Nutzer ---------- */

function aktuellerNutzer(): ?array
{
    static $nutzer = false;
    if ($nutzer === false) {
        $nutzer = null;
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $nutzer = einzeln("SELECT id, email, display_name, locale, is_admin FROM users
                                WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$id]);
            if ($nutzer === null) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $nutzer;
}

function mussEingeloggtSein(): array
{
    $n = aktuellerNutzer();
    if ($n === null) {
        weiterleiten('/login.php?weiter=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    return $n;
}

function mussAdminSein(): array
{
    $n = mussEingeloggtSein();
    if ((int)$n['is_admin'] !== 1) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    return $n;
}

function einloggen(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
}

/* ---------- Sprache ---------- */

function ermittleSprache(): string
{
    $gewaehlt = $_GET['lang'] ?? null;
    if (is_string($gewaehlt) && in_array($gewaehlt, SPRACHEN, true)) {
        setcookie('lang', $gewaehlt, ['expires' => time() + 31536000, 'path' => '/', 'secure' => istHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['lang'] = $gewaehlt;
        if (!empty($_SESSION['uid'])) {
            ausfuehren('UPDATE users SET locale = ? WHERE id = ?', [$gewaehlt, (int)$_SESSION['uid']]);
        }
        return $gewaehlt;
    }
    $n = aktuellerNutzer();
    if ($n !== null) {
        return $n['locale'];
    }
    if (in_array($_COOKIE['lang'] ?? '', SPRACHEN, true)) {
        return $_COOKIE['lang'];
    }
    return str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'en') ? 'en' : 'de';
}

$TEXTE = require __DIR__ . '/lang.php';
$LANG = ermittleSprache();

function t(string $key, array $vars = []): string
{
    global $TEXTE, $LANG;
    $text = $TEXTE[$LANG][$key] ?? $TEXTE['de'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function te(string $key, array $vars = []): string
{
    return e(t($key, $vars));
}

/* ---------- Formulare ---------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfFeld(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function pruefeCsrf(): void
{
    $hatteToken = !empty($_SESSION['csrf']);
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        global $ELTOURO_DEBUG;
        if (!empty($ELTOURO_DEBUG)) {
            // Im Debug-Modus unterscheiden: Sitzung verloren oder nur altes Formular?
            exit(t('fehler.csrf') . ' [Debug: ' . ($hatteToken
                ? 'Sitzung vorhanden, Token passt nicht – altes Formular oder zweiter Tab.'
                : 'Sitzung war leer – die Sitzung wird zwischen zwei Aufrufen nicht gespeichert. /pruefen.php zeigt die Ursache.') . ']');
        }
        exit(t('fehler.csrf'));
    }
}

function istPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function feld(string $name, int $max = 200): string
{
    return mb_substr(trim((string)($_POST[$name] ?? '')), 0, $max);
}

function weiterleiten(string $ziel): never
{
    // Nur relative Ziele zulassen (kein Open Redirect)
    if (!str_starts_with($ziel, '/') || str_starts_with($ziel, '//')) {
        $ziel = '/';
    }
    header('Location: ' . $ziel, true, 303);
    exit;
}

function meldung(string $text, string $art = 'ok'): void
{
    $_SESSION['meldungen'][] = [$art, $text];
}

function basisUrl(): string
{
    global $CONFIG;
    return rtrim((string)$CONFIG['base_url'], '/');
}

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------- Einstellungen (Admin) ---------- */

function einstellung(string $key, string $standard = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (alle('SELECT k, v FROM settings') as $r) {
                $cache[$r['k']] = (string)$r['v'];
            }
        } catch (Throwable) {
            // Vor der ersten Migration gibt es die Tabelle noch nicht
        }
    }
    return $cache[$key] ?? $standard;
}

/* ---------- Textformatierung für gepflegte Seiten ---------- */

/**
 * Sehr kleines, sicheres Markdown: "## Überschrift", "### Unterüberschrift",
 * Absätze durch Leerzeile, "- Punkt", **fett**, [Text](https://…) und [Text](mailto:…).
 * Alles wird zuerst escaped – HTML aus dem Admin-Formular kommt nie ungefiltert durch.
 */
function formatiereText(string $roh): string
{
    $html = '';
    $bloecke = preg_split("/\R{2,}/", trim(str_replace("\r", '', $roh))) ?: [];
    foreach ($bloecke as $block) {
        $zeilen = explode("\n", $block);
        if (preg_match('/^(#{2,3}) (.+)$/', $zeilen[0], $m) && count($zeilen) === 1) {
            $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html .= "<$tag>" . inlineFormat($m[2]) . "</$tag>\n";
            continue;
        }
        if (str_starts_with($zeilen[0], '- ')) {
            $html .= "<ul>\n";
            foreach ($zeilen as $z) {
                $html .= '<li>' . inlineFormat(ltrim(substr($z, 2))) . "</li>\n";
            }
            $html .= "</ul>\n";
            continue;
        }
        $html .= '<p>' . implode("<br>\n", array_map('inlineFormat', $zeilen)) . "</p>\n";
    }
    return $html;
}

function inlineFormat(string $s): string
{
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    return preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|\/)[^\s)]+)\)/', function ($m) {
        return '<a href="' . $m[2] . '">' . $m[1] . '</a>';   // beides schon escaped
    }, $s);
}

/* ---------- Layout ---------- */

function seitenKopf(string $titel): void
{
    global $LANG;
    $n = aktuellerNutzer();
    $pfad = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    $umg = umgebung();
    $hinweis = einstellung('banner_' . $LANG);
    ?><!doctype html>
<html lang="<?= e($LANG) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titel) ?> – ElTouro<?= istLive() ? '' : ' ' . strtoupper(e($umg)) ?></title>
<?php if (!istLive()): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/style.css?v=1">
<meta name="theme-color" content="#14263F">
</head>
<body>
<a class="skip" href="#inhalt"><?= te('nav.skip') ?></a>
<?php if (!istLive()): ?><div class="umgebung umgebung-<?= e($umg) ?>"><?= e(strtoupper($umg)) ?> · <?= te('env.hinweis') ?></div><?php endif; ?>
<?php if ($hinweis !== ''): ?><div class="banner" role="status"><?= inlineFormat($hinweis) ?></div><?php endif; ?>
<header class="kopf">
  <a class="marke" href="/">ElTouro</a>
  <nav class="hauptnav" aria-label="<?= te('nav.haupt') ?>">
    <?php if ($n): ?>
      <a href="/touren.php"><?= te('nav.touren') ?></a>
      <a href="/herden.php"><?= te('nav.herden') ?></a>
      <a href="/forum.php"><?= te('nav.forum') ?></a>
      <a href="/profil.php"><?= te('nav.profil') ?></a>
      <?php if ((int)$n['is_admin'] === 1): ?><a href="/admin/"><?= te('nav.admin') ?></a><?php endif; ?>
      <form method="post" action="/logout.php" class="inline"><?= csrfFeld() ?><button type="submit" class="link"><?= te('nav.logout') ?></button></form>
    <?php else: ?>
      <a href="/login.php"><?= te('nav.login') ?></a>
      <?php if (einstellung('registrierung_offen', '1') === '1'): ?><a href="/registrieren.php"><?= te('nav.registrieren') ?></a><?php endif; ?>
    <?php endif; ?>
  </nav>
  <nav class="sprache" aria-label="<?= te('nav.sprache') ?>">
    <a href="<?= e($pfad) ?>?lang=de" <?= $LANG === 'de' ? 'aria-current="true"' : '' ?>>DE</a>
    <a href="<?= e($pfad) ?>?lang=en" <?= $LANG === 'en' ? 'aria-current="true"' : '' ?>>EN</a>
  </nav>
</header>
<main id="inhalt" class="inhalt">
<?php
    foreach ($_SESSION['meldungen'] ?? [] as [$art, $text]) {
        echo '<p class="meldung meldung-' . e($art) . '" role="' . ($art === 'ok' ? 'status' : 'alert') . '">' . e($text) . '</p>';
    }
    unset($_SESSION['meldungen']);
}

function seitenFuss(): void
{
    global $LANG;
    ?>
</main>
<footer class="fuss">
  <p>© <?= date('Y') ?> ElTouro</p>
  <nav>
    <a href="/impressum"><?= te('footer.impressum') ?></a>
    <a href="/datenschutz"><?= te('footer.datenschutz') ?></a>
    <a href="/nutzungsbedingungen"><?= te('footer.nutzungsbedingungen') ?></a>
  </nav>
</footer>
</body>
</html>
<?php
}
