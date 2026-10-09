<?php
/** REST API v1 front controller: /api/v1/<path>. See api_routes.php (routes) and api_lib.php (tokens, rate limit, OpenAPI). */
declare(strict_types=1);
const SKIP_ACCESS_GATE = true;      // the token is the credential, not the tester password
const NO_CONSENT_NEEDED = true;     // there is no session; api_lib checks the consent of the token's owner itself
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/api_routes.php';
header_remove('Set-Cookie');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function apiRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = '/' . trim((string)($_GET['path'] ?? ''), '/');
    [$token, $user] = apiAuthenticate();
    $allowed = [];
    $found = null;
    foreach (apiRoutes() as $r) {
        $re = '#^' . preg_replace(['/\{slug\}/', '/\{\w+\}/'], ['([a-z0-9-]+)', '(\d+)'], $r['path']) . '$#';
        if (preg_match($re, $path, $m)) {
            $allowed[] = $r['method'];
            if ($r['method'] === $method) {
                preg_match_all('/\{(\w+)\}/', $r['path'], $names);
                $found = [$r, array_combine($names[1], array_slice($m, 1)) ?: []];
            }
        }
    }
    if ($found === null) {
        if ($allowed) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ApiError(405, 'method_not_allowed', 'Allowed: ' . implode(', ', $allowed));
        }
        throw new ApiError(404, 'unknown_route', 'No such endpoint. See /api/docs.');
    }
    $body = [];
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > 4_000_000) {
            throw new ApiError(413, 'too_large', 'Request body too large.');
        }
        if (trim($raw) !== '') {
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                throw new ApiError(400, 'invalid_json', 'The body must be a JSON object.');
            }
        }
    }
    session_write_close();
    $out = ($found[0]['handler'])(['user' => $user, 'uid' => (int)$user['id'], 'p' => $found[1], 'q' => $_GET, 'body' => $body, 'token' => $token]);
    [$data, $status] = is_array($out) && count($out) === 2 && is_int($out[1]) ? $out : [$out, 200];
    apiRespond($status, ['data' => $data]);
} catch (ApiError $e) {
    apiRespond($e->status, ['error' => ['code' => $e->apiCode, 'message' => $e->getMessage()]]);
} catch (Throwable $e) {
    error_log('ElTouro API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    apiRespond(500, ['error' => ['code' => 'server_error', 'message' => 'Something went wrong on our side.']]);
}
