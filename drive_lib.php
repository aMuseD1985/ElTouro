<?php
/**
 * Recorded rides as keepsakes ("Fahrten"): the ridden track, the route as planned on that day, figures, and – on request –
 * a new tour made from the recording (same measurements as for a planned tour, computed on the server).
 */
declare(strict_types=1);
require_once __DIR__ . '/rides_lib.php';
require_once __DIR__ . '/track_lib.php';
require_once __DIR__ . '/poi_lib.php';

/** Good fixes of a recording as [lat, lng], jumps removed, at most $max points. */
function drivePoints(int $sessionId, int $max = 1500): array
{
    $rows = dbAll('SELECT lat, lng, recorded_at FROM track_points WHERE session_id = ? AND (accuracy IS NULL OR accuracy <= ?) ORDER BY seq', [$sessionId, TRACK_MAX_ACCURACY]);
    $pts = []; $prev = null;
    foreach ($rows as $r) {
        $t = strtotime(substr($r['recorded_at'], 0, 19) . ' UTC');
        if ($prev !== null) {
            $d = distanceMeters($prev[0], $prev[1], (float)$r['lat'], (float)$r['lng']); $dt = max(1, $t - $prev[2]);
            if ($d / $dt * 3.6 > TRACK_MAX_PLAUSIBLE_KMH) { continue; }
        }
        $prev = [(float)$r['lat'], (float)$r['lng'], $t];
        $pts[] = [(float)$r['lat'], (float)$r['lng']];
    }
    $tol = 2.0;
    while (count($pts) > $max && $tol < 200) { $pts = simplifyLine($pts, $tol); $tol *= 1.6; }
    return $pts;
}

function driveGeoJson(array $pts): string
{
    return json_encode(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['recorded' => true],
        'geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn($p) => [$p[1], $p[0]], $pts)]]]]);
}

function loadOwnDrive(int $id, int $userId): ?array
{
    return dbOne("SELECT s.*, t.title AS tour_title FROM track_sessions s LEFT JOIN tours t ON t.id = s.tour_id AND t.deleted_at IS NULL
                   WHERE s.id = ? AND s.user_id = ? AND s.status = 'finished'", [$id, $userId]);
}

function ownDrives(int $userId, int $limit = 100, ?int $tourId = null): array
{
    $tourSql = $tourId !== null ? ' AND s.tour_id = ' . (int)$tourId : '';
    return dbAll("SELECT s.id, s.started_at, s.distance_m, s.moving_s, s.max_speed_kmh, s.tour_id, s.saved_tour_id, t.title AS tour_title
                    FROM track_sessions s LEFT JOIN tours t ON t.id = s.tour_id AND t.deleted_at IS NULL
                   WHERE s.user_id = ? AND s.status = 'finished' AND s.distance_m >= 100" . $tourSql . " ORDER BY s.started_at DESC LIMIT " . (int)$limit, [$userId]);
}

/** Creates a private tour from a recording. Returns the new tour id or null. */
function saveDriveAsTour(array $drive, int $userId, string $title): ?int
{
    $pts = drivePoints((int)$drive['id'], 1200);
    if (count($pts) < 5) {
        return null;
    }
    $geo = validateGeometry(driveGeoJson($pts));
    if ($geo === null || $geo['distance'] < 100) {
        return null;
    }
    // Waypoints: start, one about every 400 m (fewer on very long rides – at most MAX_WAYPOINTS), finish. Close together,
    // they keep a re-planned tour on the road that was really ridden.
    $step = max(400.0, $geo['distance'] / (MAX_WAYPOINTS - 2));
    $wps = [[$pts[0][0], $pts[0][1]]]; $acc = 0.0;
    for ($i = 1; $i < count($pts) - 1; $i++) {
        $acc += distanceMeters($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
        if ($acc >= $step && count($wps) < MAX_WAYPOINTS - 1) { $wps[] = [$pts[$i][0], $pts[$i][1]]; $acc = 0.0; }
    }
    $wps[] = [end($pts)[0], end($pts)[1]];
    dbExec("INSERT INTO tours (owner_user_id, title, description, content_lang, visibility, difficulty, style, rule_set, vehicle_class, source, distance_m, ascent_m,
                   freehand_share_pct, waypoints_json, geojson, start_lat, start_lng, bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng)
            VALUES (?, ?, NULL, ?, 'private', 'moderate', 'social', 'ekfv', 2, 'recorded', ?, NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$userId, mb_substr($title, 0, 120), $GLOBALS['LANG'] ?? 'de', $geo['distance'], json_encode($wps), $geo['geojson'],
         $geo['start'][0], $geo['start'][1], $geo['bbox'][0], $geo['bbox'][1], $geo['bbox'][2], $geo['bbox'][3]]);
    $id = (int)db()->lastInsertId();
    dbExec('UPDATE track_sessions SET saved_tour_id = ? WHERE id = ?', [$id, $drive['id']]);
    recordEvent($userId, 'tour_created', 'tour', $id, null, (float)round($geo['distance'] / 1000, 2), ['source' => 'recorded']);
    return $id;
}

/** Small preview picture of a ride (the ridden track on a light ground), cached per ride. */
function driveThumbPath(array $drive): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $file = dataDir('thumbs') . '/drive-' . (int)$drive['id'] . '.png';
    if (is_file($file)) {
        return $file;
    }
    $pts = drivePoints((int)$drive['id'], 600);
    require_once __DIR__ . '/tours_lib.php';
    $fc = driveGeoJson(count($pts) >= 2 ? $pts : [[0, 0], [0, 0.0001]]);
    return tourThumbPath(['id' => 'd' . (int)$drive['id'], 'geojson' => $fc, 'updated_at' => '2000-01-01 00:00:00'], $file);
}

/** Tiles for a list of rides (drives page and tour page). */
function driveCards(array $list): string
{
    $h = '<ul class="cards tour-cards">';
    foreach ($list as $d) {
        $min = (int)round((int)$d['moving_s'] / 60);
        $avg = (int)$d['moving_s'] > 0 ? number_format((int)$d['distance_m'] / (int)$d['moving_s'] * 3.6, 1, t('common.decimal_point'), '') : '–';
        $h .= '<li class="card tour-card"><a class="tour-thumb" href="/drive/' . (int)$d['id'] . '" tabindex="-1" aria-hidden="true"><img src="/drive/' . (int)$d['id'] . '/thumb.png" alt="" loading="lazy" width="240" height="135"></a>'
            . '<div class="tour-body"><h3><a href="/drive/' . (int)$d['id'] . '">' . e(formatRideTime($d['started_at'])) . '</a></h3>'
            . '<p class="tour-meta"><strong>' . e(formatKm((int)$d['distance_m'])) . '</strong> · ' . $min . ' min · ⌀ ' . $avg . ' km/h · ' . te('drive.top', ['v' => number_format((float)$d['max_speed_kmh'], 1, t('common.decimal_point'), '')]) . '</p>'
            . '<p class="muted tour-by">' . ($d['tour_title'] ? e($d['tour_title']) : te('drive.free')) . ($d['saved_tour_id'] ? ' · ' . te('drive.saved_badge') : '') . '</p></div></li>';
    }
    return $h . '</ul>';
}
