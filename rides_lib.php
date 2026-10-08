<?php
/**
 * Rides ("Ausfahrten"): a tour offered as a date with meeting point, participant limit and sign-up.
 *
 * Access rules (EVERY visibility decision goes through here):
 *  - group:  active members of the ride's crew (+ organizer). Crew deleted → the ride is gone too.
 *  - public: all logged-in users.
 *  - Participant names: organizer, signed-up riders (incl. waiting list), crew members for crew rides, admins.
 *    Everyone else only sees how many places are left.
 *  - Managing (edit, cancel): organizer, crew leads of the ride's crew, platform admins.
 *
 * Product rules that are not negotiable: ElTouro does not organise rides; every participant confirms
 * a road-legal, insured e-scooter; the participant limit is mandatory; from STVO29_THRESHOLD places the
 * organizer confirms having checked § 29 StVO; photo consent is voluntary, revocable and never a
 * precondition for joining.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';
require_once __DIR__ . '/talk_lib.php';

const RIDE_MIN_CAPACITY = 2;
const RIDE_MAX_CAPACITY = 200;
const STVO29_THRESHOLD = 15;
const RIDE_SIGNUP_RETENTION_DAYS = 180;
const APP_TIMEZONE = 'Europe/Berlin';

function loadRide(int $id): ?array
{
    return dbOne('SELECT r.*, u.display_name AS organizer, g.name AS crew_name, g.slug AS crew_slug,
                         t.title AS tour_title, t.deleted_at AS tour_deleted_at
                    FROM rides r JOIN users u ON u.id = r.organizer_user_id
                    JOIN tours t ON t.id = r.tour_id
                    LEFT JOIN rider_groups g ON g.id = r.group_id AND g.deleted_at IS NULL
                   WHERE r.id = ? AND r.deleted_at IS NULL', [$id]);
}

function canSeeRide(array $ride, int $userId): bool
{
    if ((int)$ride['organizer_user_id'] === $userId || $ride['visibility'] === 'public') {
        return true;
    }
    return $ride['group_id'] !== null && $ride['crew_slug'] !== null
        && isActiveMember(membership((int)$ride['group_id'], $userId));
}

function canManageRide(array $ride, array $user): bool
{
    if ((int)$ride['organizer_user_id'] === (int)$user['id'] || (int)$user['is_admin'] === 1) {
        return true;
    }
    return $ride['group_id'] !== null && isCrewLead(membership((int)$ride['group_id'], (int)$user['id']));
}

function rideSignup(int $rideId, int $userId): ?array
{
    return dbOne('SELECT * FROM ride_signups WHERE ride_id = ? AND user_id = ?', [$rideId, $userId]);
}

function isSignedUp(?array $signup): bool
{
    return $signup !== null && $signup['status'] !== 'cancelled';
}

function canSeeParticipants(array $ride, array $user, ?array $signup): bool
{
    if (isSignedUp($signup) || (int)$ride['organizer_user_id'] === (int)$user['id'] || (int)$user['is_admin'] === 1) {
        return true;
    }
    return $ride['group_id'] !== null && $ride['crew_slug'] !== null
        && isActiveMember(membership((int)$ride['group_id'], (int)$user['id']));
}

/** @return array{confirmed:int, waitlist:int} */
function rideCounts(int $rideId): array
{
    $r = dbOne("SELECT SUM(status = 'confirmed') AS confirmed, SUM(status = 'waitlist') AS waitlist FROM ride_signups WHERE ride_id = ?", [$rideId]);
    return ['confirmed' => (int)($r['confirmed'] ?? 0), 'waitlist' => (int)($r['waitlist'] ?? 0)];
}

function hasRideStarted(array $ride): bool
{
    return strtotime($ride['starts_at'] . ' UTC') <= time();
}

/** Sign-ups and changes are possible until the ride starts and as long as it is not cancelled. */
function isRideOpen(array $ride): bool
{
    return $ride['status'] === 'planned' && !hasRideStarted($ride);
}

/**
 * The ride's audience must be able to see the tour – otherwise riders would sign up for a route
 * they cannot open. Crew ride: public tour or a tour of the same crew. Public ride: public tour.
 */
