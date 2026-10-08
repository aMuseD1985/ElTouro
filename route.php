<?php
/**
 * POST /api/route  (JSON: {"points": [[lat,lng], …], "rule_set": "ekfv"|"ekfv2027"})
 * Response: {"geojson": FeatureCollection, "guidance": [[coordIndex, command, exit], …], "notice": "no_router"|"partly_freehand"|null}
 *
 * guidance are BRouter's turn instructions (VoiceHint commands, see tours_lib.php), with the index counted over the
 * coordinates of all features in order – the navigation (assets/navigate.js) announces them.
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
session_write_close();   // routing can take a while – don't block the rider's other requests

$input = json_decode((string)file_get_contents('php://input'), true);
$points = validateWaypoints($input['points'] ?? null);
if ($points === null) {
    respond(422, ['error' => 'points']);
}
$ruleSet = ($input['rule_set'] ?? '') === 'ekfv2027' ? 'ekfv2027' : 'ekfv';
$vehicle = vehicleClass($input['vehicle'] ?? VEHICLE_CLASS_DEFAULT);
// Long legs take the home router very long – the planner enforces the same limit (crow-flies distance)
$maxLegKm = maxLegKm();
for ($i = 1; $i < count($points); $i++) {
    if (distanceMeters($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]) > $maxLegKm * 1000) {
        respond(422, ['error' => 'leg_too_long', 'max_km' => $maxLegKm]);
    }
}
@set_time_limit(120);

/**
 * Asks BRouter for a sequence of points. Returns ['coords' => [[lng,lat,ele], …], 'hints' => [[index, command, exit], …]] or null.
 * Vehicle class and rule set go to the profile as parameters; profiles without them simply ignore them.
 */
function brouter(array $points, string $ruleSet, int $vehicle): ?array
{
    global $CONFIG;
    $b = $CONFIG['brouter'] ?? [];
    if (empty($b['url'])) {
        return null;
    }
    $lonlats = implode('|', array_map(fn($p) => $p[1] . ',' . $p[0], $points));
    $url = rtrim((string)$b['url'], '?') . '?' . http_build_query([
        'lonlats'        => $lonlats,
        'profile'        => $ruleSet === 'ekfv2027' ? ($b['profile_2027'] ?? $b['profile'] ?? 'escooter') : ($b['profile'] ?? 'escooter'),
        'alternativeidx' => 0,
        'format'         => 'geojson',
        'timode'         => 2,          // turn instructions (voicehints) for the ride mode – without it BRouter sends none
        'profile:scooter_class' => $vehicle,
        'profile:rules_2027'    => $ruleSet === 'ekfv2027' ? 1 : 0,
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
    if (!is_array($coords) || count($coords) < 2) {
        return null;
    }
    $hints = [];
    foreach ((array)($gj['features'][0]['properties']['voicehints'] ?? []) as $h) {
        if (is_array($h) && count($h) >= 3) {
            $hints[] = [(int)$h[0], (int)$h[1], (int)$h[2]];
        }
    }
    return ['coords' => $coords, 'hints' => $hints];
}

function line(array $coords, bool $freehand): array
{
    return ['type' => 'Feature', 'properties' => ['freehand' => $freehand],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coords]];
}

$features = [];
$guidance = [];
$offset = 0;   // coordinates of the features before the current one
$notice = null;

/** Adds a routed section and its turn instructions. */
function addRouted(array $r, array &$features, array &$guidance, int &$offset): void
{
    $features[] = line($r['coords'], false);
    foreach ($r['hints'] as [$i, $cmd, $exit]) {
        $guidance[] = [$offset + $i, $cmd, $exit];
    }
    $offset += count($r['coords']);
}

if (empty($CONFIG['brouter']['url'])) {
    $features[] = line(array_map(fn($p) => [$p[1], $p[0]], $points), true);
    $notice = 'no_router';
} elseif (($whole = brouter($points, $ruleSet, $vehicle)) !== null) {
    addRouted($whole, $features, $guidance, $offset);
} elseif (count($points) === 2) {
    // Only one section: routing it again would just repeat the failed request
    $features[] = line(array_map(fn($p) => [$p[1], $p[0]], $points), true);
    $notice = 'partly_freehand';
} else {
    for ($i = 0; $i < count($points) - 1; $i++) {
        $section = [$points[$i], $points[$i + 1]];
        $c = brouter($section, $ruleSet, $vehicle);
        if ($c !== null) {
            addRouted($c, $features, $guidance, $offset);
        } else {
            $features[] = line([[$section[0][1], $section[0][0]], [$section[1][1], $section[1][0]]], true);
            $offset += 2;
            $notice = 'partly_freehand';
        }
    }
}

respond(200, ['geojson' => ['type' => 'FeatureCollection', 'features' => $features], 'guidance' => $guidance, 'notice' => $notice]);
