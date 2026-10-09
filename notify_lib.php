<?php
/**
 * In-app notifications (the bell) and the optional mail digest.
 * A notification stores only a type, a link and a few variables (names, titles); the text is built when it is shown, in the
 * reader's language. Nobody is notified about their own actions. Mail digests are off unless the rider switches them on
 * (profile) and go out via digest.php (cron).
 */
declare(strict_types=1);

const NOTIFICATION_KEEP_DAYS = 90;

function notifyUser(int $to, int $from, string $type, string $link, array $vars = []): void
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
        if (!$rows) {
            continue;
        }
        $list = '';
        foreach ($rows as $n) {
            $list .= '• ' . notificationText($n, $u['locale']) . "\n  " . baseUrl() . $n['link'] . "\n";
        }
        $text = tl($u['locale'], 'mail.digest_text', ['name' => $u['display_name'], 'n' => count($rows), 'list' => $list, 'link' => baseUrl() . '/profile#notifications']);
        try {
            sendMail($u['email'], tl($u['locale'], 'mail.digest_subject', ['n' => count($rows)]), mailHtml($text), $text);
            dbExec('UPDATE users SET digest_sent_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
            $sent++;
        } catch (Throwable $ex) {
            error_log('ElTouro digest: ' . $ex->getMessage());
        }
    }
    return $sent;
}
