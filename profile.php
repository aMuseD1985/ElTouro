<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$me = requireLogin();

if (isPost()) {
    checkCsrf();
    $lang = in_array($_POST['locale'] ?? '', LANGUAGES, true) ? $_POST['locale'] : $me['locale'];
    dbExec('UPDATE user_profiles SET bio = ?, home_region = ? WHERE user_id = ?',
        [postField('bio', 1000) ?: null, postField('region', 100) ?: null, $me['id']]);
    dbExec('UPDATE users SET locale = ? WHERE id = ?', [$lang, $me['id']]);
    setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    flash(t('profile.saved'));
    redirect('/profile.php');
}

$p = dbOne('SELECT bio, home_region FROM user_profiles WHERE user_id = ?', [$me['id']]) ?? ['bio' => '', 'home_region' => ''];
pageHeader(t('profile.title'));
?>
<section class="narrow">
  <h1><?= te('profile.title') ?></h1>
  <p class="muted"><?= e($me['display_name']) ?> · <?= e($me['email']) ?></p>
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
<?php pageFooter();
