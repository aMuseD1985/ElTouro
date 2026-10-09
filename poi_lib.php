<?php
/**
 * Stops on a tour (charging, food, a break, something worth seeing) and suggestions for them from OpenStreetMap.
 *
 * Suggestions come from the Overpass API, asked by our server with the corridor of the track – without its first and
 * last 300 m (SHARE_PRIVACY_METERS), so start and finish stay private. OSM has no ratings: "worth it" is scored from
 * what the data does say (beer garden, outdoor seating, opening hours known, normal socket for charging, viewpoint,
 * castle …) and from where the place lies (how far off the track, at which kilometre). Results are cached for a week.
 * config.php: 'pois' => ['urls' => [...]] – tried in order; [] switches suggestions off.
 */
declare(strict_types=1);
require_once __DIR__ . '/share_lib.php';

const STOP_TYPES = ['charge', 'food', 'break', 'sight'];
const STOP_INTERVAL_KM = 25;   // a charging or food stop roughly this often on long tours
const POI_DEFAULT_URLS = ['https://lz4.overpass-api.de/api/interpreter', 'https://z.overpass-api.de/api/interpreter',
                          'https://overpass.private.coffee/api/interpreter', 'https://overpass.openstreetmap.fr/api/interpreter',
                          'https://overpass-api.de/api/interpreter'];

/** Stops as stored with a tour: [{i: waypoint index, type, name}], or null if invalid. */
function validateStops(mixed $stops, int $waypointCount): ?array
{
    if (!is_array($stops) || count($stops) > MAX_WAYPOINTS) {
        return null;
    }
    $clean = [];
    foreach ($stops as $s) {
        if (!is_array($s) || !is_int($s['i'] ?? null) || $s['i'] < 0 || $s['i'] >= $waypointCount || !in_array($s['type'] ?? '', STOP_TYPES, true)) {
            return null;
        }
        $clean[$s['i']] = ['i' => $s['i'], 'type' => $s['type'], 'name' => mb_substr(trim((string)($s['name'] ?? '')), 0, 80)];
    }
    ksort($clean);
    return array_values($clean);
}

/** The tour's stops with the kilometre at which they lie, for the tour page, GPX and the ride mode. */
function tourStops(array $tour): array
{
    $stops = json_decode((string)($tour['stops_json'] ?? ''), true);
    $wps = json_decode((string)$tour['waypoints_json'], true);
    if (!is_array($stops) || !$stops || !is_array($wps)) {
        return [];
    }
    $route = routeLine($tour['geojson']);
    $out = [];
    foreach ($stops as $s) {
        if (!isset($wps[$s['i']])) {
            continue;
        }
        [$lat, $lng] = $wps[$s['i']];
        $out[] = $s + ['lat' => (float)$lat, 'lng' => (float)$lng, 'km' => round(projectOnRoute($route, (float)$lat, (float)$lng)['along'] / 1000, 1)];
    }
    return $out;
}

