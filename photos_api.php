<?php
/** POST /api/photos (JSON, X-CSRF) {"action":"like","photo":ID} → {"liked":true,"count":3} – members of the crew only. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/photos_lib.php';
header('Content-Type: application/json; charset=utf-8');
function respond(int $code, array $data): never { http_response_code($code); echo json_encode($data); exit; }
$me = currentUser();
if ($me === null) { respond(401, ['error' => 'login']); }
if (!isPost() || !checkCsrfHeader()) { respond(400, ['error' => 'csrf']); }
session_write_close();
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$p = dbOne('SELECT id, group_id FROM crew_photos WHERE id = ? AND deleted_at IS NULL', [(int)($in['photo'] ?? 0)]);
$crew = $p ? loadCrewById((int)$p['group_id']) : null;
[$ok] = $crew ? photoAccess($crew, $me) : [false];
if (!$ok || ($in['action'] ?? '') !== 'like') { respond(404, ['error' => 'not_found']); }
$liked = toggleLike((int)$p['id'], (int)$me['id']);
respond(200, ['liked' => $liked, 'count' => (int)dbOne('SELECT like_count FROM crew_photos WHERE id = ?', [$p['id']])['like_count']]);
