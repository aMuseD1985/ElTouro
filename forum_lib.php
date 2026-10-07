<?php
/**
 * Forum: Kategorien (vom Admin gepflegt) → Themen → Beiträge.
 * Lesen und Schreiben nur für angemeldete Nutzer. Moderieren (anpinnen, sperren, löschen) nur Plattform-Admins.
 * Löschen ist weich (deleted_at), damit Moderationsentscheidungen nachvollziehbar bleiben.
 */
declare(strict_types=1);

const BEITRAEGE_PRO_SEITE = 25;
const BEITRAG_MAX = 8000;
const SEKUNDEN_ZWISCHEN_BEITRAEGEN = 15;

function kategorieName(array $k): string
{
    global $LANG;
    return $LANG === 'en' ? $k['name_en'] : $k['name_de'];
}

function kategorieText(array $k): string
{
    global $LANG;
    return (string)($LANG === 'en' ? $k['description_en'] : $k['description_de']);
}

function istModerator(array $ich): bool
{
    return (int)$ich['is_admin'] === 1;
}

/** Bremse gegen Spam und Doppelklicks */
function darfJetztSchreiben(int $userId): bool
{
    $letzter = einzeln('SELECT MAX(created_at) AS t FROM forum_posts WHERE user_id = ?', [$userId])['t'] ?? null;
    return $letzter === null || strtotime($letzter . ' UTC') < time() - SEKUNDEN_ZWISCHEN_BEITRAEGEN;
}

function zeitAnzeige(string $utc): string
{
    global $LANG;
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/Berlin'))->format($LANG === 'de' ? 'd.m.Y, H:i' : 'd M Y, H:i');
}
