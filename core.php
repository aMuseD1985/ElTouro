<?php
/**
 * ElTouro App – shared core for every page.
 */
declare(strict_types=1);

// $CONFIG is loaded and checked in bootstrap.php

const LANGUAGES = ['de', 'en'];
const MIN_AGE = 16;
const DATA_DIR = __DIR__ . '/data';

/* ---------- Database ---------- */

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

function dbOne(string $sql, array $p = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function dbAll(string $sql, array $p = []): array
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function dbExec(string $sql, array $p = []): int
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->rowCount();
}

/* ---------- Data directory ---------- */

/**
 * Returns a subdirectory of data/ and creates it if needed. data/ always gets its
 * "Require all denied" .htaccess first – backups contain config.php with passwords.
 */
function dataDir(string $sub = ''): string
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0700, true);
    }
    if (is_dir(DATA_DIR) && !is_file(DATA_DIR . '/.htaccess')) {
        @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
    }
    $dir = $sub === '' ? DATA_DIR : DATA_DIR . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/* ---------- Environment & access gate ---------- */

function environment(): string
{
    global $CONFIG;
    return (string)($CONFIG['env'] ?? 'live');
}

function isLive(): bool
{
    return environment() === 'live';
}

function sign(string $value): string
{
    global $CONFIG;
    return hash_hmac('sha256', $value, (string)$CONFIG['app_secret']);
}

/**
 * Beta/test: anyone without the tester password only sees the access page.
 * The cookie is signed with app_secret and bound to the password hash – changing the
 * password automatically locks out all previous testers.
 */
function accessGate(): void
{
    global $CONFIG;
    if (!isLive()) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    $a = $CONFIG['access'] ?? [];
    if (empty($a['enabled'])) {
        return;
    }
    $expected = sign('access|' . ($a['password_hash'] ?? ''));
    if (hash_equals($expected, (string)($_COOKIE['et_access'] ?? ''))) {
        return;
    }
    // Pages that must be reachable without the tester password define the constant before the require.
    // (Do not check SCRIPT_NAME – with PHP as CGI it is unreliable on some hosts and then
    //  causes an endless loop access.php → access.php.)
    if (defined('SKIP_ACCESS_GATE')) {
        return;
    }
    $next = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (str_starts_with($next, '/access')) {
        $next = '/';   // never chain "next" onto the access page itself
    }
    header('Location: /access?next=' . rawurlencode($next));
    exit;
}

