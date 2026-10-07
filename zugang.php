<?php
declare(strict_types=1);
const OHNE_ZUGANGSSCHUTZ = true;
require __DIR__ . '/bootstrap.php';

$fehler = false;
$weiter = (string)($_GET['weiter'] ?? $_POST['weiter'] ?? '/');

if (istPost()) {
    // Einfache Bremse gegen Durchprobieren
    $versuche = (int)einzeln('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [clientIp()])['n'];
    if ($versuche < 10 && password_verify((string)($_POST['passwort'] ?? ''), (string)($CONFIG['zugang']['passwort_hash'] ?? ''))) {
        setzeZugangsCookie();
        weiterleiten($weiter);
    }
    ausfuehren('INSERT INTO login_attempts (ip) VALUES (?)', [clientIp()]);
    $fehler = true;
}

seitenKopf(t('zugang.titel'));
?>
<section class="schmal">
  <h1><?= te('zugang.titel') ?></h1>
  <p><?= te('zugang.text') ?></p>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= te('zugang.falsch') ?></p><?php endif; ?>
  <form method="post" class="formular">
    <input type="hidden" name="weiter" value="<?= e($weiter) ?>">
    <div class="feld">
      <label for="passwort"><?= te('zugang.passwort') ?></label>
      <input id="passwort" name="passwort" type="password" required autocomplete="current-password">
    </div>
    <button type="submit"><?= te('zugang.knopf') ?></button>
  </form>
</section>
<?php seitenFuss();
