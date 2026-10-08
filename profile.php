<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rewards_lib.php';
require __DIR__ . '/share_lib.php';
$me = requireLogin();

if (isPost()) {
    checkCsrf();
    $lang = in_array($_POST['locale'] ?? '', LANGUAGES, true) ? $_POST['locale'] : $me['locale'];
    dbExec('UPDATE user_profiles SET bio = ?, home_region = ? WHERE user_id = ?',
        [postField('bio', 1000) ?: null, postField('region', 100) ?: null, $me['id']]);
    dbExec('UPDATE users SET locale = ? WHERE id = ?', [$lang, $me['id']]);
    setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    flash(t('profile.saved'));
    redirect('/profile');
}

$p = dbOne('SELECT bio, home_region FROM user_profiles WHERE user_id = ?', [$me['id']]) ?? ['bio' => '', 'home_region' => ''];
$google = dbOne("SELECT email FROM user_identities WHERE provider = 'google' AND user_id = ?", [$me['id']]);
$inviteUrl = inviteUrl(userInviteCode((int)$me['id']));
$brought = confirmedReferralCount((int)$me['id']);
$cameVia = dbOne('SELECT r.source, u.display_name FROM referrals r LEFT JOIN users u ON u.id = r.referrer_user_id AND u.status = \'active\' WHERE r.user_id = ?', [$me['id']]);
pageHeader(t('profile.title'));
?>
<section class="narrow">
  <h1><?= te('profile.title') ?></h1>
  <p class="muted"><?= e($me['display_name']) ?> · <?= e($me['email']) ?></p>
  <?php if ($google): ?><p class="muted"><?= te('google.connected', ['email' => $google['email']]) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= csrfField() ?>
    <div class="field"><label for="region"><?= te('profile.region') ?></label>
      <input id="region" name="region" maxlength="100" value="<?= e($p['home_region']) ?>"></div>
    <div class="field"><label for="bio"><?= te('profile.bio') ?></label>
      <textarea id="bio" name="bio" rows="4" maxlength="1000"><?= e($p['bio']) ?></textarea></div>
    <div class="field"><label for="locale"><?= te('profile.language') ?></label>
      <select id="locale" name="locale">
        <option value="de" <?= $me['locale'] === 'de' ? 'selected' : '' ?>>Deutsch</option>
        <option value="en" <?= $me['locale'] === 'en' ? 'selected' : '' ?>>English</option>
      </select></div>
    <button type="submit"><?= te('profile.button') ?></button>
  </form>
</section>

<section class="panel narrow" id="invite">
  <h2><?= te('invite.title') ?></h2>
  <p><?= te('invite.text') ?></p>
  <?= shareBox($inviteUrl, t('invite.share_title'), t('invite.share_text', ['name' => $me['display_name']]), false) ?>
  <p class="muted"><?= $brought > 0 ? te('invite.count', ['n' => $brought]) : te('invite.none') ?></p>
  <?php if ($cameVia && $cameVia['display_name']): ?><p class="muted"><?= te('invite.came_via', ['name' => $cameVia['display_name']]) ?></p><?php endif; ?>
</section>
<script src="/assets/share.js?v=1"></script>
<?php pageFooter();
