<?php
/**
 * Places (stops) with the community: every OSM place we suggest is remembered as a snapshot in `spots`; riders can add
 * missing places, report corrections ("notes") and confirm each other's notes (the idea of Community Notes: a note counts
 * once different riders found it helpful). The admin adopts notes – an adopted value overlays the OpenStreetMap data for
 * everybody who plans afterwards. Ratings (1–5 stars + short comment) exist for tours and places.
 */
declare(strict_types=1);
require_once __DIR__ . '/talk_lib.php';   // relativeTime()
require_once __DIR__ . '/tours_lib.php';  // isValidCoordinate(), loadTour(), canSeeTour()
require_once __DIR__ . '/rewards_lib.php'; // recordEvent()

/** Kinds a rider may choose for a new place, by type */
const SPOT_KINDS = [
    'charge' => ['charging_station'],
    'food'   => ['cafe', 'restaurant', 'biergarten', 'ice_cream', 'fast_food'],
    'break'  => ['drinking_water', 'toilets'],
    'sight'  => ['viewpoint', 'attraction', 'castle', 'ruins', 'museum'],
];
/** What a note may change: field => max length */
const SPOT_NOTE_FIELDS = ['name' => 80, 'website' => 255, 'opening_hours' => 200, 'phone' => 40, 'note' => 500, 'closed' => 200];
const SPOT_CONFIRMS_NEEDED = 2;   // other riders who must agree before a note shows as "confirmed"
const RATING_TYPES = ['tour', 'spot'];

function loadSpot(int $id): ?array
{
    return dbOne('SELECT * FROM spots WHERE id = ?', [$id]);
}

/** Overlay values (adopted by the admin) decoded */
function spotOverrides(array $spot): array
{
    $o = json_decode((string)($spot['overrides'] ?? ''), true);
    return is_array($o) ? $o : [];
}

/** The data as shown: adopted values replace what OpenStreetMap (or the first rider) said */
function spotEffective(array $spot): array
{
    $o = spotOverrides($spot);
    foreach (['name', 'website', 'opening_hours', 'phone', 'note'] as $f) {
        if (isset($o[$f]) && $o[$f] !== '') {
            $spot[$f] = $o[$f];
        }
    }
    $spot['closed'] = $o['closed'] ?? '';
    return $spot;
}

/** Valid, harmless website: http(s) only */
function cleanWebsite(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 255) : '';
}

/**
 * Remembers the OSM places of a suggestion run (snapshot, refreshed each time) and returns the items as the rider should see
 * them: hidden places dropped, adopted values applied, spot_id and rating added. Items come from suggestStops() ('id' like n123).
 */
