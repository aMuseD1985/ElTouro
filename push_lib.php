<?php
/**
 * Web Push (RFC 8030 + payload encryption RFC 8291 + VAPID RFC 8292), written for plain PHP + OpenSSL – no Composer.
 *
 * - Keys: a VAPID key pair per environment, created by the migration and kept in the settings table (the public part is
 *   handed to the browser, the private part never leaves the server). Do not replace it later: existing subscriptions are
 *   bound to the public key.
 * - Subscriptions are only accepted for the known push services (no free URLs: our server would POST to them).
 * - Messages are queued (push_queue) and sent at the end of the request or by the cron job (digest.php).
 * - Each rider decides what reaches the phone (notify_prefs): replies and mentions, rides nearby, rides starting soon.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';
require_once __DIR__ . '/rides_lib.php';

const PUSH_QUEUE_BATCH = 40;
const PUSH_MAX_FAILS = 5;
const PUSH_HOSTS = '/^(fcm\.googleapis\.com|android\.googleapis\.com|updates\.push\.services\.mozilla\.com|[a-z0-9-]+\.push\.services\.mozilla\.com|[a-z0-9.-]+\.push\.apple\.com|[a-z0-9.-]+\.notify\.windows\.com)$/i';
const NEAR_RADII = [10, 25, 50, 100, 200];

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64uDecode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** Raw uncompressed public point (65 bytes) of an EC key resource */
function ecPublicRaw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** PEM of a public key from its raw point (SPKI header for prime256v1) */
function ecPublicPem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** [private PEM, public key base64url]; created once and kept in the settings table */
function vapidKeys(bool $create = true): ?array
{
    $priv = dbOne("SELECT v FROM settings WHERE k = 'vapid_private'")['v'] ?? '';
    $pub = dbOne("SELECT v FROM settings WHERE k = 'vapid_public'")['v'] ?? '';
    if ($priv !== '' && $pub !== '') {
        return [$priv, $pub];
    }
    if (!$create) {
        return null;
    }
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) {
        return null;
    }
    openssl_pkey_export($key, $pem);
    $pub = b64u(ecPublicRaw($key));
    foreach ([['vapid_private', $pem], ['vapid_public', $pub]] as [$k, $v]) {
        dbExec('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
    return [$pem, $pub];
}

/** DER ECDSA signature → raw R||S (64 bytes) as JWT wants it */
function derToRawSignature(string $der): string
{
    $off = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$off + 1]);
        $int = substr($der, $off + 2, $len);
        $parts[] = str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $off += 2 + $len;
    }
    return $parts[0] . $parts[1];
}

