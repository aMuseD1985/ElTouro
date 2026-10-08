<?php
/**
 * Permanent redirects from every .php address (old German pages and the English .php names that
 * existed before the clean URLs) to the clean URLs. .htaccess sends every external .php request here.
 * Deliberately standalone: no bootstrap, no database, no access gate – it only redirects.
 * Keeps links in mails already sent (confirmation, password reset) and invite links working.
 */
declare(strict_types=1);

$path = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$q = $_GET;

/** Takes a parameter out of the query (it moves into the path). */
$take = function (string ...$names) use (&$q): string {
    $value = '';
    foreach ($names as $n) {
        if (isset($q[$n]) && is_string($q[$n]) && $value === '') {
            $value = $q[$n];
        }
        unset($q[$n]);
    }
    return $value;
};
/** Renames a query parameter (old German name → new name). */
$rename = function (string $old, string $new) use (&$q): void {
    if (isset($q[$old]) && !isset($q[$new])) {
        $q[$new] = $q[$old];
    }
    unset($q[$old]);
};
$id = fn(string ...$n) => (string)(int)$take(...$n);
$slug = fn(string ...$n) => rawurlencode(preg_replace('/[^a-z0-9-]/', '', strtolower($take(...$n))));

$target = match (preg_replace('/\.php$/', '', $path)) {
    'index', ''                          => '/',
    'zugang', 'access'                   => (function () use ($rename) { $rename('weiter', 'next'); return '/access'; })(),
    'login'                              => (function () use ($rename) { $rename('weiter', 'next'); return '/login'; })(),
    'logout'                             => '/login',
    'registrieren', 'register'           => '/register',
    'bestaetigen', 'verify'              => '/verify',
    'passwort_vergessen', 'password_forgot' => '/password/forgot',
    'passwort_neu', 'password_reset'     => '/password/reset',
    'profil', 'profile'                  => '/profile',
    'herden', 'crews'                    => '/crews',
    'herde_neu', 'crew_new'              => '/crews/new',
    'herde', 'crew'                      => '/crew/' . $slug('s'),
    'traenke', 'talk'                    => '/crew/' . $slug('s') . '/talk',
    'traenke_thema', 'talk_topic'        => (function () use ($id, $rename) { $rename('ende', 'end'); return '/talk/' . $id('id'); })(),
    'touren', 'tours'                    => '/tours',
    'tour'                               => '/tour/' . $id('id'),
    'tour_planen', 'tour_plan'           => (function () use ($id, $rename, &$q) {
                                                $rename('herde', 'crew');
                                                return isset($q['id']) ? '/tour/' . $id('id') . '/edit' : '/tour/plan';
                                            })(),
    'tour_gpx'                           => '/tour/' . $id('id') . '/gpx',
    'rides'                              => '/rides',
    'ride'                               => '/ride/' . $id('id'),
    'ride_edit'                          => (function () use ($id, &$q) { return isset($q['id']) ? '/ride/' . $id('id') . '/edit' : '/ride/new'; })(),
    'forum'                              => '/forum',
    'forum_kategorie', 'forum_category'  => '/forum/' . $slug('k', 'c'),
    'forum_neu', 'forum_new'             => '/forum/' . $slug('k', 'c') . '/new',
    'forum_thema', 'forum_topic'         => (function () use ($id, $rename) { $rename('s', 'p'); return '/forum/topic/' . $id('t'); })(),
    'melden', 'report'                   => (function () use ($rename) { $rename('typ', 'type'); return '/report'; })(),
    'impressum'                          => '/imprint',
    'datenschutz'                        => '/privacy',
    'nutzungsbedingungen'                => '/terms',
    'seite', 'page'                      => (function () use ($take) {
                                                $s = preg_replace('/[^a-z0-9-]/', '', $take('s'));
                                                $map = ['impressum' => 'imprint', 'datenschutz' => 'privacy', 'nutzungsbedingungen' => 'terms'];
                                                $s = $map[$s] ?? $s;
                                                return in_array($s, ['imprint', 'privacy', 'terms'], true) ? '/' . $s : '/page/' . rawurlencode($s);
                                            })(),
    'pruefen', 'check'                   => '/check',
    'migrate'                            => '/migrate',
    'admin/index', 'admin'               => '/admin',
    'admin/seiten', 'admin/pages'        => '/admin/pages',
    'admin/einstellungen', 'admin/settings' => '/admin/settings',
    'admin/nutzer', 'admin/users'        => '/admin/users',
    'admin/forum'                        => '/admin/forum',
    'admin/meldungen', 'admin/reports'   => '/admin/reports',
    'admin/betrieb', 'admin/ops'         => '/admin/ops',
    default                              => null,
};

// /seite/kurzname (old clean URL for maintained pages)
if ($target === null && preg_match('~^seite/([a-z0-9-]+)/?$~', $path, $m)) {
    $target = in_array($m[1], ['impressum', 'datenschutz', 'nutzungsbedingungen'], true)
        ? ['impressum' => '/imprint', 'datenschutz' => '/privacy', 'nutzungsbedingungen' => '/terms'][$m[1]]
        : '/page/' . $m[1];
}

// Missing ids or slugs (e.g. "/tour/0", "/crew//talk") are not worth a redirect
$broken = $target !== '/' && ($target === null || str_ends_with($target, '/') || str_contains($target, '//') || preg_match('~/0(/|$)~', $target));
if ($broken) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Nicht gefunden. / Not found.\n");
}
header('Location: ' . $target . ($q ? '?' . http_build_query($q) : ''), true, 301);
