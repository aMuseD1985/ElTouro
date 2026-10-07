<?php
/**
 * Crew talk ("Tränke") – the conversation inside a crew (Discourse-light).
 * Access: active crew members only. Moderation: the crew's leads and platform admins.
 * Posts are rendered as HTML on the server (escaped) – also when loaded later via fetch.
 */
declare(strict_types=1);
require_once __DIR__ . '/crews_lib.php';

const TALK_PAGE_SIZE = 20;          // posts per load
const TALK_TOPICS_PAGE_SIZE = 20;   // topics per load
const TALK_POST_MAX = 6000;
const TALK_POST_INTERVAL_SEC = 5;

/** @return array{0:bool,1:bool} [may access, is moderator] – no access unless active member. */
function talkAccess(array $crew, array $me): array
{
    $m = membership((int)$crew['id'], (int)$me['id']);
    if (!isActiveMember($m)) {
        return [false, false];
    }
    return [true, isCrewLead($m) || (int)$me['is_admin'] === 1];
}

function loadTalkTopic(int $id): ?array
{
    return dbOne('SELECT t.*, g.slug AS crew_slug, g.name AS crew_name, g.id AS crew_id
                    FROM herd_topics t JOIN rider_groups g ON g.id = t.group_id AND g.deleted_at IS NULL
                   WHERE t.id = ? AND t.deleted_at IS NULL', [$id]);
}

/** Consistent colour per user for the avatar circle */
function avatarColor(int $userId): string
{
    $colors = ['#2F5E8C', '#8A6412', '#2F7D5B', '#7A3E8C', '#A34B2B', '#1F6F7A', '#5B6B2F', '#8C2F4E'];
    return $colors[$userId % count($colors)];
}

function relativeTime(string $utc): string
{
    global $LANG;
    $sec = time() - strtotime($utc . ' UTC');
    if ($sec < 60) return t('time.now');
    if ($sec < 3600) return t('time.min', ['n' => intdiv($sec, 60)]);
    if ($sec < 86400) return t('time.hours', ['n' => intdiv($sec, 3600)]);
    if ($sec < 86400 * 7) return t('time.days', ['n' => intdiv($sec, 86400)]);
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'));
    return $d->format($LANG === 'de' ? 'd.m.Y' : 'j M Y');
}

/**
 * Loads posts with everything the rendering needs.
 * $condition/$params narrow down (e.g. "p.id > ?"), $descending for "load older".
 */
function loadTalkPosts(int $topicId, int $userId, string $condition = '1=1', array $params = [], bool $descending = false, int $limit = TALK_PAGE_SIZE): array
{
    $sort = $descending ? 'DESC' : 'ASC';
    $rows = dbAll("SELECT p.*, u.display_name, (m.role = 'admin') AS is_lead,
                          (SELECT 1 FROM herd_reactions r WHERE r.post_id = p.id AND r.user_id = ?) AS i_like,
                          q.id AS quote_id, qu.display_name AS quote_name, q.body AS quote_text, q.deleted_at AS quote_deleted
                     FROM herd_posts p
                     JOIN users u ON u.id = p.user_id
                     JOIN herd_topics t ON t.id = p.topic_id
                     LEFT JOIN group_members m ON m.group_id = t.group_id AND m.user_id = p.user_id
                     LEFT JOIN herd_posts q ON q.id = p.reply_to_id
                     LEFT JOIN users qu ON qu.id = q.user_id
                    WHERE p.topic_id = ? AND $condition
                    ORDER BY p.id $sort LIMIT " . (int)$limit,
        [$userId, $topicId, ...$params]);
    return $descending ? array_reverse($rows) : $rows;
}

function renderTalkPost(array $p, int $userId, bool $moderator): string
{
    $id = (int)$p['id'];
    $name = (string)$p['display_name'];
    $h = '<article class="tb" id="p' . $id . '" data-id="' . $id . '">';
    $h .= '<div class="tb-avatar" style="background:' . avatarColor((int)$p['user_id']) . '" aria-hidden="true">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</div>';
    $h .= '<div class="tb-body"><header><strong>' . e($name) . '</strong>';
    if (!empty($p['is_lead'])) {
        $h .= ' <span class="badge">' . te('crew.lead') . '</span>';
    }
    $h .= ' <a class="tb-time" href="#p' . $id . '" title="' . e($p['created_at']) . ' UTC">' . e(relativeTime($p['created_at'])) . '</a>';
    if ($p['edited_at']) {
        $h .= ' <span class="muted">· ' . te('talk.edited') . '</span>';
    }
    $h .= '</header>';

    if ($p['deleted_at']) {
        return $h . '<p class="muted"><em>' . te('talk.deleted') . '</em></p></div></article>';
    }
    if ($p['quote_id']) {
        $excerpt = $p['quote_deleted'] ? t('talk.deleted') : mb_strimwidth(preg_replace('/\s+/', ' ', (string)$p['quote_text']), 0, 110, '…');
        $h .= '<a class="tb-quote" href="#p' . (int)$p['quote_id'] . '" data-jump="' . (int)$p['quote_id'] . '">↪ <strong>' . e((string)$p['quote_name']) . '</strong>: ' . e($excerpt) . '</a>';
    }
    $h .= '<div class="tb-text">' . formatText($p['body']) . '</div>';
    $h .= '<footer>';
    $h .= '<button type="button" class="tb-btn tb-like' . ($p['i_like'] ? ' active' : '') . '" data-action="like" aria-pressed="' . ($p['i_like'] ? 'true' : 'false') . '" aria-label="' . te('talk.like') . '">'
        . '<span aria-hidden="true">♥</span> <span class="tb-count">' . ((int)$p['like_count'] ?: '') . '</span></button>';
    $h .= '<button type="button" class="tb-btn" data-action="reply" data-name="' . e($name) . '">' . te('talk.reply') . '</button>';
    $h .= '<a class="tb-btn" href="/report.php?type=herdpost&amp;id=' . $id . '">' . te('report.link') . '</a>';
    if ((int)$p['user_id'] === $userId || $moderator) {
        $h .= '<button type="button" class="tb-btn danger" data-action="delete">' . te('talk.delete') . '</button>';
    }
    return $h . '</footer></div></article>';
}

function canTalkNow(int $userId): bool
{
    $last = dbOne('SELECT MAX(created_at) AS t FROM herd_posts WHERE user_id = ?', [$userId])['t'] ?? null;
    return $last === null || strtotime($last . ' UTC') < time() - TALK_POST_INTERVAL_SEC;
}

/** Topic list of a crew with unread counter */
function loadTalkTopics(int $groupId, int $userId, int $offset): array
{
    return dbAll("SELECT t.id, t.title, t.is_pinned, t.is_locked, t.post_count, t.last_post_at, t.last_post_id,
                         u.display_name AS author, lu.display_name AS last_user,
                         r.last_read_post_id,
                         (SELECT COUNT(*) FROM herd_posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL
                                  AND p.id > COALESCE(r.last_read_post_id, 0) AND p.user_id <> ?) AS unread
                    FROM herd_topics t
                    JOIN users u ON u.id = t.user_id
                    LEFT JOIN users lu ON lu.id = t.last_post_user_id
                    LEFT JOIN herd_reads r ON r.topic_id = t.id AND r.user_id = ?
                   WHERE t.group_id = ? AND t.deleted_at IS NULL
                   ORDER BY t.is_pinned DESC, t.last_post_at DESC, t.id DESC
                   LIMIT " . TALK_TOPICS_PAGE_SIZE . ' OFFSET ' . max(0, $offset), [$userId, $userId, $groupId]);
}

function renderTalkTopicRow(array $t): string
{
    $new = $t['last_read_post_id'] === null;
    $h = '<li class="tt' . ((int)$t['unread'] > 0 ? ' tt-unread' : '') . '">';
    $h .= '<div class="tt-title"><a href="/talk_topic.php?id=' . (int)$t['id'] . '">' . e($t['title']) . '</a>';
    if ($t['is_pinned']) $h .= ' <span class="badge">' . te('forum.pinned') . '</span>';
    if ($t['is_locked']) $h .= ' <span class="badge muted">' . te('forum.locked') . '</span>';
    if ((int)$t['unread'] > 0) {
        $h .= ' <span class="tt-new">' . ($new ? te('talk.new') : te('talk.n_new', ['n' => (int)$t['unread']])) . '</span>';
    }
    $h .= '<p class="muted">' . te('forum.by', ['name' => $t['author']]) . '</p></div>';
    $h .= '<div class="tt-meta"><span>' . te('talk.posts', ['n' => (int)$t['post_count']]) . '</span>'
        . '<span class="muted">' . e(relativeTime($t['last_post_at'])) . ($t['last_user'] ? ' · ' . e($t['last_user']) : '') . '</span></div>';
    return $h . '</li>';
}

/** Total unread posts of a crew (for hints on the crew page) */
function talkUnreadCount(int $groupId, int $userId): int
{
    return (int)dbOne("SELECT COUNT(*) AS n FROM herd_posts p JOIN herd_topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
                         LEFT JOIN herd_reads r ON r.topic_id = t.id AND r.user_id = ?
                        WHERE t.group_id = ? AND p.deleted_at IS NULL AND p.user_id <> ?
                          AND p.id > COALESCE(r.last_read_post_id, 0)", [$userId, $groupId, $userId])['n'];
}

/**
 * Starts a new topic with its first post. Used by the talk page and by rides
 * (each ride announced in a crew gets its own topic). Returns [topicId, postId].
 */
function createTalkTopic(int $groupId, int $userId, string $title, string $body): array
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    dbExec('INSERT INTO herd_topics (group_id, user_id, title, post_count, last_post_at, last_post_user_id) VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), ?)',
        [$groupId, $userId, $title, $userId]);
    $tid = (int)$pdo->lastInsertId();
    dbExec('INSERT INTO herd_posts (topic_id, user_id, body) VALUES (?, ?, ?)', [$tid, $userId, $body]);
    $pid = (int)$pdo->lastInsertId();
    dbExec('UPDATE herd_topics SET last_post_id = ? WHERE id = ?', [$pid, $tid]);
    dbExec('INSERT INTO herd_reads (topic_id, user_id, last_read_post_id) VALUES (?, ?, ?)', [$tid, $userId, $pid]);
    if ($own) {
        $pdo->commit();
    }
    return [$tid, $pid];
}

/** Adds a post to an existing topic and updates the counters. Returns the post id. */
function addTalkPost(int $topicId, int $userId, string $body, ?int $replyTo = null): int
{
    dbExec('INSERT INTO herd_posts (topic_id, user_id, reply_to_id, body) VALUES (?, ?, ?, ?)', [$topicId, $userId, $replyTo, $body]);
    $pid = (int)db()->lastInsertId();
    dbExec('UPDATE herd_topics SET post_count = post_count + 1, last_post_at = UTC_TIMESTAMP(), last_post_id = ?, last_post_user_id = ? WHERE id = ?',
        [$pid, $userId, $topicId]);
    markTalkRead($topicId, $userId, $pid);
    return $pid;
}

function markTalkRead(int $topicId, int $userId, int $postId): void
{
    dbExec('INSERT INTO herd_reads (topic_id, user_id, last_read_post_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_read_post_id = GREATEST(last_read_post_id, VALUES(last_read_post_id))', [$topicId, $userId, $postId]);
}
