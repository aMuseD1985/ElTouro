<?php
/**
 * Tränke – der Austausch innerhalb einer Herde (Discourse-light).
 * Zugriff: ausschließlich aktive Mitglieder der Herde. Moderation: Leitstiere der Herde und Plattform-Admins.
 * Beiträge werden serverseitig als HTML gerendert (escaped) – auch für Nachladen per fetch.
 */
declare(strict_types=1);
require_once __DIR__ . '/herden_lib.php';

const TRAENKE_SEITE = 20;          // Beiträge pro Nachladen
const TRAENKE_THEMEN_SEITE = 20;   // Themen pro Nachladen
const TRAENKE_MAX = 6000;
const TRAENKE_ABSTAND_SEK = 5;

/** Lädt Herde + Mitgliedschaft; bricht mit 404 ab, wenn der Nutzer kein aktives Mitglied ist. */
function traenkeZugang(array $herde, array $ich): array
{
    $m = mitgliedschaft((int)$herde['id'], (int)$ich['id']);
    if (!istAktivesMitglied($m)) {
        return [false, false];
    }
    return [true, istLeitstier($m) || (int)$ich['is_admin'] === 1];
}

function ladeTraenkeThema(int $id): ?array
{
    return einzeln('SELECT t.*, g.slug AS herde_slug, g.name AS herde_name, g.id AS herde_id
                      FROM herd_topics t JOIN rider_groups g ON g.id = t.group_id AND g.deleted_at IS NULL
                     WHERE t.id = ? AND t.deleted_at IS NULL', [$id]);
}

/** Einheitlicher Farbton pro Nutzer für den Avatar-Kreis */
function avatarFarbe(int $userId): string
{
    $farben = ['#2F5E8C', '#8A6412', '#2F7D5B', '#7A3E8C', '#A34B2B', '#1F6F7A', '#5B6B2F', '#8C2F4E'];
    return $farben[$userId % count($farben)];
}

function relativeZeit(string $utc): string
{
    global $LANG;
    $sek = time() - strtotime($utc . ' UTC');
    if ($sek < 60) return t('zeit.jetzt');
    if ($sek < 3600) return t('zeit.min', ['n' => intdiv($sek, 60)]);
    if ($sek < 86400) return t('zeit.std', ['n' => intdiv($sek, 3600)]);
    if ($sek < 86400 * 7) return t('zeit.tage', ['n' => intdiv($sek, 86400)]);
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'));
    return $d->format($LANG === 'de' ? 'd.m.Y' : 'j M Y');
}

/**
 * Beiträge mit allem laden, was die Darstellung braucht.
 * $bedingung/$parameter grenzen ein (z. B. "p.id > ?"), $absteigend für "ältere nachladen".
 */
function ladeTraenkeBeitraege(int $themaId, int $userId, string $bedingung = '1=1', array $parameter = [], bool $absteigend = false, int $limit = TRAENKE_SEITE): array
{
    $sort = $absteigend ? 'DESC' : 'ASC';
    $zeilen = alle("SELECT p.*, u.display_name, (m.role = 'admin') AS ist_leitstier,
                           (SELECT 1 FROM herd_reactions r WHERE r.post_id = p.id AND r.user_id = ?) AS ich_mag,
                           bz.id AS bezug_id, bu.display_name AS bezug_name, bz.body AS bezug_text, bz.deleted_at AS bezug_geloescht
                      FROM herd_posts p
                      JOIN users u ON u.id = p.user_id
                      JOIN herd_topics t ON t.id = p.topic_id
                      LEFT JOIN group_members m ON m.group_id = t.group_id AND m.user_id = p.user_id
                      LEFT JOIN herd_posts bz ON bz.id = p.reply_to_id
                      LEFT JOIN users bu ON bu.id = bz.user_id
                     WHERE p.topic_id = ? AND $bedingung
                     ORDER BY p.id $sort LIMIT " . (int)$limit,
        [$userId, $themaId, ...$parameter]);
    return $absteigend ? array_reverse($zeilen) : $zeilen;
}

function renderBeitrag(array $p, int $userId, bool $moderator): string
{
    $id = (int)$p['id'];
    $name = (string)$p['display_name'];
    $h = '<article class="tb" id="p' . $id . '" data-id="' . $id . '">';
    $h .= '<div class="tb-avatar" style="background:' . avatarFarbe((int)$p['user_id']) . '" aria-hidden="true">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</div>';
    $h .= '<div class="tb-inhalt"><header><strong>' . e($name) . '</strong>';
    if (!empty($p['ist_leitstier'])) {
        $h .= ' <span class="marke-klein">' . te('herde.leitstier') . '</span>';
    }
    $h .= ' <a class="tb-zeit" href="#p' . $id . '" title="' . e($p['created_at']) . ' UTC">' . e(relativeZeit($p['created_at'])) . '</a>';
    if ($p['edited_at']) {
        $h .= ' <span class="leise">· ' . te('traenke.bearbeitet') . '</span>';
    }
    $h .= '</header>';

    if ($p['deleted_at']) {
        return $h . '<p class="leise"><em>' . te('traenke.geloescht') . '</em></p></div></article>';
    }
    if ($p['bezug_id']) {
        $auszug = $p['bezug_geloescht'] ? t('traenke.geloescht') : mb_strimwidth(preg_replace('/\s+/', ' ', (string)$p['bezug_text']), 0, 110, '…');
        $h .= '<a class="tb-bezug" href="#p' . (int)$p['bezug_id'] . '" data-springe="' . (int)$p['bezug_id'] . '">↪ <strong>' . e((string)$p['bezug_name']) . '</strong>: ' . e($auszug) . '</a>';
    }
    $h .= '<div class="tb-text">' . formatiereText($p['body']) . '</div>';
    $h .= '<footer>';
    $h .= '<button type="button" class="tb-knopf tb-like' . ($p['ich_mag'] ? ' aktiv' : '') . '" data-aktion="like" aria-pressed="' . ($p['ich_mag'] ? 'true' : 'false') . '" aria-label="' . te('traenke.gefaellt') . '">'
        . '<span aria-hidden="true">♥</span> <span class="tb-zahl">' . ((int)$p['like_count'] ?: '') . '</span></button>';
    $h .= '<button type="button" class="tb-knopf" data-aktion="antworten" data-name="' . e($name) . '">' . te('traenke.antworten') . '</button>';
    $h .= '<a class="tb-knopf" href="/melden.php?typ=herdpost&amp;id=' . $id . '">' . te('melden.link') . '</a>';
    if ((int)$p['user_id'] === $userId || $moderator) {
        $h .= '<button type="button" class="tb-knopf gefahr" data-aktion="loeschen">' . te('traenke.loeschen') . '</button>';
    }
    return $h . '</footer></div></article>';
}

function darfJetztInTraenkeSchreiben(int $userId): bool
{
    $letzter = einzeln('SELECT MAX(created_at) AS t FROM herd_posts WHERE user_id = ?', [$userId])['t'] ?? null;
    return $letzter === null || strtotime($letzter . ' UTC') < time() - TRAENKE_ABSTAND_SEK;
}

/** Themenliste einer Herde mit Ungelesen-Zähler */
function ladeTraenkeThemen(int $gruppeId, int $userId, int $offset): array
{
    return alle("SELECT t.id, t.title, t.is_pinned, t.is_locked, t.post_count, t.last_post_at, t.last_post_id,
                        u.display_name AS autor, lu.display_name AS letzter,
                        r.last_read_post_id,
                        (SELECT COUNT(*) FROM herd_posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL
                                 AND p.id > COALESCE(r.last_read_post_id, 0) AND p.user_id <> ?) AS ungelesen
                   FROM herd_topics t
                   JOIN users u ON u.id = t.user_id
                   LEFT JOIN users lu ON lu.id = t.last_post_user_id
                   LEFT JOIN herd_reads r ON r.topic_id = t.id AND r.user_id = ?
                  WHERE t.group_id = ? AND t.deleted_at IS NULL
                  ORDER BY t.is_pinned DESC, t.last_post_at DESC, t.id DESC
                  LIMIT " . TRAENKE_THEMEN_SEITE . ' OFFSET ' . max(0, $offset), [$userId, $userId, $gruppeId]);
}

function renderThemaZeile(array $t): string
{
    $neu = $t['last_read_post_id'] === null;
    $h = '<li class="tt' . ((int)$t['ungelesen'] > 0 ? ' tt-ungelesen' : '') . '">';
    $h .= '<div class="tt-titel"><a href="/traenke_thema.php?id=' . (int)$t['id'] . '">' . e($t['title']) . '</a>';
    if ($t['is_pinned']) $h .= ' <span class="marke-klein">' . te('forum.angepinnt') . '</span>';
    if ($t['is_locked']) $h .= ' <span class="marke-klein leise">' . te('forum.gesperrt') . '</span>';
    if ((int)$t['ungelesen'] > 0) {
        $h .= ' <span class="tt-neu">' . ($neu ? te('traenke.neu') : te('traenke.n_neu', ['n' => (int)$t['ungelesen']])) . '</span>';
    }
    $h .= '<p class="leise">' . te('forum.von', ['name' => $t['autor']]) . '</p></div>';
    $h .= '<div class="tt-meta"><span>' . te('traenke.beitraege', ['n' => (int)$t['post_count']]) . '</span>'
        . '<span class="leise">' . e(relativeZeit($t['last_post_at'])) . ($t['letzter'] ? ' · ' . e($t['letzter']) : '') . '</span></div>';
    return $h . '</li>';
}

/** Summe ungelesener Beiträge einer Herde (für Hinweise auf der Herdenseite) */
function traenkeUngelesen(int $gruppeId, int $userId): int
{
    return (int)einzeln("SELECT COUNT(*) AS n FROM herd_posts p JOIN herd_topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
                           LEFT JOIN herd_reads r ON r.topic_id = t.id AND r.user_id = ?
                          WHERE t.group_id = ? AND p.deleted_at IS NULL AND p.user_id <> ?
                            AND p.id > COALESCE(r.last_read_post_id, 0)", [$userId, $gruppeId, $userId])['n'];
}
