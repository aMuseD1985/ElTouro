<?php
/**
 * Einmal-Tokens (E-Mail-Bestätigung, Passwort-Reset) und Konto-Mails.
 * In der DB liegt nur der SHA-256-Hash; der Klartext steht ausschließlich im Mail-Link.
 */
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

function erzeugeToken(int $userId, string $zweck, int $stunden): string
{
    // Ältere offene Tokens desselben Zwecks entwerten
    ausfuehren('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $zweck]);
    $roh = bin2hex(random_bytes(32));
    ausfuehren('INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR)',
        [$userId, $zweck, hash('sha256', $roh), $stunden]);
    return $roh;
}

/** Prüft ein Token und verbraucht es. Gibt die user_id zurück oder null. */
function loeseTokenEin(string $roh, string $zweck): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $roh)) {
        return null;
    }
    $r = einzeln('SELECT id, user_id FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [hash('sha256', $roh), $zweck]);
    if ($r === null) {
        return null;
    }
    // Atomar verbrauchen – zwei gleichzeitige Klicks können nicht beide gewinnen
    if (ausfuehren('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL', [$r['id']]) !== 1) {
        return null;
    }
    return (int)$r['user_id'];
}

/** Prüft ein Token, ohne es zu verbrauchen (für das Formular "neues Passwort"). */
function pruefeToken(string $roh, string $zweck): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $roh)) {
        return null;
    }
    $r = einzeln('SELECT user_id FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [hash('sha256', $roh), $zweck]);
    return $r ? (int)$r['user_id'] : null;
}

function sendeKontoMail(string $an, string $betreffKey, string $textKey, array $vars): void
{
    $text = t($textKey, $vars);
    $html = '<div style="font-family:Arial,sans-serif;font-size:16px;line-height:1.5;color:#14263F;max-width:560px">'
          . '<p style="font-size:22px;font-weight:bold;font-style:italic;margin:0 0 16px">ElTouro</p>'
          . preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#2F5E8C">$1</a>', nl2br(e($text)))
          . '</div>';
    sendeMail($an, t($betreffKey), $html, $text);
}
