<?php
/**
 * Offer a ride (?tour=ID) or edit one (?id=ID). Creating is two steps without JavaScript:
 * first pick the tour, then the form with a map of that tour (click sets the meeting point).
 * Tour and audience are fixed after creation – riders signed up for exactly that.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rides_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$ride = null;
if (isset($_GET['id']) || isset($_POST['id'])) {
    $ride = loadRide((int)($_GET['id'] ?? $_POST['id']));
    if ($ride === null || !canSeeRide($ride, $uid) || !canManageRide($ride, $me)) {
        notFound();
    }
    if (!isRideOpen($ride)) {
        flash(t('ride.not_editable'), 'error');
        redirect('/ride/' . (int)$ride['id']);
    }
}

$tourId = $ride ? (int)$ride['tour_id'] : (int)($_GET['tour'] ?? $_POST['tour'] ?? 0);
$tour = $tourId ? loadTour($tourId) : null;
if ($ride === null && $tour !== null && !canSeeTour($tour, $uid)) {
    $tour = null;
}
$crews = activeCrewsOf($uid);
$crewIds = array_map('intval', array_column($crews, 'id'));

// Step 1: choose a tour
if ($ride === null && $tour === null) {
    $tours = toursForRides($uid);
    pageHeader(t('ride.new'));
    ?>
<section class="narrow">
  <p class="breadcrumbs"><a href="/rides"><?= te('rides.title') ?></a> ›</p>
  <h1><?= te('ride.new') ?></h1>
  <p><?= te('ride.pick_tour_text') ?></p>
  <?php if (!$tours): ?>
    <p class="alert alert-info"><?= te('ride.no_tours') ?></p>
    <p><a class="btn" href="/tour/plan"><?= te('tour.new') ?></a></p>
  <?php else: ?>
  <form method="get" class="form">
    <div class="field"><label for="tour"><?= te('ride.tour') ?></label>
      <select id="tour" name="tour" required>
        <?php foreach ($tours as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['title']) ?> · <?= e(formatKm((int)$t['distance_m'])) ?> · <?= te('tour.v_' . $t['visibility']) ?></option><?php endforeach; ?>
      </select></div>
    <button type="submit"><?= te('ride.continue') ?></button>
  </form>
  <?php endif; ?>
</section>
<?php
    pageFooter();
    exit;
}
if ($tour === null) {
    // The tour of an existing ride was deleted – the ride can still be edited, just without map
    $tour = dbOne('SELECT * FROM tours WHERE id = ?', [$tourId]);
}

$localStart = $ride ? utcToLocal($ride['starts_at']) : null;
$w = [
    'title'       => $ride['title'] ?? $tour['title'],
    'description' => $ride['description'] ?? '',
    'date'        => $localStart ? $localStart->format('Y-m-d') : '',
    'time'        => $localStart ? $localStart->format('H:i') : '',
    'meeting'     => $ride['meeting_point'] ?? '',
    'lat'         => $ride['meeting_lat'] ?? '',
    'lng'         => $ride['meeting_lng'] ?? '',
    'capacity'    => (string)($ride['capacity'] ?? 10),
    'waitlist'    => (int)($ride['waitlist_enabled'] ?? 1) === 1,
    'style'       => $ride['style'] ?? $tour['style'],
    'audience'    => $ride ? ($ride['visibility'] === 'public' ? 'public' : (string)$ride['group_id'])
                           : (string)($tour['visibility'] === 'group' ? $tour['owner_group_id'] : ($crewIds[0] ?? 'public')),
    'photo'       => false,
];
$errors = [];

if (isPost()) {
    checkCsrf();
    $w = [
        'title'       => postField('title', 120),
        'description' => postText('description', 4000),
        'date'        => postField('date', 10),
        'time'        => postField('time', 5),
        'meeting'     => postField('meeting', 200),
        'lat'         => postField('meeting_lat', 20),
        'lng'         => postField('meeting_lng', 20),
        'capacity'    => postField('capacity', 5),
        'waitlist'    => ($_POST['waitlist'] ?? '') === '1',
        'style'       => postField('style'),
        'audience'    => $ride ? $w['audience'] : postField('audience', 20),
        'photo'       => ($_POST['photo_consent'] ?? '') === '1',
    ];
    $startsAt = localToUtc($w['date'], $w['time']);
    $capacity = ctype_digit($w['capacity']) ? (int)$w['capacity'] : 0;
    $hasPoint = $w['lat'] !== '' && $w['lng'] !== '' && isValidCoordinate($w['lat'], $w['lng']);
    $counts = $ride ? rideCounts((int)$ride['id']) : ['confirmed' => 0, 'waitlist' => 0];

    if (mb_strlen($w['title']) < 3) $errors[] = t('ride.error_title');
    if ($startsAt === null) {
        $errors[] = t('ride.error_date');
    } elseif ($startsAt->getTimestamp() < time() + 15 * 60 || $startsAt->getTimestamp() > time() + 366 * 86400) {
        $errors[] = t('ride.error_date_range');
    }
    if (mb_strlen($w['meeting']) < 3) $errors[] = t('ride.error_meeting');
    if ($capacity < RIDE_MIN_CAPACITY || $capacity > RIDE_MAX_CAPACITY) {
        $errors[] = t('ride.error_capacity', ['min' => RIDE_MIN_CAPACITY, 'max' => RIDE_MAX_CAPACITY]);
    } elseif ($capacity < $counts['confirmed']) {
        $errors[] = t('ride.error_capacity_below', ['n' => $counts['confirmed']]);
    }
    if (!in_array($w['style'], ['relaxed', 'social', 'sporty'], true)) $w['style'] = 'social';
    $needsStvo = $capacity >= STVO29_THRESHOLD && empty($ride['stvo29_confirmed_at']);
    if ($needsStvo && ($_POST['stvo29'] ?? '') !== '1') $errors[] = t('ride.error_stvo29', ['n' => STVO29_THRESHOLD]);

    if (!$ride) {
        $visibility = $w['audience'] === 'public' ? 'public' : 'group';
        $groupId = $visibility === 'group' ? (int)$w['audience'] : null;
        if ($visibility === 'group' && !in_array($groupId, $crewIds, true)) {
            $errors[] = t('ride.error_audience');
        } elseif (!rideAudienceCanSeeTour($visibility, $groupId, $tour)) {
            $errors[] = t('ride.error_tour_visibility');
        }
        if (($_POST['road_legal'] ?? '') !== '1') $errors[] = t('ride.error_road_legal');
    }

    if (!$errors) {
        $pdo = db();
        $lat = $hasPoint ? round((float)$w['lat'], 6) : null;
        $lng = $hasPoint ? round((float)$w['lng'], 6) : null;
        $stvoSql = $needsStvo ? 'UTC_TIMESTAMP()' : 'stvo29_confirmed_at';
        if ($ride) {
            $pdo->beginTransaction();
            dbOne('SELECT id FROM rides WHERE id = ? FOR UPDATE', [$ride['id']]);
            dbExec("UPDATE rides SET title = ?, description = ?, starts_at = ?, meeting_point = ?, meeting_lat = ?, meeting_lng = ?,
                           capacity = ?, waitlist_enabled = ?, style = ?, stvo29_confirmed_at = $stvoSql WHERE id = ?",
                [$w['title'], $w['description'] ?: null, $startsAt->format('Y-m-d H:i:s'), $w['meeting'], $lat, $lng,
                 $capacity, $w['waitlist'] ? 1 : 0, $w['style'], $ride['id']]);
            $promoted = promoteWaitlist((int)$ride['id']);
            $pdo->commit();

            $updated = loadRide((int)$ride['id']);
            if ($promoted) {
                notifyRiders($updated, 'promoted', $promoted);
            }
            $moved = $updated['starts_at'] !== $ride['starts_at'] || $updated['meeting_point'] !== $ride['meeting_point'];
            if ($moved) {
                notifyRiders($updated, 'changed', null, $uid);
                postRideUpdate($updated, $uid, t('ride.talk_changed', ['when' => formatRideTime($updated['starts_at']), 'meeting' => $updated['meeting_point']]));
            }
            flash(t($moved ? 'ride.saved_notified' : 'ride.saved'));
            redirect('/ride/' . (int)$ride['id']);
        }

        $pdo->beginTransaction();
        dbExec("INSERT INTO rides (tour_id, organizer_user_id, group_id, visibility, title, description, content_lang, starts_at,
                       meeting_point, meeting_lat, meeting_lng, capacity, waitlist_enabled, style, stvo29_confirmed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($needsStvo ? 'UTC_TIMESTAMP()' : 'NULL') . ")",
            [$tour['id'], $uid, $groupId, $visibility, $w['title'], $w['description'] ?: null, $LANG, $startsAt->format('Y-m-d H:i:s'),
             $w['meeting'], $lat, $lng, $capacity, $w['waitlist'] ? 1 : 0, $w['style']]);
        $rid = (int)$pdo->lastInsertId();
        // The organizer rides along and takes the first place
        dbExec("INSERT INTO ride_signups (ride_id, user_id, status, road_legal_confirmed_at, photo_consent) VALUES (?, ?, 'confirmed', UTC_TIMESTAMP(), ?)",
            [$rid, $uid, $w['photo'] ? 1 : 0]);
        if ($groupId !== null) {
            $new = loadRide($rid);
            [$topicId] = createTalkTopic($groupId, $uid, mb_substr(t('ride.talk_title', ['title' => $w['title'], 'date' => utcToLocal($new['starts_at'])->format($LANG === 'de' ? 'd.m.' : 'j M')]), 0, 150),
                rideAnnouncement($new, $tour));
            dbExec('UPDATE rides SET talk_topic_id = ? WHERE id = ?', [$topicId, $rid]);
        }
        $pdo->commit();
        if ($groupId !== null) {
            require_once __DIR__ . '/notify_lib.php';
            $members = array_column(dbAll("SELECT user_id FROM group_members WHERE group_id = ? AND status = 'active'", [$groupId]), 'user_id');
            notifyMany($members, $uid, 'ride_new', '/ride/' . $rid, ['title' => $w['title'], 'crew' => (string)(loadCrewById($groupId)['name'] ?? '')]);
        }
        flash(t('ride.created'));
        redirect('/ride/' . $rid);
    }
}

$jsTexts = ['meeting' => t('ride.meeting_point')];
pageHeader($ride ? t('ride.edit') : t('ride.new'));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<p class="breadcrumbs"><a href="/rides"><?= te('rides.title') ?></a> ›<?php if ($ride): ?> <a href="/ride/<?= (int)$ride['id'] ?>"><?= e($ride['title']) ?></a> ›<?php endif; ?></p>
<h1><?= te($ride ? 'ride.edit' : 'ride.new') ?></h1>
<?php foreach ($errors as $err): ?><p class="alert alert-error" role="alert"><?= e($err) ?></p><?php endforeach; ?>
<p class="muted"><?= te('ride.tour') ?>: <a href="/tour/<?= (int)$tour['id'] ?>"><?= e($tour['title']) ?></a> · <?= e(formatKm((int)$tour['distance_m'])) ?>
  <?php if (!$ride): ?> · <a href="/ride/new"><?= te('ride.other_tour') ?></a><?php endif; ?></p>

<?php if (!$tour['deleted_at']): ?>
<p class="hint"><?= te('ride.map_hint') ?></p>
<div id="ride-map" class="map-large"
     data-geojson="<?= e($tour['geojson']) ?>"
     <?= mapData() ?>
     data-texts="<?= e(json_encode($jsTexts, JSON_UNESCAPED_UNICODE)) ?>"></div>
<p><button type="button" class="link" id="meeting-start"><?= te('ride.meeting_at_start') ?></button>
   <button type="button" class="link" id="meeting-clear"><?= te('ride.meeting_clear') ?></button></p>
<?php endif; ?>

<form method="post" class="form wide" id="ride-form">
  <?= csrfField() ?>
  <?php if ($ride): ?><input type="hidden" name="id" value="<?= (int)$ride['id'] ?>"><?php else: ?><input type="hidden" name="tour" value="<?= (int)$tour['id'] ?>"><?php endif; ?>
  <input type="hidden" name="meeting_lat" id="meeting_lat" value="<?= e((string)$w['lat']) ?>">
  <input type="hidden" name="meeting_lng" id="meeting_lng" value="<?= e((string)$w['lng']) ?>">

  <div class="field"><label for="title"><?= te('ride.title_label') ?></label>
    <input id="title" name="title" required minlength="3" maxlength="120" value="<?= e($w['title']) ?>"></div>
  <div class="row3">
    <div class="field"><label for="date"><?= te('ride.date') ?></label><input id="date" name="date" type="date" required value="<?= e($w['date']) ?>"></div>
    <div class="field"><label for="time"><?= te('ride.time') ?></label><input id="time" name="time" type="time" required value="<?= e($w['time']) ?>"></div>
    <div class="field"><label for="style"><?= te('tour.style') ?></label>
      <select id="style" name="style"><?php foreach (['relaxed', 'social', 'sporty'] as $o): ?>
        <option value="<?= $o ?>" <?= $w['style'] === $o ? 'selected' : '' ?>><?= te('tour.s_' . $o) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field"><label for="meeting"><?= te('ride.meeting_point') ?></label>
    <input id="meeting" name="meeting" required minlength="3" maxlength="200" value="<?= e($w['meeting']) ?>" aria-describedby="meeting-h">
    <p class="hint" id="meeting-h"><?= te('ride.meeting_hint') ?></p></div>
  <div class="row">
    <div class="field"><label for="capacity"><?= te('ride.capacity') ?></label>
      <input id="capacity" name="capacity" type="number" required min="<?= RIDE_MIN_CAPACITY ?>" max="<?= RIDE_MAX_CAPACITY ?>" value="<?= e($w['capacity']) ?>" aria-describedby="cap-h" data-stvo="<?= STVO29_THRESHOLD ?>">
      <p class="hint" id="cap-h"><?= te('ride.capacity_hint') ?></p></div>
    <div class="field check"><input id="waitlist" name="waitlist" type="checkbox" value="1" <?= $w['waitlist'] ? 'checked' : '' ?>>
      <label for="waitlist"><?= te('ride.waitlist_enabled') ?></label></div>
  </div>
  <?php if (!$ride): ?>
  <div class="field"><label for="audience"><?= te('ride.audience') ?></label>
    <select id="audience" name="audience">
      <?php foreach ($crews as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $w['audience'] === (string)$c['id'] ? 'selected' : '' ?>><?= te('ride.audience_crew', ['name' => $c['name']]) ?></option><?php endforeach; ?>
      <option value="public" <?= $w['audience'] === 'public' ? 'selected' : '' ?>><?= te('ride.audience_public') ?></option>
    </select>
    <p class="hint"><?= te('ride.audience_hint') ?></p></div>
  <?php else: ?>
  <p class="muted"><?= te('ride.audience') ?>: <?= $ride['visibility'] === 'public' ? te('ride.audience_public') : te('ride.audience_crew', ['name' => (string)$ride['crew_name']]) ?></p>
  <?php endif; ?>
  <div class="field"><label for="description"><?= te('ride.description') ?></label>
    <textarea id="description" name="description" rows="5" maxlength="4000" aria-describedby="desc-h"><?= e($w['description']) ?></textarea>
    <p class="hint" id="desc-h"><?= te('ride.description_hint') ?></p></div>

  <?php if (empty($ride['stvo29_confirmed_at'])): ?>
  <div class="field check" id="stvo29-field"><input id="stvo29" name="stvo29" type="checkbox" value="1">
    <label for="stvo29"><?= te('ride.stvo29_confirm', ['n' => STVO29_THRESHOLD]) ?></label></div>
  <?php endif; ?>
  <?php if (!$ride): ?>
  <div class="field check"><input id="road_legal" name="road_legal" type="checkbox" value="1" required>
    <label for="road_legal"><?= te('ride.road_legal_organizer') ?></label></div>
  <div class="field check"><input id="photo_consent" name="photo_consent" type="checkbox" value="1" <?= $w['photo'] ? 'checked' : '' ?>>
    <label for="photo_consent"><?= te('ride.photo_consent') ?></label></div>
  <p class="hint"><?= te('ride.organizer_note') ?></p>
  <?php endif; ?>
  <button type="submit"><?= te($ride ? 'ride.save' : 'ride.publish') ?></button>
</form>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/ride_form.js?v=2"></script>
<?php pageFooter();
