<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rewards_lib.php';
require_once __DIR__ . '/share_lib.php';
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
    if (isset($_POST['radius_km'])) {
        require_once __DIR__ . '/push_lib.php';
        saveNotifyPrefs((int)$me['id'], $_POST);
    }
    if (isset($_POST['digest'])) {
        dbExec('UPDATE users SET mail_digest = ? WHERE id = ?', [in_array($_POST['digest'], ['off', 'daily', 'weekly'], true) ? $_POST['digest'] : 'off', $me['id']]);
    }
    setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    flash(t('profile.saved'));
    redirect('/profile');
}

$p = dbOne('SELECT bio, home_region FROM user_profiles WHERE user_id = ?', [$me['id']]) ?? ['bio' => '', 'home_region' => ''];
$avatarVersion = dbOne('SELECT avatar_version FROM users WHERE id = ?', [$me['id']])['avatar_version'] ?? null;
$google = dbOne("SELECT email FROM user_identities WHERE provider = 'google' AND user_id = ?", [$me['id']]);
require_once __DIR__ . '/push_lib.php';
$prefs = notifyPrefs((int)$me['id']);
$vapid = vapidKeys(false);
$digest = (string)(dbOne('SELECT mail_digest FROM users WHERE id = ?', [$me['id']])['mail_digest'] ?? 'off');
$consentRow = dbOne('SELECT consent_version, consent_at FROM users WHERE id = ?', [$me['id']]);
require_once __DIR__ . '/rides_lib.php';
$inviteUrl = inviteUrl(userInviteCode((int)$me['id']));
$brought = confirmedReferralCount((int)$me['id']);
$cameVia = dbOne('SELECT r.source, u.display_name FROM referrals r LEFT JOIN users u ON u.id = r.referrer_user_id AND u.status = \'active\' WHERE r.user_id = ?', [$me['id']]);
pageHeader(t('profile.title'));
?>
<section class="narrow">
  <h1><?= te('profile.title') ?></h1>
  <nav class="more-links" aria-label="<?= te('profile.more') ?>">
    <a href="/live"><?= te('profile.more_live') ?></a>
    <?php if ((int)$me['is_admin'] === 1): ?><a href="/admin/"><?= te('nav.admin') ?></a><?php endif; ?>
    <form method="post" action="/logout" class="inline"><?= csrfField() ?><button type="submit" class="link"><?= te('nav.logout') ?></button></form>
  </nav>
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
    <div class="field" id="notifications"><label for="digest"><?= te('notif.digest') ?></label>
      <select id="digest" name="digest">
        <?php foreach (['off', 'daily', 'weekly'] as $o): ?><option value="<?= $o ?>" <?= $digest === $o ? 'selected' : '' ?>><?= te('notif.digest_' . $o) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= te('notif.digest_hint') ?></p></div>
    <div class="field" id="push"><strong><?= te('push.title') ?></strong>
      <p class="hint"><?= te('push.intro') ?></p>
      <p><button type="button" id="push-toggle" class="secondary-submit" data-vapid="<?= e((string)($vapid[1] ?? '')) ?>" data-csrf="<?= e(csrfToken()) ?>"
                 data-on="<?= te('push.disable') ?>" data-off="<?= te('push.enable') ?>" data-blocked="<?= te('push.blocked') ?>" data-unsupported="<?= te('push.unsupported') ?>"><?= te('push.enable') ?></button>
         <button type="button" id="push-test" class="secondary-submit" hidden><?= te('push.test') ?></button>
         <span id="push-status" class="muted" role="status"></span></p>
      <label class="choice"><input type="checkbox" name="push_social" value="1" <?= $prefs['push_social'] ? 'checked' : '' ?>> <?= te('push.social') ?></label>
      <label class="choice"><input type="checkbox" name="push_rides_near" value="1" <?= $prefs['push_rides_near'] ? 'checked' : '' ?>> <?= te('push.rides_near') ?></label>
      <label class="choice"><input type="checkbox" name="push_rides_soon" value="1" <?= $prefs['push_rides_soon'] ? 'checked' : '' ?>> <?= te('push.rides_soon') ?></label>
      <label class="choice"><input type="checkbox" name="mail_rides_near" value="1" <?= $prefs['mail_rides_near'] ? 'checked' : '' ?>> <?= te('push.mail_rides') ?></label>
      <label for="radius_km"><?= te('push.radius') ?></label>
      <select id="radius_km" name="radius_km"><?php foreach (NEAR_RADII as $r): ?><option value="<?= $r ?>" <?= (int)$prefs['radius_km'] === $r ? 'selected' : '' ?>><?= te('push.km', ['n' => $r]) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= te('push.home_hint') ?></p>
      <div id="home-pick" class="map-medium" data-lat="<?= e((string)($prefs['home_lat'] ?? '51.4')) ?>" data-lng="<?= e((string)($prefs['home_lng'] ?? '7.0')) ?>" data-fixed="<?= $prefs['home_lat'] !== null ? '1' : '' ?>" <?= mapData() ?>></div>
      <input type="hidden" name="home_lat" id="home-lat" value="<?= e((string)($prefs['home_lat'] ?? '')) ?>"><input type="hidden" name="home_lng" id="home-lng" value="<?= e((string)($prefs['home_lng'] ?? '')) ?>">
      <p><button type="button" id="home-clear" class="link"><?= te('push.home_clear') ?></button> <span class="muted small" id="home-state"></span></p>
    </div>
    <button type="submit"><?= te('profile.button') ?></button>
  </form>
