<?php
/**
 * In-app notifications (the bell) and the optional mail digest.
 * A notification stores only a type, a link and a few variables (names, titles); the text is built when it is shown, in the
 * reader's language. Nobody is notified about their own actions. Mail digests are off unless the rider switches them on
 * (profile) and go out via digest.php (cron).
 */
declare(strict_types=1);

const NOTIFICATION_KEEP_DAYS = 90;

function notifyUser(int $to, int $from, string $type, string $link, array $vars = [], bool $push = true): void
{
    if ($to === $from || $to <= 0) {
        return;
    }
    try {
        // The same thing twice in a row (several replies in one thread) stays one unread entry
        $open = dbOne('SELECT id FROM notifications WHERE user_id = ? AND type = ? AND link = ? AND read_at IS NULL', [$to, $type, $link]);
        if ($open !== null) {
            dbExec('UPDATE notifications SET created_at = UTC_TIMESTAMP(), vars = ? WHERE id = ?', [json_encode($vars, JSON_UNESCAPED_UNICODE), $open['id']]);
            return;
        }
        dbExec('INSERT INTO notifications (user_id, type, link, vars) VALUES (?, ?, ?, ?)', [$to, $type, mb_substr($link, 0, 200), json_encode($vars, JSON_UNESCAPED_UNICODE)]);
        if ($push) {
            pushForNotification($to, $type, $link, $vars);
        }
        if (random_int(1, 50) === 1) {
            dbExec('DELETE FROM notifications WHERE created_at < UTC_TIMESTAMP() - INTERVAL ' . NOTIFICATION_KEEP_DAYS . ' DAY');
        }
    } catch (Throwable $ex) {
        error_log('ElTouro notification: ' . $ex->getMessage());   // never break the action itself
    }
}

/** @param int[] $userIds */
function notifyMany(array $userIds, int $from, string $type, string $link, array $vars = []): void
{
    foreach (array_unique(array_map('intval', $userIds)) as $id) {
        notifyUser($id, $from, $type, $link, $vars);
    }
}

/** Riders named with @Name in a text. With $crewId only active members of that crew count. @return int[] */
function mentionedUsers(string $text, int $authorId, ?int $crewId = null): array
{
    if (!preg_match_all('/(?<![\w@])@([\p{L}\p{N}._-]{3,30})/u', $text, $m)) {
        return [];
    }
    $out = [];
    foreach (array_slice(array_unique($m[1]), 0, 10) as $name) {
        $u = dbOne("SELECT id FROM users WHERE display_name = ? AND status = 'active'", [rtrim($name, '.-_')]);
        if ($u === null || (int)$u['id'] === $authorId) {
            continue;
        }
        if ($crewId !== null && dbOne("SELECT 1 AS x FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'active'", [$crewId, $u['id']]) === null) {
            continue;
        }
        $out[] = (int)$u['id'];
    }
    return $out;
}

function displayNameOf(int $userId): string
{
    return (string)(dbOne('SELECT display_name FROM users WHERE id = ?', [$userId])['display_name'] ?? '');
}

function unreadNotificationCount(int $userId): int
{
    static $cache = [];
    try {
        return $cache[$userId] ??= (int)dbOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId])['n'];
    } catch (Throwable $ex) {
        return 0;   // table not there yet (migration pending)
    }
}

/** Text of a stored notification in the given language. */
function notificationText(array $n, ?string $lang = null): string
{
    $vars = json_decode((string)$n['vars'], true) ?: [];
    return $lang === null ? t('notif.' . $n['type'], $vars) : tl($lang, 'notif.' . $n['type'], $vars);
}

