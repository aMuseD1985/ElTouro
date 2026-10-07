<?php
/** Rides overview: my sign-ups, upcoming rides of my crews and public rides, recent past rides. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rides_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

if (random_int(1, 20) === 1) {   // occasional cleanup instead of a cron job
    purgeOldRideSignups();
}

$mine = visibleRides($uid, true, "(r.organizer_user_id = ? OR ms.status IN ('confirmed','waitlist'))", [$uid]);
$others = visibleRides($uid, true, "r.organizer_user_id <> ? AND (ms.status IS NULL OR ms.status = 'cancelled')", [$uid]);
$past = visibleRides($uid, false, "(r.organizer_user_id = ? OR ms.status = 'confirmed')", [$uid], 10);

pageHeader(t('rides.title'));
?>
<div class="title-row">
  <h1><?= te('rides.title') ?></h1>
  <a class="btn" href="/ride_edit.php"><?= te('ride.new') ?></a>
</div>
<p class="muted"><?= te('rides.intro') ?></p>
<h2><?= te('rides.mine') ?></h2><?php rideCards($mine, 'rides.none_mine'); ?>
<h2><?= te('rides.upcoming') ?></h2><?php rideCards($others, 'rides.none'); ?>
<?php if ($past): ?><h2><?= te('rides.past') ?></h2><?php rideCards($past, 'rides.none'); endif; ?>
<?php pageFooter();
