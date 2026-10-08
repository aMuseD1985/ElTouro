<?php
/**
 * Personal invite link: /join/<code>[?via=channel]. Remembers who invited the visitor (first touch)
 * and shows a short welcome with the sign-up options. Logged-in riders are just sent home.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rewards_lib.php';
require __DIR__ . '/google_lib.php';

if (currentUser()) {
    redirect('/');
}
$code = (string)($_GET['code'] ?? '');
$inviter = preg_match('/^[a-z0-9]{10}$/', $code)
    ? dbOne("SELECT id, display_name FROM users WHERE invite_code = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$code])
    : null;
if ($inviter === null) {
    redirect('/register');
}
rememberReferral('invite_link', (int)$inviter['id'], 'user', (int)$inviter['id'], $_GET['via'] ?? null);

pageHeader(t('join.title', ['name' => $inviter['display_name']]), [
    'og:title'       => t('join.title', ['name' => $inviter['display_name']]),
    'og:description' => t('home.guest_text'),
    'og:site_name'   => 'ElTouro',
]);
?>
<section class="narrow">
  <p class="share-kicker"><?= te('join.kicker') ?></p>
  <h1><?= te('join.title', ['name' => $inviter['display_name']]) ?></h1>
  <p><?= te('share.cta_text') ?></p>
  <?php if (setting('registration_open', '1') === '1'): ?>
    <?= googleButton('/', 'google.button_register') ?>
    <p><a class="btn" href="/register"><?= te('join.register') ?></a></p>
  <?php else: ?>
    <p class="alert alert-info"><?= te('register.closed') ?></p>
  <?php endif; ?>
  <p class="muted"><?= te('join.have_account') ?> <a href="/login"><?= te('nav.login') ?></a></p>
</section>
<?php pageFooter();
