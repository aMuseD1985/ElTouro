<?php
/** Plattform-Einstellungen: Registrierung auf/zu, Hinweisband oben (DE/EN). */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
mussAdminSein();

$felder = ['registrierung_offen', 'banner_de', 'banner_en'];

if (istPost()) {
    pruefeCsrf();
    $werte = [
        'registrierung_offen' => ($_POST['registrierung_offen'] ?? '') === '1' ? '1' : '0',
        'banner_de' => feld('banner_de', 300),
        'banner_en' => feld('banner_en', 300),
        'betreiber_name'      => feld('betreiber_name', 150),
        'betreiber_anschrift' => trim(str_replace("\r", '', (string)($_POST['betreiber_anschrift'] ?? ''))),
        'betreiber_email'     => filter_var(feld('betreiber_email', 254), FILTER_VALIDATE_EMAIL) ?: '',
        'betreiber_telefon'   => feld('betreiber_telefon', 60),
        'aufsichtsbehoerde'   => trim(str_replace("\r", '', (string)($_POST['aufsichtsbehoerde'] ?? ''))),
        'log_tage'            => (string)max(1, min(90, (int)($_POST['log_tage'] ?? 7))),
    ];
    foreach ($werte as $k => $v) {
        ausfuehren('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
    meldung('Gespeichert.');
    weiterleiten('/admin/einstellungen.php');
}

seitenKopf('Einstellungen');
require __DIR__ . '/_nav.php';
?>
<h1>Einstellungen</h1>
<form method="post" class="formular breit">
  <?= csrfFeld() ?>
  <div class="feld haken"><input id="reg" name="registrierung_offen" type="checkbox" value="1" <?= einstellung('registrierung_offen', '1') === '1' ? 'checked' : '' ?>>
    <label for="reg">Registrierung offen (aus: nur bestehende Konten können sich anmelden)</label></div>
  <div class="feld"><label for="bde">Hinweisband oben – Deutsch</label>
    <input id="bde" name="banner_de" maxlength="300" value="<?= e(einstellung('banner_de')) ?>">
    <p class="hinweis">Leer lassen, um nichts anzuzeigen. Erlaubt: <code>**fett**</code> und <code>[Link](https://…)</code>.</p></div>
  <div class="feld"><label for="ben">Hinweisband oben – Englisch</label>
    <input id="ben" name="banner_en" maxlength="300" value="<?= e(einstellung('banner_en')) ?>"></div>
  <h2>Betreiberdaten</h2>
  <p class="hinweis">Diese Angaben setzen Impressum, Datenschutzerklärung und Nutzungsbedingungen automatisch ein (Platzhalter wie <code>{{betreiber_name}}</code>).
    Nötig ist eine ladungsfähige Anschrift – ein Postfach reicht nicht.</p>
  <div class="feld"><label for="bn">Name (bzw. Firma und Vertretungsberechtigter)</label>
    <input id="bn" name="betreiber_name" maxlength="150" value="<?= e(einstellung('betreiber_name')) ?>"></div>
  <div class="feld"><label for="ba">Anschrift</label>
    <textarea id="ba" name="betreiber_anschrift" rows="3"><?= e(einstellung('betreiber_anschrift')) ?></textarea>
    <p class="hinweis">Straße und Hausnummer, dann PLZ und Ort – jeweils eine Zeile.</p></div>
  <div class="zeile">
    <div class="feld"><label for="bm">E-Mail</label><input id="bm" name="betreiber_email" type="email" value="<?= e(einstellung('betreiber_email')) ?>"></div>
    <div class="feld"><label for="bt">Telefon (oder zweiter schneller Kontaktweg)</label><input id="bt" name="betreiber_telefon" maxlength="60" value="<?= e(einstellung('betreiber_telefon')) ?>"></div>
  </div>
  <div class="feld"><label for="ab">Zuständige Datenschutz-Aufsichtsbehörde</label>
    <textarea id="ab" name="aufsichtsbehoerde" rows="2"><?= e(einstellung('aufsichtsbehoerde')) ?></textarea>
    <p class="hinweis">Die Behörde deines Bundeslands, z. B. „Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen, Kavalleriestraße 2–4, 40213 Düsseldorf“.</p></div>
  <div class="feld"><label for="lt">Server-Logfiles werden gelöscht nach … Tagen</label>
    <input id="lt" name="log_tage" type="number" min="1" max="90" value="<?= e(einstellung('log_tage', '7')) ?>">
    <p class="hinweis">Wert laut KAS → Statistik/Logs einstellen.</p></div>
  <button type="submit">Speichern</button>
</form>
<?php seitenFuss();
