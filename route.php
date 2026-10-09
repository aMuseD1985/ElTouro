<?php
/**
 * POST /api/route  (JSON: {"points": [[lat,lng], …], "rule_set": "ekfv"|"ekfv2027"})
 * Response: {"geojson": FeatureCollection, "guidance": [[coordIndex, command, exit], …], "notice": "no_router"|"partly_freehand"|null}
 *
 * With "avoid_uturns": true, out-and-back spurs to a waypoint (in a side street up to the waypoint and the same way back)
 * are cut out and the waypoint moves to where the spur starts – except for stops ("stops": [waypoint indices]), which the
 * rider wants to reach. Response then also has "waypoints" (moved) and "uturns": {"avoided": n, "left": m}.
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
require_once __DIR__ . '/tours_lib.php';
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
$avoidUturns = !empty($input['avoid_uturns']);
$stopIdx = array_values(array_filter((array)($input['stops'] ?? []), fn($i) => is_int($i) && $i >= 0 && $i < count($points)));
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

$uturns = null;
if ($avoidUturns && count($features) === 1 && empty($features[0]['properties']['freehand'])) {
    $r = removeSpurs($features[0]['geometry']['coordinates'], $guidance, $points, $stopIdx);
    $features[0]['geometry']['coordinates'] = $r['coords'];
    $guidance = $r['hints'];
    $points = $r['points'];
    $uturns = ['avoided' => $r['avoided'], 'left' => $r['left']];
}

$response = ['geojson' => ['type' => 'FeatureCollection', 'features' => $features], 'guidance' => $guidance, 'notice' => $notice];
if ($uturns !== null) {
    $response['uturns'] = $uturns;
    if ($uturns['avoided'] > 0) {
        $response['waypoints'] = $points;
    }
}
respond(200, $response);

/**
 * Cuts out-and-back spurs at U-turns (BRouter VoiceHint 10, 11, 15). A spur is the stretch where the way in and the way out
 * run over the same nodes; it goes if it is at most SPUR_MAX_METERS long and no stop lies on it. The waypoint that caused it
 * moves to the start of the spur, and the junction there gets a turn instruction of its own.
 */
function removeSpurs(array $coords, array $hints, array $points, array $stopIdx): array
{
    $SPUR_MAX_METERS = 600;
    $d = fn(array $a, array $b) => distanceMeters($a[1], $a[0], $b[1], $b[0]);
    $avoided = 0;
    $uturnIdx = array_values(array_map(fn($h) => $h[0], array_filter($hints, fn($h) => in_array($h[1], [10, 11, 15], true))));
    rsort($uturnIdx);   // from the end, so earlier indices stay valid
    foreach ($uturnIdx as $u) {
        if ($u <= 0 || $u >= count($coords) - 1) {
            continue;
        }
        // Walk back along the way in and forward along the way out while both run side by side: first on the same
        // nodes (6 m), then – if that still ends in a U-turn – across the two carriageways of a divided road (25 m)
        [$i, $j] = spurExtent($coords, $u, $u, 6.0);
        if ($i > 0 && $j < count($coords) - 1) {
            $turnBack = abs(fmod(bearingDeg($coords[$i], $coords[$j + 1]) - bearingDeg($coords[$i - 1], $coords[$i]) + 540, 360) - 180);
            if ($turnBack >= 150) {
                [$i, $j] = spurExtent($coords, $i, $j, 25.0);
            }
        }
        $length = 0.0;
        for ($k = $i; $k < $u; $k++) {
            $length += $d($coords[$k], $coords[$k + 1]);
        }
        if ($i === $u || $i === 0 || $j >= count($coords) - 1 || $length > $SPUR_MAX_METERS) {
            continue;
        }
        // The waypoint at the tip of the spur – a stop there is wanted, so the spur stays
        $near = null; $best = 200.0;   // BRouter snaps a waypoint up to 250 m away onto a road
        foreach ($points as $w => $p) {
            if ($w === 0 || $w === count($points) - 1) continue;
            for ($k = $i; $k <= $j; $k++) {
                $dist = distanceMeters($p[0], $p[1], $coords[$k][1], $coords[$k][0]);
                if ($dist < $best) { $best = $dist; $near = $w; }
            }
        }
        if ($near === null || in_array($near, $stopIdx, true)) {
            continue;
        }
        $removed = $j - $i;
        $before = $coords[max(0, $i - 1)];
        $after = $coords[min(count($coords) - 1, $j + 1)];
        $coords = array_merge(array_slice($coords, 0, $i + 1), array_slice($coords, $j + 1));
        $points[$near] = [round($coords[$i][1], 6), round($coords[$i][0], 6)];
        $kept = [];
        foreach ($hints as $h) {
            if ($h[0] > $i && $h[0] <= $j) continue;          // instructions inside the spur
            if ($h[0] === $i) continue;                        // the turn into the spur – replaced below
            $kept[] = $h[0] > $j ? [$h[0] - $removed, $h[1], $h[2]] : $h;
        }
        // The junction at the start of the spur: what is left of the turn there
        $turn = fmod(bearingDeg($coords[$i], $after) - bearingDeg($before, $coords[$i]) + 540, 360) - 180;
        $a = abs($turn);
        if ($a >= 30) {
            $right = $turn > 0;
            $cmd = $a >= 150 ? 15 : ($a >= 110 ? ($right ? 7 : 4) : ($a >= 55 ? ($right ? 5 : 2) : ($right ? 6 : 3)));
            $kept[] = [$i, $cmd, 0];
        }
        usort($kept, fn($x, $y) => $x[0] <=> $y[0]);
        $hints = $kept;
        $avoided++;
    }
    $left = count(array_filter($hints, fn($h) => in_array($h[1], [10, 11, 15], true)));
    return ['coords' => $coords, 'hints' => $hints, 'points' => $points, 'avoided' => $avoided, 'left' => $left];
}

/** Widens a spur [i, j] while the way in (going back from i) and the way out (going on from j) stay within $tolerance metres. */
function spurExtent(array $coords, int $i, int $j, float $tolerance): array
{
    $last = count($coords) - 1;
    $d = fn(array $a, array $b) => distanceMeters($a[1], $a[0], $b[1], $b[0]);
    while ($i > 0 && $j < $last) {
        if ($d($coords[$i - 1], $coords[$j + 1]) < $tolerance) { $i--; $j++; }
        // the two sides need not have their points at the same places
        elseif ($d($coords[$i - 1], $coords[$j]) < $tolerance) { $i--; }
        elseif ($d($coords[$i], $coords[$j + 1]) < $tolerance) { $j++; }
        else break;
    }
    return [$i, $j];
}

/** Compass bearing from a to b, both [lng, lat]. */
function bearingDeg(array $a, array $b): float
{
    $y = sin(deg2rad($b[0] - $a[0])) * cos(deg2rad($b[1]));
    $x = cos(deg2rad($a[1])) * sin(deg2rad($b[1])) - sin(deg2rad($a[1])) * cos(deg2rad($b[1])) * cos(deg2rad($b[0] - $a[0]));
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}
