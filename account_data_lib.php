<?php
/**
 * Data protection for an account: complete export (Art. 15 and 20 GDPR) and complete deletion (Art. 17).
 *
 * Export: everything stored about the rider, as JSON (daten.json) in a ZIP together with the profile photo and a read-me.
 *
 * Deletion – what happens to what:
 *  - own data without any other person involved is deleted (profile, tokens, tracks, live position, memberships,
 *    reactions, sign-ups, own tours, rides without other participants, crews where nobody else is a member);
 *  - what other riders built on stays, but loses the person: replies in a thread keep their place with the text erased,
 *    tours that other riders' rides are planned on lose description and share link, rides with other participants are
 *    cancelled (with mail) or, when over, keep their date for the participants' history. All of that is attributed to
 *    the stand-in user "Gelöscht / Deleted" (DELETED_USER_EMAIL), never to the person;
 *  - the account row itself is removed for good (no soft delete), the profile photo file too.
 * Deleting is refused while it would leave something headless: the last admin, or the only crew lead of a crew
 * that still has other members (hand over first).
 */
declare(strict_types=1);

function deletedUserId(): int
{
    static $id = null;
    if ($id === null) {
        $id = (int)(dbOne('SELECT id FROM users WHERE email = ?', [DELETED_USER_EMAIL])['id'] ?? 0);
    }
    return $id;
}

