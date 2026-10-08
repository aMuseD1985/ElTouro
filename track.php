<?php
/**
 * POST /api/track  (JSON, X-CSRF header) – recording from the ride mode.
 *   {"action": "start", "tour_id": 5}                       -> {"session_id": 12}
 *   {"action": "points", "session_id": 12, "points": [[unixMillis, lat, lng, accuracy, speedKmh], …]} -> {"stored": 30}
 *   {"action": "finish", "session_id": 12}                  -> {"distance_m": 23400, "moving_s": 4120, "max_speed_kmh": 19.8}
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/track_lib.php';
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
if (!isPost() || !checkCsrfHeader()) {
    respond(400, ['error' => 'csrf']);
}
session_write_close();
$uid = (int)$me['id'];
$input = json_decode((string)file_get_contents('php://input'), true);
$action = (string)($input['action'] ?? '');

if ($action === 'start') {
    $tour = loadTour((int)($input['tour_id'] ?? 0));
    if ($tour === null || !canSeeTour($tour, $uid)) {
        respond(404, ['error' => 'tour']);
    }
    respond(200, ['session_id' => startTrack($uid, (int)$tour['id'])]);
}

$session = loadOwnTrack((int)($input['session_id'] ?? 0), $uid);
if ($session === null) {
    respond(404, ['error' => 'session']);
}
if ($action === 'points') {
    respond(200, ['stored' => addTrackPoints($session, is_array($input['points'] ?? null) ? $input['points'] : [])]);
}
if ($action === 'finish') {
    $s = finishTrack((int)$session['id'], $uid);
    respond(200, ['distance_m' => (int)$s['distance_m'], 'moving_s' => (int)$s['moving_s'], 'max_speed_kmh' => (float)$s['max_speed_kmh']]);
}
respond(422, ['error' => 'action']);
