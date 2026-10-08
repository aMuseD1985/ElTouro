<?php
/**
 * Reward system – stage 1 "foundation" (concept: docs/rewards.md).
 * Stores FACTS only: where a new rider came from (referrals) and what happened (activity_events).
 * Points, ranks and badges will be derived from these later; rules may change and be recomputed.
 *
 * Referral attribution is first touch: the first invite/share/crew link someone arrives through is
 * remembered in the existing session (no extra cookie) and stored when the account is created.
 */
declare(strict_types=1);

const REFERRAL_CHANNELS = ['whatsapp', 'telegram', 'facebook', 'x', 'email', 'copy', 'native'];

/** Channel from the ?via= parameter of our own links; anything else is "unknown". */
function referralChannel(mixed $via): string
{
    return is_string($via) && in_array($via, REFERRAL_CHANNELS, true) ? $via : 'unknown';
}

/**
 * Remembers where a visitor came from – only for visitors who are not logged in, and only the first time.
 * @param string $source invite_link | share_link | crew_invite
 */
function rememberReferral(string $source, ?int $referrerId, string $refType, int $refId, mixed $via): void
{
    if (currentUser() !== null || !empty($_SESSION['referral'])) {
        return;
    }
    $_SESSION['referral'] = ['source' => $source, 'referrer' => $referrerId, 'channel' => referralChannel($via),
                             'ref_type' => $refType, 'ref_id' => $refId, 'at' => time()];
}

/**
 * Stores the remembered origin for a new account. A previous row is replaced: it can only exist for an
 * unconfirmed account that is being taken over (Google sign-up) and must not credit whoever created it.
 */
function storeReferral(int $newUserId): void
{
    $r = $_SESSION['referral'] ?? null;
    unset($_SESSION['referral']);
    dbExec('DELETE FROM referrals WHERE user_id = ?', [$newUserId]);
    if (!is_array($r)) {
        return;
    }
    $referrer = $r['referrer'] !== null ? (int)$r['referrer'] : null;
    if ($referrer === $newUserId || ($referrer !== null && !dbOne("SELECT id FROM users WHERE id = ? AND status = 'active'", [$referrer]))) {
        $referrer = null;   // no self-referral, no credit for blocked or deleted accounts
    }
    dbExec('INSERT INTO referrals (user_id, referrer_user_id, source, channel, ref_type, ref_id) VALUES (?, ?, ?, ?, ?, ?)',
        [$newUserId, $referrer, $r['source'], $r['channel'], $r['ref_type'], $r['ref_id']]);
}

/** Writes an event once (idempotent per type, user and subject). Never throws into the user's action. */
function recordEvent(int $userId, string $type, string $subjectType, int $subjectId, ?int $relatedUserId = null, ?float $value = null, array $meta = []): void
{
    try {
        dbExec('INSERT IGNORE INTO activity_events (user_id, type, subject_type, subject_id, related_user_id, value, meta, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$userId, $type, $subjectType, $subjectId, $relatedUserId, $value, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $ex) {
        error_log('ElTouro event ' . $type . ': ' . $ex->getMessage());
    }
}

/** A confirmed account: event for the rider and – if someone brought them – for the referrer. */
function recordVerification(int $userId): void
{
    $r = dbOne('SELECT referrer_user_id, source, channel FROM referrals WHERE user_id = ?', [$userId]);
    recordEvent($userId, 'user_verified', 'user', $userId, null, null, $r ? ['source' => $r['source'], 'channel' => $r['channel']] : []);
    if ($r && $r['referrer_user_id'] !== null) {
        recordEvent((int)$r['referrer_user_id'], 'referral_verified', 'user', $userId, $userId, null,
            ['source' => $r['source'], 'channel' => $r['channel']]);
    }
}

/** Personal invite code (created on first use). */
function userInviteCode(int $userId): string
{
    $code = dbOne('SELECT invite_code FROM users WHERE id = ?', [$userId])['invite_code'] ?? null;
    while ($code === null) {
        $candidate = substr(str_replace(['+', '/', '='], '', strtolower(base64_encode(random_bytes(12)))), 0, 10);
        if (strlen($candidate) !== 10) {
            continue;
        }
        try {
            dbExec('UPDATE users SET invite_code = ? WHERE id = ? AND invite_code IS NULL', [$candidate, $userId]);
        } catch (PDOException) {
            continue;   // code already taken by someone else – roll again
        }
        $code = dbOne('SELECT invite_code FROM users WHERE id = ?', [$userId])['invite_code'] ?? null;
    }
    return $code;
}

function inviteUrl(string $code): string
{
    return baseUrl() . '/join/' . $code;
}

/** Number of riders who came through this user's links and confirmed their account. */
function confirmedReferralCount(int $userId): int
{
    return (int)dbOne('SELECT COUNT(*) AS n FROM referrals r JOIN users u ON u.id = r.user_id
                        WHERE r.referrer_user_id = ? AND u.email_verified_at IS NOT NULL', [$userId])['n'];
}
