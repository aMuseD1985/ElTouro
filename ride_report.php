<?php
/**
 * POST /api/ride-report (JSON, X-CSRF header) – the moment after a ride.
 *   {"action": "crews"}                                          -> {"crews": [{"slug", "name"}]}   crews the rider may post in
 *   {"action": "post", "crew": "slug", "tour_id": 5, "km": 12.3, "min": 48}  -> {"topic": 17}      a short report in the crew talk
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/talk_lib.php';
require_once __DIR__ . '/tours_lib.php';
header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];

if (($in['action'] ?? '') === 'crews') {
    $rows = dbAll("SELECT g.slug, g.name FROM group_members m JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                    WHERE m.user_id = ? AND m.status = 'active' ORDER BY g.name", [$uid]);
    respond(200, ['crews' => $rows]);
}

if (($in['action'] ?? '') === 'post') {
    $crew = loadCrew((string)($in['crew'] ?? ''));
    [$allowed] = $crew ? talkAccess($crew, $me) : [false];
    $tour = loadTour((int)($in['tour_id'] ?? 0));
    if (!$allowed || $tour === null || !canSeeTour($tour, $uid)) {
        respond(404, ['error' => 'not_found']);
    }
    if (!canTalkNow($uid)) {
        respond(429, ['error' => 'slow_down']);
    }
    $km = max(0.0, min(500.0, (float)($in['km'] ?? 0)));
    $min = max(0, min(2000, (int)($in['min'] ?? 0)));
    $title = mb_substr(t('ride.report_title', ['title' => $tour['title']]), 0, 150);
    $body = t('ride.report_body', ['km' => number_format($km, 1, t('common.decimal_point'), ''), 'min' => $min])
          . "\n\n" . t('ride.report_link', ['link' => baseUrl() . '/tour/' . (int)$tour['id']]);
    [$topicId] = createTalkTopic((int)$crew['id'], $uid, $title, $body);
    respond(200, ['topic' => $topicId]);
}
respond(422, ['error' => 'action']);
