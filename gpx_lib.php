<?php
/**
 * GPX import: reads a GPX file (tracks, routes) and turns it into the geometry of a tour. Only coordinates and elevation are
 * used – timestamps, heart rate, device data and everything else in the file are dropped. The server then measures the tour itself
 * (validateGeometry), exactly like for a planned one.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';

require_once __DIR__ . '/poi_lib.php';   // tourStops(), routeLine(), projectOnRoute()

const GPX_MAX_BYTES = 6 * 1024 * 1024;
const GPX_THIN_METERS = 6.0;     // points closer than this to the last kept one are dropped
const GPX_MAX_POINTS = 15000;

/**
 * @return array{name:string, places: array<int, array>, segments: array<int, array<int, array{0:float,1:float,2:?float}>>, via: array<int, array{0:float,1:float}>}|string
 *         the parsed file, or an error key (gpx.error_*)
 */
function parseGpx(string $xml): array|string
{
    if (strlen($xml) > GPX_MAX_BYTES) {
        return 'too_big';
    }
    // No DTD, no entities: protects against XXE and "billion laughs"
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', substr($xml, 0, 200000))) {
        return 'invalid';
    }
    $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml) ?? $xml;   // namespaces make the paths needlessly hard
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($doc === false || $doc->getName() !== 'gpx') {
        return 'invalid';
    }
    $point = function (SimpleXMLElement $p): ?array {
        $lat = (string)($p['lat'] ?? ''); $lon = (string)($p['lon'] ?? '');
        if (!is_numeric($lat) || !is_numeric($lon) || !isValidCoordinate((float)$lat, (float)$lon)) {
            return null;
        }
        $ele = isset($p->ele) && is_numeric((string)$p->ele) ? (float)$p->ele : null;
        return [(float)$lat, (float)$lon, $ele];
    };
    $segments = [];
    foreach ($doc->trk as $trk) {
        foreach ($trk->trkseg as $seg) {
            $line = [];
            foreach ($seg->trkpt as $p) {
                if (($c = $point($p)) !== null) { $line[] = $c; }
            }
            if (count($line) >= 2) { $segments[] = $line; }
        }
    }
    $via = [];
    foreach ($doc->rte as $rte) {
        $line = [];
        foreach ($rte->rtept as $p) {
            if (($c = $point($p)) !== null) { $line[] = $c; }
        }
        if (count($line) >= 2) {
            $via = $via ?: array_map(fn($c) => [$c[0], $c[1]], $line);
            if (!$segments) { $segments[] = $line; }   // a file with only a route: its points are the geometry
        }
    }
    if (!$segments) {
        return 'no_track';
    }
    $places = [];
    foreach ($doc->wpt as $w) {
        if (count($places) >= 40) {
            break;
        }
        if (($c = $point($w)) !== null) {
            $places[] = ['lat' => $c[0], 'lng' => $c[1], 'name' => trim((string)($w->name ?? '')), 'hint' => mb_strtolower(trim((string)($w->type ?? '') . ' ' . (string)($w->sym ?? '') . ' ' . (string)($w->name ?? '') . ' ' . (string)($w->desc ?? '')))];
        }
    }
    $name = trim((string)($doc->trk[0]->name ?? $doc->metadata->name ?? $doc->rte[0]->name ?? ''));
    return ['name' => mb_substr($name, 0, 120), 'segments' => $segments, 'via' => $via, 'places' => $places];
}

/** Thins a segment to what a tour needs (keeps first and last point, elevation stays with its point). */
function thinSegment(array $line, float $minMeters): array
{
    $out = [$line[0]];
    $last = $line[0];
    for ($i = 1; $i < count($line) - 1; $i++) {
        if (distanceMeters($last[0], $last[1], $line[$i][0], $line[$i][1]) >= $minMeters) {
            $out[] = $line[$i];
            $last = $line[$i];
        }
    }
    $out[] = $line[count($line) - 1];
    return $out;
}

/** GeoJSON FeatureCollection string for validateGeometry() from parsed segments. */
function gpxGeoJson(array $segments): string
{
    $min = GPX_THIN_METERS;
    do {
        $thin = array_map(fn($s) => thinSegment($s, $min), $segments);
        $count = array_sum(array_map('count', $thin));
        $min *= 1.6;
    } while ($count > GPX_MAX_POINTS && $min < 500);
    $features = [];
    foreach ($thin as $line) {
        $features[] = ['type' => 'Feature', 'properties' => ['freehand' => false], 'geometry' => ['type' => 'LineString',
            'coordinates' => array_map(fn($c) => $c[2] !== null ? [$c[1], $c[0], $c[2]] : [$c[1], $c[0]], $line)]];
    }
    return (string)json_encode(['type' => 'FeatureCollection', 'features' => $features]);
}

