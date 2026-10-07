<?php
/**
 * POST /route.php  (JSON: {"punkte": [[lat,lng], …], "regelwerk": "ekfv"|"ekfv2027"})
 * Antwort: {"geojson": FeatureCollection, "hinweis": string|null}
 *
 * Erst wird die ganze Route in einem Rutsch berechnet. Klappt das nicht (z. B. ein Punkt liegt
 * abseits erlaubter Wege), wird Abschnitt für Abschnitt geroutet; nicht routbare Abschnitte
 * werden gerade verbunden und als freihand markiert. Ohne konfigurierten BRouter ist alles freihand.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/touren_lib.php';
header('Content-Type: application/json; charset=utf-8');

function antworte(int $code, array $daten): never
{
    http_response_code($code);
    echo json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (aktuellerNutzer() === null) {
    antworte(401, ['fehler' => 'login']);
}
if (!istPost() || !hash_equals(csrfToken(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    antworte(400, ['fehler' => 'csrf']);
}

$eingabe = json_decode((string)file_get_contents('php://input'), true);
$punkte = pruefeWegpunkte($eingabe['punkte'] ?? null);
if ($punkte === null) {
    antworte(422, ['fehler' => 'punkte']);
}
$regelwerk = ($eingabe['regelwerk'] ?? '') === 'ekfv2027' ? 'ekfv2027' : 'ekfv';

/** Fragt BRouter für eine Punktfolge. Gibt Koordinaten [[lng,lat,ele], …] oder null zurück. */
function brouter(array $punkte, string $regelwerk): ?array
{
    global $CONFIG;
    $b = $CONFIG['brouter'] ?? [];
    if (empty($b['url'])) {
        return null;
    }
    $lonlats = implode('|', array_map(fn($p) => $p[1] . ',' . $p[0], $punkte));
    $url = rtrim((string)$b['url'], '?') . '?' . http_build_query([
        'lonlats'        => $lonlats,
        'profile'        => $regelwerk === 'ekfv2027' ? ($b['profil_2027'] ?? 'escooter-2027') : ($b['profil'] ?? 'escooter'),
        'alternativeidx' => 0,
        'format'         => 'geojson',
    ]);
    $timeout = (int)($b['timeout'] ?? 10);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
                                CURLOPT_USERAGENT => 'ElTouro/1.0', CURLOPT_FOLLOWLOCATION => false]);
        $roh = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);   // curl_close() ist ab PHP 8.5 veraltet
        if ($roh === false || $status !== 200) {
            return null;
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true,
                                                 'header' => "User-Agent: ElTouro/1.0\r\n"]]);
        $roh = @file_get_contents($url, false, $ctx);
        if ($roh === false) {
            return null;
        }
    }
    $gj = json_decode($roh, true);
    $koord = $gj['features'][0]['geometry']['coordinates'] ?? null;
    return is_array($koord) && count($koord) >= 2 ? $koord : null;
}

function linie(array $koord, bool $freihand): array
{
    return ['type' => 'Feature', 'properties' => ['freihand' => $freihand],
            'geometry' => ['type' => 'LineString', 'coordinates' => $koord]];
}

$features = [];
$hinweis = null;

if (empty($CONFIG['brouter']['url'])) {
    $features[] = linie(array_map(fn($p) => [$p[1], $p[0]], $punkte), true);
    $hinweis = 'ohne_router';
} elseif (($ganz = brouter($punkte, $regelwerk)) !== null) {
    $features[] = linie($ganz, false);
} else {
    for ($i = 0; $i < count($punkte) - 1; $i++) {
        $abschnitt = [$punkte[$i], $punkte[$i + 1]];
        $k = brouter($abschnitt, $regelwerk);
        $features[] = $k !== null ? linie($k, false) : linie([[$abschnitt[0][1], $abschnitt[0][0]], [$abschnitt[1][1], $abschnitt[1][0]]], true);
        if ($k === null) { $hinweis = 'teilweise_freihand'; }
    }
}

antworte(200, ['geojson' => ['type' => 'FeatureCollection', 'features' => $features], 'hinweis' => $hinweis]);
