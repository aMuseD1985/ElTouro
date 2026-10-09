<?php
/**
 * POST /api/forum/react  (JSON: {"post": 12, "emoji": "🔥"}, X-CSRF header) – toggles a reaction on a forum post.
 * Response: the post's reactions {"reactions": {emoji: {n, mine, names}}}.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/community_lib.php';
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
$input = json_decode((string)file_get_contents('php://input'), true);
$postId = (int)($input['post'] ?? 0);
// Only visible posts in visible topics; locked topics can still get reactions – they are not new posts
$post = dbOne('SELECT p.id FROM forum_posts p JOIN forum_threads t ON t.id = p.thread_id
                WHERE p.id = ? AND p.deleted_at IS NULL AND t.deleted_at IS NULL', [$postId]);
if ($post === null) {
    respond(404, ['error' => 'post']);
}
if (!in_array((string)($input['emoji'] ?? ''), REACTIONS, true)) {
    respond(422, ['error' => 'emoji']);
}
toggleForumReaction($postId, (int)$me['id'], (string)$input['emoji']);
respond(200, ['reactions' => forumReactions([$postId], (int)$me['id'])[$postId] ?? new stdClass()]);