function setAccessCookie(): void
{
    global $CONFIG;
    setcookie('et_access', sign('access|' . ($CONFIG['access']['password_hash'] ?? '')), [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/* ---------- Session ---------- */

/*
 * Sessions live in the app's own directory instead of the host's shared temp directory.
 * That is more reliable on shared hosting (permissions, other customers' cleanup jobs) and safer.
 */
$sessionDir = dataDir('sessions');
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', '28800');   // 8 hours without activity
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('eltouro_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

accessGate();
sendSecurityHeaders();

/**
 * Content-Security-Policy from PHP because the tile server comes from the config.
 * Everything else only from our own server.
 */
function sendSecurityHeaders(): void
{
    global $CONFIG;
    $tiles = (string)($CONFIG['map']['tiles'] ?? '');
    $tileHost = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], $tiles), PHP_URL_HOST);
    if ($tileHost && str_contains($tiles, '{s}')) {
        $tileHost = '*.' . substr($tileHost, strpos($tileHost, '.') + 1);   // a.tile.example.org, b.…, c.… -> *.tile.example.org
    }
    $tileSrc = $tileHost ? ' https://' . $tileHost : '';
    // Leaflet loads tiles as images, MapLibre (ride mode) fetches them and decodes them via blob:
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:$tileSrc; style-src 'self' 'unsafe-inline'; font-src 'self'; "
         . "script-src 'self'; worker-src 'self'; connect-src 'self'$tileSrc; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

/* ---------- Users ---------- */

function currentUser(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $user = dbOne("SELECT id, email, display_name, locale, is_admin FROM users
                            WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$id]);
            if ($user === null) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

function requireLogin(): array
{
    $u = currentUser();
    if ($u === null) {
        redirect('/login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    return $u;
}

function requireAdmin(): array
{
    $u = requireLogin();
    if ((int)$u['is_admin'] !== 1) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    return $u;
}

function logIn(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['referral']);   // an existing account was not brought by whoever's link opened this session
}

/* ---------- Language ---------- */

function detectLanguage(): string
{
    $chosen = $_GET['lang'] ?? null;
    if (is_string($chosen) && in_array($chosen, LANGUAGES, true)) {
        setcookie('lang', $chosen, ['expires' => time() + 31536000, 'path' => '/', 'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['lang'] = $chosen;
        if (!empty($_SESSION['uid'])) {
            dbExec('UPDATE users SET locale = ? WHERE id = ?', [$chosen, (int)$_SESSION['uid']]);
        }
        return $chosen;
    }
    $u = currentUser();
    if ($u !== null) {
        return $u['locale'];
    }
    if (in_array($_COOKIE['lang'] ?? '', LANGUAGES, true)) {
        return $_COOKIE['lang'];
    }
    return str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'en') ? 'en' : 'de';
}

$TEXTS = require __DIR__ . '/lang.php';
$LANG = detectLanguage();

function t(string $key, array $vars = []): string
{
    global $TEXTS, $LANG;
    $text = $TEXTS[$LANG][$key] ?? $TEXTS['de'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

/** Text in a given language – for mails to other users, who may have chosen a different language. */
function tl(string $lang, string $key, array $vars = []): string
{
    global $LANG;
    $current = $LANG;
    $LANG = in_array($lang, LANGUAGES, true) ? $lang : 'de';
    try {
        return t($key, $vars);
    } finally {
        $LANG = $current;
    }
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function te(string $key, array $vars = []): string
{
    return e(t($key, $vars));
}

/* ---------- Forms ---------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function checkCsrf(): void
{
    $hadToken = !empty($_SESSION['csrf']);
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        global $ELTOURO_DEBUG;
        if (!empty($ELTOURO_DEBUG)) {
            // In debug mode, tell apart: session lost or just an old form?
            exit(t('error.csrf') . ' [Debug: ' . ($hadToken
                ? 'Sitzung vorhanden, Token passt nicht – altes Formular oder zweiter Tab.'
                : 'Sitzung war leer – die Sitzung wird zwischen zwei Aufrufen nicht gespeichert. /check.php zeigt die Ursache.') . ']');
        }
        exit(t('error.csrf'));
    }
}

/** For fetch() calls: CSRF token in the X-CSRF header instead of a form field. */
function checkCsrfHeader(): bool
{
    return hash_equals(csrfToken(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''));
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function postField(string $name, int $max = 200): string
{
    return mb_substr(trim((string)($_POST[$name] ?? '')), 0, $max);
}

/** Multi-line text from a form: normalised line breaks, trimmed, cut to $max characters. */
function postText(string $name, int $max): string
{
    return mb_substr(trim(str_replace("\r", '', (string)($_POST[$name] ?? ''))), 0, $max);
}

function redirect(string $target): never
{
    // Only relative targets (no open redirect)
    if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
        $target = '/';
    }
    header('Location: ' . $target, true, 303);
    exit;
}

function flash(string $text, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $text];
}

/** Uniform 404 page. Also used where something exists but must not be revealed. */
function notFound(string $extra = ''): never
{
    http_response_code(404);
    pageHeader(t('error.not_found'));
    echo '<h1>' . te('error.not_found') . '</h1>' . ($extra !== '' ? '<p>' . e($extra) . '</p>' : '');
    pageFooter();
    exit;
}

function baseUrl(): string
{
    global $CONFIG;
    return rtrim((string)$CONFIG['base_url'], '/');
}

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------- Settings (admin) ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (dbAll('SELECT k, v FROM settings') as $r) {
                $cache[$r['k']] = (string)$r['v'];
            }
        } catch (Throwable) {
            // Before the first migration the table does not exist yet
        }
    }
    return $cache[$key] ?? $default;
}

/* ---------- Text formatting for maintained pages and posts ---------- */

/**
 * Very small, safe Markdown: "## Heading", "### Subheading", paragraphs separated by a blank line,
 * "- item", **bold**, [text](https://…) and [text](mailto:…).
 * Everything is escaped first – HTML from a form never gets through unfiltered.
 */
function formatText(string $raw): string
{
    $html = '';
    $blocks = preg_split("/\R{2,}/", trim(str_replace("\r", '', $raw))) ?: [];
    foreach ($blocks as $block) {
        $lines = explode("\n", $block);
        if (preg_match('/^(#{2,3}) (.+)$/', $lines[0], $m) && count($lines) === 1) {
            $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html .= "<$tag>" . inlineFormat($m[2]) . "</$tag>\n";
            continue;
        }
        if (str_starts_with($lines[0], '- ')) {
            $html .= "<ul>\n";
            foreach ($lines as $l) {
                $html .= '<li>' . inlineFormat(ltrim(substr($l, 2))) . "</li>\n";
            }
            $html .= "</ul>\n";
            continue;
        }
        $html .= '<p>' . implode("<br>\n", array_map('inlineFormat', $lines)) . "</p>\n";
    }
    return $html;
}

function inlineFormat(string $s): string
{
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    return preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|\/)[^\s)]+)\)/', function ($m) {
        return '<a href="' . $m[2] . '">' . $m[1] . '</a>';   // both already escaped
    }, $s);
}