/**
 * Creates a tour from GPX text for a rider. Returns the new tour id or an error key (gpx.error_*).
 * $o: title, description, visibility (private|group|public), group_id, difficulty, style
 */
function importGpxTour(string $xml, int $userId, array $o): int|string
{
    $parsed = parseGpx($xml);
    if (is_string($parsed)) {
        return $parsed;
    }
    $geo = validateGeometry(gpxGeoJson($parsed['segments']));
    if ($geo === null) {
        return 'invalid';
    }
    if ($geo['distance'] < 200) {
        return 'too_short';
    }
    // Waypoints: those of a route in the file if it has few enough, otherwise one about every 400 m along the track
    $pts = [];
    foreach (json_decode($geo['geojson'], true)['features'] as $f) {
        foreach ($f['geometry']['coordinates'] as $c) { $pts[] = [$c[1], $c[0]]; }
    }
    $wps = $parsed['via'] && count($parsed['via']) <= MAX_WAYPOINTS ? $parsed['via'] : waypointsFromTrack($pts, $geo['distance']);
    [$wps, $stops] = $parsed['places'] ? stopsFromGpxPlaces($parsed['places'], $wps, $geo['geojson'], $geo['distance']) : [$wps, []];
    $title = mb_substr(trim((string)($o['title'] ?? '')) ?: ($parsed['name'] ?: 'GPX'), 0, 120);
    $vis = in_array($o['visibility'] ?? '', ['private', 'group', 'public'], true) ? $o['visibility'] : 'private';
    $groupId = $vis === 'group' ? (int)($o['group_id'] ?? 0) ?: null : null;
    if ($vis === 'group' && $groupId === null) {
        $vis = 'private';
    }
    dbExec("INSERT INTO tours (owner_user_id, owner_group_id, title, description, content_lang, visibility, source, rule_set, difficulty, style, vehicle_class, distance_m, ascent_m,
                   freehand_share_pct, waypoints_json, stops_json, geojson, start_lat, start_lng, bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng)
            VALUES (?, ?, ?, ?, ?, ?, 'imported', 'ekfv', ?, ?, 2, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$userId, $groupId, $title, ($d = trim((string)($o['description'] ?? ''))) !== '' ? mb_substr($d, 0, 4000) : null, $GLOBALS['LANG'] ?? 'de', $vis,
         in_array($o['difficulty'] ?? '', ['easy', 'moderate', 'demanding'], true) ? $o['difficulty'] : 'easy',
         in_array($o['style'] ?? '', ['relaxed', 'social', 'sporty'], true) ? $o['style'] : 'social',
         $geo['distance'], computeAscent($geo['geojson']), json_encode($wps), $stops ? json_encode($stops, JSON_UNESCAPED_UNICODE) : null, $geo['geojson'],
         $geo['start'][0], $geo['start'][1], $geo['bbox'][0], $geo['bbox'][1], $geo['bbox'][2], $geo['bbox'][3]]);
    $id = (int)db()->lastInsertId();
    require_once __DIR__ . '/rewards_lib.php';
    recordEvent($userId, 'tour_created', 'tour', $id, null, (float)round($geo['distance'] / 1000, 2), ['source' => 'gpx']);
    return $id;
}


/** GPX 1.1 text of a tour: stops as waypoints, the planned waypoints as a route, the ridden/planned line as a track (with elevation) */
function gpxExport(array $tour): string
{
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<gpx version="1.1" creator="ElTouro" xmlns="http://www.topografix.com/GPX/1/1">' . "\n"
         . '<metadata><name>' . $x($tour['title']) . '</name></metadata>' . "\n";
    foreach (tourStops($tour) as $st) {
        $out .= '<wpt lat="' . $st['lat'] . '" lon="' . $st['lng'] . '"><name>' . $x($st['name'] !== '' ? $st['name'] : t('stop.type_' . $st['type'])) . '</name>'
              . '<type>' . $x(t('stop.type_' . $st['type'])) . "</type></wpt>\n";
    }
    // The planned waypoints (the "points" of the tour) as a GPX route: navigation apps and a re-import keep them as via points
    $wps = json_decode((string)$tour['waypoints_json'], true) ?: [];
    if ($wps) {
        $out .= '<rte><name>' . $x($tour['title']) . "</name>\n";
        foreach ($wps as $i => $w) {
            $label = $i === 0 ? t('gpx.start') : ($i === count($wps) - 1 ? t('gpx.finish') : t('gpx.waypoint', ['n' => $i]));
            $out .= '<rtept lat="' . (float)$w[0] . '" lon="' . (float)$w[1] . '"><name>' . $x($label) . "</name></rtept>\n";
        }
        $out .= "</rte>\n";
    }
    $out .= '<trk><name>' . $x($tour['title']) . "</name>\n";
    foreach (json_decode($tour['geojson'], true)['features'] as $f) {
        $out .= "<trkseg>\n";
        foreach ($f['geometry']['coordinates'] as $c) {
            $out .= '<trkpt lat="' . $c[1] . '" lon="' . $c[0] . '">' . (isset($c[2]) ? '<ele>' . $c[2] . '</ele>' : '') . "</trkpt>\n";
        }
        $out .= "</trkseg>\n";
    }
    return $out . "</trk>\n</gpx>\n";
}


