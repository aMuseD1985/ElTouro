<?php
/**
 * Access layer for crews. EVERY decision "may X see/change crew Y" goes through here –
 * no visibility queries of their own in the pages.
 *
 * Rules:
 *  - listed: every logged-in user sees name, description, region, member count.
 *  - secret: visible only to members (including pending ones) and to whoever brings the valid invite code.
 *    For everyone else the crew does not exist (404, not 403 – otherwise its existence leaks).
 *  - Member list: active members only.
 *  - Crew lead (role=admin, status=active): settings, requests, managing members.
 */
declare(strict_types=1);

function loadCrew(string $slug): ?array
{
    return dbOne('SELECT * FROM rider_groups WHERE slug = ? AND deleted_at IS NULL', [$slug]);
}

function loadCrewById(int $id): ?array
{
    return dbOne('SELECT * FROM rider_groups WHERE id = ? AND deleted_at IS NULL', [$id]);
}

function membership(int $groupId, int $userId): ?array
{
    return dbOne('SELECT role, status FROM group_members WHERE group_id = ? AND user_id = ?', [$groupId, $userId]);
}

function hasValidInviteCode(array $crew, ?string $code): bool
{
    return $code !== null && $code !== '' && hash_equals($crew['invite_code'], $code);
}

function canSeeCrew(array $crew, ?array $m, ?string $code): bool
{
    if ($crew['discoverability'] === 'listed') {
        return true;
    }
    return $m !== null || hasValidInviteCode($crew, $code);
}

function isActiveMember(?array $m): bool
{
    return $m !== null && $m['status'] === 'active';
}

function isCrewLead(?array $m): bool
{
    return isActiveMember($m) && $m['role'] === 'admin';
}

/**
 * Outcome of a join attempt: 'active', 'pending' or null (not allowed).
 * With a valid invite code you are always in directly – the crew lead shared the link on purpose.
 */
function joinStatus(array $crew, ?string $code): ?string
{
    if (hasValidInviteCode($crew, $code)) {
        return 'active';
    }
    if ($crew['discoverability'] === 'secret') {
        return null;
    }
    return match ($crew['join_policy']) {
        'open'    => 'active',
        'request' => 'pending',
        default   => null,
    };
}

function countCrewLeads(int $groupId): int
{
    return (int)dbOne("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND role = 'admin' AND status = 'active'", [$groupId])['n'];
}

function newInviteCode(): string
{
    return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');   // 24 characters, URL-safe
}

function makeSlug(string $name): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-',
        strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'])), '-'));
    $base = substr($base !== '' ? $base : 'crew', 0, 60);
    $slug = $base;
    for ($i = 2; dbOne('SELECT id FROM rider_groups WHERE slug = ?', [$slug]); $i++) {
        $slug = $base . '-' . $i;
    }
    return $slug;
}

function inviteLink(array $crew): string
{
    return baseUrl() . '/crew/' . rawurlencode($crew['slug']) . '?code=' . rawurlencode($crew['invite_code']);
}

/** Crews the user is an active member of (e.g. to assign a route or ride). */
function activeCrewsOf(int $userId): array
{
    return dbAll("SELECT g.id, g.name, g.slug FROM group_members m JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                   WHERE m.user_id = ? AND m.status = 'active' ORDER BY g.name", [$userId]);
}