/* ---------- Layout ---------- */

/**
 * @param array<string,string> $meta Open Graph / Twitter tags for link previews, e.g. ['og:title' => …, 'og:image' => …]
 */
function pageHeader(string $title, array $meta = []): void
{
    global $LANG;
    $u = currentUser();
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    $env = environment();
    $banner = setting('banner_' . $LANG);
    ?><!doctype html>
<html lang="<?= e($LANG) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – ElTouro<?= isLive() ? '' : ' ' . strtoupper(e($env)) ?></title>
<?php if (!isLive()): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/style.css?v=19">
<meta name="theme-color" content="#14263F">
<?php foreach ($meta as $property => $content): ?><meta <?= str_starts_with($property, 'og:') ? 'property' : 'name' ?>="<?= e($property) ?>" content="<?= e($content) ?>">
<?php endforeach; ?>
</head>
<body>
<a class="skip" href="#content"><?= te('nav.skip') ?></a>
<?php if (!isLive()): ?><div class="env-band env-<?= e($env) ?>"><?= e(strtoupper($env)) ?> · <?= te('env.notice') ?></div><?php endif; ?>
<?php if ($banner !== ''): ?><div class="banner" role="status"><?= inlineFormat($banner) ?></div><?php endif; ?>
<header class="site-header">
  <a class="brand" href="/">ElTouro</a>
  <nav class="main-nav" aria-label="<?= te('nav.main') ?>">
    <?php if ($u): ?>
      <a href="/rides"><?= te('nav.rides') ?></a>
      <a href="/tours"><?= te('nav.tours') ?></a>
      <a href="/crews"><?= te('nav.crews') ?></a>
      <a href="/forum"><?= te('nav.forum') ?></a>
      <a href="/profile"><?= te('nav.profile') ?></a>
      <?php if ((int)$u['is_admin'] === 1): ?><a href="/admin/"><?= te('nav.admin') ?></a><?php endif; ?>
      <form method="post" action="/logout" class="inline"><?= csrfField() ?><button type="submit" class="link"><?= te('nav.logout') ?></button></form>
    <?php else: ?>
      <a href="/login"><?= te('nav.login') ?></a>
      <?php if (setting('registration_open', '1') === '1'): ?><a href="/register"><?= te('nav.register') ?></a><?php endif; ?>
    <?php endif; ?>
  </nav>
  <nav class="lang-switch" aria-label="<?= te('nav.language') ?>">
    <a href="<?= e($path) ?>?lang=de" <?= $LANG === 'de' ? 'aria-current="true"' : '' ?>>DE</a>
    <a href="<?= e($path) ?>?lang=en" <?= $LANG === 'en' ? 'aria-current="true"' : '' ?>>EN</a>
  </nav>
</header>
<main id="content" class="content">
<?php
    foreach ($_SESSION['flash'] ?? [] as [$type, $text]) {
        echo '<p class="alert alert-' . e($type) . '" role="' . ($type === 'ok' ? 'status' : 'alert') . '">' . e($text) . '</p>';
    }
    unset($_SESSION['flash']);
}

function pageFooter(): void
{
    ?>
</main>
<footer class="site-footer">
  <p>© <?= date('Y') ?> ElTouro</p>
  <nav>
    <a href="/imprint"><?= te('footer.imprint') ?></a>
    <a href="/privacy"><?= te('footer.privacy') ?></a>
    <a href="/terms"><?= te('footer.terms') ?></a>
  </nav>
</footer>
</body>
</html>
<?php
}
