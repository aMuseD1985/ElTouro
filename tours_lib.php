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
