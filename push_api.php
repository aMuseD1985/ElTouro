<?php
/**
 * POST /api/push (JSON, X-CSRF) – subscribe or unsubscribe this device for web push, send a test message.
 *   {"action":"subscribe","endpoint":"https://…","keys":{"p256dh":"…","auth":"…"}}
 *   {"action":"unsubscribe","endpoint":"https://…"}   {"action":"test"}
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/push_lib.php';
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
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($in['action'] ?? '');
$endpoint = (string)($in['endpoint'] ?? '');

if ($action === 'subscribe') {
    $p256 = (string)($in['keys']['p256dh'] ?? ''); $auth = (string)($in['keys']['auth'] ?? '');
    if (!allowedPushEndpoint($endpoint) || strlen(b64uDecode($p256)) !== 65 || strlen(b64uDecode($auth)) < 8 || strlen($auth) > 40) {
        respond(422, ['error' => 'subscription']);
    }
    if ((int)dbOne('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id = ?', [$uid])['n'] >= 8) {
        dbExec('DELETE FROM push_subscriptions WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]);   // at most 8 devices per rider
    }
    dbExec('INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, ua) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), fails = 0',
        [$uid, hash('sha256', $endpoint), $endpoint, $p256, $auth, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)]);
    respond(200, ['ok' => true]);
}
if ($action === 'unsubscribe') {
    dbExec('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$uid, hash('sha256', $endpoint)]);
    respond(200, ['ok' => true]);
}
if ($action === 'test') {
    $sent = 0;
    foreach (dbAll('SELECT * FROM push_subscriptions WHERE user_id = ?', [$uid]) as $sub) {
        $code = sendPushTo($sub, ['title' => t('push.test_title'), 'body' => t('push.test_body'), 'url' => '/profile#notifications', 'tag' => 'test']);
        $sent += $code >= 200 && $code < 300 ? 1 : 0;
    }
    respond(200, ['sent' => $sent]);
}
respond(422, ['error' => 'action']);