/** @return array{admin: bool, crews: string[]} reasons why the account cannot be deleted right now */
function deletionBlockers(int $userId): array
{
    $admin = false;
    if ((int)(dbOne('SELECT is_admin FROM users WHERE id = ?', [$userId])['is_admin'] ?? 0) === 1) {
        $admin = (int)dbOne("SELECT COUNT(*) AS n FROM users WHERE is_admin = 1 AND status = 'active' AND id <> ?", [$userId])['n'] === 0;
    }
    $crews = [];
    foreach (dbAll("SELECT g.id, g.name FROM group_members m JOIN rider_groups g ON g.id = m.group_id
                     WHERE m.user_id = ? AND m.role = 'admin' AND m.status = 'active' AND g.deleted_at IS NULL", [$userId]) as $g) {
        $others = (int)dbOne("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND user_id <> ? AND status = 'active'", [$g['id'], $userId])['n'];
        $otherLeads = (int)dbOne("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND user_id <> ? AND role = 'admin' AND status = 'active'", [$g['id'], $userId])['n'];
        if ($others > 0 && $otherLeads === 0) {
            $crews[] = $g['name'];
        }
    }
    return ['admin' => $admin, 'crews' => $crews];
}

/* ---------------------------------------------------------------- Export */

function decodeJsonColumns(array $row, array $columns): array
{
    foreach ($columns as $c) {
        if (isset($row[$c]) && is_string($row[$c])) {
            $decoded = json_decode($row[$c], true);
            if ($decoded !== null) {
                $row[$c] = $decoded;
            }
        }
    }
    return $row;
}

/** Everything stored about the account. Hashes and tokens are left out (they are not information about the person). */
function exportAccountData(int $userId): array
{
    $one = fn(string $sql, array $p = []) => dbOne($sql, $p);
    $all = fn(string $sql, array $p = []) => dbAll($sql, $p);

    $account = $one('SELECT id, email, display_name, birth_date, locale, is_admin, status, email_verified_at, terms_accepted_at,
                            consent_version, consent_at, created_at, updated_at, invite_code, avatar_version
                       FROM users WHERE id = ?', [$userId]);
    $tracks = [];
    foreach ($all('SELECT id, tour_id, status, started_at, ended_at, point_count, distance_m, moving_s, max_speed_kmh, created_at
                     FROM track_sessions WHERE user_id = ? ORDER BY id', [$userId]) as $t) {
        $t['points'] = $all('SELECT seq, recorded_at, lat, lng, accuracy, speed_kmh FROM track_points WHERE session_id = ? ORDER BY seq', [$t['id']]);
        $tracks[] = $t;
    }
    return [
        'about' => [
            'exported_at' => gmdate('c'),
            'service' => 'ElTouro (' . ($_SERVER['HTTP_HOST'] ?? '') . ')',
            'note' => 'Alle Zeiten in UTC. All times in UTC. Passwort-Hash und Einmal-Tokens sind absichtlich nicht enthalten. '
                    . 'Beiträge anderer Fahrer, die auf deine antworten, gehören ihnen und sind nicht enthalten. / '
                    . 'Password hash and one-time tokens are left out on purpose. Other riders\' posts replying to yours belong to them and are not included.',
        ],
        'account' => $account,
        'profile' => $one('SELECT bio, home_region, updated_at FROM user_profiles WHERE user_id = ?', [$userId]),
        'sign_in_with' => $all('SELECT provider, subject, email, created_at, last_login_at FROM user_identities WHERE user_id = ?', [$userId]),
        'one_time_links' => $all('SELECT purpose, expires_at, used_at, created_at FROM auth_tokens WHERE user_id = ? ORDER BY id', [$userId]),
        'how_you_came' => $one('SELECT source, channel, ref_type, ref_id, created_at FROM referrals WHERE user_id = ?', [$userId]),
        'crews_member' => $all('SELECT g.name, g.slug, m.role, m.status, m.created_at, m.updated_at
                                  FROM group_members m JOIN rider_groups g ON g.id = m.group_id WHERE m.user_id = ? ORDER BY m.created_at', [$userId]),
        'crews_created' => $all('SELECT id, slug, name, description, region, discoverability, join_policy, created_at, deleted_at FROM rider_groups WHERE created_by = ?', [$userId]),
        'tours' => array_map(fn($t) => decodeJsonColumns($t, ['waypoints_json', 'stops_json', 'geojson', 'guidance_json']),
            $all('SELECT * FROM tours WHERE owner_user_id = ? ORDER BY id', [$userId])),
        'rides_organized' => $all('SELECT id, tour_id, group_id, visibility, title, description, starts_at, meeting_point, meeting_lat, meeting_lng,
                                          capacity, waitlist_enabled, style, stvo29_confirmed_at, status, cancel_reason, cancelled_at, created_at, deleted_at
                                     FROM rides WHERE organizer_user_id = ? ORDER BY id', [$userId]),
        'ride_signups' => $all('SELECT s.ride_id, r.title AS ride_title, r.starts_at, s.status, s.road_legal_confirmed_at, s.photo_consent, s.queued_at, s.created_at
                                  FROM ride_signups s JOIN rides r ON r.id = s.ride_id WHERE s.user_id = ? ORDER BY s.created_at', [$userId]),
        'forum_threads' => $all('SELECT id, category_id, title, is_pinned, is_locked, post_count, created_at, deleted_at FROM forum_threads WHERE user_id = ? ORDER BY id', [$userId]),
        'forum_posts' => $all('SELECT id, thread_id, body, created_at, edited_at, deleted_at FROM forum_posts WHERE user_id = ? ORDER BY id', [$userId]),
        'forum_reactions' => $all('SELECT post_id, emoji, created_at FROM forum_reactions WHERE user_id = ? ORDER BY created_at', [$userId]),
        'crew_talk_topics' => $all('SELECT id, group_id, title, is_pinned, is_locked, created_at, deleted_at FROM herd_topics WHERE user_id = ? ORDER BY id', [$userId]),
        'crew_talk_posts' => $all('SELECT id, topic_id, reply_to_id, body, like_count, created_at, edited_at, deleted_at FROM herd_posts WHERE user_id = ? ORDER BY id', [$userId]),
        'crew_talk_likes' => $all('SELECT post_id, created_at FROM herd_reactions WHERE user_id = ? ORDER BY created_at', [$userId]),
        'crew_talk_read_marks' => $all('SELECT topic_id, last_read_post_id, updated_at FROM herd_reads WHERE user_id = ?', [$userId]),
        'reports_filed' => $all('SELECT id, target_type, target_id, reason, status, created_at FROM reports WHERE reporter_user_id = ? ORDER BY id', [$userId]),
        'activity' => array_map(fn($e) => decodeJsonColumns($e, ['meta']),
            $all('SELECT type, subject_type, subject_id, value, meta, occurred_at, voided_at, void_reason FROM activity_events WHERE user_id = ? ORDER BY id', [$userId])),
        'notifications' => $all('SELECT type, link, vars, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY id', [$userId]),
        'mail_digest' => $one('SELECT mail_digest, digest_sent_at FROM users WHERE id = ?', [$userId]),
        'recorded_rides' => $tracks,
        'live_position' => $one('SELECT lat, lng, heading, speed_kmh, scope, tour_id, visible, updated_at FROM live_positions WHERE user_id = ?', [$userId]),
        'not_included' => [
            'Zugriffsprotokolle des Servers (IP-Adresse, Aufruf) und die Login-Bremse gehören keinem Konto zugeordnet und werden nach wenigen Tagen gelöscht. / '
            . 'Server access logs (IP address, request) and the login throttle are not linked to an account and are deleted after a few days.',
        ],
    ];
}

/** Builds the ZIP in a temp file and returns its path (caller sends and unlinks it), or null without ZipArchive. */
function buildExportZip(int $userId): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $data = exportAccountData($userId);
    $path = tempnam(sys_get_temp_dir(), 'eltouro-export');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    $zip->addFromString('daten.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    $zip->addFromString('LIES-MICH.txt', "ElTouro – deine Daten / your data\r\n\r\n"
        . "daten.json enthält alles, was wir zu deinem Konto gespeichert haben (maschinenlesbar, UTF-8).\r\n"
        . "daten.json contains everything we store about your account (machine-readable, UTF-8).\r\n\r\n"
        . "Touren: 'geojson' ist die Strecke als GeoJSON (Koordinaten als [Länge, Breite]).\r\n"
        . "Aufgezeichnete Fahrten: 'recorded_rides' mit allen Messpunkten.\r\n"
        . "Ein Profilfoto liegt als Datei bei, falls du eines hochgeladen hast.\r\n");
    $avatar = avatarFileFor($userId);
    if ($avatar !== null) {
        $zip->addFile($avatar, 'profilfoto.' . pathinfo($avatar, PATHINFO_EXTENSION));
    }
    $zip->close();
    return $path;
}

function avatarFileFor(int $userId): ?string
{
    foreach (['webp', 'jpg'] as $ext) {
        $f = DATA_DIR . '/avatars/' . $userId . '.' . $ext;
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/* ---------------------------------------------------------------- Deletion */

/**
 * Deletes the account for good. Call deletionBlockers() first. Sends the mails (cancelled rides, freed places,
 * confirmation) after the data is gone; mail trouble never stops the deletion.
 */
function deleteAccount(int $userId): void
{
    require_once __DIR__ . '/rides_lib.php';
    require_once __DIR__ . '/account_lib.php';
    require_once __DIR__ . '/mailer.php';
    $ghost = deletedUserId();
    if ($ghost === 0 || $ghost === $userId) {
        throw new RuntimeException('stand-in user missing – run the migration');
    }
    $me = dbOne('SELECT id, email, display_name, locale FROM users WHERE id = ?', [$userId]);
    if ($me === null) {
        return;
    }

    // 1. Places in other riders' rides: free them (the waiting list moves up, those riders get a mail)
    $promotions = [];
    foreach (dbAll("SELECT s.ride_id FROM ride_signups s JOIN rides r ON r.id = s.ride_id
                     WHERE s.user_id = ? AND s.status IN ('confirmed','waitlist') AND r.organizer_user_id <> ?", [$userId, $userId]) as $s) {
        $ride = loadRide((int)$s['ride_id']);
        if ($ride !== null && isRideOpen($ride)) {
            $promoted = cancelRideSignup((int)$s['ride_id'], $userId);
            if ($promoted) {
                $promotions[] = [$ride, $promoted];
            }
        }
    }

    // 2. Own rides with other participants: cancel what has not started, keep the rest for their history
    $cancelled = [];
    $kept = dbAll("SELECT r.* FROM rides r WHERE r.organizer_user_id = ?
                     AND EXISTS (SELECT 1 FROM ride_signups s WHERE s.ride_id = r.id AND s.user_id <> ? AND s.status <> 'cancelled')", [$userId, $userId]);
    foreach ($kept as $ride) {
        if ($ride['status'] === 'planned' && !hasRideStarted($ride)) {
            dbExec("UPDATE rides SET status = 'cancelled', cancel_reason = ?, cancelled_at = UTC_TIMESTAMP() WHERE id = ?",
                ['Der Organisator hat sein Konto gelöscht. / The organiser deleted their account.', $ride['id']]);
            $cancelled[] = $ride;
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // rides nobody else joined: gone; the others are attributed to the stand-in
        dbExec("DELETE FROM rides WHERE organizer_user_id = ? AND NOT EXISTS (SELECT 1 FROM ride_signups s WHERE s.ride_id = rides.id AND s.user_id <> ? AND s.status <> 'cancelled')", [$userId, $userId]);
        dbExec('UPDATE rides SET organizer_user_id = ?, meeting_point = ?, description = NULL WHERE organizer_user_id = ?', [$ghost, '–', $userId]);

        // tours: deleted, unless a ride of somebody else is planned on them (then the route stays, without person and text)
        dbExec('DELETE FROM tours WHERE owner_user_id = ? AND NOT EXISTS (SELECT 1 FROM rides WHERE rides.tour_id = tours.id)', [$userId]);
        dbExec('UPDATE tours SET owner_user_id = ?, description = NULL, share_token = NULL, share_created_by = NULL, share_created_at = NULL WHERE owner_user_id = ?', [$ghost, $userId]);
        dbExec('UPDATE tours SET share_created_by = NULL, share_token = NULL WHERE share_created_by = ?', [$userId]);

        // forum: threads nobody else wrote in disappear, the rest stays with the person removed and the own text erased
        dbExec('DELETE FROM forum_threads WHERE user_id = ? AND NOT EXISTS (SELECT 1 FROM forum_posts p WHERE p.thread_id = forum_threads.id AND p.user_id <> ? AND p.deleted_at IS NULL)', [$userId, $userId]);
        dbExec('UPDATE forum_threads SET user_id = ? WHERE user_id = ?', [ $ghost, $userId]);
        dbExec("UPDATE forum_posts SET user_id = ?, body = '', deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()) WHERE user_id = ?", [$ghost, $userId]);

        // crew talk: the same
        dbExec('DELETE FROM herd_topics WHERE user_id = ? AND NOT EXISTS (SELECT 1 FROM herd_posts p WHERE p.topic_id = herd_topics.id AND p.user_id <> ? AND p.deleted_at IS NULL)', [$userId, $userId]);
        dbExec('UPDATE herd_topics SET user_id = ? WHERE user_id = ?', [$ghost, $userId]);
        dbExec('UPDATE herd_topics SET last_post_user_id = ? WHERE last_post_user_id = ?', [$ghost, $userId]);
        dbExec("UPDATE herd_posts SET user_id = ?, body = '', deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()) WHERE user_id = ?", [$ghost, $userId]);

        // crews: where nobody else is an active member the crew goes with the person (talk and memberships follow by cascade);
        // crews that go on lose the person as creator
        foreach (dbAll('SELECT group_id AS id FROM group_members WHERE user_id = ?', [$userId]) as $g) {
            $others = (int)dbOne("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND user_id <> ? AND status = 'active'", [$g['id'], $userId])['n'];
            if ($others === 0) {
                dbExec('DELETE FROM rider_groups WHERE id = ?', [$g['id']]);
            }
        }
        dbExec('UPDATE rider_groups SET created_by = ? WHERE created_by = ?', [$ghost, $userId]);

        // traces in other people's records
        dbExec('UPDATE reports SET reporter_user_id = NULL WHERE reporter_user_id = ?', [$userId]);
        dbExec('UPDATE reports SET handled_by = NULL WHERE handled_by = ?', [$userId]);
        dbExec('UPDATE activity_events SET related_user_id = NULL WHERE related_user_id = ?', [$userId]);
        dbExec('UPDATE activity_events SET voided_by = NULL WHERE voided_by = ?', [$userId]);

        // the account; everything left (profile, tokens, memberships, sign-ups, reactions, tracks, live position, events,
        // sign-in links, referral) follows through ON DELETE CASCADE
        dbExec('DELETE FROM users WHERE id = ?', [$userId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }

    foreach (['webp', 'jpg'] as $ext) {
        @unlink(DATA_DIR . '/avatars/' . $userId . '.' . $ext);
    }

    // Mails after the fact; problems are only logged
    foreach ($cancelled as $ride) {
        notifyRiders($ride, 'cancelled', null, $userId, ['reason' => 'Der Organisator hat sein Konto gelöscht. / The organiser deleted their account.']);
    }
    foreach ($promotions as [$ride, $promoted]) {
        notifyRiders($ride, 'promoted', $promoted);
    }
    try {
        $text = tl($me['locale'], 'mail.account_deleted_text', ['name' => $me['display_name']]);
        sendMail($me['email'], tl($me['locale'], 'mail.account_deleted_subject'), mailHtml($text), $text);
    } catch (Throwable $ex) {
        error_log('ElTouro deletion mail: ' . $ex->getMessage());
    }
}
