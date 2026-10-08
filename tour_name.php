<?php
/**
 * POST /api/tour-name  (JSON: {"geojson": FeatureCollection, "exclude": "previous suggestion"})
 * Response: {"name": "Rodeo Rheinhausen"}
 *
 * The facts (length, loop, middle of the track) are computed here from the geometry, like everything else
 * about tours. See tour_name_lib.php for what is sent to the geocoder.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tour_name_lib.php';
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
$input = json_decode((string)file_get_contents('php://input'), true);
$geo = is_array($input['geojson'] ?? null) ? validateGeometry(json_encode($input['geojson'])) : null;
if ($geo === null) {
    respond(422, ['error' => 'geojson']);
}
$facts = tourNameFacts($geo);
$place = placeNear($facts['middle'][0], $facts['middle'][1], $LANG);
respond(200, ['name' => suggestTourName($facts, $place, mb_substr((string)($input['exclude'] ?? ''), 0, 120))]);
