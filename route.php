<?php
/**
 * POST /route.php  (JSON: {"points": [[lat,lng], …], "rule_set": "ekfv"|"ekfv2027"})
 * Response: {"geojson": FeatureCollection, "notice": "no_router"|"partly_freehand"|null}
 *
 * First the whole route is calculated in one go. If that fails (e.g. a point lies off permitted
 * paths), it is routed section by section; sections that cannot be routed are connected with a
 * straight line and marked as freehand. Without a configured BRouter everything is freehand.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (currentUser() === null) {
    respond(401, ['error' => 'login']);
}
if (!isPost() || !checkCsrfHeader()) {
    respond(400, ['error' => 'csrf']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$points = validateWaypoints($input['points'] ?? null);
if ($points === null) {
    respond(422, ['error' => 'points']);
}
$ruleSet = ($input['rule_set'] ?? '') === 'ekfv2027' ? 'ekfv2027' : 'ekfv';

/** Asks BRouter for a sequence of points. Returns coordinates [[lng,lat,ele], …] or null. */
function brouter(array $points, string $ruleSet): ?array
{
    global $CONFIG;
    $b = $CONFIG['brouter'] ?? [];
    if (empty($b['url'])) {
        return null;
    }
    $lonlats = implode('|', array_map(fn($p) => $p[1] . ',' . $p[0], $points));
    $url = rtrim((string)$b['url'], '?') . '?' . http_build_query([
        'lonlats'        => $lonlats,
        'profile'        => $ruleSet === 'ekfv2027' ? ($b['profile_2027'] ?? 'escooter-2027') : ($b['profile'] ?? 'escooter'),
        'alternativeidx' => 0,
        'format'         => 'geojson',
    ]);
    $timeout = (int)($b['timeout'] ?? 10);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
                                CURLOPT_USERAGENT => 'ElTouro/1.0', CURLOPT_FOLLOWLOCATION => false]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);   // curl_close() is deprecated as of PHP 8.5
        if ($raw === false || $status !== 200) {
            return null;
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true,
                                                 'header' => "User-Agent: ElTouro/1.0\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
    }
    $gj = json_decode($raw, true);
    $coords = $gj['features'][0]['geometry']['coordinates'] ?? null;
    return is_array($coords) && count($coords) >= 2 ? $coords : null;
}

function line(array $coords, bool $freehand): array
{
    return ['type' => 'Feature', 'properties' => ['freehand' => $freehand],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coords]];
}

$features = [];
$notice = null;

if (empty($CONFIG['brouter']['url'])) {
    $features[] = line(array_map(fn($p) => [$p[1], $p[0]], $points), true);
    $notice = 'no_router';
} elseif (($whole = brouter($points, $ruleSet)) !== null) {
    $features[] = line($whole, false);
} else {
    for ($i = 0; $i < count($points) - 1; $i++) {
        $section = [$points[$i], $points[$i + 1]];
        $c = brouter($section, $ruleSet);
        $features[] = $c !== null ? line($c, false) : line([[$section[0][1], $section[0][0]], [$section[1][1], $section[1][0]]], true);
        if ($c === null) { $notice = 'partly_freehand'; }
    }
}

respond(200, ['geojson' => ['type' => 'FeatureCollection', 'features' => $features], 'notice' => $notice]);
