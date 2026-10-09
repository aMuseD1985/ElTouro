<?php
/**
 * /api/live – live positions (see live_lib.php for who sees whom).
 *   GET  ?box=south,west,north,east                     -> {"riders": [{id, name, crew, lat, lng, heading, age}]}
 *   POST {"action": "update", "lat", "lng", "heading", "speed", "scope": "crews"|"all", "tour_id"}   (X-CSRF header)
 *   POST {"action": "stop"}
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/live_lib.php';
header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$me = currentUser();
if ($me === null) {
    respond(401, ['error' => 'login']);
}
$uid = (int)$me['id'];

if (!isPost()) {
    session_write_close();
    $box = array_map('floatval', explode(',', (string)($_GET['box'] ?? '')));
    if (count($box) !== 4 || !isValidCoordinate($box[0], $box[1]) || !isValidCoordinate($box[2], $box[3])
        || $box[2] - $box[0] > 2 || $box[3] - $box[1] > 3) {   // at most about 200 km across – a city or region, not the country
        respond(422, ['error' => 'box']);
    }
    respond(200, ['riders' => visibleLiveRiders($uid, $box)]);
}

if (!checkCsrfHeader()) {
    respond(400, ['error' => 'csrf']);
}
session_write_close();
$input = json_decode((string)file_get_contents('php://input'), true);
$action = (string)($input['action'] ?? '');
if ($action === 'stop') {
    stopLive($uid);
    respond(200, ['ok' => true]);
}
if ($action === 'update') {
    $tour = isset($input['tour_id']) ? loadTour((int)$input['tour_id']) : null;
    $ok = updateLivePosition($uid, (float)($input['lat'] ?? 0), (float)($input['lng'] ?? 0),
        isset($input['heading']) && is_numeric($input['heading']) ? fmod((float)$input['heading'] + 360, 360) : null,
        isset($input['speed']) && is_numeric($input['speed']) ? max(0, min(99.9, (float)$input['speed'])) : null,
        (string)($input['scope'] ?? ''), $tour !== null && canSeeTour($tour, $uid) ? (int)$tour['id'] : null);
    respond($ok ? 200 : 422, ['ok' => $ok]);
}
respond(422, ['error' => 'action']);
