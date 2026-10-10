<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$me = currentUser();
pageHeader(t('home.title'));

if ($me === null): ?>
  <section class="landing">
    <div class="landing-hero">
      <div>
        <h1 class="claim"><?= te('home.guest_title') ?></h1>
        <p class="landing-lead"><?= te('home.guest_text') ?></p>
        <p class="landing-cta"><?php if (setting('registration_open', '1') === '1'): ?><a class="btn" href="/register"><?= te('home.guest_register') ?></a> <?php endif; ?>
          <a class="btn secondary" href="/login"><?= te('nav.login') ?></a></p>
        <p class="hint"><?= te('home.guest_small') ?></p>
      </div>
      <img class="landing-mascot" src="/assets/img/mascot/side-large.webp?v=2" alt="" width="310" height="400">
    </div>
    <ul class="landing-steps">
      <li><span class="step-i" aria-hidden="true">🗺</span><h3><?= te('home.step1_title') ?></h3><p><?= te('home.step1_text') ?></p></li>
      <li><span class="step-i" aria-hidden="true">🐂</span><h3><?= te('home.step2_title') ?></h3><p><?= te('home.step2_text') ?></p></li>
      <li><span class="step-i" aria-hidden="true">📅</span><h3><?= te('home.step3_title') ?></h3><p><?= te('home.step3_text') ?></p></li>
    </ul>
  </section>
<?php else:
  $uid = (int)$me['id'];
  require_once __DIR__ . '/rides_lib.php';
  require_once __DIR__ . '/tours_lib.php';
  $mine = dbAll("SELECT g.slug, g.name, g.region, m.status FROM group_members m
                   JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                  WHERE m.user_id = ? ORDER BY g.name", [$uid]);
  $myRides = visibleRides($uid, true, "r.status <> 'cancelled' AND (r.organizer_user_id = ? OR ms.status IN ('confirmed','waitlist'))", [$uid], 6);
  $myTours = dbAll("SELECT id, title, distance_m, difficulty, style FROM tours WHERE owner_user_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 3", [$uid]);
  // Where the rider left off: the tour of the last recorded ride, otherwise the last tour they edited
  $last = dbOne("SELECT t.id, t.title FROM track_sessions s JOIN tours t ON t.id = s.tour_id AND t.deleted_at IS NULL WHERE s.user_id = ? ORDER BY s.started_at DESC LIMIT 1", [$uid])
       ?? ($myTours[0] ?? null);
  $hasPhoto = dbOne('SELECT avatar_version FROM users WHERE id = ?', [$uid])['avatar_version'] !== null;
  $hasRide = dbOne("SELECT 1 AS x FROM ride_signups WHERE user_id = ? AND status <> 'cancelled' LIMIT 1", [$uid]) !== null
          || dbOne('SELECT 1 AS x FROM rides WHERE organizer_user_id = ? AND deleted_at IS NULL LIMIT 1', [$uid]) !== null;
  $steps = [[(bool)$myTours, '/tour/plan', 'home.todo_tour'], [(bool)$mine, '/crews', 'home.todo_crew'], [$hasRide, '/rides', 'home.todo_ride'], [$hasPhoto, '/profile#avatar', 'home.todo_photo']];
  $done = count(array_filter(array_column($steps, 0))); ?>
  <h1><?= te('home.hello', ['name' => $me['display_name']]) ?></h1>

  <div class="home-actions">
    <a class="btn" href="/tour/plan">🗺 <?= te('home.plan') ?></a>
    <?php require_once __DIR__ . '/track_lib.php'; $running = anyActiveRecording($uid); ?>
    <?php if ($running): ?><a class="btn" href="<?= $running['tour_id'] ? '/tour/' . (int)$running['tour_id'] . '/go' : '/free' ?>">⏺ <?= te('home.running', ['title' => $running['title'] ?: t('drive.free')]) ?></a>
    <?php elseif ($last): ?><a class="btn secondary" href="/tour/<?= (int)$last['id'] ?>/go">▶ <?= te('home.resume', ['title' => $last['title']]) ?></a><?php endif; ?>
    <a class="btn secondary" href="/live">📍 <?= te('profile.more_live') ?></a>
  </div>

  <?php if ($done < count($steps)): ?>
  <section class="panel todo">
    <h2><?= te('home.todo_title') ?> <span class="badge"><?= $done ?>/<?= count($steps) ?></span></h2>
    <ul class="todo-list">
      <?php foreach ($steps as [$ok, $href, $key]): ?>
        <li class="<?= $ok ? 'is-done' : '' ?>"><span aria-hidden="true"><?= $ok ? '✔' : '○' ?></span> <?= $ok ? te($key) : '<a href="' . $href . '">' . te($key) . '</a>' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section>
    <div class="title-row">
      <h2><?= te('home.my_rides') ?></h2>
      <a class="btn secondary" href="/rides"><?= te('home.all_rides') ?></a>
    </div>
    <?php rideCards($myRides, 'home.no_rides'); ?>
  </section>

  <?php if ($myTours): ?>
  <section>
    <div class="title-row">
      <h2><?= te('home.my_tours') ?></h2>
      <a class="btn secondary" href="/tours"><?= te('home.all_tours') ?></a>
    </div>
    <ul class="cards">
      <?php foreach ($myTours as $t): ?>
        <li class="card"><h3><a href="/tour/<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a></h3>
          <p class="muted"><?= e(formatKm((int)$t['distance_m'])) ?> · <?= te('tour.d_' . $t['difficulty']) ?> · <?= te('tour.s_' . $t['style']) ?></p></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section>
    <h2><?= te('home.my_crews') ?></h2>
    <?php if (!$mine): ?>
      <?= touroSays(t('home.no_crews'), 'front', '<p><a class="btn" href="/crews">' . te('crews.discover') . '</a> <a class="btn secondary" href="/crews/new">' . te('crews.new') . '</a></p>') ?>
    <?php else: ?>
      <ul class="list">
      <?php foreach ($mine as $c): ?>
        <li><a href="/crew/<?= e(rawurlencode($c['slug'])) ?>"><?= e($c['name']) ?></a>
          <?php if ($c['status'] === 'pending'): ?><span class="badge muted"><?= te('crew.request_pending') ?></span><?php endif; ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif;
pageFooter();