function vapidAuthorization(string $endpoint): ?string
{
    $keys = vapidKeys();
    $u = parse_url($endpoint);
    if ($keys === null || empty($u['scheme']) || empty($u['host'])) {
        return null;
    }
    $header = b64u((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u((string)json_encode(['aud' => $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''), 'exp' => time() + 12 * 3600,
        'sub' => 'mailto:' . (setting('operator_email') ?: 'hallo@eltouro.de')]));
    if (!openssl_sign("$header.$claims", $der, $keys[0], OPENSSL_ALGO_SHA256)) {
        return null;
    }
    return 'vapid t=' . "$header.$claims." . b64u(derToRawSignature($der)) . ', k=' . $keys[1];
}

/** Encrypts a payload for one subscription (aes128gcm). Returns the request body or null. */
function encryptPush(string $payload, string $uaPublic, string $authSecret): ?string
{
    if (strlen($uaPublic) !== 65 || strlen($authSecret) < 8) {
        return null;
    }
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPublic = ecPublicRaw($eph);
    $peer = openssl_pkey_get_public(ecPublicPem($uaPublic));
    if ($eph === false || $peer === false) {
        return null;
    }
    $shared = openssl_pkey_derive($peer, $eph, 32);
    if ($shared === false) {
        return null;
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

function allowedPushEndpoint(string $endpoint): bool
{
    $u = parse_url($endpoint);
    return $u && ($u['scheme'] ?? '') === 'https' && !isset($u['user']) && !isset($u['port'])
        && preg_match(PUSH_HOSTS, (string)($u['host'] ?? '')) === 1 && strlen($endpoint) <= 700;
}

/** Sends to one stored subscription. Returns the HTTP status (0 = not sent). Dead subscriptions are removed. */
function sendPushTo(array $sub, array $message): int
{
    if (!allowedPushEndpoint($sub['endpoint'])) {
        dbExec('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
        return 0;
    }
    $body = encryptPush((string)json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), b64uDecode($sub['p256dh']), b64uDecode($sub['auth']));
    $auth = vapidAuthorization($sub['endpoint']);
    if ($body === null || $auth === null) {
        return 0;
    }
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: normal']]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }
    if ($code === 404 || $code === 410) {
        dbExec('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
    } elseif ($code >= 200 && $code < 300) {
        dbExec('UPDATE push_subscriptions SET last_ok_at = UTC_TIMESTAMP(), fails = 0 WHERE id = ?', [$sub['id']]);
    } else {
        dbExec('UPDATE push_subscriptions SET fails = fails + 1 WHERE id = ?', [$sub['id']]);
        dbExec('DELETE FROM push_subscriptions WHERE id = ? AND fails >= ?', [$sub['id'], PUSH_MAX_FAILS]);
    }
    return $code;
}

// ---------------------------------------------------------------- what a rider wants

function notifyPrefs(int $userId): array
{
    $p = dbOne('SELECT * FROM notify_prefs WHERE user_id = ?', [$userId]);
    return $p ?? ['user_id' => $userId, 'push_social' => 1, 'push_rides_near' => 0, 'push_rides_soon' => 0, 'mail_rides_near' => 0, 'radius_km' => 25, 'home_lat' => null, 'home_lng' => null];
}

function saveNotifyPrefs(int $userId, array $in): void
{
    $radius = (int)($in['radius_km'] ?? 25);
    $radius = in_array($radius, NEAR_RADII, true) ? $radius : 25;
    $lat = isset($in['home_lat']) && is_numeric($in['home_lat']) ? round((float)$in['home_lat'], 2) : null;   // about 1 km: an area, not an address
    $lng = isset($in['home_lng']) && is_numeric($in['home_lng']) ? round((float)$in['home_lng'], 2) : null;
    if ($lat !== null && $lng !== null && !isValidCoordinate($lat, $lng)) {
        $lat = $lng = null;
    }
    dbExec('INSERT INTO notify_prefs (user_id, push_social, push_rides_near, push_rides_soon, mail_rides_near, radius_km, home_lat, home_lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE push_social = VALUES(push_social), push_rides_near = VALUES(push_rides_near), push_rides_soon = VALUES(push_rides_soon),
              mail_rides_near = VALUES(mail_rides_near), radius_km = VALUES(radius_km), home_lat = VALUES(home_lat), home_lng = VALUES(home_lng)',
        [$userId, !empty($in['push_social']) ? 1 : 0, !empty($in['push_rides_near']) ? 1 : 0, !empty($in['push_rides_soon']) ? 1 : 0,
         !empty($in['mail_rides_near']) ? 1 : 0, $radius, $lat, $lng]);
}

// ---------------------------------------------------------------- queue

/** Puts a message into the queue for all devices of a rider (only if there are some) */
function enqueuePush(int $userId, string $title, string $body, string $url, string $tag = ''): void
{
    try {
        if (dbOne('SELECT 1 AS x FROM push_subscriptions WHERE user_id = ? LIMIT 1', [$userId]) === null) {
            return;
        }
        dbExec('INSERT INTO push_queue (user_id, title, body, url, tag) VALUES (?, ?, ?, ?, ?)',
            [$userId, mb_substr($title, 0, 80), mb_substr($body, 0, 200), mb_substr($url, 0, 200), mb_substr($tag, 0, 40)]);
    } catch (Throwable $ex) {
        error_log('ElTouro push queue: ' . $ex->getMessage());
    }
}

/** Sends queued messages. @return int number of messages delivered to at least one device */
function processPushQueue(int $max = PUSH_QUEUE_BATCH): int
{
    $sent = 0;
    try {
        foreach (dbAll('SELECT * FROM push_queue ORDER BY id LIMIT ' . (int)$max) as $m) {
            dbExec('DELETE FROM push_queue WHERE id = ?', [$m['id']]);
            $ok = false;
            foreach (dbAll('SELECT * FROM push_subscriptions WHERE user_id = ?', [$m['user_id']]) as $sub) {
                $code = sendPushTo($sub, ['title' => $m['title'], 'body' => $m['body'], 'url' => $m['url'], 'tag' => $m['tag']]);
                $ok = $ok || ($code >= 200 && $code < 300);
            }
            $sent += $ok ? 1 : 0;
        }
    } catch (Throwable $ex) {
        error_log('ElTouro push: ' . $ex->getMessage());
    }
    return $sent;
}

/** Few messages go out right after the request that caused them (the browser has its answer by then); the rest waits for cron */
function pushAfterResponse(): void
{
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        processPushQueue(8);
    });
}

// ---------------------------------------------------------------- rides nearby

/** SQL condition: the rider's home (rounded) is within the rider's radius of the meeting point. $lat/$lng are bound twice, once as lng. */
function nearSql(): string
{
    return '6371 * 2 * ASIN(SQRT(POW(SIN(RADIANS(p.home_lat - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(p.home_lat)) * POW(SIN(RADIANS(p.home_lng - ?) / 2), 2))) <= p.radius_km';
}

/** Riders whose home area lies within their radius of the point; only those who switched the given preference on. @return int[] */
function ridersNear(float $lat, float $lng, string $prefColumn, ?array $onlyCrewMembersOf = null): array
{
    if (!in_array($prefColumn, ['push_rides_near', 'push_rides_soon', 'mail_rides_near'], true)) {
        return [];
    }
    $sql = "SELECT u.id FROM notify_prefs p JOIN users u ON u.id = p.user_id AND u.status = 'active'
             WHERE p.$prefColumn = 1 AND p.home_lat IS NOT NULL AND " . nearSql();
    $args = [$lat, $lat, $lng];
    if ($onlyCrewMembersOf !== null) {
        $sql .= " AND EXISTS (SELECT 1 FROM group_members g WHERE g.group_id = ? AND g.user_id = u.id AND g.status = 'active')";
        $args[] = (int)$onlyCrewMembersOf['id'];
    }
    return array_map('intval', array_column(dbAll($sql, $args), 'id'));
}

/** A new public ride was created: riders nearby who asked for it get a bell entry and a push. */
function announceRideNearby(array $ride): void
{
    if ($ride['meeting_lat'] === null || $ride['meeting_lng'] === null) {
        return;
    }
    try {
        require_once __DIR__ . '/notify_lib.php';
        if ($ride['visibility'] !== 'public') {
            return;   // crew rides reach the crew through the normal notification
        }
        foreach (ridersNear((float)$ride['meeting_lat'], (float)$ride['meeting_lng'], 'push_rides_near') as $uid) {
            if ($uid === (int)$ride['organizer_user_id']) {
                continue;
            }
            $lang = (string)(dbOne('SELECT locale FROM users WHERE id = ?', [$uid])['locale'] ?? 'de');
            notifyUser($uid, (int)$ride['organizer_user_id'], 'ride_near', rideUrl($ride), ['title' => $ride['title'], 'where' => $ride['meeting_point']], false);
            enqueuePush($uid, tl($lang, 'push.ride_near_title'), tl($lang, 'push.ride_near_body', ['title' => $ride['title'], 'when' => utcToLocal($ride['starts_at'])->format($lang === 'de' ? 'd.m. H:i' : 'j M, H:i'),
                'where' => $ride['meeting_point']]), rideUrl($ride), 'ride' . (int)$ride['id']);
        }
        pushAfterResponse();
    } catch (Throwable $ex) {
        error_log('ElTouro ride nearby: ' . $ex->getMessage());
    }
}

/** Cron: rides starting within the next three hours, to riders nearby who asked for that – once per ride and rider */
function sendRideSoonAlerts(): int
{
    $n = 0;
    foreach (dbAll("SELECT * FROM rides WHERE deleted_at IS NULL AND status = 'planned' AND meeting_lat IS NOT NULL
                     AND starts_at BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP() + INTERVAL 3 HOUR") as $ride) {
        $crew = $ride['group_id'] !== null ? ['id' => (int)$ride['group_id']] : null;
        if ($ride['visibility'] === 'group' && $crew === null) {
            continue;
        }
        foreach (ridersNear((float)$ride['meeting_lat'], (float)$ride['meeting_lng'], 'push_rides_soon', $ride['visibility'] === 'group' ? $crew : null) as $uid) {
            if (dbOne('SELECT 1 AS x FROM ride_alerts WHERE ride_id = ? AND user_id = ?', [$ride['id'], $uid]) !== null) {
                continue;
            }
            dbExec('INSERT IGNORE INTO ride_alerts (ride_id, user_id) VALUES (?, ?)', [$ride['id'], $uid]);
            $lang = (string)(dbOne('SELECT locale FROM users WHERE id = ?', [$uid])['locale'] ?? 'de');
            enqueuePush($uid, tl($lang, 'push.ride_soon_title'), tl($lang, 'push.ride_soon_body', ['title' => $ride['title'], 'when' => utcToLocal($ride['starts_at'])->format('H:i'),
                'where' => $ride['meeting_point']]), rideUrl($ride), 'soon' . (int)$ride['id']);
            $n++;
        }
    }
    return $n;
}