</section>

<details class="panel narrow advanced">
  <summary><?= te('profile.advanced') ?></summary>
  <div class="field"><label for="theme-pick"><?= te('profile.theme') ?></label>
    <select id="theme-pick"><option value="auto"><?= te('profile.theme_auto') ?></option><option value="light"><?= te('profile.theme_light') ?></option><option value="dark"><?= te('profile.theme_dark') ?></option></select></div>
  <label class="check"><input type="checkbox" id="allow-select"> <?= te('profile.allow_select') ?></label>
  <p class="hint"><?= te('profile.allow_select_hint') ?></p>
</details>

<section class="panel narrow" id="data">
  <h2><?= te('privacy.heading') ?></h2>
  <p><?= te('privacy.intro') ?></p>
  <?php if ($consentRow && (int)$consentRow['consent_version'] > 0): ?>
    <p class="muted"><?= te('privacy.consent_line', ['when' => formatRideTime((string)$consentRow['consent_at']), 'version' => (int)$consentRow['consent_version']]) ?>
      <a href="/consent"><?= te('privacy.consent_open') ?></a></p>
  <?php endif; ?>
  <form method="post" action="/account/export">
    <?= csrfField() ?>
    <button type="submit" class="secondary-submit"><?= te('privacy.export') ?></button>
    <p class="hint"><?= te('privacy.export_hint') ?></p>
  </form>
  <p><a class="danger" href="/account/delete"><?= te('privacy.delete') ?></a><br><span class="hint"><?= te('privacy.delete_hint') ?></span></p>
</section>

<section class="panel narrow" id="invite">
  <h2><?= te('invite.title') ?></h2>
  <p><?= te('invite.text') ?></p>
  <?= shareBox($inviteUrl, t('invite.share_title'), t('invite.share_text', ['name' => $me['display_name']]), false) ?>
  <p class="muted"><?= $brought > 0 ? te('invite.count', ['n' => $brought]) : te('invite.none') ?></p>
  <?php if ($cameVia && $cameVia['display_name']): ?><p class="muted"><?= te('invite.came_via', ['name' => $cameVia['display_name']]) ?></p><?php endif; ?>
</section>
<script src="/assets/share.js?v=1"></script>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/push.js?v=1"></script>
<?= communityAssets() ?>
<?php pageFooter();
