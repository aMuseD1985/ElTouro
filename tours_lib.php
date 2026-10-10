<?php
/**
 * Tours (routes). Access rules:
 *  - private: the creator only
 *  - group:   active members of the assigned crew (+ creator)
 *  - public:  all logged-in users
 * Key figures (length, bounding box, start) are ALWAYS calculated by the server from the geometry –
 * numbers sent by the browser are never used.
 */
declare(strict_types=1);
require_once __DIR__ . '/crews_lib.php';

const MAX_WAYPOINTS = 60;
const MAX_TRACK_POINTS = 20000;

function loadTour(int $id): ?array
{
    return dbOne('SELECT t.*, u.display_name AS creator, g.name AS crew_name, g.slug AS crew_slug
                    FROM tours t JOIN users u ON u.id = t.owner_user_id
                    LEFT JOIN rider_groups g ON g.id = t.owner_group_id AND g.deleted_at IS NULL
                   WHERE t.id = ? AND t.deleted_at IS NULL', [$id]);
}

function canSeeTour(array $tour, int $userId): bool
{
    if ((int)$tour['owner_user_id'] === $userId || $tour['visibility'] === 'public') {
        return true;
    }
    if ($tour['visibility'] === 'group' && $tour['owner_group_id']) {
        return isActiveMember(membership((int)$tour['owner_group_id'], $userId));
    }
    return false;
}

function canEditTour(array $tour, int $userId): bool
{
    if ((int)$tour['owner_user_id'] === $userId) {
        return true;
    }
    // Crew leads may maintain their crew's routes
    return $tour['owner_group_id'] && isCrewLead(membership((int)$tour['owner_group_id'], $userId));
}

function currentRuleSet(): string
{
    return gmdate('Y-m-d') >= '2027-03-01' ? 'ekfv2027' : 'ekfv';
}

function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371008.8;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

function isValidCoordinate(mixed $lat, mixed $lng): bool
{
    return is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180;
}

/**
 * Validates waypoints ([[lat,lng], …]) and returns them cleaned up, or null.
 */
function validateWaypoints(mixed $points): ?array
{
    if (!is_array($points) || count($points) < 2 || count($points) > MAX_WAYPOINTS) {
        return null;
    }
    $clean = [];
    foreach ($points as $p) {
        if (!is_array($p) || count($p) < 2 || !isValidCoordinate($p[0], $p[1])) {
            return null;
        }
        $clean[] = [round((float)$p[0], 6), round((float)$p[1], 6)];
    }
    return $clean;
}

/**
 * Validates the route geometry: a GeoJSON FeatureCollection of LineStrings with
 * properties.freehand (bool) per section. Returns key figures or null.
 * @return array{geojson:string, distance:int, freehand_pct:int, start:array, bbox:array}|null
 */
function validateGeometry(string $json): ?array
{
    if (strlen($json) > 3_000_000) {
        return null;
    }
    $fc = json_decode($json, true);
    if (!is_array($fc) || ($fc['type'] ?? '') !== 'FeatureCollection' || !is_array($fc['features'] ?? null) || !$fc['features']) {
        return null;
    }
    $total = 0.0; $freehand = 0.0; $count = 0;
    $bbox = [90.0, 180.0, -90.0, -180.0];
    $start = null;
    $clean = ['type' => 'FeatureCollection', 'features' => []];

    foreach ($fc['features'] as $f) {
        $coords = $f['geometry']['coordinates'] ?? null;
        if (($f['geometry']['type'] ?? '') !== 'LineString' || !is_array($coords) || count($coords) < 2) {
            return null;
        }
        $isFree = !empty($f['properties']['freehand']);
        $line = [];
        $prev = null;
        foreach ($coords as $c) {
            if (!is_array($c) || !isValidCoordinate($c[1] ?? null, $c[0] ?? null) || ++$count > MAX_TRACK_POINTS) {
                return null;
            }
            $lng = round((float)$c[0], 6); $lat = round((float)$c[1], 6);
            $point = [$lng, $lat];
            if (isset($c[2]) && is_numeric($c[2])) {
                $point[] = round((float)$c[2], 1);   // elevation, if the router delivered it
            }
            $line[] = $point;
            $start ??= [$lat, $lng];
            $bbox = [min($bbox[0], $lat), min($bbox[1], $lng), max($bbox[2], $lat), max($bbox[3], $lng)];
            if ($prev) {
                $d = distanceMeters($prev[1], $prev[0], $lat, $lng);
                $total += $d;
                if ($isFree) { $freehand += $d; }
            }
            $prev = $point;
        }
        $clean['features'][] = ['type' => 'Feature', 'properties' => ['freehand' => $isFree],
                                'geometry' => ['type' => 'LineString', 'coordinates' => $line]];
    }

    return [
        'geojson'      => json_encode($clean, JSON_UNESCAPED_SLASHES),
        'distance'     => (int)round($total),
        'freehand_pct' => $total > 0 ? (int)round($freehand / $total * 100) : 100,
        'start'        => $start,
        'bbox'         => $bbox,
    ];
}

/**
 * Vehicle classes of the e-scooter profile (tools/brouter/escooter.brf, parameter scooter_class).
 * They change how rough surfaces and climbs are weighted – never which ways are allowed.
 */
const VEHICLE_CLASSES = [1 => 'city', 2 => 'allround', 3 => 'bull', 4 => 'bullrun'];
const VEHICLE_CLASS_DEFAULT = 2;

function vehicleClass(mixed $value): int
{
    $v = (int)$value;
    return isset(VEHICLE_CLASSES[$v]) ? $v : VEHICLE_CLASS_DEFAULT;
}

/**
 * BRouter turn instructions kept for the navigation (VoiceHint commands): turn left/right, slight, sharp,
 * keep left/right, U-turns, roundabouts (with exit number), exits. "Continue" and "end" are not stored.
 */
const GUIDANCE_COMMANDS = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 14, 15, 17, 18];

