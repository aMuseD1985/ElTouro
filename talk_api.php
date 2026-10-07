<?php
/**
 * JSON interface of the crew talk. All calls: logged in, active crew member, POST with X-CSRF header
 * (read calls too – that keeps the check uniform in one place).
 *
 * action=topics   {crew, offset}          → {html, offset, more}
 * action=posts    {topic, before|after}   → {html, more}
 * action=new      {topic, since}          → {count}
 * action=reply    {topic, text, reply_to} → {html, id}
 * action=like     {post}                  → {liked, count}
 * action=delete   {post}                  → {html}
 * action=read     {topic, until}          → {ok}
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/talk_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$me = currentUser();
if ($me === null) json(401, ['error' => 'login']);
if (!isPost() || !checkCsrfHeader()) json(400, ['error' => 'csrf']);
$uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($_GET['action'] ?? '');

/** Load topic and check membership */
function topicWithAccess(int $id, array $me): array
{
    $topic = loadTalkTopic($id);
    $crew = $topic ? loadCrew($topic['crew_slug']) : null;
    [$allowed, $mod] = $crew ? talkAccess($crew, $me) : [false, false];
    if (!$allowed) json(404, ['error' => 'not_found']);
    return [$topic, $mod];
}

/** Load post + topic and check membership */
function postWithAccess(int $id, array $me): array
{
    $p = dbOne('SELECT * FROM herd_posts WHERE id = ?', [$id]);
    if ($p === null) json(404, ['error' => 'not_found']);
    [$topic, $mod] = topicWithAccess((int)$p['topic_id'], $me);
    return [$p, $topic, $mod];
}

function renderList(array $posts, int $uid, bool $mod): string
{
    return implode('', array_map(fn($p) => renderTalkPost($p, $uid, $mod), $posts));
}

switch ($action) {
    case 'topics':
        $crew = loadCrew((string)($in['crew'] ?? ''));
        [$allowed] = $crew ? talkAccess($crew, $me) : [false];
        if (!$allowed) json(404, ['error' => 'not_found']);
        $offset = max(0, (int)($in['offset'] ?? 0));
        $list = loadTalkTopics((int)$crew['id'], $uid, $offset);
        json(200, ['html' => implode('', array_map('renderTalkTopicRow', $list)),
                   'offset' => $offset + count($list), 'more' => count($list) === TALK_TOPICS_PAGE_SIZE]);

    case 'posts':
        [$topic, $mod] = topicWithAccess((int)($in['topic'] ?? 0), $me);
        if (isset($in['before'])) {
            $list = loadTalkPosts((int)$topic['id'], $uid, 'p.id < ?', [(int)$in['before']], true);
        } else {
            $list = loadTalkPosts((int)$topic['id'], $uid, 'p.id > ?', [(int)($in['after'] ?? 0)]);
        }
        json(200, ['html' => renderList($list, $uid, $mod), 'more' => count($list) === TALK_PAGE_SIZE]);

    case 'new':
        [$topic] = topicWithAccess((int)($in['topic'] ?? 0), $me);
        $n = (int)dbOne('SELECT COUNT(*) AS n FROM herd_posts WHERE topic_id = ? AND id > ? AND user_id <> ?',
            [$topic['id'], (int)($in['since'] ?? 0), $uid])['n'];
        json(200, ['count' => $n]);

    case 'reply':
        [$topic, $mod] = topicWithAccess((int)($in['topic'] ?? 0), $me);
        if ($topic['is_locked'] && !$mod) json(403, ['error' => t('forum.locked_text')]);
        $text = trim(str_replace("\r", '', (string)($in['text'] ?? '')));
        if (mb_strlen($text) < 1 || mb_strlen($text) > TALK_POST_MAX) json(422, ['error' => t('forum.error_text', ['max' => TALK_POST_MAX])]);
        if (!canTalkNow($uid)) json(429, ['error' => t('forum.too_fast')]);
        $replyTo = (int)($in['reply_to'] ?? 0);
        if ($replyTo && !dbOne('SELECT 1 AS x FROM herd_posts WHERE id = ? AND topic_id = ?', [$replyTo, $topic['id']])) {
            $replyTo = 0;   // reply reference only within the same topic
        }
        $pid = addTalkPost((int)$topic['id'], $uid, $text, $replyTo ?: null);
        $new = loadTalkPosts((int)$topic['id'], $uid, 'p.id = ?', [$pid]);
        json(200, ['html' => renderList($new, $uid, $mod), 'id' => $pid]);

    case 'like':
        [$p] = postWithAccess((int)($in['post'] ?? 0), $me);
        if ($p['deleted_at']) json(410, ['error' => 'deleted']);
        $pdo = db();
        $pdo->beginTransaction();
        if (dbExec('DELETE FROM herd_reactions WHERE post_id = ? AND user_id = ?', [$p['id'], $uid])) {
            dbExec('UPDATE herd_posts SET like_count = GREATEST(like_count, 1) - 1 WHERE id = ?', [$p['id']]);
            $liked = false;
        } else {
            dbExec('INSERT INTO herd_reactions (post_id, user_id) VALUES (?, ?)', [$p['id'], $uid]);
            dbExec('UPDATE herd_posts SET like_count = like_count + 1 WHERE id = ?', [$p['id']]);
            $liked = true;
        }
        $count = (int)dbOne('SELECT like_count FROM herd_posts WHERE id = ?', [$p['id']])['like_count'];
        $pdo->commit();
        json(200, ['liked' => $liked, 'count' => $count]);

    case 'delete':
        [$p, $topic, $mod] = postWithAccess((int)($in['post'] ?? 0), $me);
        if ((int)$p['user_id'] !== $uid && !$mod) json(403, ['error' => 'forbidden']);
        dbExec('UPDATE herd_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$p['id']]);
        $new = loadTalkPosts((int)$topic['id'], $uid, 'p.id = ?', [(int)$p['id']]);
        json(200, ['html' => renderList($new, $uid, $mod)]);

    case 'read':
        [$topic] = topicWithAccess((int)($in['topic'] ?? 0), $me);
        $until = (int)($in['until'] ?? 0);
        // Only accept IDs that really exist in this topic
        $until = (int)(dbOne('SELECT MAX(id) AS m FROM herd_posts WHERE topic_id = ? AND id <= ?', [$topic['id'], $until])['m'] ?? 0);
        if ($until > 0) {
            markTalkRead((int)$topic['id'], $uid, $until);
        }
        json(200, ['ok' => true]);
}
json(400, ['error' => 'action']);
