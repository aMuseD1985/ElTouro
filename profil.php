<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$ich = mussEingeloggtSein();

if (istPost()) {
    pruefeCsrf();
    $sprache = in_array($_POST['locale'] ?? '', SPRACHEN, true) ? $_POST['locale'] : $ich['locale'];
    ausfuehren('UPDATE user_profiles SET bio = ?, home_region = ? WHERE user_id = ?',
        [feld('bio', 1000) ?: null, feld('region', 100) ?: null, $ich['id']]);
    ausfuehren('UPDATE users SET locale = ? WHERE id = ?', [$sprache, $ich['id']]);
    setcookie('lang', $sprache, ['expires' => time() + 31536000, 'path' => '/', 'secure' => istHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    meldung(t('profil.ok'));
    weiterleiten('/profil.php');
}

$p = einzeln('SELECT bio, home_region FROM user_profiles WHERE user_id = ?', [$ich['id']]) ?? ['bio' => '', 'home_region' => ''];
seitenKopf(t('profil.titel'));
?>
<section class="schmal">
  <h1><?= te('profil.titel') ?></h1>
  <p class="leise"><?= e($ich['display_name']) ?> · <?= e($ich['email']) ?></p>
  <form method="post" class="formular">
    <?= csrfFeld() ?>
    <div class="feld"><label for="region"><?= te('profil.region') ?></label>
      <input id="region" name="region" maxlength="100" value="<?= e($p['home_region']) ?>"></div>
    <div class="feld"><label for="bio"><?= te('profil.bio') ?></label>
      <textarea id="bio" name="bio" rows="4" maxlength="1000"><?= e($p['bio']) ?></textarea></div>
    <div class="feld"><label for="locale"><?= te('profil.sprache') ?></label>
      <select id="locale" name="locale">
        <option value="de" <?= $ich['locale'] === 'de' ? 'selected' : '' ?>>Deutsch</option>
        <option value="en" <?= $ich['locale'] === 'en' ? 'selected' : '' ?>>English</option>
      </select></div>
    <button type="submit"><?= te('profil.knopf') ?></button>
  </form>
</section>
<?php seitenFuss();
