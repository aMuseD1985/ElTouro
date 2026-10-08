<?php
/**
 * GET /api/place-search?q=Moers  ->  {"results": [{"label": "Moers, Kreis Wesel, Nordrhein-Westfalen", "lat": …, "lng": …, "bbox": […]}]}
 * Only on Enter/click, never while typing (Nominatim usage policy). See geocoder_lib.php for what leaves the server.
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
// At most 20 searches per minute and rider – the geocoder is a shared, free service
$now = time();
$recent = array_values(array_filter((array)($_SESSION['place_searches'] ?? []), fn($t) => $t > $now - 60));
if (count($recent) >= 20) {
    respond(429, ['error' => 'slow_down']);
}
$recent[] = $now;
$_SESSION['place_searches'] = $recent;
session_write_close();

$results = searchPlaces(mb_substr((string)($_GET['q'] ?? ''), 0, 120), $LANG);
if ($results === null) {
    respond(503, ['error' => 'unavailable']);
}
respond(200, ['results' => $results]);