/** Checks guidance from the browser against the track: [[index, command, exit], …] sorted, or null if invalid. */
function validateGuidance(mixed $guidance, string $geojson): ?array
{
    if (!is_array($guidance) || count($guidance) > 5000) {
        return null;
    }
    $total = 0;
    foreach (json_decode($geojson, true)['features'] ?? [] as $f) {
        $total += count($f['geometry']['coordinates']);
    }
    $clean = [];
    foreach ($guidance as $g) {
        if (!is_array($g) || count($g) < 3 || !is_int($g[0]) || !is_int($g[1]) || !is_int($g[2])) {
            return null;
        }
        if ($g[0] < 0 || $g[0] >= $total || !in_array($g[1], GUIDANCE_COMMANDS, true) || $g[2] < 0 || $g[2] > 20) {
            continue;   // unknown commands are simply left out
        }
        $clean[] = [$g[0], $g[1], $g[2]];
    }
    usort($clean, fn($a, $b) => $a[0] <=> $b[0]);
    return $clean;
}

/** Longest crow-flies distance between two waypoints the planner accepts (config brouter.max_leg_km). */
function maxLegKm(): float
{
    global $CONFIG;
    return max(1.0, (float)($CONFIG['brouter']['max_leg_km'] ?? 50));
}

/** Sum of climbs from the elevation values (only if the router delivered elevations). */
function computeAscent(string $geojson): ?int
{
    $fc = json_decode($geojson, true);
    $sum = 0.0; $hasElevation = false;
    foreach ($fc['features'] ?? [] as $f) {
        $ref = null;
        foreach ($f['geometry']['coordinates'] as $c) {
            if (!isset($c[2])) { continue; }
            $hasElevation = true;
            // Hysteresis: only count from 3 m, otherwise measurement noise adds up
            if ($ref === null || $c[2] < $ref) { $ref = $c[2]; }
            elseif ($c[2] - $ref >= 3) { $sum += $c[2] - $ref; $ref = $c[2]; }
        }
    }
    return $hasElevation ? (int)round($sum) : null;
}

function formatKm(int $meters): string
{
    global $LANG;
    return number_format($meters / 1000, 1, $LANG === 'de' ? ',' : '.', $LANG === 'de' ? '.' : ',') . ' km';
}


/** Riding time in minutes at the same cruising speed the ride mode assumes (18 km/h). */
function tourDurationMinutes(int $distanceM): int
{
    return (int)round($distanceM / 1000 / 18 * 60);
}

function formatDuration(int $minutes): string
{
    return $minutes >= 60 ? intdiv($minutes, 60) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT) . ' h' : $minutes . ' min';
}

/**
 * Elevation profile as an inline SVG (distance on x, elevation on y) or null when the track has no elevation data
 * (freehand tours). Returns ['svg' => string, 'min' => int, 'max' => int].
 */
function elevationProfile(string $geojson): ?array
{
    $fc = json_decode($geojson, true);
    $pts = []; $cum = 0.0; $prev = null;
    foreach ($fc['features'] ?? [] as $f) {
        foreach ($f['geometry']['coordinates'] ?? [] as $c) {
            if (!isset($c[2])) { $prev = null; continue; }
            if ($prev !== null) {
                $dLat = deg2rad($c[1] - $prev[1]); $dLng = deg2rad($c[0] - $prev[0]);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($prev[1])) * cos(deg2rad($c[1])) * sin($dLng / 2) ** 2;
                $cum += 2 * 6371008.8 * asin(min(1, sqrt($a)));
            }
            $pts[] = [$cum, (float)$c[2]];
            $prev = $c;
        }
    }
    if (count($pts) < 2 || $cum < 200) {
        return null;
    }
    $step = max(1, (int)ceil(count($pts) / 160));
    $pts = array_values(array_filter($pts, fn($p, $i) => $i % $step === 0 || $i === count($pts) - 1, ARRAY_FILTER_USE_BOTH));
    $min = min(array_column($pts, 1)); $max = max(array_column($pts, 1));
    $span = max($max - $min, 10.0);                     // flat tours stay flat instead of looking like mountains
    $w = 600; $h = 120; $padY = 8;
    $line = [];
    foreach ($pts as [$d, $z]) {
        $line[] = sprintf('%.1f,%.1f', $d / $cum * $w, $h - $padY - ($z - $min) / $span * ($h - 2 * $padY));
    }
    $poly = implode(' ', $line);
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" class="profile-svg" aria-hidden="true">'
         . '<polygon points="0,' . $h . ' ' . $poly . ' ' . $w . ',' . $h . '" class="profile-fill"/>'
         . '<polyline points="' . $poly . '" class="profile-line" vector-effect="non-scaling-stroke"/></svg>';
    return ['svg' => $svg, 'min' => (int)round($min), 'max' => (int)round($max)];
}


