<?php
/**
 * Live positions: riders who switch on "share live location" in the ride mode can be seen by others.
 *
 * Who sees whom is decided here and nowhere else:
 *  - scope 'crews': only riders who share at least one active crew with the rider
 *  - scope 'all':   every logged-in ElTouro rider
 * Only the latest position is kept (no trail). It is invisible until the rider is LIVE_PRIVACY_METERS away from where
 * sharing started (privacy zone around the start – often someone's home), and it is deleted when the rider stops or
 * after LIVE_STALE_SECONDS without an update.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';

const LIVE_SCOPES = ['crews', 'all'];
const LIVE_PRIVACY_METERS = 300;
const LIVE_STALE_SECONDS = 120;
const LIVE_MIN_INTERVAL = 4;    // seconds between updates that are stored

function updateLivePosition(int $userId, float $lat, float $lng, ?float $heading, ?float $speedKmh, string $scope, ?int $tourId): bool
{
    if (!isValidCoordinate($lat, $lng) || !in_array($scope, LIVE_SCOPES, true)) {
        return false;
    }
    $row = dbOne('SELECT origin_lat, origin_lng, visible, TIMESTAMPDIFF(SECOND, updated_at, UTC_TIMESTAMP()) AS age FROM live_positions WHERE user_id = ?', [$userId]);
    if ($row !== null && (int)$row['age'] < LIVE_MIN_INTERVAL && (int)$row['age'] < LIVE_STALE_SECONDS) {
        return true;   // too soon – nothing to do
    }
    if ($row === null || (int)$row['age'] >= LIVE_STALE_SECONDS) {
        // a new sharing session: its first position is the centre of the privacy zone
        dbExec('REPLACE INTO live_positions (user_id, lat, lng, heading, speed_kmh, scope, tour_id, origin_lat, origin_lng, visible, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())',
            [$userId, round($lat, 5), round($lng, 5), $heading, $speedKmh, $scope, $tourId, round($lat, 5), round($lng, 5)]);
        return true;
    }
    $visible = (int)$row['visible'] === 1 || distanceMeters((float)$row['origin_lat'], (float)$row['origin_lng'], $lat, $lng) >= LIVE_PRIVACY_METERS;
    dbExec('UPDATE live_positions SET lat = ?, lng = ?, heading = ?, speed_kmh = ?, scope = ?, tour_id = ?, visible = ?, updated_at = UTC_TIMESTAMP()
             WHERE user_id = ?',
        [round($lat, 5), round($lng, 5), $heading, $speedKmh, $scope, $tourId, $visible ? 1 : 0, $userId]);
    return true;
}

function stopLive(int $userId): void
{
    dbExec('DELETE FROM live_positions WHERE user_id = ?', [$userId]);
}

/**
 * Riders the viewer may see inside a box [south, west, north, east]:
 * [{id, name, crew, lat, lng, heading, age}] – id is only for keeping markers apart on the map.
 */
function visibleLiveRiders(int $viewerId, array $box): array
{
    // Forgotten sessions (closed tab, dead battery) disappear for good
    dbExec('DELETE FROM live_positions WHERE updated_at < UTC_TIMESTAMP() - INTERVAL ? SECOND', [LIVE_STALE_SECONDS]);
    $rows = dbAll("SELECT lp.user_id, lp.lat, lp.lng, lp.heading, lp.scope, u.display_name,
                          TIMESTAMPDIFF(SECOND, lp.updated_at, UTC_TIMESTAMP()) AS age,
                          (SELECT g.name FROM group_members a
                             JOIN group_members b ON b.group_id = a.group_id AND b.user_id = lp.user_id AND b.status = 'active'
                             JOIN rider_groups g ON g.id = a.group_id AND g.deleted_at IS NULL
                            WHERE a.user_id = ? AND a.status = 'active' ORDER BY g.name LIMIT 1) AS shared_crew
                     FROM live_positions lp JOIN users u ON u.id = lp.user_id AND u.status = 'active'
                    WHERE lp.user_id <> ? AND lp.visible = 1 AND lp.lat BETWEEN ? AND ? AND lp.lng BETWEEN ? AND ?
                    LIMIT 300",
        [$viewerId, $viewerId, $box[0], $box[2], $box[1], $box[3]]);
    $out = [];
    foreach ($rows as $r) {
        if ($r['scope'] === 'crews' && $r['shared_crew'] === null) {
            continue;
        }
        $out[] = ['id' => substr(hash('sha256', $r['user_id'] . '|' . date('Y-m-d') . '|' . $viewerId), 0, 12),
                  'name' => $r['display_name'], 'crew' => $r['shared_crew'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
                  'heading' => $r['heading'] !== null ? (int)$r['heading'] : null, 'age' => (int)$r['age']];
    }
    return $out;
}

/** Whether the rider is sharing right now (for the ride mode after a reload). */
function liveStatus(int $userId): ?array
{
    return dbOne('SELECT scope, visible FROM live_positions WHERE user_id = ? AND updated_at >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
        [$userId, LIVE_STALE_SECONDS]);
}
