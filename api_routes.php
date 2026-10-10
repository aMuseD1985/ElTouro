<?php
/**
 * Route table of the REST API v1. One list drives both the router (api.php) and the OpenAPI document (api_lib.php), so the
 * documentation cannot drift from the code. Handlers get a context: user, uid, p (path parameters), q (query), body (JSON).
 * They return the data (wrapped as {"data": …}) or [data, status]. Rules are the web app's rules – via its library functions.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';
require_once __DIR__ . '/crews_lib.php';
require_once __DIR__ . '/rides_lib.php';
require_once __DIR__ . '/talk_lib.php';
require_once __DIR__ . '/forum_lib.php';
require_once __DIR__ . '/track_lib.php';
require_once __DIR__ . '/drive_lib.php';
require_once __DIR__ . '/spots_lib.php';
require_once __DIR__ . '/notify_lib.php';
require_once __DIR__ . '/rewards_lib.php';
require_once __DIR__ . '/gpx_lib.php';

function apiIso(?string $utc): ?string
{
    return $utc === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int)strtotime($utc . ' UTC'));
}

function apiNeedText(array $body, string $key, int $min, int $max): string
{
    $v = trim(str_replace("\r", '', (string)($body[$key] ?? '')));
    if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
        throw new ApiError(422, 'invalid_' . $key, "$key must have $min to $max characters.");
    }
    return $v;
}

function apiTourOut(array $t, bool $detail = false): array
{
    $out = ['id' => (int)$t['id'], 'title' => $t['title'], 'distance_m' => (int)$t['distance_m'], 'ascent_m' => $t['ascent_m'] !== null ? (int)$t['ascent_m'] : null,
            'difficulty' => $t['difficulty'], 'style' => $t['style'], 'rule_set' => $t['rule_set'], 'vehicle_class' => (int)($t['vehicle_class'] ?? 2), 'visibility' => $t['visibility'],
            'source' => $t['source'] ?? 'planned', 'start' => ['lat' => (float)$t['start_lat'], 'lng' => (float)$t['start_lng']],
            'creator' => $t['creator'] ?? null, 'crew' => $t['crew_name'] ?? null, 'updated_at' => apiIso($t['updated_at'])];
    $r = ratingSummary('tour', (int)$t['id']);
    $out['rating'] = $r ? ['average' => round($r['avg'], 2), 'count' => $r['n']] : null;
    if ($detail) {
        $out['description'] = $t['description'];
        $out['waypoints'] = json_decode((string)$t['waypoints_json'], true);
        $out['stops'] = tourStops($t);
        $out['geojson'] = json_decode((string)$t['geojson'], true);
    }
    return $out;
}

function apiTourOrFail(array $c, bool $edit = false): array
{
    $t = loadTour((int)$c['p']['id']);
    if ($t === null || !canSeeTour($t, $c['uid']) || ($edit && !canEditTour($t, $c['uid']))) {
        throw new ApiError(404, 'not_found', 'Tour not found.');
    }
    return $t;
}

function apiCrewOrFail(array $c, bool $memberOnly = false): array
{
    $crew = loadCrew((string)$c['p']['slug']);
    $m = $crew ? membership((int)$crew['id'], $c['uid']) : null;
    if ($crew === null || ($memberOnly ? !isActiveMember($m) : !canSeeCrew($crew, $m, null))) {
        throw new ApiError(404, 'not_found', 'Crew not found.');   // secret crews look like they do not exist
    }
    return [$crew, $m];
}

function apiRideOut(array $r): array
{
    return ['id' => (int)$r['id'], 'title' => $r['title'], 'starts_at' => apiIso($r['starts_at']), 'meeting_point' => $r['meeting_point'],
            'capacity' => (int)$r['capacity'], 'confirmed' => isset($r['confirmed']) ? (int)$r['confirmed'] : null, 'status' => $r['status'], 'visibility' => $r['visibility'],
            'style' => $r['style'], 'organizer' => $r['organizer'] ?? null, 'crew' => $r['crew_name'] ?? null, 'crew_slug' => $r['crew_slug'] ?? null,
            'my_status' => $r['my_status'] ?? null];
}

function apiRoutes(): array
{
    $page = ['limit' => ['integer', 'max. ' . API_MAX_PAGE . ', default 25'], 'offset' => ['integer', 'skip n entries']];
    $R = [];
    $add = function (string $method, string $path, string $tag, string $summary, callable $h, array $query = [], array $body = [], string $description = '') use (&$R) {
        $R[] = ['method' => $method, 'path' => $path, 'tag' => $tag, 'summary' => $summary, 'handler' => $h, 'query' => $query, 'body' => $body, 'description' => $description];
    };

    // ---- Account
    $add('GET', '/me', 'Account', 'The account of the token', fn($c) => ['id' => (int)$c['uid'], 'display_name' => $c['user']['display_name'], 'locale' => $c['user']['locale'],
        'is_admin' => (bool)$c['user']['is_admin'], 'member_since' => apiIso($c['user']['created_at'])]);
    $add('GET', '/notifications', 'Account', 'Your notifications (the bell)', function ($c) use ($page) {
        [$limit, $off] = apiLimit($c['q']);
        $rows = dbAll('SELECT id, type, link, vars, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $off, [$c['uid']]);
        return array_map(fn($n) => ['id' => (int)$n['id'], 'type' => $n['type'], 'text' => notificationText($n, $c['user']['locale']), 'link' => $n['link'], 'created_at' => apiIso($n['created_at']), 'read' => $n['read_at'] !== null], $rows);
    }, $page);
    $add('POST', '/notifications/read', 'Account', 'Mark all notifications as read', function ($c) {
        dbExec('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [$c['uid']]);
        return ['ok' => true];
    });

    // ---- Tours
    $add('GET', '/tours', 'Tours', 'Tours you may see (own, your crews\', public)', function ($c) {
        [$limit, $off] = apiLimit($c['q']);
        $where = '';
        $params = [$c['uid'], $c['uid']];
        if (apiBool($c['q']['mine'] ?? false)) {
            $where .= ' AND t.owner_user_id = ' . (int)$c['uid'];
        }
        if (($q = trim((string)($c['q']['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
            $where .= ' AND (t.title LIKE ? OR t.description LIKE ?)';
            array_push($params, $like, $like);
        }
        $rows = dbAll("SELECT t.*, u.display_name AS creator, g.name AS crew_name FROM tours t JOIN users u ON u.id = t.owner_user_id
                       LEFT JOIN rider_groups g ON g.id = t.owner_group_id AND g.deleted_at IS NULL
                       WHERE t.deleted_at IS NULL AND (t.owner_user_id = ? OR t.visibility = 'public'
                          OR (t.visibility = 'group' AND EXISTS (SELECT 1 FROM group_members m WHERE m.group_id = t.owner_group_id AND m.user_id = ? AND m.status = 'active')))
                         $where ORDER BY t.updated_at DESC LIMIT $limit OFFSET $off", $params);
        return array_map(fn($t) => apiTourOut($t), $rows);
    }, ['q' => ['string', 'search in title and description'], 'mine' => ['boolean', 'only your own tours']] + $page);
    $add('GET', '/tours/{id}', 'Tours', 'One tour with geometry (GeoJSON), waypoints and stops', fn($c) => apiTourOut(apiTourOrFail($c), true));
    $add('POST', '/tours', 'Tours', 'Create a tour from a computed track', function ($c) {
        $b = $c['body'];
        $title = apiNeedText($b, 'title', 2, 120);
        $geo = validateGeometry(json_encode($b['geojson'] ?? null));
        if ($geo === null || $geo['distance'] < 100) {
            throw new ApiError(422, 'invalid_geojson', 'geojson must be a FeatureCollection of LineStrings (≥ 100 m).');
        }
        $vis = (string)($b['visibility'] ?? 'private');
        $groupId = null;
        if ($vis === 'group') {
            $crew = loadCrew((string)($b['crew_slug'] ?? ''));
            if ($crew === null || !isActiveMember(membership((int)$crew['id'], $c['uid']))) {
                throw new ApiError(422, 'invalid_crew', 'crew_slug must name a crew you are an active member of.');
            }
            $groupId = (int)$crew['id'];
        } elseif (!in_array($vis, ['private', 'public'], true)) {
            throw new ApiError(422, 'invalid_visibility', 'visibility: private, group or public.');
        }
        $diff = in_array($b['difficulty'] ?? '', ['easy', 'moderate', 'demanding'], true) ? $b['difficulty'] : 'easy';
        $style = in_array($b['style'] ?? '', ['relaxed', 'social', 'sporty'], true) ? $b['style'] : 'social';
        $rules = ($b['rule_set'] ?? '') === 'ekfv2027' ? 'ekfv2027' : 'ekfv';
        $wps = validateWaypoints($b['waypoints'] ?? null) ?? [[$geo['start'][0], $geo['start'][1]]];
        dbExec("INSERT INTO tours (owner_user_id, owner_group_id, title, description, content_lang, visibility, source, rule_set, difficulty, style, vehicle_class, distance_m, ascent_m,
                       freehand_share_pct, waypoints_json, geojson, start_lat, start_lng, bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng)
                VALUES (?, ?, ?, ?, ?, ?, 'imported', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$c['uid'], $groupId, $title, ($d = trim((string)($b['description'] ?? ''))) !== '' ? mb_substr($d, 0, 4000) : null, $c['user']['locale'] === 'en' ? 'en' : 'de', $vis, $rules, $diff, $style,
             vehicleClass($b['vehicle_class'] ?? 2), $geo['distance'], computeAscent($geo['geojson']), $geo['freehand_pct'], json_encode($wps), $geo['geojson'],
             $geo['start'][0], $geo['start'][1], $geo['bbox'][0], $geo['bbox'][1], $geo['bbox'][2], $geo['bbox'][3]]);
        $id = (int)db()->lastInsertId();
        recordEvent($c['uid'], 'tour_created', 'tour', $id, null, (float)round($geo['distance'] / 1000, 2), ['source' => 'api']);
        return [apiTourOut(loadTour($id), true), 201];
    }, [], ['title' => ['string', '2–120 characters', true], 'geojson' => ['object', 'FeatureCollection with LineString features, [lng, lat(, ele)]', true],
           'visibility' => ['string', 'default private', false, ['private', 'group', 'public']], 'crew_slug' => ['string', 'required when visibility is group'],
           'description' => ['string'], 'difficulty' => ['string', '', false, ['easy', 'moderate', 'demanding']], 'style' => ['string', '', false, ['relaxed', 'social', 'sporty']],
           'rule_set' => ['string', '', false, ['ekfv', 'ekfv2027']], 'vehicle_class' => ['integer', '1 city, 2 allround, 3 bull, 4 bull run'], 'waypoints' => ['array', '[[lat, lng], …]']],
        'Distance, bounding box, start and ascent are computed by the server from the geometry; numbers you send are ignored.');
    $add('POST', '/tours/gpx', 'Tours', 'Create a tour from GPX text', function ($c) {
        $b = $c['body'];
        $gid = null;
        if (($b['visibility'] ?? '') === 'group') {
            $crew = loadCrew((string)($b['crew_slug'] ?? ''));
            if ($crew === null || !isActiveMember(membership((int)$crew['id'], $c['uid']))) {
                throw new ApiError(422, 'invalid_crew', 'crew_slug must name a crew you are an active member of.');
            }
            $gid = (int)$crew['id'];
        }
        $r = importGpxTour((string)($b['gpx'] ?? ''), $c['uid'], $b + ['group_id' => $gid]);
        if (is_string($r)) {
            throw new ApiError(422, 'gpx_' . $r, 'The GPX could not be imported (' . $r . ').');
        }
        return [apiTourOut(loadTour($r), true), 201];
    }, [], ['gpx' => ['string', 'the GPX file content (max. 6 MB); tracks or a route', true], 'title' => ['string', 'default: name in the file'], 'description' => ['string'],
           'visibility' => ['string', 'default private', false, ['private', 'group', 'public']], 'crew_slug' => ['string'], 'difficulty' => ['string', '', false, ['easy', 'moderate', 'demanding']],
           'style' => ['string', '', false, ['relaxed', 'social', 'sporty']]], 'Timestamps and device data in the file are ignored; the server measures the tour itself.');
    $add('GET', '/tours/{id}/gpx', 'Tours', 'GPX of a tour (track, route points and stops) – returns the XML text under data.gpx', function ($c) {
        $t = apiTourOrFail($c);
        return ['gpx' => gpxExport($t)];
    });
    $add('DELETE', '/tours/{id}', 'Tours', 'Delete one of your tours', function ($c) {
        $t = apiTourOrFail($c, true);
        if (dbOne("SELECT 1 AS x FROM rides WHERE tour_id = ? AND deleted_at IS NULL AND status = 'planned' AND starts_at > UTC_TIMESTAMP() LIMIT 1", [$t['id']])) {
            throw new ApiError(422, 'has_rides', 'A ride is planned on this tour – cancel it first.');
        }
        dbExec('UPDATE tours SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$t['id']]);
        return ['ok' => true];
    });
    $add('GET', '/tours/{id}/ratings', 'Tours', 'Ratings of a tour', function ($c) {
        $t = apiTourOrFail($c);
        return array_map(fn($r) => ['stars' => (int)$r['stars'], 'comment' => $r['comment'], 'by' => $r['display_name'], 'at' => apiIso($r['created_at'])], ratingList('tour', (int)$t['id'], 50));
    });
    $add('PUT', '/tours/{id}/rating', 'Tours', 'Rate a tour (not your own); changes your earlier rating', function ($c) {
        $t = apiTourOrFail($c);
        if ((int)$t['owner_user_id'] === $c['uid']) {
            throw new ApiError(422, 'own_tour', 'You cannot rate your own tour.');
        }
        saveRating('tour', (int)$t['id'], $c['uid'], (int)($c['body']['stars'] ?? 0) ?: throw new ApiError(422, 'invalid_stars', 'stars: 1 to 5.'), trim((string)($c['body']['comment'] ?? '')));
        return ['ok' => true];
    }, [], ['stars' => ['integer', '1–5', true], 'comment' => ['string', 'max. 600 characters']]);

    // ---- Rides
    $add('GET', '/rides', 'Rides', 'Upcoming rides you may see', function ($c) {
        [$limit] = apiLimit($c['q']);
        return array_map('apiRideOut', visibleRides($c['uid'], true, '1=1', [], $limit));
    }, ['limit' => ['integer']]);
    $add('GET', '/rides/{id}', 'Rides', 'One ride', function ($c) {
        $r = loadRide((int)$c['p']['id']);
        if ($r === null || !canSeeRide($r, $c['uid'])) {
            throw new ApiError(404, 'not_found', 'Ride not found.');
        }
        $counts = rideCounts((int)$r['id']);
        $sign = rideSignup((int)$r['id'], $c['uid']);
        return apiRideOut($r + ['confirmed' => $counts['confirmed'] ?? null, 'my_status' => $sign['status'] ?? null]) + ['tour_id' => (int)$r['tour_id'], 'description' => $r['description'],
            'meeting' => $r['meeting_lat'] !== null ? ['lat' => (float)$r['meeting_lat'], 'lng' => (float)$r['meeting_lng']] : null, 'counts' => $counts];
    });
    $add('POST', '/rides/{id}/signup', 'Rides', 'Sign up (or join the waiting list)', function ($c) {
        $r = loadRide((int)$c['p']['id']);
        if ($r === null || !canSeeRide($r, $c['uid'])) {
            throw new ApiError(404, 'not_found', 'Ride not found.');
        }
        if (!apiBool($c['body']['road_legal'] ?? false)) {
            throw new ApiError(422, 'road_legal_required', 'road_legal must be true: you confirm a road-legal, insured scooter.');
        }
        if (!isRideOpen($r)) {
            throw new ApiError(422, 'closed', 'This ride is closed.');
        }
        return ['status' => signUpForRide((int)$r['id'], $c['uid'], apiBool($c['body']['photo_consent'] ?? false))];
    }, [], ['road_legal' => ['boolean', 'confirms a road-legal, insured scooter', true], 'photo_consent' => ['boolean', 'optional, never required']]);
    $add('DELETE', '/rides/{id}/signup', 'Rides', 'Cancel your sign-up', function ($c) {
        $r = loadRide((int)$c['p']['id']);
        if ($r === null || !canSeeRide($r, $c['uid'])) {
            throw new ApiError(404, 'not_found', 'Ride not found.');
        }
        cancelRideSignup((int)$r['id'], $c['uid']);
        return ['ok' => true];
    });

    // ---- Crews and crew talk (Stammtisch)
    $add('GET', '/crews', 'Crews', 'Your crews and listed crews', function ($c) {
        $mine = array_map(fn($g) => ['slug' => $g['slug'], 'name' => $g['name'], 'member' => true], activeCrewsOf($c['uid']));
        $listed = dbAll("SELECT slug, name, region FROM rider_groups WHERE deleted_at IS NULL AND discoverability = 'listed' ORDER BY name LIMIT 100");
        $slugs = array_column($mine, 'slug');
        foreach ($listed as $g) {
            if (!in_array($g['slug'], $slugs, true)) {
                $mine[] = ['slug' => $g['slug'], 'name' => $g['name'], 'region' => $g['region'], 'member' => false];
            }
        }
        return $mine;
    });
    $add('GET', '/crews/{slug}', 'Crews', 'One crew', function ($c) {
        [$crew, $m] = apiCrewOrFail($c);
        return ['slug' => $crew['slug'], 'name' => $crew['name'], 'description' => $crew['description'], 'region' => $crew['region'], 'discoverability' => $crew['discoverability'],
                'join_policy' => $crew['join_policy'], 'my_role' => $m && isActiveMember($m) ? ($m['role'] === 'admin' ? 'lead' : 'member') : null];
    });
    $add('GET', '/crews/{slug}/talk', 'Crew talk (Stammtisch)', 'Topics of the crew talk (members only)', function ($c) {
        [$crew] = apiCrewOrFail($c, true);
        return array_map(fn($t) => ['id' => (int)$t['id'], 'title' => $t['title'], 'author' => $t['author'], 'posts' => (int)$t['post_count'], 'last_post_at' => apiIso($t['last_post_at']),
            'unread' => (int)$t['unread'], 'pinned' => (bool)$t['is_pinned']], loadTalkTopics((int)$crew['id'], $c['uid'], max(0, (int)($c['q']['offset'] ?? 0))));
    }, ['offset' => ['integer']]);
    $add('POST', '/crews/{slug}/talk', 'Crew talk (Stammtisch)', 'Start a topic', function ($c) {
        [$crew] = apiCrewOrFail($c, true);
        $title = apiNeedText($c['body'], 'title', 3, 150);
        $text = apiNeedText($c['body'], 'text', 1, TALK_POST_MAX);
        if (!canTalkNow($c['uid'])) {
            throw new ApiError(429, 'too_fast', 'Please wait a few seconds between posts.');
        }
        [$tid] = createTalkTopic((int)$crew['id'], $c['uid'], $title, $text);
        return [['id' => $tid], 201];
    }, [], ['title' => ['string', '3–150', true], 'text' => ['string', 'Mini-Markdown, max. ' . TALK_POST_MAX, true]]);
    $add('GET', '/talk/{id}', 'Crew talk (Stammtisch)', 'A topic with its posts', function ($c) {
        $topic = loadTalkTopic((int)$c['p']['id']);
        $crew = $topic ? loadCrewById((int)$topic['group_id']) : null;
        [$ok] = $crew ? talkAccess($crew, $c['user']) : [false];
        if (!$ok) {
            throw new ApiError(404, 'not_found', 'Topic not found.');
        }
        $posts = loadTalkPosts((int)$topic['id'], $c['uid'], 'p.deleted_at IS NULL', [], false, API_MAX_PAGE);
        return ['id' => (int)$topic['id'], 'title' => $topic['title'], 'posts' => array_map(fn($p) => ['id' => (int)$p['id'], 'author' => $p['display_name'], 'text' => $p['body'], 'at' => apiIso($p['created_at'])], $posts)];
    });
    $add('POST', '/talk/{id}/posts', 'Crew talk (Stammtisch)', 'Reply in a topic', function ($c) {
        $topic = loadTalkTopic((int)$c['p']['id']);
        $crew = $topic ? loadCrewById((int)$topic['group_id']) : null;
        [$ok] = $crew ? talkAccess($crew, $c['user']) : [false];
        if (!$ok || $topic['deleted_at'] !== null) {
            throw new ApiError(404, 'not_found', 'Topic not found.');
        }
        if ($topic['is_locked']) {
            throw new ApiError(422, 'locked', 'This topic is locked.');
        }
        $text = apiNeedText($c['body'], 'text', 1, TALK_POST_MAX);
        if (!canTalkNow($c['uid'])) {
            throw new ApiError(429, 'too_fast', 'Please wait a few seconds between posts.');
        }
        return [['id' => addTalkPost((int)$topic['id'], $c['uid'], $text)], 201];
    }, [], ['text' => ['string', '', true]]);

    // ---- Forum
    $add('GET', '/forum/categories', 'Forum', 'Forum categories', fn($c) => array_map(fn($k) => ['id' => (int)$k['id'], 'slug' => $k['slug'], 'name' => categoryName($k), 'description' => categoryDescription($k)],
        dbAll('SELECT * FROM forum_categories ORDER BY sort, id')));
    $add('GET', '/forum/categories/{id}/threads', 'Forum', 'Threads of a category', function ($c) {
        [$limit, $off] = apiLimit($c['q']);
        return array_map(fn($t) => ['id' => (int)$t['id'], 'title' => $t['title'], 'author' => $t['author'], 'posts' => (int)$t['post_count'], 'last_post_at' => apiIso($t['last_post_at'])],
            dbAll("SELECT t.id, t.title, t.post_count, t.last_post_at, u.display_name AS author FROM forum_threads t JOIN users u ON u.id = t.user_id
                    WHERE t.category_id = ? AND t.deleted_at IS NULL ORDER BY t.is_pinned DESC, t.last_post_at DESC LIMIT $limit OFFSET $off", [(int)$c['p']['id']]));
    }, $page);
    $add('GET', '/forum/threads/{id}', 'Forum', 'A thread with its posts', function ($c) {
        $t = dbOne('SELECT id, title, is_locked FROM forum_threads WHERE id = ? AND deleted_at IS NULL', [(int)$c['p']['id']]);
        if ($t === null) {
            throw new ApiError(404, 'not_found', 'Thread not found.');
        }
        [$limit, $off] = apiLimit($c['q']);
        $posts = dbAll("SELECT p.id, p.body, p.created_at, u.display_name FROM forum_posts p JOIN users u ON u.id = p.user_id WHERE p.thread_id = ? AND p.deleted_at IS NULL ORDER BY p.id LIMIT $limit OFFSET $off", [$t['id']]);
        return ['id' => (int)$t['id'], 'title' => $t['title'], 'locked' => (bool)$t['is_locked'],
                'posts' => array_map(fn($p) => ['id' => (int)$p['id'], 'author' => $p['display_name'], 'text' => $p['body'], 'at' => apiIso($p['created_at'])], $posts)];
    }, $page);
    $add('POST', '/forum/threads/{id}/posts', 'Forum', 'Reply in a thread', function ($c) {
        $t = dbOne('SELECT id, user_id, title, is_locked FROM forum_threads WHERE id = ? AND deleted_at IS NULL', [(int)$c['p']['id']]);
        if ($t === null) {
            throw new ApiError(404, 'not_found', 'Thread not found.');
        }
        if ($t['is_locked'] && !isForumModerator($c['user'])) {
            throw new ApiError(422, 'locked', 'This thread is locked.');
        }
        $text = apiNeedText($c['body'], 'text', 2, FORUM_POST_MAX);
        if (!canPostNow($c['uid'])) {
            throw new ApiError(429, 'too_fast', 'Please wait a few seconds between posts.');
        }
        dbExec('INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)', [$t['id'], $c['uid'], $text]);
        $pid = (int)db()->lastInsertId();
        dbExec('UPDATE forum_threads SET post_count = post_count + 1, last_post_at = UTC_TIMESTAMP() WHERE id = ?', [$t['id']]);
        notifyUser((int)$t['user_id'], $c['uid'], 'forum_reply', '/forum/topic/' . $t['id'] . '#b' . $pid, ['name' => (string)$c['user']['display_name'], 'title' => (string)$t['title']]);
        return [['id' => $pid], 201];
    }, [], ['text' => ['string', '2–' . FORUM_POST_MAX, true]]);

    // ---- Recorded rides
    $add('GET', '/drives', 'Recorded rides', 'Your recorded rides', function ($c) {
        [$limit] = apiLimit($c['q']);
        return array_map(fn($d) => ['id' => (int)$d['id'], 'started_at' => apiIso($d['started_at']), 'distance_m' => (int)$d['distance_m'], 'moving_s' => (int)$d['moving_s'],
            'max_speed_kmh' => (float)$d['max_speed_kmh'], 'tour_id' => $d['tour_id'] !== null ? (int)$d['tour_id'] : null, 'saved_tour_id' => $d['saved_tour_id'] !== null ? (int)$d['saved_tour_id'] : null], ownDrives($c['uid'], $limit));
    }, ['limit' => ['integer']]);
    $add('GET', '/drives/{id}', 'Recorded rides', 'One of your recorded rides with the ridden track (GeoJSON)', function ($c) {
        $d = loadOwnDrive((int)$c['p']['id'], $c['uid']);
        if ($d === null) {
            throw new ApiError(404, 'not_found', 'Ride not found.');
        }
        return ['id' => (int)$d['id'], 'started_at' => apiIso($d['started_at']), 'distance_m' => (int)$d['distance_m'], 'moving_s' => (int)$d['moving_s'], 'max_speed_kmh' => (float)$d['max_speed_kmh'],
                'ridden' => json_decode(driveGeoJson(drivePoints((int)$d['id'])), true), 'planned' => $d['planned_geojson'] ? json_decode($d['planned_geojson'], true) : null];
    });
    $add('POST', '/drives/{id}/tour', 'Recorded rides', 'Make a private tour from a recorded ride', function ($c) {
        $d = loadOwnDrive((int)$c['p']['id'], $c['uid']);
        if ($d === null) {
            throw new ApiError(404, 'not_found', 'Ride not found.');
        }
        $id = $d['saved_tour_id'] === null ? saveDriveAsTour($d, $c['uid'], mb_substr(trim((string)($c['body']['title'] ?? '')) ?: 'Ride ' . $d['started_at'], 0, 120)) : (int)$d['saved_tour_id'];
        if (!$id) {
            throw new ApiError(422, 'too_short', 'This ride is too short to become a tour.');
        }
        return [['tour_id' => $id], 201];
    }, [], ['title' => ['string']]);
    $add('DELETE', '/drives/{id}', 'Recorded rides', 'Delete a recorded ride and all its positions', function ($c) {
        if (!deleteTrack((int)$c['p']['id'], $c['uid'])) {
            throw new ApiError(404, 'not_found', 'Ride not found.');
        }
        return ['ok' => true];
    });

    // ---- Places (stops)
    $add('POST', '/stops/suggest', 'Places (stops)', 'Stop suggestions along a track (OpenStreetMap + community)', function ($c) {
        require_once __DIR__ . '/poi_lib.php';
        $geo = validateGeometry(json_encode($c['body']['geojson'] ?? null));
        if ($geo === null) {
            throw new ApiError(422, 'invalid_geojson', 'geojson must be a FeatureCollection of LineStrings.');
        }
        @set_time_limit(120);
        $res = suggestStops($geo);
        if ($res === null) {
            throw new ApiError(503, 'unavailable', 'The suggestion service is unreachable right now.');
        }
        return $res;
    }, [], ['geojson' => ['object', 'the track', true]], 'Slow (several seconds); counts like several requests – use sparingly.');
    $add('GET', '/spots/{id}', 'Places (stops)', 'One place with community notes', function ($c) {
        $s = loadSpot((int)$c['p']['id']);
        if ($s === null || $s['hidden'] || ($s['status'] !== 'approved' && (int)$s['created_by'] !== $c['uid'] && !$c['user']['is_admin'])) {
            throw new ApiError(404, 'not_found', 'Place not found.');
        }
        $e = spotEffective($s);
        $r = ratingSummary('spot', (int)$s['id']);
        return ['id' => (int)$s['id'], 'type' => $s['type'], 'kind' => $s['kind'], 'name' => $e['name'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'website' => $e['website'], 'opening_hours' => $e['opening_hours'],
                'phone' => $e['phone'], 'note' => $e['note'], 'closed' => $e['closed'] ?: null, 'source' => $s['source'], 'status' => $s['status'],
                'rating' => $r ? ['average' => round($r['avg'], 2), 'count' => $r['n']] : null,
                'notes' => array_map(fn($n) => ['id' => (int)$n['id'], 'field' => $n['field'], 'value' => $n['value'], 'confirmed' => $n['confirmed'], 'helpful' => $n['helpful'], 'unhelpful' => $n['unhelpful']], spotNotes((int)$s['id'], $c['uid']))];
    });
    $add('POST', '/spots', 'Places (stops)', 'Add a missing place (waits for the admin)', function ($c) {
        $b = $c['body'];
        $type = (string)($b['type'] ?? ''); $kind = (string)($b['kind'] ?? '');
        if (!isset(SPOT_KINDS[$type]) || !in_array($kind, SPOT_KINDS[$type], true)) {
            throw new ApiError(422, 'invalid_kind', 'type/kind: ' . json_encode(SPOT_KINDS));
        }
        if (!isValidCoordinate($b['lat'] ?? null, $b['lng'] ?? null)) {
            throw new ApiError(422, 'invalid_position', 'lat/lng missing or invalid.');
        }
        if ((int)dbOne('SELECT COUNT(*) AS n FROM spots WHERE created_by = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY', [$c['uid']])['n'] >= 10) {
            throw new ApiError(429, 'too_many', 'At most 10 new places per day.');
        }
        $web = trim((string)($b['website'] ?? '')) !== '' ? cleanWebsite((string)$b['website']) : '';
        dbExec("INSERT INTO spots (source, status, type, kind, name, lat, lng, website, opening_hours, note, created_by) VALUES ('community', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$type, $kind, mb_substr(trim((string)($b['name'] ?? '')), 0, 80), round((float)$b['lat'], 6), round((float)$b['lng'], 6), $web ?: null,
             mb_substr(trim((string)($b['opening_hours'] ?? '')), 0, 200) ?: null, mb_substr(trim((string)($b['note'] ?? '')), 0, 500) ?: null, $c['uid']]);
        return [['id' => (int)db()->lastInsertId(), 'status' => 'pending'], 201];
    }, [], ['type' => ['string', '', true, array_keys(SPOT_KINDS)], 'kind' => ['string', 'depends on type', true], 'lat' => ['number', '', true], 'lng' => ['number', '', true],
           'name' => ['string'], 'website' => ['string'], 'opening_hours' => ['string', 'OSM syntax'], 'note' => ['string']]);
    $add('POST', '/spots/{id}/notes', 'Places (stops)', 'Report a correction or info for a place', function ($c) {
        $s = loadSpot((int)$c['p']['id']);
        $field = (string)($c['body']['field'] ?? '');
        if ($s === null || $s['hidden'] || $s['status'] !== 'approved') {
            throw new ApiError(404, 'not_found', 'Place not found.');
        }
        if (!isset(SPOT_NOTE_FIELDS[$field])) {
            throw new ApiError(422, 'invalid_field', 'field: ' . implode(', ', array_keys(SPOT_NOTE_FIELDS)));
        }
        $value = trim((string)($c['body']['value'] ?? ''));
        $value = mb_substr($field === 'website' ? cleanWebsite($value) : $value, 0, SPOT_NOTE_FIELDS[$field]);
        if ($value === '' && $field !== 'closed') {
            throw new ApiError(422, 'invalid_value', 'value is empty or invalid.');
        }
        if ((int)dbOne('SELECT COUNT(*) AS n FROM spot_reports WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [$c['uid']])['n'] >= 15) {
            throw new ApiError(429, 'too_many', 'At most 15 notes per hour.');
        }
        dbExec('INSERT INTO spot_reports (spot_id, user_id, field, value) VALUES (?, ?, ?, ?)', [$s['id'], $c['uid'], $field, $value]);
        return [['id' => (int)db()->lastInsertId()], 201];
    }, [], ['field' => ['string', '', true, array_keys(SPOT_NOTE_FIELDS)], 'value' => ['string', 'empty allowed for field "closed"']]);
    $add('PUT', '/spot-notes/{id}/vote', 'Places (stops)', 'Confirm or reject a note of another rider', function ($c) {
        $n = dbOne("SELECT id, user_id FROM spot_reports WHERE id = ? AND status = 'open'", [(int)$c['p']['id']]);
        if ($n === null) {
            throw new ApiError(404, 'not_found', 'Note not found.');
        }
        if ((int)$n['user_id'] === $c['uid']) {
            throw new ApiError(422, 'own_note', 'You cannot vote on your own note.');
        }
        $vote = (int)($c['body']['vote'] ?? 0);
        if (!in_array($vote, [1, -1], true)) {
            throw new ApiError(422, 'invalid_vote', 'vote: 1 (correct) or -1 (not correct).');
        }
        dbExec('INSERT INTO spot_votes (report_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), created_at = UTC_TIMESTAMP()', [$n['id'], $c['uid'], $vote]);
        return ['ok' => true];
    }, [], ['vote' => ['integer', '1 or -1', true, [1, -1]]]);
    $add('PUT', '/spots/{id}/rating', 'Places (stops)', 'Rate a place', function ($c) {
        $s = loadSpot((int)$c['p']['id']);
        if ($s === null || $s['hidden'] || $s['status'] !== 'approved') {
            throw new ApiError(404, 'not_found', 'Place not found.');
        }
        saveRating('spot', (int)$s['id'], $c['uid'], (int)($c['body']['stars'] ?? 0) ?: throw new ApiError(422, 'invalid_stars', 'stars: 1 to 5.'), trim((string)($c['body']['comment'] ?? '')));
        return ['ok' => true];
    }, [], ['stars' => ['integer', '1–5', true], 'comment' => ['string']]);

    return $R;
}
