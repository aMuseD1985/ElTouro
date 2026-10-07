<?php
/**
 * Touren (Strecken). Zugriffsregeln:
 *  - private: nur der Ersteller
 *  - group:   aktive Mitglieder der zugeordneten Herde (+ Ersteller)
 *  - public:  alle angemeldeten Nutzer
 * Kennzahlen (Länge, Bounding Box, Start) rechnet IMMER der Server aus der Geometrie –
 * Zahlen aus dem Browser werden nicht übernommen.
 */
declare(strict_types=1);
require_once __DIR__ . '/herden_lib.php';

const MAX_WEGPUNKTE = 60;
const MAX_TRACKPUNKTE = 20000;

function ladeTour(int $id): ?array
{
    return einzeln('SELECT t.*, u.display_name AS ersteller, g.name AS herde_name, g.slug AS herde_slug
                      FROM tours t JOIN users u ON u.id = t.owner_user_id
                      LEFT JOIN rider_groups g ON g.id = t.owner_group_id AND g.deleted_at IS NULL
                     WHERE t.id = ? AND t.deleted_at IS NULL', [$id]);
}

function darfTourSehen(array $tour, int $userId): bool
{
    if ((int)$tour['owner_user_id'] === $userId || $tour['visibility'] === 'public') {
        return true;
    }
    if ($tour['visibility'] === 'group' && $tour['owner_group_id']) {
        return istAktivesMitglied(mitgliedschaft((int)$tour['owner_group_id'], $userId));
    }
    return false;
}

function darfTourBearbeiten(array $tour, int $userId): bool
{
    if ((int)$tour['owner_user_id'] === $userId) {
        return true;
    }
    // Leitstiere dürfen Touren ihrer Herde pflegen
    return $tour['owner_group_id'] && istLeitstier(mitgliedschaft((int)$tour['owner_group_id'], $userId));
}

/** Herden, denen der Nutzer eine Tour zuordnen darf (aktive Mitgliedschaft). */
function meineHerdenFuerTouren(int $userId): array
{
    return alle("SELECT g.id, g.name FROM group_members m JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                  WHERE m.user_id = ? AND m.status = 'active' ORDER BY g.name", [$userId]);
}

function regelwerkHeute(): string
{
    return gmdate('Y-m-d') >= '2027-03-01' ? 'ekfv2027' : 'ekfv';
}

function abstandMeter(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371008.8;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

function gueltigeKoordinate(mixed $lat, mixed $lng): bool
{
    return is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180;
}

/**
 * Prüft Wegpunkte ([[lat,lng], …]) und gibt sie bereinigt zurück oder null.
 */
function pruefeWegpunkte(mixed $punkte): ?array
{
    if (!is_array($punkte) || count($punkte) < 2 || count($punkte) > MAX_WEGPUNKTE) {
        return null;
    }
    $sauber = [];
    foreach ($punkte as $p) {
        if (!is_array($p) || count($p) < 2 || !gueltigeKoordinate($p[0], $p[1])) {
            return null;
        }
        $sauber[] = [round((float)$p[0], 6), round((float)$p[1], 6)];
    }
    return $sauber;
}

/**
 * Prüft die Routengeometrie: GeoJSON-FeatureCollection aus LineStrings mit
 * properties.freihand (bool) je Abschnitt. Gibt Kennzahlen zurück oder null.
 * @return array{geojson:string, distanz:int, freihand_pct:int, start:array, bbox:array}|null
 */
function pruefeGeometrie(string $json): ?array
{
    if (strlen($json) > 3_000_000) {
        return null;
    }
    $fc = json_decode($json, true);
    if (!is_array($fc) || ($fc['type'] ?? '') !== 'FeatureCollection' || !is_array($fc['features'] ?? null) || !$fc['features']) {
        return null;
    }
    $gesamt = 0.0; $freihand = 0.0; $anzahl = 0;
    $bbox = [90.0, 180.0, -90.0, -180.0];
    $start = null;
    $sauber = ['type' => 'FeatureCollection', 'features' => []];

    foreach ($fc['features'] as $f) {
        $koord = $f['geometry']['coordinates'] ?? null;
        if (($f['geometry']['type'] ?? '') !== 'LineString' || !is_array($koord) || count($koord) < 2) {
            return null;
        }
        $istFrei = !empty($f['properties']['freihand']);
        $linie = [];
        $vorher = null;
        foreach ($koord as $k) {
            if (!is_array($k) || !gueltigeKoordinate($k[1] ?? null, $k[0] ?? null) || ++$anzahl > MAX_TRACKPUNKTE) {
                return null;
            }
            $lng = round((float)$k[0], 6); $lat = round((float)$k[1], 6);
            $punkt = [$lng, $lat];
            if (isset($k[2]) && is_numeric($k[2])) {
                $punkt[] = round((float)$k[2], 1);   // Höhe, falls vom Router geliefert
            }
            $linie[] = $punkt;
            $start ??= [$lat, $lng];
            $bbox = [min($bbox[0], $lat), min($bbox[1], $lng), max($bbox[2], $lat), max($bbox[3], $lng)];
            if ($vorher) {
                $d = abstandMeter($vorher[1], $vorher[0], $lat, $lng);
                $gesamt += $d;
                if ($istFrei) { $freihand += $d; }
            }
            $vorher = $punkt;
        }
        $sauber['features'][] = ['type' => 'Feature', 'properties' => ['freihand' => $istFrei],
                                 'geometry' => ['type' => 'LineString', 'coordinates' => $linie]];
    }

    return [
        'geojson'      => json_encode($sauber, JSON_UNESCAPED_SLASHES),
        'distanz'      => (int)round($gesamt),
        'freihand_pct' => $gesamt > 0 ? (int)round($freihand / $gesamt * 100) : 100,
        'start'        => $start,
        'bbox'         => $bbox,
    ];
}

/** Summe der Anstiege aus den Höhenwerten (nur wenn der Router Höhen geliefert hat). */
function berechneAnstieg(string $geojson): ?int
{
    $fc = json_decode($geojson, true);
    $summe = 0.0; $hatHoehe = false;
    foreach ($fc['features'] ?? [] as $f) {
        $ref = null;
        foreach ($f['geometry']['coordinates'] as $k) {
            if (!isset($k[2])) { continue; }
            $hatHoehe = true;
            // Hysterese: erst ab 3 m zählen, sonst summiert sich das Messrauschen auf
            if ($ref === null || $k[2] < $ref) { $ref = $k[2]; }
            elseif ($k[2] - $ref >= 3) { $summe += $k[2] - $ref; $ref = $k[2]; }
        }
    }
    return $hatHoehe ? (int)round($summe) : null;
}

function formatiereKm(int $meter): string
{
    global $LANG;
    return number_format($meter / 1000, 1, $LANG === 'de' ? ',' : '.', $LANG === 'de' ? '.' : ',') . ' km';
}
