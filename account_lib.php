<?php
/**
 * One-time tokens (email confirmation, password reset) and account emails.
 * Only the SHA-256 hash is stored in the DB; the plain token exists only in the email link.
 */
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

function createToken(int $userId, string $purpose, int $hours): string
{
    // Invalidate older open tokens for the same purpose
    dbExec('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $purpose]);
    $raw = bin2hex(random_bytes(32));
    dbExec('INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR)',
        [$userId, $purpose, hash('sha256', $raw), $hours]);
    return $raw;
}

/** Checks a token and consumes it. Returns the user_id or null. */
function redeemToken(string $raw, string $purpose): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) {
        return null;
    }
    $r = dbOne('SELECT id, user_id FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [hash('sha256', $raw), $purpose]);
    if ($r === null) {
        return null;
    }
    // Consume atomically – two simultaneous clicks cannot both win
    if (dbExec('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL', [$r['id']]) !== 1) {
        return null;
    }
    return (int)$r['user_id'];
}

/** Checks a token without consuming it (for the "new password" form). */
function peekToken(string $raw, string $purpose): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) {
        return null;
    }
    $r = dbOne('SELECT user_id FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [hash('sha256', $raw), $purpose]);
    return $r ? (int)$r['user_id'] : null;
}

function sendAccountMail(string $to, string $subjectKey, string $textKey, array $vars): void
{
    $text = t($textKey, $vars);
    sendMail($to, t($subjectKey), mailHtml($text), $text);
}

/** Wraps a plain-text mail body in the simple ElTouro HTML layout; URLs become links. */
function mailHtml(string $text): string
{
    return '<div style="font-family:Arial,sans-serif;font-size:16px;line-height:1.5;color:#14263F;max-width:560px">'
         . '<p style="font-size:22px;font-weight:bold;font-style:italic;margin:0 0 16px">ElTouro</p>'
         . preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#2F5E8C">$1</a>', nl2br(e($text)))
         . '</div>';
}
