<?php
/**
 * Zugriffsschicht für Herden. JEDE Entscheidung "darf X Herde Y sehen/ändern" läuft hier durch –
 * keine eigenen Sichtbarkeitsabfragen in den Seiten.
 *
 * Regeln:
 *  - listed: jeder angemeldete Nutzer sieht Name, Beschreibung, Region, Mitgliederzahl.
 *  - secret: sichtbar nur für Mitglieder (auch wartende) und wer den gültigen Einladungscode mitbringt.
 *    Für alle anderen existiert die Herde nicht (404, kein 403 – sonst verrät man ihre Existenz).
 *  - Mitgliederliste: nur für aktive Mitglieder.
 *  - Leitstier (role=admin, status=active): Einstellungen, Anfragen, Mitglieder verwalten.
 */
declare(strict_types=1);

function ladeHerde(string $slug): ?array
{
    return einzeln('SELECT * FROM rider_groups WHERE slug = ? AND deleted_at IS NULL', [$slug]);
}

function mitgliedschaft(int $gruppeId, int $userId): ?array
{
    return einzeln('SELECT role, status FROM group_members WHERE group_id = ? AND user_id = ?', [$gruppeId, $userId]);
}

function hatGueltigenCode(array $herde, ?string $code): bool
{
    return $code !== null && $code !== '' && hash_equals($herde['invite_code'], $code);
}

function darfHerdeSehen(array $herde, ?array $m, ?string $code): bool
{
    if ($herde['discoverability'] === 'listed') {
        return true;
    }
    return $m !== null || hatGueltigenCode($herde, $code);
}

function istAktivesMitglied(?array $m): bool
{
    return $m !== null && $m['status'] === 'active';
}

function istLeitstier(?array $m): bool
{
    return istAktivesMitglied($m) && $m['role'] === 'admin';
}

/**
 * Ergebnis eines Beitrittsversuchs: 'active', 'pending' oder null (nicht erlaubt).
 * Mit gültigem Einladungscode ist man immer direkt drin – der Leitstier hat den Link ja bewusst geteilt.
 */
function beitrittsStatus(array $herde, ?string $code): ?string
{
    if (hatGueltigenCode($herde, $code)) {
        return 'active';
    }
    if ($herde['discoverability'] === 'secret') {
        return null;
    }
    return match ($herde['join_policy']) {
        'open'    => 'active',
        'request' => 'pending',
        default   => null,
    };
}

function anzahlLeitstiere(int $gruppeId): int
{
    return (int)einzeln("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND role = 'admin' AND status = 'active'", [$gruppeId])['n'];
}

function neuerEinladungscode(): string
{
    return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');   // 24 Zeichen, URL-sicher
}

function erzeugeSlug(string $name): string
{
    $basis = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-',
        strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'])), '-'));
    $basis = substr($basis !== '' ? $basis : 'herde', 0, 60);
    $slug = $basis;
    for ($i = 2; einzeln('SELECT id FROM rider_groups WHERE slug = ?', [$slug]); $i++) {
        $slug = $basis . '-' . $i;
    }
    return $slug;
}

function einladungsLink(array $herde): string
{
    return basisUrl() . '/herde.php?s=' . rawurlencode($herde['slug']) . '&code=' . rawurlencode($herde['invite_code']);
}
