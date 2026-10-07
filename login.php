<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (aktuellerNutzer()) {
    weiterleiten('/');
}
$fehler = '';
$email = '';
$weiter = (string)($_GET['weiter'] ?? $_POST['weiter'] ?? '/');

if (istPost()) {
    pruefeCsrf();
    $email = strtolower(feld('email', 254));
    if (random_int(1, 20) === 1) {   // gelegentlich aufräumen: Versuche höchstens 24 h aufbewahren
        ausfuehren('DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
    $versuche = (int)einzeln('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [clientIp()])['n'];
    if ($versuche >= 10) {
        $fehler = t('login.zu_viele');
    } else {
        $u = einzeln("SELECT id, password_hash FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$email]);
        // Auch ohne Treffer einen Hash prüfen, damit die Antwortzeit nichts verrät
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        if (password_verify((string)($_POST['passwort'] ?? ''), $hash) && $u) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                ausfuehren('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['passwort'], PASSWORD_DEFAULT), $u['id']]);
            }
            einloggen((int)$u['id']);
            weiterleiten($weiter);
        }
        ausfuehren('INSERT INTO login_attempts (ip) VALUES (?)', [clientIp()]);
        $fehler = t('login.fehler');
    }
}

seitenKopf(t('login.titel'));
?>
<section class="schmal">
  <h1><?= te('login.titel') ?></h1>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
  <form method="post" class="formular">
    <?= csrfFeld() ?>
    <input type="hidden" name="weiter" value="<?= e($weiter) ?>">
    <div class="feld"><label for="email"><?= te('reg.email') ?></label>
      <input id="email" name="email" type="email" required autocomplete="email" value="<?= e($email) ?>"></div>
    <div class="feld"><label for="passwort"><?= te('reg.passwort') ?></label>
      <input id="passwort" name="passwort" type="password" required autocomplete="current-password"></div>
    <button type="submit"><?= te('login.knopf') ?></button>
  </form>
  <p><a href="/passwort_vergessen.php"><?= te('login.vergessen') ?></a></p>
</section>
<?php seitenFuss();