/** JSON for data-stops of a tour map (assets/tour_map.js): [{lat, lng, type, name, label}]. */
function stopsForMap(array $stops): string
{
    $out = [];
    foreach ($stops as $s) {
        if (!in_array($s['type'] ?? '', STOP_TYPES, true)) {
            continue;
        }
        $out[] = ['lat' => $s['lat'], 'lng' => $s['lng'], 'type' => $s['type'], 'name' => (string)($s['name'] ?? ''), 'label' => t('stop.type_' . $s['type'])];
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** ['pts' => [[lat, lng], …], 'cum' => [metres along, …]] of all features in order. */
function routeLine(string $geojson): array
{
    $pts = [];
    foreach (json_decode($geojson, true)['features'] ?? [] as $f) {
        foreach ($f['geometry']['coordinates'] as $c) {
            $pts[] = [(float)$c[1], (float)$c[0]];
        }
    }
    $cum = [0.0];
    for ($i = 1; $i < count($pts); $i++) {
        $cum[$i] = $cum[$i - 1] + distanceMeters($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
    }
    return ['pts' => $pts, 'cum' => $cum];
}

/** Nearest point of the route: ['along' => metres from the start, 'off' => metres away from the track]. */
function projectOnRoute(array $route, float $lat, float $lng): array
{
    $pts = $route['pts'];
    $kx = cos(deg2rad($lat)) * 111320; $ky = 110540;
    $best = ['along' => 0.0, 'off' => INF];
    for ($s = 0; $s < count($pts) - 1; $s++) {
        $ax = $pts[$s][1] * $kx; $ay = $pts[$s][0] * $ky; $bx = $pts[$s + 1][1] * $kx; $by = $pts[$s + 1][0] * $ky;
        $px = $lng * $kx; $py = $lat * $ky;
        $vx = $bx - $ax; $vy = $by - $ay; $l2 = $vx * $vx + $vy * $vy;
        $r = $l2 > 0 ? max(0, min(1, (($px - $ax) * $vx + ($py - $ay) * $vy) / $l2)) : 0;
        $d = hypot($ax + $vx * $r - $px, $ay + $vy * $r - $py);
        if ($d < $best['off']) {
            $best = ['along' => $route['cum'][$s] + ($route['cum'][$s + 1] - $route['cum'][$s]) * $r, 'off' => $d];
        }
    }
    return $best;
}

/** Douglas-Peucker on [lat, lng] points with a tolerance in metres. */
function simplifyLine(array $pts, float $tolerance): array
{
    if (count($pts) < 3) {
        return $pts;
    }
    $keep = array_fill(0, count($pts), false);
    $keep[0] = $keep[count($pts) - 1] = true;
    $stack = [[0, count($pts) - 1]];
    while ($stack) {
        [$a, $b] = array_pop($stack);
        $kx = cos(deg2rad($pts[$a][0])) * 111320; $ky = 110540;
        $ax = $pts[$a][1] * $kx; $ay = $pts[$a][0] * $ky; $bx = $pts[$b][1] * $kx; $by = $pts[$b][0] * $ky;
        $len = hypot($bx - $ax, $by - $ay);
        $maxD = 0.0; $idx = -1;
        for ($i = $a + 1; $i < $b; $i++) {
            $px = $pts[$i][1] * $kx; $py = $pts[$i][0] * $ky;
            $d = $len > 0 ? abs(($bx - $ax) * ($ay - $py) - ($ax - $px) * ($by - $ay)) / $len : hypot($px - $ax, $py - $ay);
            if ($d > $maxD) { $maxD = $d; $idx = $i; }
        }
        if ($idx >= 0 && $maxD > $tolerance) {
            $keep[$idx] = true;
            array_push($stack, [$a, $idx], [$idx, $b]);
        }
    }
    return array_values(array_filter($pts, fn($k) => $keep[$k], ARRAY_FILTER_USE_KEY));
}

/** Runs an Overpass query (cached for a week). Null if every server failed. */
function overpass(string $query): ?array
{
    global $CONFIG;
    $urls = $CONFIG['pois']['urls'] ?? POI_DEFAULT_URLS;
    if (!$urls) {
        return null;
    }
    $cache = dataDir('pois') . '/' . hash('sha256', $query) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < 7 * 86400) {
        $hit = json_decode((string)file_get_contents($cache), true);
        if (is_array($hit)) {
            return $hit;
        }
    }
    // A mirror that just failed is skipped for five minutes (a busy one would cost 10–15 s per piece), unless all are bad
    $badDir = dataDir('pois');
    $fresh = array_values(array_filter($urls, fn($u) => !is_file($badDir . '/bad-' . md5($u)) || time() - filemtime($badDir . '/bad-' . md5($u)) > 300));
    foreach ($fresh ?: $urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
                                CURLOPT_USERAGENT => 'ElTouro/1.0 (+' . baseUrl() . ')']);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        $data = is_string($raw) && $status === 200 ? json_decode($raw, true) : null;
        // A busy server answers 200 with a "remark" ("Query timed out", "out of memory") and incomplete or no elements:
        // never cache that, ask the next mirror
        if (is_array($data) && isset($data['elements']) && empty($data['remark'])) {
            file_put_contents($cache, json_encode($data['elements'], JSON_UNESCAPED_UNICODE), LOCK_EX);
            @unlink($badDir . '/bad-' . md5($url));
            return $data['elements'];
        }
        @touch($badDir . '/bad-' . md5($url));
        error_log('ElTouro Overpass ' . $url . ': HTTP ' . $status . ' ' . $err . ' ' . (is_array($data) ? (string)($data['remark'] ?? '') : ''));
    }
    return null;
}

