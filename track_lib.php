<?php
/**
 * Recorded rides from the ride mode (assets/navigate.js): only when the rider switches recording on.
 *
 * Positions go to track_points in batches; finishing computes distance, moving time and top speed on the
 * server (the browser's numbers are not trusted) and writes the event "ride_recorded" for the reward system.
 * For now only the rider sees their recordings; deleting removes all points for good.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';
require_once __DIR__ . '/rewards_lib.php';

const TRACK_MAX_BATCH = 600;          // points per upload (about 10 minutes at one fix per second)
const TRACK_MAX_POINTS = 40000;       // per ride
const TRACK_MAX_ACCURACY = 50;        // metres – rougher fixes are not used for the distance
const TRACK_MAX_PLAUSIBLE_KMH = 40;   // jumps faster than this are GPS errors, not riding

function startTrack(int $userId, ?int $tourId): int
{
    // A rider records one ride at a time: anything left open (closed tab, empty battery) is finished first
    foreach (dbAll("SELECT id FROM track_sessions WHERE user_id = ? AND status = 'recording'", [$userId]) as $open) {
        finishTrack((int)$open['id'], $userId);
    }
    // The route as it was planned on this day – a later change of the tour must not rewrite the history of the ride
    $planned = $tourId !== null ? (dbOne('SELECT geojson FROM tours WHERE id = ?', [$tourId])['geojson'] ?? null) : null;
    dbExec("INSERT INTO track_sessions (user_id, tour_id, started_at, planned_geojson) VALUES (?, ?, UTC_TIMESTAMP(), ?)", [$userId, $tourId, $planned]);
    return (int)db()->lastInsertId();
}

function loadOwnTrack(int $sessionId, int $userId): ?array
{
    return dbOne('SELECT * FROM track_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
}

/** Stores a batch [[unixMillis, lat, lng, accuracy, speedKmh|null], …]. Returns the number of points kept. */
function addTrackPoints(array $session, array $points): int
{
    if ($session['status'] !== 'recording' || count($points) > TRACK_MAX_BATCH) {
        return 0;
    }
    $seq = (int)$session['point_count'];
    $now = microtime(true) * 1000;
    $rows = [];
    foreach ($points as $p) {
        if (!is_array($p) || count($p) < 4 || $seq >= TRACK_MAX_POINTS) {
            continue;
        }
        [$t, $lat, $lng, $acc] = [(float)$p[0], (float)$p[1], (float)$p[2], (float)$p[3]];
        $speed = isset($p[4]) && is_numeric($p[4]) ? max(0, min(99.9, (float)$p[4])) : null;
        // Points from the future or from before the start are not accepted
        if (!isValidCoordinate($lat, $lng) || $t > $now + 60000 || $t < strtotime($session['started_at'] . ' UTC') * 1000 - 60000) {
            continue;
        }
        $rows[] = [(int)$session['id'], $seq++, gmdate('Y-m-d H:i:s', (int)($t / 1000)) . sprintf('.%03d', (int)$t % 1000),
                   round($lat, 6), round($lng, 6), (int)min(65535, max(0, $acc)), $speed];
    }
    if (!$rows) {
        return 0;
    }
    $sql = 'INSERT IGNORE INTO track_points (session_id, seq, recorded_at, lat, lng, accuracy, speed_kmh) VALUES '
         . implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?)'));
    dbExec($sql, array_merge(...$rows));
    dbExec('UPDATE track_sessions SET point_count = ? WHERE id = ?', [$seq, $session['id']]);
    return count($rows);
}

/** Closes a recording and computes its figures. Rides under 300 m are not worth an event. */
function finishTrack(int $sessionId, int $userId): ?array
{
    $s = loadOwnTrack($sessionId, $userId);
    if ($s === null) {
        return null;
    }
    if ($s['status'] === 'finished') {
        return $s;
    }
    $points = dbAll('SELECT recorded_at, lat, lng, accuracy FROM track_points WHERE session_id = ? AND (accuracy IS NULL OR accuracy <= ?) ORDER BY seq',
        [$sessionId, TRACK_MAX_ACCURACY]);
    $distance = 0.0; $moving = 0.0; $top = 0.0; $prev = null;
    $window = [];   // [time, distance so far] of the last seconds – top speed is measured over at least 5 s, single fixes jitter too much
    foreach ($points as $p) {
        $t = strtotime(substr($p['recorded_at'], 0, 19) . ' UTC') + (float)('0.' . substr($p['recorded_at'], 20, 3));
        if ($prev !== null) {
            $d = distanceMeters((float)$prev['lat'], (float)$prev['lng'], (float)$p['lat'], (float)$p['lng']);
            $dt = $t - $prev['t'];
            if ($dt <= 0 || $d / $dt * 3.6 > TRACK_MAX_PLAUSIBLE_KMH) {
                continue;   // GPS jump: the point is skipped, the next one is compared with the last good one
            }
            $distance += $d;
            if ($d / $dt * 3.6 >= 3 && $dt <= 60) {   // standing at lights is not riding
                $moving += $dt;
            }
        }
        $prev = ['lat' => $p['lat'], 'lng' => $p['lng'], 't' => $t];
        $window[] = [$t, $distance];
        while (count($window) > 2 && $t - $window[1][0] >= 5) {
            array_shift($window);
        }
        if ($t - $window[0][0] >= 5 && $t - $window[0][0] <= 30) {
            $top = max($top, ($distance - $window[0][1]) / ($t - $window[0][0]) * 3.6);
        }
    }
    dbExec("UPDATE track_sessions SET status = 'finished', ended_at = COALESCE((SELECT MAX(recorded_at) FROM track_points WHERE session_id = ?), UTC_TIMESTAMP()),
                   distance_m = ?, moving_s = ?, max_speed_kmh = ? WHERE id = ?",
        [$sessionId, (int)round($distance), (int)round($moving), round(min($top, 99.9), 1), $sessionId]);
    if ($distance >= 300) {
        recordEvent($userId, 'ride_recorded', 'track', $sessionId, null, round($distance / 1000, 2),
            ['tour_id' => $s['tour_id'] !== null ? (int)$s['tour_id'] : null, 'moving_s' => (int)round($moving)]);
    }
    return loadOwnTrack($sessionId, $userId);
}

/** Deletes a recording with all its points; its event no longer counts. */
function deleteTrack(int $sessionId, int $userId): bool
{
    if (loadOwnTrack($sessionId, $userId) === null) {
        return false;
    }
    dbExec('DELETE FROM track_sessions WHERE id = ?', [$sessionId]);   // points go with it (ON DELETE CASCADE)
    dbExec("UPDATE activity_events SET voided_at = UTC_TIMESTAMP(), void_reason = 'recording deleted by rider'
             WHERE type = 'ride_recorded' AND subject_type = 'track' AND subject_id = ? AND voided_at IS NULL", [$sessionId]);
    return true;
}

/** The rider's finished recordings of one tour, newest first. */
function ownTracksOfTour(int $userId, int $tourId): array
{
    return dbAll("SELECT id, started_at, distance_m, moving_s, max_speed_kmh FROM track_sessions
                   WHERE user_id = ? AND tour_id = ? AND status = 'finished' ORDER BY started_at DESC LIMIT 20", [$userId, $tourId]);
}
