<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/rewards_lib.php';
require __DIR__ . '/share_lib.php';
require __DIR__ . '/community_lib.php';
$me = requireLogin();

if (isPost() && in_array($_POST['action'] ?? '', ['avatar', 'avatar_delete'], true)) {
    checkCsrf();
    if ($_POST['action'] === 'avatar_delete') {
        deleteAvatar((int)$me['id']);
        flash(t('profile.avatar_deleted'));
    } elseif (($err = saveAvatar((int)$me['id'], $_FILES['avatar'] ?? [])) !== null) {
        flash(t('profile.' . $err), 'error');
    } else {
        flash(t('profile.avatar_saved'));
    }
    redirect('/profile#avatar');
}

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
$avatarVersion = dbOne('SELECT avatar_version FROM users WHERE id = ?', [$me['id']])['avatar_version'] ?? null;
$google = dbOne("SELECT email FROM user_identities WHERE provider = 'google' AND user_id = ?", [$me['id']]);
$inviteUrl = inviteUrl(userInviteCode((int)$me['id']));
$brought = confirmedReferralCount((int)$me['id']);
$cameVia = dbOne('SELECT r.source, u.display_name FROM referrals r LEFT JOIN users u ON u.id = r.referrer_user_id AND u.status = \'active\' WHERE r.user_id = ?', [$me['id']]);
pageHeader(t('profile.title'));
?>
<section class="narrow">
  <h1><?= te('profile.title') ?></h1>
  <div class="profile-head" id="avatar">
    <?= avatarHtml((int)$me['id'], $me['display_name'], $avatarVersion, 'lg') ?>
    <div>
      <p class="muted"><?= e($me['display_name']) ?> · <?= e($me['email']) ?></p>
      <form method="post" enctype="multipart/form-data" class="avatar-form">
        <?= csrfField() ?><input type="hidden" name="action" value="avatar">
        <label for="avatar-file" class="secondary-submit"><?= te($avatarVersion ? 'profile.avatar_change' : 'profile.avatar_add') ?></label>
        <input type="file" id="avatar-file" name="avatar" accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-autosubmit>
        <noscript><button type="submit"><?= te('profile.avatar_upload') ?></button></noscript>
      </form>
      <?php if ($avatarVersion): ?>
        <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="avatar_delete"><button class="link danger"><?= te('profile.avatar_remove') ?></button></form>
      <?php endif; ?>
      <p class="hint"><?= te('profile.avatar_hint') ?></p>
    </div>
  </div>
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

<details class="panel narrow advanced">
  <summary><?= te('profile.advanced') ?></summary>
  <label class="check"><input type="checkbox" id="allow-select"> <?= te('profile.allow_select') ?></label>
  <p class="hint"><?= te('profile.allow_select_hint') ?></p>
</details>

<section class="panel narrow" id="invite">
  <h2><?= te('invite.title') ?></h2>
  <p><?= te('invite.text') ?></p>
  <?= shareBox($inviteUrl, t('invite.share_title'), t('invite.share_text', ['name' => $me['display_name']]), false) ?>
  <p class="muted"><?= $brought > 0 ? te('invite.count', ['n' => $brought]) : te('invite.none') ?></p>
  <?php if ($cameVia && $cameVia['display_name']): ?><p class="muted"><?= te('invite.came_via', ['name' => $cameVia['display_name']]) ?></p><?php endif; ?>
</section>
<script src="/assets/share.js?v=1"></script>
<?= communityAssets() ?>
<?php pageFooter();
