<?php
/**
 * POST /api/stops  (JSON: {"geojson": FeatureCollection}) – suggestions for stops along a planned track.
 * Response: {"plan": [...], "by_type": {"charge": [...], "food": [...], "break": [...], "sight": [...]}}. See poi_lib.php.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/poi_lib.php';
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
$now = time();
$recent = array_values(array_filter((array)($_SESSION['stop_suggestions'] ?? []), fn($t) => $t > $now - 60));
if (count($recent) >= 6) {
    respond(429, ['error' => 'slow_down']);
}
$recent[] = $now;
$_SESSION['stop_suggestions'] = $recent;
session_write_close();   // Overpass can take a few seconds

$input = json_decode((string)file_get_contents('php://input'), true);
$geo = is_array($input['geojson'] ?? null) ? validateGeometry(json_encode($input['geojson'])) : null;
if ($geo === null) {
    respond(422, ['error' => 'geojson']);
}
@set_time_limit(90);
$result = suggestStops($geo);
if ($result === null) {
    respond(503, ['error' => 'unavailable']);
}
respond(200, $result);
