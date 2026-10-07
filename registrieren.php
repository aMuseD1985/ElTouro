<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/konto.php';

if (aktuellerNutzer()) {
    weiterleiten('/');
}
$offen = einstellung('registrierung_offen', '1') === '1';
$fehler = [];
$werte = ['email' => '', 'name' => '', 'geburt' => ''];

if ($offen && istPost()) {
    pruefeCsrf();
    $werte = ['email' => strtolower(feld('email', 254)), 'name' => feld('name', 30), 'geburt' => feld('geburt', 10)];
    $pw = (string)($_POST['passwort'] ?? '');

    if (!filter_var($werte['email'], FILTER_VALIDATE_EMAIL)) $fehler[] = t('reg.fehler_email');
    if (!preg_match('/^[\p{L}\p{N}._-]{3,30}$/u', $werte['name'])) $fehler[] = t('reg.fehler_name');
    $geburt = DateTimeImmutable::createFromFormat('!Y-m-d', $werte['geburt']);
    if (!$geburt || $geburt > new DateTimeImmutable('-' . MINDESTALTER . ' years') || $geburt < new DateTimeImmutable('-110 years')) {
        $fehler[] = t('reg.fehler_alter', ['alter' => MINDESTALTER]);
    }
    if (mb_strlen($pw) < 10) $fehler[] = t('reg.fehler_passwort');
    if (($_POST['agb'] ?? '') !== '1') $fehler[] = t('reg.fehler_agb');

    if (!$fehler && einzeln('SELECT id FROM users WHERE display_name = ?', [$werte['name']])) {
        $fehler[] = t('reg.fehler_name_vergeben');
    }

    if (!$fehler) {
        try {
            $vorhanden = einzeln('SELECT id, email_verified_at FROM users WHERE email = ?', [$werte['email']]);
            if ($vorhanden === null) {
                ausfuehren('INSERT INTO users (email, password_hash, display_name, birth_date, locale, terms_accepted_at)
                            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$werte['email'], password_hash($pw, PASSWORD_DEFAULT), $werte['name'], $geburt->format('Y-m-d'), $LANG]);
                $uid = (int)db()->lastInsertId();
                ausfuehren('INSERT INTO user_profiles (user_id) VALUES (?)', [$uid]);
                $token = erzeugeToken($uid, 'verify', 48);
                sendeKontoMail($werte['email'], 'mail.verify_betreff', 'mail.verify_text',
                    ['name' => $werte['name'], 'link' => basisUrl() . '/bestaetigen.php?t=' . $token]);
            }
            // Bei schon vorhandener Adresse dieselbe Antwort – niemand soll erfahren, wer registriert ist
            meldung(t('reg.fertig'));
            weiterleiten('/login.php');
        } catch (Throwable $ex) {
            error_log('ElTouro registrieren: ' . $ex->getMessage());
            $fehler[] = t('fehler.allgemein');
        }
    }
}

$agb = '<a href="/nutzungsbedingungen" target="_blank">' . te('footer.nutzungsbedingungen') . '</a>';
$ds  = '<a href="/datenschutz" target="_blank">' . te('footer.datenschutz') . '</a>';

seitenKopf(t('reg.titel'));
?>
<section class="schmal">
  <h1><?= te('reg.titel') ?></h1>
  <?php if (!$offen): ?>
    <p><?= te('reg.zu') ?></p>
  <?php else: ?>
    <?php foreach ($fehler as $f): ?><p class="meldung meldung-fehler" role="alert"><?= e($f) ?></p><?php endforeach; ?>
    <form method="post" class="formular">
      <?= csrfFeld() ?>
      <div class="feld"><label for="email"><?= te('reg.email') ?></label>
        <input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= e($werte['email']) ?>"></div>
      <div class="feld"><label for="name"><?= te('reg.name') ?></label>
        <input id="name" name="name" required maxlength="30" autocomplete="username" value="<?= e($werte['name']) ?>" aria-describedby="name-h">
        <p class="hinweis" id="name-h"><?= te('reg.name_hint') ?></p></div>
      <div class="feld"><label for="geburt"><?= te('reg.geburt') ?></label>
        <input id="geburt" name="geburt" type="date" required value="<?= e($werte['geburt']) ?>" aria-describedby="geburt-h">
        <p class="hinweis" id="geburt-h"><?= te('reg.geburt_hint', ['alter' => MINDESTALTER]) ?></p></div>
      <div class="feld"><label for="passwort"><?= te('reg.passwort') ?></label>
        <input id="passwort" name="passwort" type="password" required minlength="10" autocomplete="new-password" aria-describedby="pw-h">
        <p class="hinweis" id="pw-h"><?= te('reg.passwort_hint') ?></p></div>
      <div class="feld haken"><input id="agb" name="agb" type="checkbox" value="1" required>
        <label for="agb"><?= str_replace(['{agb}', '{ds}'], [$agb, $ds], te('reg.agb')) ?></label></div>
      <button type="submit"><?= te('reg.knopf') ?></button>
    </form>
  <?php endif; ?>
</section>
<?php seitenFuss();