/** Type, kind and score of an OSM element – or null if it is no useful stop. */
function classifyPoi(array $tags): ?array
{
    $amenity = $tags['amenity'] ?? ''; $tourism = $tags['tourism'] ?? ''; $historic = $tags['historic'] ?? '';
    $facts = [];
    if (!empty($tags['opening_hours'])) $facts[] = 'opening';
    if ($amenity === 'charging_station') {
        $text = mb_strtolower(implode(' ', [$tags['name'] ?? '', $tags['description'] ?? '', $tags['operator'] ?? '']));
        $schuko = !empty($tags['socket:schuko']) && $tags['socket:schuko'] !== 'no';
        $ebike = in_array($tags['bicycle'] ?? '', ['yes', 'designated'], true) || str_contains($text, 'e-bike') || str_contains($text, 'ebike') || str_contains($text, 'pedelec');
        if (!$schuko && !$ebike) {
            return null;   // car chargers (Type 2, CCS) are no use for an e-scooter
        }
        if ($schuko) $facts[] = 'schuko';
        if ($ebike) $facts[] = 'ebike';
        return ['type' => 'charge', 'kind' => 'charging_station', 'score' => 4, 'facts' => $facts];
    }
    if (in_array($amenity, ['cafe', 'restaurant', 'biergarten', 'ice_cream', 'fast_food'], true)) {
        $score = ['biergarten' => 3, 'cafe' => 1.5, 'ice_cream' => 1.6, 'restaurant' => 1.2, 'fast_food' => 0.5][$amenity];
        if (($tags['outdoor_seating'] ?? '') === 'yes' || $amenity === 'biergarten') { $score += 1.5; $facts[] = 'outdoor'; }
        if (!empty($tags['opening_hours'])) $score += 0.3;
        if (!empty($tags['website']) || !empty($tags['contact:website'])) $score += 0.2;
        return ['type' => 'food', 'kind' => $amenity, 'score' => $score, 'facts' => $facts];
    }
    if (in_array($tourism, ['viewpoint', 'attraction', 'museum'], true) || in_array($historic, ['castle', 'ruins'], true)) {
        $kind = $tourism !== '' && $tourism !== 'museum' ? $tourism : ($historic !== '' ? $historic : $tourism);
        $score = ['viewpoint' => 2.5, 'castle' => 2.5, 'ruins' => 1.8, 'attraction' => 1.5, 'museum' => 1][$kind] ?? 1;
        if (!empty($tags['name'])) $score += 0.5;
        return ['type' => 'sight', 'kind' => $kind, 'score' => $score, 'facts' => $facts];
    }
    if (in_array($amenity, ['drinking_water', 'toilets'], true)) {
        if ($amenity === 'toilets' && ($tags['fee'] ?? '') === 'no') $facts[] = 'free';
        return ['type' => 'break', 'kind' => $amenity, 'score' => $amenity === 'drinking_water' ? 1 : 0.8, 'facts' => $facts];
    }
    return null;
}

/**
 * Suggestions along a validated track: ['plan' => [...], 'by_type' => ['charge' => [...], 'food' => [...], …]], or null if
 * the service is unreachable. Each item: id, type, kind, name, lat, lng, km, off (m), facts, opening_hours.
 */
