<?php
/**
 * Forum: categories (maintained by the admin) → topics → posts.
 * Reading and writing for logged-in users only. Moderating (pin, lock, delete) for platform admins only.
 * Deleting is soft (deleted_at) so moderation decisions stay traceable.
 */
declare(strict_types=1);

const FORUM_POSTS_PER_PAGE = 25;
const FORUM_POST_MAX = 8000;
const FORUM_POST_INTERVAL_SEC = 15;

function categoryName(array $c): string
{
    global $LANG;
    return $LANG === 'en' ? $c['name_en'] : $c['name_de'];
}

function categoryDescription(array $c): string
{
    global $LANG;
    return (string)($LANG === 'en' ? $c['description_en'] : $c['description_de']);
}

function isForumModerator(array $me): bool
{
    return (int)$me['is_admin'] === 1;
}

/** Brake against spam and double clicks */
function canPostNow(int $userId): bool
{
    $last = dbOne('SELECT MAX(created_at) AS t FROM forum_posts WHERE user_id = ?', [$userId])['t'] ?? null;
    return $last === null || strtotime($last . ' UTC') < time() - FORUM_POST_INTERVAL_SEC;
}

/** UTC timestamp from the DB as local date and time (Europe/Berlin). */
function formatDateTime(string $utc): string
{
    global $LANG;
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/Berlin'))->format($LANG === 'de' ? 'd.m.Y, H:i' : 'd M Y, H:i');
}