/**
 * Small preview picture of a tour for lists (480×270): the route on a light map-like ground, start and finish marked.
 * Cached in data/thumbs per tour state. Only for riders who may see the tour (tour_thumb.php checks that).
 */
function tourThumbPath(array $tour, ?string $forceFile = null): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $file = $forceFile ?? dataDir('thumbs') . '/' . (int)$tour['id'] . '-' . strtotime($tour['updated_at'] . ' UTC') . '.png';
    if (is_file($file)) {
        return $file;
    }
    if ($forceFile === null) {
        foreach (glob(dataDir('thumbs') . '/' . (int)$tour['id'] . '-*.png') ?: [] as $old) {
            @unlink($old);   // older states of this tour
        }
    }
    $fc = json_decode((string)$tour['geojson'], true);
    $w = 480; $h = 270; $pad = 36;
    $img = imagecreatetruecolor($w, $h);
    $col = fn(int $hex) => imagecolorallocate($img, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255);
    $chalk = $col(0xE8ECF1); $grid = $col(0xD3DBE5); $denim = $col(0x2F5E8C); $white = $col(0xFFFFFF); $gold = $col(0xD7A845); $night = $col(0x14263F); $red = $col(0xC2553F);
    imagefilledrectangle($img, 0, 0, $w, $h, $chalk);
    imagesetthickness($img, 1);
    for ($x = 0; $x < $w; $x += 40) { imageline($img, $x, 0, $x, $h, $grid); }
    for ($y = 0; $y < $h; $y += 40) { imageline($img, 0, $y, $w, $y, $grid); }
    $all = [];
    foreach ($fc['features'] ?? [] as $f) {
        foreach ($f['geometry']['coordinates'] ?? [] as $c) { $all[] = $c; }
    }
    if (count($all) >= 2) {
        $lats = array_column($all, 1); $lngs = array_column($all, 0);
        $k = cos(deg2rad((min($lats) + max($lats)) / 2));
        $minX = min($lngs) * $k; $maxX = max($lngs) * $k; $minY = min($lats); $maxY = max($lats);
        $scale = min(($w - 2 * $pad) / max($maxX - $minX, 1e-9), ($h - 2 * $pad) / max($maxY - $minY, 1e-9));
        $offX = ($w - ($maxX - $minX) * $scale) / 2; $offY = ($h - ($maxY - $minY) * $scale) / 2;
        $px = fn(float $lng, float $lat) => [(int)round($offX + ($lng * $k - $minX) * $scale), (int)round($offY + ($maxY - $lat) * $scale)];
        foreach ([[12, $white], [6, null]] as [$thick, $color]) {
            imagesetthickness($img, $thick);
            foreach ($fc['features'] as $f) {
                $c = $color ?? (!empty($f['properties']['freehand']) ? $red : $denim);
                $prev = null;
                foreach ($f['geometry']['coordinates'] as $pt) {
                    $p = $px($pt[0], $pt[1]);
                    if ($prev) { imageline($img, $prev[0], $prev[1], $p[0], $p[1], $c); }
                    $prev = $p;
                }
            }
        }
        $s = $px($all[0][0], $all[0][1]); $e = $px(end($all)[0], end($all)[1]);
        imagefilledellipse($img, $s[0], $s[1], 20, 20, $night); imagefilledellipse($img, $s[0], $s[1], 13, 13, $gold);
        imagefilledellipse($img, $e[0], $e[1], 20, 20, $white); imagefilledellipse($img, $e[0], $e[1], 12, 12, $night);
    }
    imagepng($img, $file . '.tmp', 6);
    rename($file . '.tmp', $file);
    return $file;
}


/** Waypoints [[lat, lng], …] along a track given as [[lat, lng], …]: start, one about every 400 m (fewer on very long tracks, at most
 *  MAX_WAYPOINTS), finish – close together they keep a re-planned tour on the road that was really ridden. */
function waypointsFromTrack(array $pts, int $distanceM): array
{
    $step = max(400.0, $distanceM / (MAX_WAYPOINTS - 2));
    $wps = [[$pts[0][0], $pts[0][1]]];
    $acc = 0.0;
    for ($i = 1; $i < count($pts) - 1; $i++) {
        $acc += distanceMeters($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
        if ($acc >= $step && count($wps) < MAX_WAYPOINTS - 1) {
            $wps[] = [$pts[$i][0], $pts[$i][1]];
            $acc = 0.0;
        }
    }
    $wps[] = [end($pts)[0], end($pts)[1]];
    return $wps;
}
