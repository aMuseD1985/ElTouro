<?php
/** One ride: details, map with meeting point, sign-up / waiting list, participants, managing. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rides_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$ride = loadRide((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($ride === null || !canSeeRide($ride, $uid)) {
    notFound();
}
$rid = (int)$ride['id'];
$self = '/ride.php?id=' . $rid;
$isOrganizer = (int)$ride['organizer_user_id'] === $uid;
$canManage = canManageRide($ride, $me);

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'signup') {
        if (($_POST['road_legal'] ?? '') !== '1') {
            flash(t('ride.error_road_legal'), 'error');
            redirect($self);
        }
        $result = signUpForRide($rid, $uid, ($_POST['photo_consent'] ?? '') === '1');
        flash(t('ride.signup_' . $result), in_array($result, ['confirmed', 'waitlist', 'already'], true) ? 'ok' : 'error');
    } elseif ($action === 'withdraw' && !$isOrganizer) {
        // Riders can withdraw until the start; the organizer cancels the whole ride instead
        if (isRideOpen($ride)) {
            $promoted = cancelRideSignup($rid, $uid);
            if ($promoted) {
                notifyRiders($ride, 'promoted', $promoted);
            }
            flash(t('ride.withdrawn'));
        }
    } elseif ($action === 'photo') {
        dbExec("UPDATE ride_signups SET photo_consent = ? WHERE ride_id = ? AND user_id = ? AND status <> 'cancelled'",
            [($_POST['photo_consent'] ?? '') === '1' ? 1 : 0, $rid, $uid]);
        flash(t('ride.photo_saved'));
    } elseif ($action === 'cancel_ride' && $canManage && isRideOpen($ride)) {
        $reason = postField('reason', 500);
        if (mb_strlen($reason) < 3) {
            flash(t('ride.error_cancel_reason'), 'error');
            redirect($self . '#manage');
        }
        dbExec("UPDATE rides SET status = 'cancelled', cancel_reason = ?, cancelled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'planned'", [$reason, $rid]);
        notifyRiders($ride, 'cancelled', null, $uid, ['reason' => $reason]);
        postRideUpdate($ride, $uid, t('ride.talk_cancelled', ['reason' => $reason]));
        flash(t('ride.cancelled_ok'));
    }
    redirect($self);
}

$tour = loadTour((int)$ride['tour_id']);   // null if the tour was deleted in the meantime
$signup = rideSignup($rid, $uid);
$counts = rideCounts($rid);
$free = max(0, (int)$ride['capacity'] - $counts['confirmed']);
$open = isRideOpen($ride);
$showPeople = canSeeParticipants($ride, $me, $signup);
$people = $showPeople ? dbAll("SELECT u.id, u.display_name, s.status, s.photo_consent FROM ride_signups s JOIN users u ON u.id = s.user_id
                               WHERE s.ride_id = ? AND s.status IN ('confirmed','waitlist')
                               ORDER BY s.status = 'waitlist', s.user_id = ? DESC, s.queued_at, u.display_name", [$rid, $ride['organizer_user_id']]) : [];
$position = waitlistPosition($rid, $uid);
$inCrew = $ride['group_id'] !== null && isActiveMember(membership((int)$ride['group_id'], $uid));

pageHeader($ride['title']);
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<p class="breadcrumbs"><a href="/rides.php"><?= te('rides.title') ?></a> ›<?php if ($ride['crew_slug'] && $inCrew): ?> <a href="/crew.php?s=<?= e(rawurlencode($ride['crew_slug'])) ?>"><?= e($ride['crew_name']) ?></a> ›<?php endif; ?></p>
<div class="title-row">
  <h1><?= e($ride['title']) ?></h1>
  <?php if ($canManage && $open): ?><a class="btn secondary" href="/ride_edit.php?id=<?= $rid ?>"><?= te('ride.edit') ?></a><?php endif; ?>
</div>
<p class="ride-when big"><?= e(formatRideTime($ride['starts_at'])) ?></p>
<p class="muted"><?= te('ride.by', ['name' => $ride['organizer']]) ?> ·
  <?= $ride['visibility'] === 'public' ? te('ride.v_public') : te('ride.v_group', ['name' => (string)$ride['crew_name']]) ?></p>

<?php if ($ride['status'] === 'cancelled'): ?>
  <p class="alert alert-error"><?= te('ride.is_cancelled', ['reason' => (string)$ride['cancel_reason']]) ?></p>
<?php elseif (hasRideStarted($ride)): ?>
  <p class="alert alert-info"><?= te('ride.is_past') ?></p>
<?php endif; ?>

<dl class="facts">
  <div><dt><?= te('ride.meeting_point') ?></dt><dd><?= e($ride['meeting_point']) ?></dd></div>
  <div><dt><?= te('ride.places') ?></dt><dd><?= $free > 0 ? te('ride.free_of', ['n' => $free, 'max' => (int)$ride['capacity']]) : te('ride.full') ?>
    <?php if ($counts['waitlist']): ?><br><span class="muted"><?= te('ride.waitlist_count', ['n' => $counts['waitlist']]) ?></span><?php endif; ?></dd></div>
  <div><dt><?= te('tour.style') ?></dt><dd><?= te('tour.s_' . $ride['style']) ?></dd></div>
  <?php if ($tour): ?>
  <div><dt><?= te('tour.length') ?></dt><dd><?= e(formatKm((int)$tour['distance_m'])) ?></dd></div>
  <div><dt><?= te('tour.difficulty') ?></dt><dd><?= te('tour.d_' . $tour['difficulty']) ?></dd></div>
  <div><dt><?= te('tour.rule_set') ?></dt><dd><?= te('tour.r_' . $tour['rule_set']) ?></dd></div>
  <?php endif; ?>
</dl>

<?php if ($tour): ?>
<div id="tour-map" class="map-large"
     data-geojson="<?= e($tour['geojson']) ?>"
     data-tiles="<?= e((string)$CONFIG['map']['tiles']) ?>"
     data-attribution="<?= e((string)$CONFIG['map']['attribution']) ?>"
     data-start="<?= te('tour.start') ?>" data-finish="<?= te('tour.finish') ?>"
     <?php if ($ride['meeting_lat'] !== null): ?>data-meeting="<?= e($ride['meeting_lat'] . ',' . $ride['meeting_lng']) ?>" data-meeting-label="<?= te('ride.meeting_point') ?>"<?php endif; ?>></div>
<p class="action-bar">
  <?php if (canSeeTour($tour, $uid)): ?><a href="/tour.php?id=<?= (int)$tour['id'] ?>"><?= te('ride.to_tour') ?></a>
    <a class="btn secondary" href="/tour_gpx.php?id=<?= (int)$tour['id'] ?>"><?= te('tour.gpx') ?></a><?php endif; ?>
  <?php if ($ride['talk_topic_id'] && $inCrew): ?><a href="/talk_topic.php?id=<?= (int)$ride['talk_topic_id'] ?>"><?= te('ride.to_talk') ?></a><?php endif; ?>
</p>
<?php if ((int)$tour['freehand_share_pct'] > 0): ?><p class="alert alert-info"><?= te('tour.freehand_warning', ['p' => (int)$tour['freehand_share_pct']]) ?></p><?php endif; ?>
<?php else: ?>
<p class="muted"><?= te('ride.tour_gone') ?></p>
<?php endif; ?>

<?php if ($ride['description']): ?><div class="description"><?= formatText($ride['description']) ?></div><?php endif; ?>

<section class="panel ride-rules">
  <p><strong><?= te('ride.rule_organizer', ['name' => $ride['organizer']]) ?></strong></p>
  <ul>
    <li><?= te('ride.rule_road_legal') ?></li>
    <li><?= te('ride.rule_own_risk') ?></li>
    <li><?= te('ride.rule_signs') ?></li>
    <?php if ($ride['stvo29_confirmed_at']): ?><li><?= te('ride.rule_stvo29') ?></li><?php endif; ?>
  </ul>
</section>

<section class="panel" id="signup">
  <?php if (isSignedUp($signup)): ?>
    <p class="alert alert-ok"><?= $signup['status'] === 'confirmed' ? te($isOrganizer ? 'ride.you_organize' : 'ride.you_confirmed') : te('ride.you_waitlist', ['n' => (int)$position]) ?></p>
    <form method="post" class="form">
      <?= csrfField() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="action" value="photo">
      <div class="field check"><input id="photo_consent" name="photo_consent" type="checkbox" value="1" <?= (int)$signup['photo_consent'] === 1 ? 'checked' : '' ?>>
        <label for="photo_consent"><?= te('ride.photo_consent') ?></label></div>
      <button type="submit" class="secondary-submit"><?= te('ride.photo_save') ?></button>
    </form>
    <?php if (!$isOrganizer && $open): ?>
      <form method="post" class="spaced"><?= csrfField() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="action" value="withdraw">
        <button class="link danger"><?= te('ride.withdraw') ?></button></form>
    <?php endif; ?>
  <?php elseif ($open): ?>
    <h2><?= te($free > 0 ? 'ride.signup_title' : 'ride.waitlist_title') ?></h2>
    <?php if ($free === 0 && !(int)$ride['waitlist_enabled']): ?>
      <p class="muted"><?= te('ride.full_no_waitlist') ?></p>
    <?php else: ?>
    <form method="post" class="form">
      <?= csrfField() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="action" value="signup">
      <div class="field check"><input id="road_legal" name="road_legal" type="checkbox" value="1" required>
        <label for="road_legal"><?= te('ride.road_legal_rider') ?></label></div>
      <div class="field check"><input id="photo_consent" name="photo_consent" type="checkbox" value="1">
        <label for="photo_consent"><?= te('ride.photo_consent') ?></label></div>
      <p class="hint"><?= te('ride.photo_hint') ?></p>
      <button type="submit"><?= te($free > 0 ? 'ride.signup' : 'ride.join_waitlist') ?></button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('ride.participants') ?></h2>
  <?php if (!$showPeople): ?>
    <p class="muted"><?= te('ride.participants_hidden', ['n' => $counts['confirmed']]) ?></p>
  <?php else: ?>
    <ul class="list">
    <?php foreach ($people as $p): ?>
      <li><?= e($p['display_name']) ?><?= (int)$p['id'] === $uid ? ' (' . te('crew.you') . ')' : '' ?>
        <?php if ((int)$p['id'] === (int)$ride['organizer_user_id']): ?><span class="badge"><?= te('ride.organizer') ?></span><?php endif; ?>
        <?php if ($p['status'] === 'waitlist'): ?><span class="badge muted"><?= te('ride.on_waitlist') ?></span><?php endif; ?>
        <?php if (!(int)$p['photo_consent']): ?><span class="badge no-photo" title="<?= te('ride.no_photo_title') ?>"><?= te('ride.no_photo') ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($canManage && $open): ?>
<section class="panel" id="manage">
  <h2><?= te('ride.cancel_title') ?></h2>
  <p class="muted"><?= te('ride.cancel_text') ?></p>
  <form method="post" class="form">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="action" value="cancel_ride">
    <div class="field"><label for="reason"><?= te('ride.cancel_reason') ?></label><input id="reason" name="reason" required minlength="3" maxlength="500"></div>
    <button type="submit" class="danger-submit"><?= te('ride.cancel_button') ?></button>
  </form>
</section>
<?php endif; ?>

<p class="spaced"><a href="/report.php?type=ride&amp;id=<?= $rid ?>"><?= te('report.link') ?></a></p>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/tour_map.js?v=3"></script>
<?php pageFooter();