/** Which kind of stop a named GPX point is (our own export writes "Laden", "Essen & Trinken", "Pause", "Sehenswert" / "Charging" …) */
function gpxPlaceType(string $hint): string
{
    $rules = [
        'charge' => '/lade|charg|strom|steckdose|socket|e-?tankstelle|akku/u',
        'food'   => '/essen|trinken|food|drink|café|cafe|kaffee|coffee|restaurant|biergarten|eis|ice.?cream|imbiss|bäcker|baecker|bakery|gasthof|gaststätte|kneipe|pub\b|bar\b|diner|burger|pizza|döner|doener/u',
        'sight'  => '/sehens|worth seeing|sight|aussicht|view|burg\b|schloss|castle|ruine|museum|denkmal|monument|turm|tower|attraction|kirche|church|brücke|bridge/u',
    ];
    foreach ($rules as $type => $re) {
        if (preg_match($re, $hint)) {
            return $type;
        }
    }
    return 'break';
}

/**
 * Named GPX points that lie along the track become stops: each is a waypoint (an existing one within 15 m is reused, otherwise a new one
 * is inserted in track order). Returns [waypoints [[lat, lng], …], stops [{i, type, name}, …]].
 */
function stopsFromGpxPlaces(array $places, array $wps, string $geojson, int $distanceM): array
{
    $route = routeLine($geojson);
    $items = [];   // [lat, lng, ?stop, along]
    foreach ($wps as $w) {
        $items[] = [(float)$w[0], (float)$w[1], null, projectOnRoute($route, (float)$w[0], (float)$w[1])['along']];
    }
    foreach ($places as $p) {
        $pos = projectOnRoute($route, $p['lat'], $p['lng']);
        if ($pos['off'] > 300 || $pos['along'] < 150 || $pos['along'] > $distanceM - 150) {
            continue;   // not on the way, or at the very start/finish
        }
        $stop = ['type' => gpxPlaceType($p['hint']), 'name' => mb_substr($p['name'], 0, 80)];
        $reused = false;
        foreach ($items as $k => $it) {
            if ($k > 0 && $k < count($items) - 1 && $it[2] === null && distanceMeters($it[0], $it[1], $p['lat'], $p['lng']) <= 15) {
                $items[$k][2] = $stop;   // the waypoint is already there: it becomes the stop
                $reused = true;
                break;
            }
        }
        if ($reused) {
            continue;
        }
        if (count($items) >= MAX_WAYPOINTS) {   // make room: drop the plain waypoint nearest to the new stop
            $best = null;
            foreach ($items as $k => $it) {
                if ($k > 0 && $k < count($items) - 1 && $it[2] === null && ($best === null || abs($it[3] - $pos['along']) < abs($items[$best][3] - $pos['along']))) {
                    $best = $k;
                }
            }
            if ($best === null) {
                continue;
            }
            array_splice($items, $best, 1);
        }
        $at = count($items) - 1;   // before the finish
        foreach ($items as $k => $it) {
            if ($k > 0 && $it[3] > $pos['along']) {
                $at = $k;
                break;
            }
        }
        array_splice($items, $at, 0, [[round($p['lat'], 6), round($p['lng'], 6), $stop, $pos['along']]]);
    }
    $outW = []; $outS = [];
    foreach ($items as $k => $it) {
        $outW[] = [$it[0], $it[1]];
        if ($it[2] !== null) {
            $outS[] = ['i' => $k, 'type' => $it[2]['type'], 'name' => $it[2]['name']];
        }
    }
    return [$outW, $outS];
}
