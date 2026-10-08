<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$me = currentUser();
pageHeader(t('home.title'));

if ($me === null): ?>
  <section class="narrow">
    <h1 class="claim"><?= te('home.guest_title') ?></h1>
    <p><?= te('home.guest_text') ?></p>
    <p><a class="btn" href="/login"><?= te('nav.login') ?></a>
    <?php if (setting('registration_open', '1') === '1'): ?> <a class="btn secondary" href="/register"><?= te('nav.register') ?></a><?php endif; ?></p>
  </section>
<?php else:
  $mine = dbAll("SELECT g.slug, g.name, g.region, m.status FROM group_members m
                   JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                  WHERE m.user_id = ? ORDER BY g.name", [$me['id']]); ?>
  <h1><?= te('home.hello', ['name' => $me['display_name']]) ?></h1>
  <?php require __DIR__ . '/rides_lib.php';
  $myRides = visibleRides((int)$me['id'], true, "(r.organizer_user_id = ? OR ms.status IN ('confirmed','waitlist'))", [(int)$me['id']], 6); ?>
  <section>
    <div class="title-row">
      <h2><?= te('home.my_rides') ?></h2>
      <a class="btn secondary" href="/rides"><?= te('home.all_rides') ?></a>
    </div>
    <?php rideCards($myRides, 'home.no_rides'); ?>
  </section>
  <section>
    <h2><?= te('home.my_crews') ?></h2>
    <?php if (!$mine): ?>
      <p><?= te('home.no_crews') ?></p>
      <p><a class="btn" href="/crews"><?= te('crews.discover') ?></a> <a class="btn secondary" href="/crews/new"><?= te('crews.new') ?></a></p>
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
