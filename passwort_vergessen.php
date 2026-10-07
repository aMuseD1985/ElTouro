<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/konto.php';

if (istPost()) {
    pruefeCsrf();
    $email = strtolower(feld('email', 254));
    $versuche = (int)einzeln('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [clientIp()])['n'];
    ausfuehren('INSERT INTO login_attempts (ip) VALUES (?)', [clientIp()]);
    $u = $versuche < 10 ? einzeln("SELECT id, display_name, locale FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL", [$email]) : null;
    if ($u) {
        try {
            $token = erzeugeToken((int)$u['id'], 'reset', 1);
            sendeKontoMail($email, 'mail.reset_betreff', 'mail.reset_text',
                ['name' => $u['display_name'], 'link' => basisUrl() . '/passwort_neu.php?t=' . $token]);
        } catch (Throwable $ex) {
            error_log('ElTouro reset: ' . $ex->getMessage());
        }
    }
    // Immer dieselbe Antwort
    meldung(t('pw.gesendet'));
    weiterleiten('/login.php');
}

seitenKopf(t('pw.titel'));
?>
<section class="schmal">
  <h1><?= te('pw.titel') ?></h1>
  <p><?= te('pw.text') ?></p>
  <form method="post" class="formular">
    <?= csrfFeld() ?>
    <div class="feld"><label for="email"><?= te('reg.email') ?></label>
      <input id="email" name="email" type="email" required autocomplete="email"></div>
    <button type="submit"><?= te('pw.knopf') ?></button>
  </form>
</section>
<?php seitenFuss();