function suggestStops(array $geo): ?array
{
    $route = routeLine($geo['geojson']);
    $total = end($route['cum']) ?: 0.0;
    $inner = [];
    foreach ($route['pts'] as $i => $p) {
        if ($route['cum'][$i] >= SHARE_PRIVACY_METERS && $route['cum'][$i] <= $total - SHARE_PRIVACY_METERS) {
            $inner[] = $p;
        }
    }
    if (count($inner) < 2) {
        return ['plan' => [], 'by_type' => []];
    }
    $tolerance = 25.0;
    do {
        $line = simplifyLine($inner, $tolerance);
        $tolerance *= 1.6;
    } while (count($line) > 250);
    // The track in pieces about 9 km across: one bounding-box query per piece is fast, an "around" query along a long line is not
    // (it times out on the public servers). Distance to the track is checked here afterwards.
    $pad = [0.006, 0.010];                       // about 650 m
    $pieces = [];
    $cur = [];
    foreach ($line as $p) {
        $cur[] = $p;
        $lat = array_column($cur, 0); $lng = array_column($cur, 1);
        if (max($lat) - min($lat) > 0.08 || max($lng) - min($lng) > 0.12) {
            array_pop($cur);
            $pieces[] = $cur;
            $cur = [$cur[count($cur) - 1], $p];   // pieces share their joint
        }
    }
    if (count($cur) >= 2) {
        $pieces[] = $cur;
    }
    $pieces = array_slice($pieces, 0, 40);
    $elements = [];
    $answered = 0;
    $asked = 0;
    $deadline = microtime(true) + 75;
    foreach ($pieces as $piece) {
        if (microtime(true) > $deadline) {
            break;
        }
        $lat = array_column($piece, 0); $lng = array_column($piece, 1);
        // snapped to a 0.01° grid, so neighbouring tours share cache entries
        $bbox = sprintf('%.2f,%.2f,%.2f,%.2f', floor((min($lat) - $pad[0]) * 100) / 100, floor((min($lng) - $pad[1]) * 100) / 100,
                                              ceil((max($lat) + $pad[0]) * 100) / 100, ceil((max($lng) + $pad[1]) * 100) / 100);
        $query = "[out:json][timeout:25][bbox:$bbox];("
               . "nwr[amenity~\"^(cafe|restaurant|biergarten|ice_cream|fast_food)$\"][name];"
               . "nwr[amenity=charging_station];"
               . "nwr[tourism~\"^(viewpoint|attraction|museum)$\"];"
               . "nwr[historic~\"^(castle|ruins)$\"];"
               . "nwr[amenity~\"^(drinking_water|toilets)$\"];"
               . ");out center tags 1500;";
        $asked++;
        $part = overpass($query);
        if ($part === null) {
            continue;
        }
        $answered++;
        foreach ($part as $el) {
            $elements[($el['type'] ?? 'n') . ($el['id'] ?? 0)] = $el;   // pieces overlap: one entry per element
        }
    }
    if ($answered === 0) {
        return null;
    }
    $elements = array_values($elements);

    $items = [];
    foreach ($elements as $el) {
        $lat = (float)($el['lat'] ?? $el['center']['lat'] ?? 0);
        $lng = (float)($el['lon'] ?? $el['center']['lon'] ?? 0);
        $tags = (array)($el['tags'] ?? []);
        $c = classifyPoi($tags);
        if ($c === null || !isValidCoordinate($lat, $lng)) {
            continue;
        }
        $pos = projectOnRoute($route, $lat, $lng);
        if ($pos['off'] > ['food' => 250, 'charge' => 400, 'sight' => 500, 'break' => 200][$c['type']]) {
            continue;   // the query is a box around a piece of the track: far-off places are dropped here
        }
        if ($pos['along'] < SHARE_PRIVACY_METERS || $pos['along'] > $total - SHARE_PRIVACY_METERS) {
            continue;
        }
        $name = trim((string)($tags['name'] ?? ''));
        $items[] = [
            'id' => substr((string)($el['type'] ?? 'n'), 0, 1) . (int)($el['id'] ?? 0),
            'type' => $c['type'], 'kind' => $c['kind'], 'name' => mb_substr($name, 0, 80),
            'lat' => round($lat, 6), 'lng' => round($lng, 6),
            'km' => round($pos['along'] / 1000, 1), 'off' => (int)round($pos['off']),
            'facts' => $c['facts'], 'opening_hours' => mb_substr((string)($tags['opening_hours'] ?? ''), 0, 120),
            // far off the track counts against a place; 200 m costs about one point
            'score' => round($c['score'] - $pos['off'] / 200, 2),
        ];
    }

    $byType = [];
    foreach (STOP_TYPES as $type) {
        $list = array_values(array_filter($items, fn($it) => $it['type'] === $type));
        usort($list, fn($a, $b) => $b['score'] <=> $a['score']);
        $seen = []; $top = [];
        foreach ($list as $it) {
            $key = mb_strtolower($it['name']) ?: $it['id'];
            if (isset($seen[$key])) continue;   // chains and duplicates (node + building)
            // spread along the route: at most two per 3 km, otherwise one old town fills the whole list
            $near = count(array_filter($top, fn($t) => abs($t['km'] - $it['km']) < 3));
            if ($near >= 2) continue;
            $seen[$key] = true;
            $top[] = $it;
            if (count($top) >= 8) break;
        }
        usort($top, fn($a, $b) => $a['km'] <=> $b['km']);
        $byType[$type] = $top;
    }

    // The plan: on long tours a charging or food stop about every STOP_INTERVAL_KM, plus the best sight
    $plan = [];
    $km = $total / 1000;
    if ($km > 30) {
        for ($target = STOP_INTERVAL_KM; $target < $km - 8; $target += STOP_INTERVAL_KM) {
            $best = null;
            foreach ($items as $it) {
                if (!in_array($it['type'], ['charge', 'food'], true) || abs($it['km'] - $target) > 6) continue;
                // charging with a normal socket beats food; among equals the one closer to the target
                $s = $it['score'] + ($it['type'] === 'charge' && $it['score'] > 1 ? 2 : 0) - abs($it['km'] - $target) / 3;
                if ($best === null || $s > $best[0]) $best = [$s, $it + ['reason' => $it['type'] === 'charge' ? 'charge' : 'interval']];
            }
            if ($best) $plan[] = $best[1];
        }
    } elseif ($km > 12) {
        $food = array_values(array_filter($items, fn($it) => $it['type'] === 'food' && abs($it['km'] - $km / 2) <= $km / 4));
        usort($food, fn($a, $b) => $b['score'] <=> $a['score']);
        if ($food) $plan[] = $food[0] + ['reason' => 'halfway'];
    }
    $sights = $byType['sight'] ?? [];
    usort($sights, fn($a, $b) => $b['score'] <=> $a['score']);
    if ($sights && $sights[0]['score'] >= 2) $plan[] = $sights[0] + ['reason' => 'sight'];
    usort($plan, fn($a, $b) => $a['km'] <=> $b['km']);

    return ['plan' => $plan, 'by_type' => $byType, 'partial' => $answered < $asked || count($pieces) >= 40];
}
