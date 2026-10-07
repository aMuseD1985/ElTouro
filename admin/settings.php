<?php
/** Platform settings: registration open/closed, banner (DE/EN), operator details for the legal pages. */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireAdmin();

if (isPost()) {
    checkCsrf();
    $values = [
        'registration_open'     => ($_POST['registration_open'] ?? '') === '1' ? '1' : '0',
        'banner_de'             => postField('banner_de', 300),
        'banner_en'             => postField('banner_en', 300),
        'operator_name'         => postField('operator_name', 150),
        'operator_address'      => trim(str_replace("\r", '', (string)($_POST['operator_address'] ?? ''))),
        'operator_email'        => filter_var(postField('operator_email', 254), FILTER_VALIDATE_EMAIL) ?: '',
        'operator_phone'        => postField('operator_phone', 60),
        'supervisory_authority' => trim(str_replace("\r", '', (string)($_POST['supervisory_authority'] ?? ''))),
        'log_days'              => (string)max(1, min(90, (int)($_POST['log_days'] ?? 7))),
    ];
    foreach ($values as $k => $v) {
        dbExec('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
    flash('Gespeichert.');
    redirect('/admin/settings.php');
}

pageHeader('Einstellungen');
require __DIR__ . '/_nav.php';
?>
<h1>Einstellungen</h1>
<form method="post" class="form wide">
  <?= csrfField() ?>
  <div class="field check"><input id="reg" name="registration_open" type="checkbox" value="1" <?= setting('registration_open', '1') === '1' ? 'checked' : '' ?>>
    <label for="reg">Registrierung offen (aus: nur bestehende Konten können sich anmelden)</label></div>
  <div class="field"><label for="bde">Hinweisband oben – Deutsch</label>
    <input id="bde" name="banner_de" maxlength="300" value="<?= e(setting('banner_de')) ?>">
    <p class="hint">Leer lassen, um nichts anzuzeigen. Erlaubt: <code>**fett**</code> und <code>[Link](https://…)</code>.</p></div>
  <div class="field"><label for="ben">Hinweisband oben – Englisch</label>
    <input id="ben" name="banner_en" maxlength="300" value="<?= e(setting('banner_en')) ?>"></div>
  <h2>Betreiberdaten</h2>
  <p class="hint">Diese Angaben setzen Impressum, Datenschutzerklärung und Nutzungsbedingungen automatisch ein (Platzhalter wie <code>{{operator_name}}</code>).
    Nötig ist eine ladungsfähige Anschrift – ein Postfach reicht nicht.</p>
  <div class="field"><label for="bn">Name (bzw. Firma und Vertretungsberechtigter)</label>
    <input id="bn" name="operator_name" maxlength="150" value="<?= e(setting('operator_name')) ?>"></div>
  <div class="field"><label for="ba">Anschrift</label>
    <textarea id="ba" name="operator_address" rows="3"><?= e(setting('operator_address')) ?></textarea>
    <p class="hint">Straße und Hausnummer, dann PLZ und Ort – jeweils eine Zeile.</p></div>
  <div class="row">
    <div class="field"><label for="bm">E-Mail</label><input id="bm" name="operator_email" type="email" value="<?= e(setting('operator_email')) ?>"></div>
    <div class="field"><label for="bt">Telefon (oder zweiter schneller Kontaktweg)</label><input id="bt" name="operator_phone" maxlength="60" value="<?= e(setting('operator_phone')) ?>"></div>
  </div>
  <div class="field"><label for="ab">Zuständige Datenschutz-Aufsichtsbehörde</label>
    <textarea id="ab" name="supervisory_authority" rows="2"><?= e(setting('supervisory_authority')) ?></textarea>
    <p class="hint">Die Behörde deines Bundeslands, z. B. „Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen, Kavalleriestraße 2–4, 40213 Düsseldorf“.</p></div>
  <div class="field"><label for="lt">Server-Logfiles werden gelöscht nach … Tagen</label>
    <input id="lt" name="log_days" type="number" min="1" max="90" value="<?= e(setting('log_days', '7')) ?>">
    <p class="hint">Wert laut KAS → Statistik/Logs einstellen.</p></div>
  <button type="submit">Speichern</button>
</form>
<?php pageFooter();
