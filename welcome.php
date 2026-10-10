<?php
/**
 * /welcome?next=… – where visitors without a session land when they open a page that needs an account (a flyer's QR code, a shared link …).
 * The first step is registering; riders who already have an account click "I am already signed in" and go to the login.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/google_lib.php';
require_once __DIR__ . '/rides_lib.php';

$next = cleanNext((string)($_GET['next'] ?? '/'));
if (currentUser()) {
    redirect($next);
}
$open = setting('registration_open', '1') === '1';
$q = $next !== '/' ? '?next=' . rawurlencode($next) : '';

// A public ride that the flyer or a share link points to: show what the visitor was invited to (title and date only)
$teaser = null;
if (preg_match('#^/ride/([A-Z0-9]{6}|\d+)/?$#', $next, $m) && ($ride = ctype_digit($m[1]) && strlen($m[1]) < 6 ? loadRide((int)$m[1]) : dbRide('r.code = ?', [$m[1]])) !== null && $ride['visibility'] === 'public' && $ride['status'] === 'planned') {
    $teaser = ['title' => $ride['title'], 'when' => formatRideTime($ride['starts_at'])];
}
pageHeader(t('welcome.title'));
?>
<section class="narrow welcome">
  <h1><?= te('welcome.title') ?></h1>
  <?php if ($teaser): ?>
    <p class="alert alert-info"><?= te('welcome.invited', ['title' => $teaser['title'], 'when' => $teaser['when']]) ?></p>
  <?php elseif ($next !== '/'): ?>
    <p class="alert alert-info"><?= te('welcome.needs_account') ?></p>
  <?php endif; ?>
  <?= touroSays(t('welcome.touro'), 'look-back-large') ?>
  <p class="lead"><?= te('welcome.intro') ?></p>
  <ul class="welcome-list">
    <li>🗺 <?= te('welcome.b1') ?></li>
    <li>🐂 <?= te('welcome.b2') ?></li>
    <li>🛴 <?= te('welcome.b3') ?></li>
  </ul>
  <?php if ($open): ?>
    <p><a class="btn btn-big" href="/register<?= $q ?>"><?= te('welcome.register') ?></a></p>
    <?= googleButton($next, 'google.button_register') ?>
  <?php else: ?>
    <p class="muted"><?= te('register.closed') ?></p>
  <?php endif; ?>
  <p class="welcome-have"><a class="btn secondary" href="/login<?= $q ?>"><?= te('welcome.have_account') ?></a></p>
</section>
<?php pageFooter();