function applySpotData(array $items): array
{
    if (!$items) {
        return [];
    }
    $now = gmdate('Y-m-d H:i:s');
    foreach (array_chunk($items, 100) as $chunk) {
        $args = [];
        foreach ($chunk as $it) {
            array_push($args, (string)$it['id'], $it['type'], mb_substr((string)$it['kind'], 0, 24), (string)$it['name'], $it['lat'], $it['lng'],
                $it['opening_hours'] !== '' ? $it['opening_hours'] : null, $now);
        }
        dbExec("INSERT INTO spots (ref, source, status, type, kind, name, lat, lng, opening_hours, seen_at) VALUES "
            . implode(',', array_fill(0, count($chunk), "(?, 'osm', 'approved', ?, ?, ?, ?, ?, ?, ?)"))
            . " ON DUPLICATE KEY UPDATE kind = VALUES(kind), name = VALUES(name), lat = VALUES(lat), lng = VALUES(lng), opening_hours = VALUES(opening_hours), seen_at = VALUES(seen_at)", $args);
    }
    $refs = array_column($items, 'id');
    $spots = [];
    foreach (array_chunk($refs, 200) as $chunk) {
        foreach (dbAll('SELECT * FROM spots WHERE ref IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
            $spots[$row['ref']] = $row;
        }
    }
    $ratings = ratingSummaries('spot', array_map(fn($r) => (int)$r['id'], $spots));
    $out = [];
    foreach ($items as $it) {
        $sp = $spots[$it['id']] ?? null;
        if ($sp === null || (int)$sp['hidden'] === 1) {
            continue;
        }
        $eff = spotEffective($sp);
        $it['spot_id'] = (int)$sp['id'];
        $it['name'] = mb_substr((string)$eff['name'], 0, 80);
        if (!empty($eff['opening_hours'])) {
            $it['opening_hours'] = mb_substr((string)$eff['opening_hours'], 0, 200);
        }
        $it = addSpotExtras($it, $eff, $ratings[(int)$sp['id']] ?? null);
        $out[] = $it;
    }
    return $out;
}

/** website, closed marker, community flag and rating into an item; the rating also moves the score a little */
function addSpotExtras(array $it, array $eff, ?array $rating): array
{
    $it['website'] = (string)($eff['website'] ?? '');
    $it['closed'] = (string)($eff['closed'] ?? '');
    $it['rating'] = $rating ? round($rating['avg'], 1) : null;
    $it['rating_n'] = $rating ? $rating['n'] : 0;
    if ($rating && $rating['n'] >= 2) {
        $it['score'] = round($it['score'] + ($rating['avg'] - 3) * 0.4, 2);
    }
    if ($it['closed'] !== '') {
        $it['score'] -= 3;   // reported as closed or gone: far down the list
    }
    return $it;
}

/** Approved community places near the track (bounding box) as suggestion items; the caller filters by distance to the route. */
function communitySpots(float $minLat, float $minLng, float $maxLat, float $maxLng): array
{
    return dbAll("SELECT * FROM spots WHERE source = 'community' AND status = 'approved' AND hidden = 0 AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?",
        [$minLat, $maxLat, $minLng, $maxLng]);
}

// ---------------------------------------------------------------- notes (community notes)

/** Open notes of a place with their votes. Each: row + helpful, unhelpful, confirmed, mine (the viewer's vote) */
function spotNotes(int $spotId, int $viewerId): array
{
    $rows = dbAll("SELECT r.*, u.display_name,
                     (SELECT COUNT(*) FROM spot_votes v WHERE v.report_id = r.id AND v.vote = 1 AND v.user_id <> r.user_id) AS helpful,
                     (SELECT COUNT(*) FROM spot_votes v WHERE v.report_id = r.id AND v.vote = -1) AS unhelpful,
                     (SELECT vote FROM spot_votes v WHERE v.report_id = r.id AND v.user_id = ?) AS mine
                   FROM spot_reports r JOIN users u ON u.id = r.user_id WHERE r.spot_id = ? AND r.status = 'open' ORDER BY r.created_at DESC LIMIT 40", [$viewerId, $spotId]);
    foreach ($rows as &$r) {
        $r['helpful'] = (int)$r['helpful']; $r['unhelpful'] = (int)$r['unhelpful'];
        $r['confirmed'] = $r['helpful'] >= SPOT_CONFIRMS_NEEDED && $r['helpful'] > $r['unhelpful'];
        $r['mine'] = $r['mine'] === null ? null : (int)$r['mine'];
    }
    unset($r);
    return $rows;
}

/** Admin adopts a note: the value overlays the OSM data from now on (closed: marks the place; position is not offered). */
function adoptSpotReport(array $report, int $adminId): void
{
    $spot = loadSpot((int)$report['spot_id']);
    if ($spot === null) {
        return;
    }
    $o = spotOverrides($spot);
    $o[$report['field']] = (string)$report['value'];
    dbExec('UPDATE spots SET overrides = ? WHERE id = ?', [json_encode($o, JSON_UNESCAPED_UNICODE), $spot['id']]);
    dbExec("UPDATE spot_reports SET status = 'adopted', handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ?", [$adminId, $report['id']]);
}

// ---------------------------------------------------------------- ratings

/** [target id => ['avg' => 4.2, 'n' => 5]] for several targets at once */
function ratingSummaries(string $type, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        foreach (dbAll('SELECT target_id, AVG(stars) AS avg, COUNT(*) AS n FROM ratings WHERE target_type = ? AND deleted_at IS NULL AND target_id IN ('
                       . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY target_id', array_merge([$type], $chunk)) as $r) {
            $out[(int)$r['target_id']] = ['avg' => (float)$r['avg'], 'n' => (int)$r['n']];
        }
    }
    return $out;
}

function ratingSummary(string $type, int $id): ?array
{
    return ratingSummaries($type, [$id])[$id] ?? null;
}

function ratingList(string $type, int $id, int $limit = 10): array
{
    return dbAll('SELECT r.id, r.stars, r.comment, r.created_at, r.user_id, u.display_name FROM ratings r JOIN users u ON u.id = r.user_id
                   WHERE r.target_type = ? AND r.target_id = ? AND r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT ' . (int)$limit, [$type, $id]);
}

function myRating(string $type, int $id, int $userId): ?array
{
    return dbOne('SELECT stars, comment FROM ratings WHERE target_type = ? AND target_id = ? AND user_id = ? AND deleted_at IS NULL', [$type, $id, $userId]);
}

function saveRating(string $type, int $id, int $userId, int $stars, string $comment): void
{
    $stars = max(1, min(5, $stars));
    dbExec('INSERT INTO ratings (target_type, target_id, user_id, stars, comment) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE stars = VALUES(stars), comment = VALUES(comment), updated_at = UTC_TIMESTAMP(), deleted_at = NULL',
        [$type, $id, $userId, $stars, $comment !== '' ? mb_substr($comment, 0, 600) : null]);
}

/** "★★★★☆ 4,2 (5)" – stars as text, readable for screen readers through the aria-label */
function starsHtml(?array $summary): string
{
    if (!$summary || $summary['n'] < 1) {
        return '<span class="stars muted">' . te('rating.none') . '</span>';
    }
    $full = (int)round($summary['avg']);
    return '<span class="stars" role="img" aria-label="' . te('rating.aria', ['avg' => number_format($summary['avg'], 1, t('common.decimal_point'), ''), 'n' => $summary['n']]) . '">'
        . str_repeat('★', $full) . str_repeat('☆', 5 - $full) . ' <strong>' . number_format($summary['avg'], 1, t('common.decimal_point'), '') . '</strong> <span class="muted">(' . $summary['n'] . ')</span></span>';
}

/** Rating form + list for a tour or a place; $back is the page to return to after sending */
function ratingBlock(string $type, int $id, int $viewerId, bool $canRate, string $back): string
{
    $sum = ratingSummary($type, $id);
    $mine = myRating($type, $id, $viewerId);
    $h = '<section class="panel ratings" id="ratings"><h2>' . te('rating.title') . '</h2><p class="rating-sum">' . starsHtml($sum) . '</p>';
    if ($canRate) {
        $h .= '<form method="post" action="/rate" class="form rating-form">' . csrfField()
            . '<input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<fieldset class="star-pick"><legend>' . te($mine ? 'rating.yours' : 'rating.give') . '</legend>';
        for ($i = 5; $i >= 1; $i--) {
            $h .= '<input type="radio" name="stars" id="st' . $i . '" value="' . $i . '"' . ($mine && (int)$mine['stars'] === $i ? ' checked' : '') . ' required><label for="st' . $i . '" title="' . $i . '">★<span class="sr-only">' . $i . '</span></label>';
        }
        $h .= '</fieldset><div class="field"><label for="rcomment">' . te('rating.comment') . '</label><textarea id="rcomment" name="comment" rows="2" maxlength="600">'
            . e((string)($mine['comment'] ?? '')) . '</textarea></div><button type="submit">' . te('rating.send') . '</button></form>';
    }
    $list = ratingList($type, $id);
    if ($list) {
        $h .= '<ul class="rating-list">';
        foreach ($list as $r) {
            $h .= '<li><span class="stars">' . str_repeat('★', (int)$r['stars']) . str_repeat('☆', 5 - (int)$r['stars']) . '</span> <strong>' . e($r['display_name']) . '</strong> <span class="muted">· ' . e(relativeTime($r['created_at'])) . '</span>'
                . ($r['comment'] ? '<p>' . e($r['comment']) . '</p>' : '')
                . ((int)$r['user_id'] !== $viewerId ? ' <a class="muted small" href="/report?type=rating&amp;id=' . (int)$r['id'] . '">' . te('report.link') . '</a>' : '') . '</li>';
        }
        $h .= '</ul>';
    }
    return $h . '</section>';
}