function rideAudienceCanSeeTour(string $visibility, ?int $groupId, array $tour): bool
{
    if ($tour['visibility'] === 'public') {
        return true;
    }
    return $visibility === 'group' && $groupId !== null && $tour['visibility'] === 'group' && (int)$tour['owner_group_id'] === $groupId;
}

/**
 * Tours that can carry a ride of this user: public tours and tours of the user's crews.
 * Private tours are left out – no ride audience could open them.
 */
function toursForRides(int $userId): array
{
    return dbAll("SELECT t.id, t.title, t.visibility, t.owner_group_id, t.owner_user_id, t.style, t.distance_m
                    FROM tours t
                    LEFT JOIN group_members m ON m.group_id = t.owner_group_id AND m.user_id = ? AND m.status = 'active'
                   WHERE t.deleted_at IS NULL
                     AND (t.visibility = 'public' OR (t.visibility = 'group' AND m.user_id IS NOT NULL))
                   ORDER BY t.owner_user_id = ? DESC, t.updated_at DESC LIMIT 200", [$userId, $userId]);
}

/**
 * Upcoming (or past) rides the user may see, with counts and the user's own sign-up status.
 * $extra narrows down further, e.g. "r.group_id = ?".
 */
function visibleRides(int $userId, bool $upcoming = true, string $extra = '1=1', array $params = [], int $limit = 50): array
{
    $time = $upcoming ? 'r.starts_at > UTC_TIMESTAMP() - INTERVAL 3 HOUR' : 'r.starts_at <= UTC_TIMESTAMP() - INTERVAL 3 HOUR';
    $order = $upcoming ? 'r.starts_at ASC' : 'r.starts_at DESC';
    return dbAll("SELECT r.id, r.title, r.starts_at, r.meeting_point, r.capacity, r.status, r.visibility, r.style, r.group_id,
                         u.display_name AS organizer, g.name AS crew_name, g.slug AS crew_slug, t.distance_m,
                         (SELECT COUNT(*) FROM ride_signups s WHERE s.ride_id = r.id AND s.status = 'confirmed') AS confirmed,
                         ms.status AS my_status
                    FROM rides r
                    JOIN users u ON u.id = r.organizer_user_id
                    JOIN tours t ON t.id = r.tour_id
                    LEFT JOIN rider_groups g ON g.id = r.group_id AND g.deleted_at IS NULL
                    LEFT JOIN ride_signups ms ON ms.ride_id = r.id AND ms.user_id = ?
                   WHERE r.deleted_at IS NULL AND $time
                     AND (r.visibility = 'public' OR r.organizer_user_id = ?
                          OR (g.id IS NOT NULL AND EXISTS (SELECT 1 FROM group_members m WHERE m.group_id = r.group_id AND m.user_id = ? AND m.status = 'active')))
                     AND $extra
                   ORDER BY $order LIMIT " . (int)$limit, [$userId, $userId, $userId, ...$params]);
}

/** Card list of rides, shared with the crew page and the home page. */
function rideCards(array $list, string $empty): void
{
    if (!$list) { echo '<p class="muted">' . te($empty) . '</p>'; return; }
    echo '<ul class="cards">';
    foreach ($list as $r) {
        $free = max(0, (int)$r['capacity'] - (int)$r['confirmed']);
        echo '<li class="card ride-card' . ($r['status'] === 'cancelled' ? ' cancelled' : '') . '">'
           . '<p class="ride-when">' . e(formatRideTime($r['starts_at'])) . '</p>'
           . '<h3><a href="/ride/' . (int)$r['id'] . '">' . e($r['title']) . '</a></h3>'
           . '<p class="muted">' . e($r['meeting_point']) . ' · ' . e(formatKm((int)$r['distance_m'])) . '</p>'
           . '<p class="muted">' . te('ride.by', ['name' => $r['organizer']]) . ($r['crew_name'] ? ' · ' . e($r['crew_name']) : ' · ' . te('ride.v_public')) . '</p><p>';
        if ($r['status'] === 'cancelled') {
            echo '<span class="badge danger">' . te('ride.cancelled') . '</span>';
        } else {
            echo $free > 0 ? '<span class="badge muted">' . te('ride.free_places', ['n' => $free]) . '</span>' : '<span class="badge muted">' . te('ride.full') . '</span>';
        }
        if ($r['my_status'] === 'confirmed') echo ' <span class="badge">' . te('ride.you_confirmed_short') . '</span>';
        if ($r['my_status'] === 'waitlist') echo ' <span class="badge">' . te('ride.you_waitlist_short') . '</span>';
        echo '</p></li>';
    }
    echo '</ul>';
}

/* ---------------- Sign-up and waiting list ---------------- */

/**
 * Signs a user up. Locks the ride row so that two simultaneous sign-ups cannot overbook it.
 * @return string 'confirmed' | 'waitlist' | 'full' | 'closed' | 'already'
 */
function signUpForRide(int $rideId, int $userId, bool $photoConsent): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ride = dbOne('SELECT capacity, waitlist_enabled, status, starts_at FROM rides WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$rideId]);
        if ($ride === null || !isRideOpen($ride)) {
            $pdo->rollBack();
            return 'closed';
        }
        if (isSignedUp(rideSignup($rideId, $userId))) {
            $pdo->rollBack();
            return 'already';
        }
        // Free places and a waiting list never exist at the same time: promotions run under the same lock
        $status = rideCounts($rideId)['confirmed'] < (int)$ride['capacity'] ? 'confirmed'
                : ((int)$ride['waitlist_enabled'] === 1 ? 'waitlist' : null);
        if ($status === null) {
            $pdo->rollBack();
            return 'full';
        }
        dbExec('INSERT INTO ride_signups (ride_id, user_id, status, road_legal_confirmed_at, photo_consent, queued_at)
                VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE status = VALUES(status), road_legal_confirmed_at = VALUES(road_legal_confirmed_at),
                                        photo_consent = VALUES(photo_consent), queued_at = VALUES(queued_at)',
            [$rideId, $userId, $status, $photoConsent ? 1 : 0]);
        $pdo->commit();
        return $status;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Cancels a sign-up; a freed place goes to the waiting list.
 * @return int[] user ids promoted from the waiting list (to notify)
 */
function cancelRideSignup(int $rideId, int $userId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        dbOne('SELECT id FROM rides WHERE id = ? FOR UPDATE', [$rideId]);
        dbExec("UPDATE ride_signups SET status = 'cancelled' WHERE ride_id = ? AND user_id = ? AND status <> 'cancelled'", [$rideId, $userId]);
        $promoted = promoteWaitlist($rideId);
        $pdo->commit();
        return $promoted;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Moves riders from the waiting list up while places are free (first come, first served).
 * Must run inside a transaction that holds the ride row lock.
 * @return int[] promoted user ids
 */
function promoteWaitlist(int $rideId): array
{
    $ride = dbOne('SELECT capacity, status, starts_at FROM rides WHERE id = ?', [$rideId]);
    if ($ride === null || !isRideOpen($ride)) {
        return [];
    }
    $free = (int)$ride['capacity'] - rideCounts($rideId)['confirmed'];
    if ($free <= 0) {
        return [];
    }
    $next = dbAll("SELECT user_id FROM ride_signups WHERE ride_id = ? AND status = 'waitlist' ORDER BY queued_at, user_id LIMIT " . $free, [$rideId]);
    foreach ($next as $n) {
        dbExec("UPDATE ride_signups SET status = 'confirmed' WHERE ride_id = ? AND user_id = ? AND status = 'waitlist'", [$rideId, $n['user_id']]);
    }
    return array_map('intval', array_column($next, 'user_id'));
}

/** Position on the waiting list (1-based) or null. */
function waitlistPosition(int $rideId, int $userId): ?int
{
    $me = rideSignup($rideId, $userId);
    if ($me === null || $me['status'] !== 'waitlist') {
        return null;
    }
    return 1 + (int)dbOne("SELECT COUNT(*) AS n FROM ride_signups WHERE ride_id = ? AND status = 'waitlist'
                            AND (queued_at < ? OR (queued_at = ? AND user_id < ?))", [$rideId, $me['queued_at'], $me['queued_at'], $userId])['n'];
}

/** Sign-ups are personal data: removed RIDE_SIGNUP_RETENTION_DAYS after the ride (as promised in the privacy policy). */
function purgeOldRideSignups(): void
{
    dbExec('DELETE s FROM ride_signups s JOIN rides r ON r.id = s.ride_id WHERE r.starts_at < UTC_TIMESTAMP() - INTERVAL ' . RIDE_SIGNUP_RETENTION_DAYS . ' DAY');
}

/* ---------------- Dates ---------------- */

/** Date (Y-m-d) and time (H:i) in German local time → UTC, or null if invalid. */
function localToUtc(string $date, string $time): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone(APP_TIMEZONE));
    if ($d === false || $d->format('Y-m-d H:i') !== $date . ' ' . $time) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'));
}

function utcToLocal(string $utc): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(APP_TIMEZONE));
}

/** "Sa., 18.10.2026, 10:00 Uhr" / "Sat 18 Oct 2026, 10:00" – without depending on the intl extension. */
function formatRideTime(string $utc, ?string $lang = null): string
{
    global $LANG;
    $lang ??= $LANG;
    $d = utcToLocal($utc);
    $w = (int)$d->format('w');
    if ($lang === 'de') {
        $days = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'];
        return $days[$w] . ', ' . $d->format('d.m.Y, H:i') . ' Uhr';
    }
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return $days[$w] . ' ' . $d->format('j M Y, H:i');
}

/* ---------------- Notifications ---------------- */

/**
 * Emails riders of a ride in their own language. $kind: 'changed' | 'cancelled' | 'promoted'.
 * $userIds: recipients; null = everyone confirmed or waiting. The acting user never gets a mail.
 * Mail problems are logged and never break the action itself.
 */
function notifyRiders(array $ride, string $kind, ?array $userIds = null, int $exceptUserId = 0, array $vars = []): int
{
    require_once __DIR__ . '/account_lib.php';
    if ($userIds === null) {
        $userIds = array_map('intval', array_column(dbAll("SELECT user_id FROM ride_signups WHERE ride_id = ? AND status IN ('confirmed','waitlist')", [$ride['id']]), 'user_id'));
    }
    $userIds = array_values(array_diff($userIds, [$exceptUserId]));
    if (!$userIds) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $sent = 0;
    foreach (dbAll("SELECT id, email, display_name, locale FROM users WHERE status = 'active' AND id IN ($in)", $userIds) as $u) {
        $text = tl($u['locale'], 'mail.ride_' . $kind . '_text', $vars + [
            'name'    => $u['display_name'],
            'title'   => $ride['title'],
            'when'    => formatRideTime($ride['starts_at'], $u['locale']),
            'meeting' => $ride['meeting_point'],
            'link'    => baseUrl() . '/ride/' . (int)$ride['id'],
        ]);
        try {
            sendMail($u['email'], tl($u['locale'], 'mail.ride_' . $kind . '_subject', ['title' => $ride['title']]), mailHtml($text), $text);
            $sent++;
        } catch (Throwable $ex) {
            error_log('ElTouro ride mail (' . $kind . '): ' . $ex->getMessage());
        }
    }
    return $sent;
}

/** Text of the crew-talk post that announces a ride (in the organizer's language). */
function rideAnnouncement(array $ride, array $tour): string
{
    return t('ride.talk_announcement', [
        'when'     => formatRideTime($ride['starts_at']),
        'meeting'  => $ride['meeting_point'],
        'tour'     => $tour['title'],
        'km'       => formatKm((int)$tour['distance_m']),
        'capacity' => (int)$ride['capacity'],
        'link'     => '/ride/' . (int)$ride['id'],
    ]) . ($ride['description'] ? "\n\n" . $ride['description'] : '');
}

/** Posts a status line (change, cancellation) into the ride's crew-talk topic, if there is one. */
function postRideUpdate(array $ride, int $userId, string $text): void
{
    if ($ride['talk_topic_id'] && dbOne('SELECT id FROM herd_topics WHERE id = ? AND deleted_at IS NULL', [$ride['talk_topic_id']])) {
        addTalkPost((int)$ride['talk_topic_id'], $userId, $text);
    }
}
