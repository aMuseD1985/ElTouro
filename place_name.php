<?php
/**
 * GET /api/place-name?lat=51.45&lng=6.62  ->  {"label": "Homberger Straße 12, Moers"}
 * Names for the planner's waypoint list; the planner asks for one point at a time. See geocoder_lib.php.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/geocoder_lib.php';
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
$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);
if (!isValidCoordinate($lat, $lng)) {
    respond(422, ['error' => 'coordinates']);
}
$now = time();
$recent = array_values(array_filter((array)($_SESSION['place_names'] ?? []), fn($t) => $t > $now - 60));
if (count($recent) >= 60) {
    respond(429, ['error' => 'slow_down']);
}
$recent[] = $now;
$_SESSION['place_names'] = $recent;
session_write_close();   // lookups wait for the geocoder – don't block the rider's other requests meanwhile

respond(200, ['label' => addressNear($lat, $lng, $LANG)]);
