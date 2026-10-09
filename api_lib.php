<?php
/**
 * REST API v1: tokens, access rules, rate limit and the OpenAPI document.
 *
 * Who may use it: admins, and riders the admin approved (users.api_access). A rider asks under /api/docs, the admin decides in
 * Admin → API. Calls carry "Authorization: Bearer elt_…" (or the header X-API-Key); the token is stored only as SHA-256.
 * The API uses the same access rules as the web app (the same library functions) and never returns more than the app shows.
 */
declare(strict_types=1);

const API_RATE_PER_MINUTE = 90;
const API_MAX_PAGE = 100;

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, public string $apiCode, string $message)
    {
        parent::__construct($message);
    }
}

function apiMayUse(array $user): bool
{
    return ($user['status'] ?? '') === 'active' && ((int)($user['is_admin'] ?? 0) === 1 || (int)($user['api_access'] ?? 0) === 1);
}

function apiNewToken(int $userId, string $name): string
{
    $plain = 'elt_' . bin2hex(random_bytes(20));
    dbExec('INSERT INTO api_tokens (user_id, name, token_hash, prefix) VALUES (?, ?, ?, ?)',
        [$userId, mb_substr(trim($name), 0, 60) ?: 'Token', hash('sha256', $plain), substr($plain, 0, 8)]);
    return $plain;
}

/** The token of this request → [token row, user row]; throws ApiError */
function apiAuthenticate(): array
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $plain = preg_match('/^Bearer\s+(elt_[0-9a-f]{40})$/i', trim($h), $m) ? $m[1] : trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if (!preg_match('/^elt_[0-9a-f]{40}$/', $plain)) {
        throw new ApiError(401, 'unauthorized', 'Missing or malformed API token (Authorization: Bearer elt_…).');
    }
    $tok = dbOne('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $plain)]);
    $user = $tok ? dbOne('SELECT * FROM users WHERE id = ?', [$tok['user_id']]) : null;
    if ($tok === null || $user === null) {
        throw new ApiError(401, 'unauthorized', 'Unknown or revoked token.');
    }
    if (!apiMayUse($user)) {
        throw new ApiError(403, 'api_not_enabled', 'API access is not enabled for this account.');
    }
    if ((int)$user['consent_version'] < CONSENT_VERSION) {
        throw new ApiError(403, 'consent_required', 'Open ElTouro once and accept the current privacy information.');
    }
    // Rate limit per token and minute
    $minute = intdiv(time(), 60);
    dbExec('INSERT INTO api_hits (token_id, minute_at, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1', [$tok['id'], $minute]);
    $n = (int)dbOne('SELECT n FROM api_hits WHERE token_id = ? AND minute_at = ?', [$tok['id'], $minute])['n'];
    header('X-RateLimit-Limit: ' . API_RATE_PER_MINUTE);
    header('X-RateLimit-Remaining: ' . max(0, API_RATE_PER_MINUTE - $n));
    if ($n > API_RATE_PER_MINUTE) {
        header('Retry-After: ' . (60 - time() % 60));
        throw new ApiError(429, 'rate_limited', 'Too many requests – at most ' . API_RATE_PER_MINUTE . ' per minute and token.');
    }
    if (random_int(1, 100) === 1) {
        dbExec('DELETE FROM api_hits WHERE minute_at < ?', [$minute - 10]);
    }
    dbExec('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$tok['id']]);
    return [$tok, $user];
}

function apiLimit(array $q): array
{
    return [max(1, min(API_MAX_PAGE, (int)($q['limit'] ?? 25))), max(0, (int)($q['offset'] ?? 0))];
}

function apiBool(mixed $v): bool
{
    return in_array($v, [true, 1, '1', 'true'], true);
}

// ---------------------------------------------------------------- OpenAPI document from the route table

/** @param array<int, array<string, mixed>> $routes */
function apiOpenApi(array $routes): array
{
    $paths = [];
    $tags = [];
    foreach ($routes as $r) {
        $tags[$r['tag']] = true;
        $params = [];
        preg_match_all('/\{(\w+)\}/', $r['path'], $m);
        foreach ($m[1] as $p) {
            $params[] = ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => $p === 'slug' ? 'string' : 'integer']];
        }
        foreach ($r['query'] ?? [] as $name => $def) {
            $params[] = ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $def[1] ?? '', 'schema' => ['type' => $def[0]]];
        }
        $op = ['tags' => [$r['tag']], 'summary' => $r['summary'], 'operationId' => strtolower($r['method']) . preg_replace('/[^A-Za-z0-9]+/', '_', $r['path']),
               'security' => [['bearerAuth' => []]], 'parameters' => $params,
               'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                               '401' => ['description' => 'Token missing or invalid'], '403' => ['description' => 'Not allowed'], '404' => ['description' => 'Not found or not visible for you'],
                               '422' => ['description' => 'Invalid input'], '429' => ['description' => 'Rate limit reached']]];
        if (!empty($r['description'])) {
            $op['description'] = $r['description'];
        }
        if (!empty($r['body'])) {
            $props = []; $req = [];
            foreach ($r['body'] as $name => $def) {
                $props[$name] = array_filter(['type' => $def[0], 'description' => $def[1] ?? '', 'enum' => $def[3] ?? null], fn($v) => $v !== null && $v !== '');
                if (!empty($def[2])) {
                    $req[] = $name;
                }
            }
            $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => array_filter(['type' => 'object', 'properties' => $props, 'required' => $req])]]];
        }
        if (!$op['parameters']) {
            unset($op['parameters']);
        }
        $paths[preg_replace('/\{(\w+)\}/', '{$1}', $r['path'])][strtolower($r['method'])] = $op;
    }
    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'ElTouro API', 'version' => '1.0.0',
                   'description' => "REST API of ElTouro for approved users.\n\n**Authentication:** create a token under *API-Zugang* on this page and send it as `Authorization: Bearer elt_…`.\n\n"
                       . "**Rules:** the API follows the same visibility rules as the app (private tours stay private, secret crews return 404, crew talk only for members). "
                       . "At most " . API_RATE_PER_MINUTE . " requests per minute per token. All times are UTC (ISO 8601). Successful answers: `{\"data\": …}`, errors: `{\"error\": {\"code\", \"message\"}}`. "
                       . "Lists take `limit` (max " . API_MAX_PAGE . ") and `offset`.\n\n**Terms:** use is allowed for your own tools and apps on your own data and what you may see; no scraping of other riders' data, no bulk copying. The admin may revoke access at any time."],
        'servers' => [['url' => baseUrl() . '/api/v1']],
        'tags' => array_map(fn($t) => ['name' => $t], array_keys($tags)),
        'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'elt_… token from /api/docs']]],
        'paths' => $paths,
    ];
}