/** Planned rides in the next three weeks near the rider's home area that no mail has mentioned yet (only if the rider asked for them) */
function ridesNearForDigest(int $userId): array
{
    require_once __DIR__ . '/push_lib.php';
    $p = notifyPrefs($userId);
    if (!$p['mail_rides_near'] || $p['home_lat'] === null) {
        return [];
    }
    return dbAll("SELECT r.id, r.title, r.starts_at, r.meeting_point FROM rides r
                   WHERE r.deleted_at IS NULL AND r.status = 'planned' AND r.meeting_lat IS NOT NULL AND r.organizer_user_id <> ?
                     AND r.starts_at BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP() + INTERVAL 21 DAY
                     AND (r.visibility = 'public' OR EXISTS (SELECT 1 FROM group_members g WHERE g.group_id = r.group_id AND g.user_id = ? AND g.status = 'active'))
                     AND 6371 * 2 * ASIN(SQRT(POW(SIN(RADIANS(r.meeting_lat - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(r.meeting_lat)) * POW(SIN(RADIANS(r.meeting_lng - ?) / 2), 2))) <= ?
                     AND NOT EXISTS (SELECT 1 FROM digest_rides d WHERE d.user_id = ? AND d.ride_id = r.id)
                   ORDER BY r.starts_at LIMIT 8",
        [$userId, $userId, $p['home_lat'], $p['home_lat'], $p['home_lng'], $p['radius_km'], $userId]);
}

/** Sends the digest mails that are due. @return int number of mails */
function sendDigests(): int
{
    require_once __DIR__ . '/account_lib.php';
    $sent = 0;
    foreach (dbAll("SELECT id, email, display_name, locale, mail_digest, digest_sent_at FROM users
                     WHERE status = 'active' AND email_verified_at IS NOT NULL AND mail_digest IN ('daily', 'weekly')") as $u) {
        $every = $u['mail_digest'] === 'weekly' ? 6.5 * 86400 : 20 * 3600;
        if ($u['digest_sent_at'] !== null && time() - strtotime($u['digest_sent_at'] . ' UTC') < $every) {
            continue;
        }
        $rows = dbAll('SELECT type, link, vars FROM notifications WHERE user_id = ? AND read_at IS NULL AND created_at > ? ORDER BY id DESC LIMIT 15',
            [$u['id'], $u['digest_sent_at'] ?? gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        $rides = ridesNearForDigest((int)$u['id']);
        if (!$rows && !$rides) {
            continue;
        }
        // Everything in ONE mail: what happened, and what is planned nearby
        $list = '';
        foreach ($rows as $n) {
            $list .= '• ' . notificationText($n, $u['locale']) . "\n  " . baseUrl() . $n['link'] . "\n";
        }
        if ($rides) {
            $list .= ($list !== '' ? "\n" : '') . tl($u['locale'], 'mail.digest_rides_head') . "\n";
            foreach ($rides as $r) {
                $list .= '• ' . tl($u['locale'], 'mail.digest_ride_line', ['title' => $r['title'], 'when' => utcToLocal($r['starts_at'])->format($u['locale'] === 'de' ? 'd.m. H:i' : 'j M, H:i'),
                    'where' => $r['meeting_point']]) . "\n  " . baseUrl() . rideUrl($r) . "\n";
            }
        }
        $count = count($rows) + count($rides);
        $text = tl($u['locale'], 'mail.digest_text', ['name' => $u['display_name'], 'n' => $count, 'list' => $list, 'link' => baseUrl() . '/profile#notifications']);
        try {
            sendMail($u['email'], tl($u['locale'], 'mail.digest_subject', ['n' => $count]), mailHtml($text), $text);
            dbExec('UPDATE users SET digest_sent_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
            foreach ($rides as $r) {
                dbExec('INSERT IGNORE INTO digest_rides (user_id, ride_id) VALUES (?, ?)', [$u['id'], $r['id']]);
            }
            $sent++;
        } catch (Throwable $ex) {
            error_log('ElTouro digest: ' . $ex->getMessage());
        }
    }
    return $sent;
}


/** The same news as a push message, if the rider has a device registered and wants social pushes (default: yes once push is on) */
function pushForNotification(int $to, string $type, string $link, array $vars): void
{
    require_once __DIR__ . '/push_lib.php';
    if (dbOne('SELECT 1 AS x FROM push_subscriptions WHERE user_id = ? LIMIT 1', [$to]) === null || !notifyPrefs($to)['push_social']) {
        return;
    }
    $lang = (string)(dbOne('SELECT locale FROM users WHERE id = ?', [$to])['locale'] ?? 'de');
    enqueuePush($to, 'ElTouro', tl($lang, 'notif.' . $type, $vars), $link, 'n-' . $type);
    pushAfterResponse();
}
