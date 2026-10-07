<?php
/**
 * Betrieb: Deployment per ZIP, Datenbank-Migration, Backups, Restore.
 * Kritische Aktionen verlangen zusätzlich das eigene Passwort (Schutz bei gekaperter Sitzung).
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../betrieb_lib.php';
require __DIR__ . '/../migrationen.php';
$ich = mussAdminSein();

// In beta/test ohne ausdrückliche Einstellung an, in live nur mit 'ops' => ['aktiv' => true]
$opsAktiv = $CONFIG['ops']['aktiv'] ?? !istLive();
if (!$opsAktiv) {
    seitenKopf('Betrieb');
    require __DIR__ . '/_nav.php';
    echo '<h1>Betrieb</h1><p>Die Betriebswerkzeuge sind in dieser Umgebung abgeschaltet (<code>ops.aktiv</code> in config.php).</p>';
    seitenFuss();
    exit;
}

function passwortBestaetigt(array $ich): bool
{
    $u = einzeln('SELECT password_hash FROM users WHERE id = ?', [$ich['id']]);
    return $u && password_verify((string)($_POST['passwort'] ?? ''), $u['password_hash']);
}

$protokoll = [];

// Download ist ein GET mit Token in der URL, damit der Browser die Datei direkt speichert
if (isset($_GET['download'])) {
    if (!hash_equals(csrfToken(), (string)($_GET['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiger Link.');
    }
    $p = backupPfad((string)$_GET['download']);
    if ($p === null) {
        http_response_code(404);
        exit('Backup nicht gefunden.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

if (istPost()) {
    pruefeCsrf();
    $aktion = (string)($_POST['aktion'] ?? '');
    try {
        switch ($aktion) {
            case 'migrieren':
                $protokoll = fuehreMigrationenAus();
                meldung('Migration ausgeführt.');
                break;

            case 'backup':
                $name = vollbackup('manuell');
                meldung('Backup angelegt: ' . $name);
                break;

            case 'pruefen':
                $f = $_FILES['paket'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    meldung('Upload fehlgeschlagen (Code ' . (int)($f['error'] ?? -1) . '). Maximale Größe laut Server: ' . ini_get('upload_max_filesize') . '.', 'fehler');
                    break;
                }
                weiterleiten('/admin/betrieb.php?paket=' . parkePaket($f['tmp_name']) . '#vorschau');

            case 'einspielen':
                $paket = geparktesPaket((string)($_POST['paket'] ?? ''));
                if ($paket === null) {
                    meldung('Das geprüfte Paket ist nicht mehr da. Bitte erneut hochladen.', 'fehler');
                    break;
                }
                if (!passwortBestaetigt($ich)) {
                    meldung('Passwort stimmt nicht – nichts geändert.', 'fehler');
                    weiterleiten('/admin/betrieb.php?paket=' . basename($paket, '.zip') . '#vorschau');
                }
                $v = vergleichePaket($paket);
                $erlaubt = [...$v['neu'], ...$v['geaendert']];
                $auswahl = array_values(array_intersect((array)($_POST['dateien'] ?? []), $erlaubt));
                $name = vollbackup('vor-deploy');
                $anzahl = $auswahl ? entpackeInWebroot($paket, false, $auswahl) : 0;
                @unlink($paket);
                $protokoll = fuehreMigrationenAus();
                $ausgelassen = count($erlaubt) - count($auswahl);
                meldung("Eingespielt: $anzahl Dateien" . ($ausgelassen ? ", $ausgelassen bewusst ausgelassen" : '')
                      . ". Gelöscht wurde nichts. Migration ausgeführt. Vorher gesichert als $name.");
                break;

            case 'verwerfen':
                if ($p = geparktesPaket((string)($_POST['paket'] ?? ''))) {
                    @unlink($p);
                }
                meldung('Paket verworfen – nichts geändert.');
                break;

            case 'restore':
                if (!passwortBestaetigt($ich)) {
                    meldung('Passwort stimmt nicht – nichts geändert.', 'fehler');
                    break;
                }
                $basis = (string)($_POST['backup'] ?? '');
                $b = null;
                foreach (listeBackups() as $kandidat) {
                    if ($kandidat['name'] === $basis) { $b = $kandidat; }
                }
                if ($b === null) {
                    meldung('Backup nicht gefunden.', 'fehler');
                    break;
                }
                $teile = (array)($_POST['teile'] ?? []);
                $sicherung = vollbackup('vor-restore');
                $info = [];
                if (in_array('dateien', $teile, true) && $b['dateien']) {
                    $info[] = entpackeInWebroot(backupPfad($b['dateien']), ($_POST['mit_config'] ?? '') === '1') . ' Dateien';
                }
                if (in_array('db', $teile, true) && $b['db']) {
                    $info[] = stelleDatenbankWiederHer(backupPfad($b['db'])) . ' SQL-Anweisungen';
                }
                meldung('Wiederhergestellt aus ' . $basis . ': ' . ($info ? implode(', ', $info) : 'nichts ausgewählt')
                      . ". Der Zustand davor liegt als $sicherung bereit.");
                // Nach DB-Restore kann die eigene Sitzung ungültig sein – sauber neu anmelden lassen
                if (in_array('db', $teile, true)) {
                    weiterleiten('/login.php');
                }
                break;

            case 'loeschen':
                $basis = (string)($_POST['backup'] ?? '');
                foreach (listeBackups() as $b) {
                    if ($b['name'] === $basis) {
                        foreach (['db', 'dateien'] as $k) {
                            if ($b[$k] && ($p = backupPfad($b[$k]))) { unlink($p); }
                        }
                        meldung('Backup gelöscht.');
                    }
                }
                break;
        }
    } catch (Throwable $ex) {
        error_log('ElTouro Betrieb: ' . $ex->getMessage());
        meldung('Fehler: ' . $ex->getMessage(), 'fehler');
    }
    if (!$protokoll) {
        weiterleiten('/admin/betrieb.php');
    }
}

$backups = listeBackups();
$zipOk = class_exists('ZipArchive');
seitenKopf('Betrieb');
require __DIR__ . '/_nav.php';
?>
<h1>Betrieb</h1>
<?php if (!$zipOk): ?><p class="meldung meldung-fehler">Die PHP-Erweiterung <code>zip</code> fehlt – Deployment und Datei-Backups gehen erst, wenn sie im KAS aktiv ist.</p><?php endif; ?>
<?php if ($protokoll): ?>
  <h2>Protokoll der Migration</h2>
  <pre class="protokoll"><?= e(implode("\n", $protokoll)) ?></pre>
<?php endif; ?>

<?php
$paketId = (string)($_GET['paket'] ?? '');
$paket = $paketId !== '' ? geparktesPaket($paketId) : null;
?>
<?php if ($paket): $v = vergleichePaket($paket); $dateiAnsicht = (string)($_GET['datei'] ?? ''); ?>
<section class="verwaltung" id="vorschau">
  <h2>Paket prüfen</h2>
  <p><strong><?= count($v['geaendert']) ?></strong> geändert · <strong><?= count($v['neu']) ?></strong> neu ·
     <?= count($v['gleich']) ?> unverändert · <?= count($v['nurServer']) ?> nur auf dem Server.
     Gelöscht wird nie etwas. Häkchen entfernen, um einzelne Dateien auszulassen; ein Klick auf den Namen zeigt die Unterschiede.</p>

  <?php if ($dateiAnsicht !== '' && (in_array($dateiAnsicht, $v['geaendert'], true) || in_array($dateiAnsicht, $v['neu'], true))):
      $neuInhalt = (string)paketDatei($paket, $dateiAnsicht);
      $altInhalt = is_file(APP_WURZEL . '/' . $dateiAnsicht) ? (string)file_get_contents(APP_WURZEL . '/' . $dateiAnsicht) : '';
      $istNeu = in_array($dateiAnsicht, $v['neu'], true); ?>
    <div class="diff-kopf" id="diff">
      <h3><?= e($dateiAnsicht) ?> <span class="marke-klein<?= $istNeu ? '' : ' leise' ?>"><?= $istNeu ? 'neu' : 'geändert' ?></span></h3>
      <a href="?paket=<?= e($paketId) ?>#vorschau">Vergleich schließen</a>
    </div>
    <?php if (!istTextdatei($dateiAnsicht, $neuInhalt . $altInhalt)): ?>
      <p class="leise">Binärdatei – kein Zeilenvergleich. Größe bisher: <?= e(formatiereGroesse(strlen($altInhalt))) ?>, neu: <?= e(formatiereGroesse(strlen($neuInhalt))) ?>.</p>
    <?php else: $diff = zeilenDiff($altInhalt, $neuInhalt); ?>
      <?php if ($diff === null): ?>
        <p class="leise">Die Datei ist für einen Vergleich im Browser zu groß.</p>
      <?php else: ?>
        <div class="diff"><table>
          <?php foreach ($diff as [$typ, $altNr, $neuNr, $zeile]): ?>
            <?php if ($typ === '…'): ?><tr class="d-luecke"><td></td><td></td><td>⋯</td></tr>
            <?php else: ?><tr class="d-<?= $typ === '+' ? 'plus' : ($typ === '-' ? 'minus' : 'gleich') ?>"><td><?= $altNr ?></td><td><?= $neuNr ?></td><td><span><?= e($typ) ?></span><?= e($zeile) ?></td></tr><?php endif; ?>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" class="formular breit">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="einspielen"><input type="hidden" name="paket" value="<?= e($paketId) ?>">
    <?php foreach (['geaendert' => 'Geändert', 'neu' => 'Neu'] as $art => $titel): if (!$v[$art]) continue; ?>
      <fieldset class="dateiliste">
        <legend><?= $titel ?> (<?= count($v[$art]) ?>)</legend>
        <?php foreach ($v[$art] as $rel): ?>
          <div class="dateizeile<?= $rel === $dateiAnsicht ? ' aktiv' : '' ?>">
            <input type="checkbox" name="dateien[]" value="<?= e($rel) ?>" id="f-<?= e(md5($rel)) ?>" checked>
            <label for="f-<?= e(md5($rel)) ?>" class="unsichtbar"><?= e($rel) ?> einspielen</label>
            <a href="?paket=<?= e($paketId) ?>&amp;datei=<?= e(rawurlencode($rel)) ?>#diff"><?= e($rel) ?></a>
          </div>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
    <?php if (!$v['geaendert'] && !$v['neu']): ?><p class="meldung meldung-info">Das Paket ist identisch mit dem Server – es gibt nichts einzuspielen.</p><?php endif; ?>

    <?php if ($v['nurServer']): ?>
      <details class="dateiliste"><summary>Nur auf dem Server (<?= count($v['nurServer']) ?>) – bleiben unverändert</summary>
        <ul><?php foreach ($v['nurServer'] as $rel): ?><li><?= e($rel) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
    <details class="dateiliste"><summary>Unverändert (<?= count($v['gleich']) ?>)</summary>
      <ul><?php foreach ($v['gleich'] as $rel): ?><li><?= e($rel) ?></li><?php endforeach; ?></ul></details>

    <div class="feld"><label for="pw1">Dein Passwort zur Bestätigung</label><input id="pw1" name="passwort" type="password" required autocomplete="current-password"></div>
    <button type="submit">Ausgewählte Dateien einspielen</button>
  </form>
  <form method="post"><?= csrfFeld() ?><input type="hidden" name="aktion" value="verwerfen"><input type="hidden" name="paket" value="<?= e($paketId) ?>"><button class="link gefahr">Paket verwerfen</button></form>
</section>
<?php else: ?>
<section class="verwaltung">
  <h2>Deployment</h2>
  <p>ZIP-Paket hochladen, wie du es von mir bekommst. Zuerst siehst du, welche Dateien neu oder geändert sind, mit Vergleich pro Datei,
     und wählst aus, was eingespielt wird. Danach: automatisches Vollbackup → ausgewählte Dateien einspielen → Migration.
     <code>config.php</code> und <code>daten/</code> werden nie angefasst, und es wird nie etwas gelöscht.</p>
  <form method="post" enctype="multipart/form-data" class="formular">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="pruefen">
    <div class="feld"><label for="paket">ZIP-Paket</label><input id="paket" name="paket" type="file" accept=".zip" required>
      <p class="hinweis">Maximal <?= e(ini_get('upload_max_filesize')) ?> laut Server-Einstellung.</p></div>
    <button type="submit">Paket prüfen</button>
  </form>
</section>
<?php endif; ?>

<section class="verwaltung">
  <h2>Datenbank</h2>
  <p>Legt fehlende Tabellen und Spalten an. Gefahrlos, beliebig oft ausführbar – bestehende Daten bleiben unberührt.</p>
  <form method="post"><?= csrfFeld() ?><input type="hidden" name="aktion" value="migrieren"><button type="submit">Migration ausführen</button></form>
</section>

<section class="verwaltung">
  <h2>Backups</h2>
  <p>Ein Backup besteht aus einem Datenbank-Abzug und einem ZIP des Webroots (inklusive <code>config.php</code>, ohne <code>daten/</code>).
     Es werden die letzten <?= (int)($CONFIG['ops']['max_backups'] ?? 10) ?> behalten. Downloads enthalten Zugangsdaten – sicher aufbewahren.</p>
  <form method="post"><?= csrfFeld() ?><input type="hidden" name="aktion" value="backup"><button type="submit">Jetzt sichern</button></form>

  <?php if ($backups): ?>
  <table class="tabelle">
    <thead><tr><th scope="col">Backup</th><th scope="col">Größe</th><th scope="col">Herunterladen</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr>
        <td><?= e($b['name']) ?></td>
        <td><?= e(formatiereGroesse($b['groesse'])) ?></td>
        <td>
          <?php if ($b['db']): ?><a href="?download=<?= e(rawurlencode($b['db'])) ?>&amp;csrf=<?= e(csrfToken()) ?>">DB</a><?php endif; ?>
          <?php if ($b['dateien']): ?> · <a href="?download=<?= e(rawurlencode($b['dateien'])) ?>&amp;csrf=<?= e(csrfToken()) ?>">Dateien</a><?php endif; ?>
        </td>
        <td>
          <details>
            <summary>Wiederherstellen …</summary>
            <form method="post" class="formular kompakt">
              <?= csrfFeld() ?><input type="hidden" name="aktion" value="restore"><input type="hidden" name="backup" value="<?= e($b['name']) ?>">
              <?php if ($b['db']): ?><label class="wahl"><input type="checkbox" name="teile[]" value="db" checked> Datenbank (ersetzt alle Tabellen)</label><?php endif; ?>
              <?php if ($b['dateien']): ?><label class="wahl"><input type="checkbox" name="teile[]" value="dateien"> Dateien</label>
                <label class="wahl"><input type="checkbox" name="mit_config" value="1"> auch config.php zurückspielen</label><?php endif; ?>
              <label for="pw-<?= e($b['name']) ?>">Dein Passwort</label>
              <input id="pw-<?= e($b['name']) ?>" name="passwort" type="password" required autocomplete="current-password">
              <button type="submit">Wiederherstellen</button>
            </form>
            <form method="post"><?= csrfFeld() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="backup" value="<?= e($b['name']) ?>"><button class="link gefahr">Backup löschen</button></form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="leise">Noch keine Backups.</p><?php endif; ?>
</section>
<?php seitenFuss();
