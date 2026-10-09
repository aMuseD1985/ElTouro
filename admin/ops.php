<?php
/**
 * Operations: deployment via ZIP, database migration, backups, restore.
 * Critical actions additionally require the admin's own password (protection against a hijacked session).
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../ops_lib.php';
require __DIR__ . '/../migrations.php';
$me = requireAdmin();

// On in beta/test without an explicit setting, in live only with 'ops' => ['enabled' => true]
$opsEnabled = $CONFIG['ops']['enabled'] ?? !isLive();
if (!$opsEnabled) {
    pageHeader('Betrieb');
    require __DIR__ . '/_nav.php';
    echo '<h1>Betrieb</h1><p>Die Betriebswerkzeuge sind in dieser Umgebung abgeschaltet (<code>ops.enabled</code> in config.php).</p>';
    pageFooter();
    exit;
}

function passwordConfirmed(array $me): bool
{
    $u = dbOne('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
    return $u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash']);
}

$log = [];

// Download is a GET with the token in the URL so the browser saves the file directly
if (isset($_GET['download'])) {
    if (!hash_equals(csrfToken(), (string)($_GET['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiger Link.');
    }
    $p = str_starts_with((string)$_GET['download'], 'eltouro-release-') ? releasePath((string)$_GET['download']) : backupPath((string)$_GET['download']);
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

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'migrate':
                $log = runMigrations();
                flash('Migration ausgeführt.');
                break;

            case 'release':
                $r = buildRelease();
                flash('Release-Paket gebaut: ' . $r['name'] . ' (' . $r['files'] . ' Dateien, ' . formatSize($r['size']) . ')');
                break;

            case 'release_delete':
                $p = releasePath((string)($_POST['name'] ?? ''));
                if ($p !== null) {
                    unlink($p);
                    flash('Release-Paket gelöscht.');
                }
                break;

            case 'backup':
                $name = fullBackup('manual');
                flash('Backup angelegt: ' . $name);
                break;

            case 'check':
                $f = $_FILES['package'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    flash('Upload fehlgeschlagen (Code ' . (int)($f['error'] ?? -1) . '). Maximale Größe laut Server: ' . ini_get('upload_max_filesize') . '.', 'error');
                    break;
                }
                redirect('/admin/ops?package=' . stagePackage($f['tmp_name']) . '#preview');

            case 'deploy':
                $package = stagedPackage((string)($_POST['package'] ?? ''));
                if ($package === null) {
                    flash('Das geprüfte Paket ist nicht mehr da. Bitte erneut hochladen.', 'error');
                    break;
                }
                if (!passwordConfirmed($me)) {
                    flash('Passwort stimmt nicht – nichts geändert.', 'error');
                    redirect('/admin/ops?package=' . basename($package, '.zip') . '#preview');
                }
                $v = comparePackage($package);
                $allowed = [...$v['new'], ...$v['changed']];
                $selection = array_values(array_intersect((array)($_POST['files'] ?? []), $allowed));
                $name = fullBackup('pre-deploy');
                $count = $selection ? extractToWebroot($package, false, $selection) : 0;
                @unlink($package);
                $log = runMigrations();
                $skipped = count($allowed) - count($selection);
                flash("Eingespielt: $count Dateien" . ($skipped ? ", $skipped bewusst ausgelassen" : '')
                    . ". Gelöscht wurde nichts. Migration ausgeführt. Vorher gesichert als $name.");
                break;

            case 'discard':
                if ($p = stagedPackage((string)($_POST['package'] ?? ''))) {
                    @unlink($p);
                }
                flash('Paket verworfen – nichts geändert.');
                break;

            case 'restore':
                if (!passwordConfirmed($me)) {
                    flash('Passwort stimmt nicht – nichts geändert.', 'error');
                    break;
                }
                $base = (string)($_POST['backup'] ?? '');
                $b = null;
                foreach (listBackups() as $candidate) {
                    if ($candidate['name'] === $base) { $b = $candidate; }
                }
                if ($b === null) {
                    flash('Backup nicht gefunden.', 'error');
                    break;
                }
                $parts = (array)($_POST['parts'] ?? []);
                $safety = fullBackup('pre-restore');
                $info = [];
                if (in_array('files', $parts, true) && $b['files']) {
                    $info[] = extractToWebroot(backupPath($b['files']), ($_POST['with_config'] ?? '') === '1') . ' Dateien';
                }
                if (in_array('db', $parts, true) && $b['db']) {
                    $info[] = restoreDatabase(backupPath($b['db'])) . ' SQL-Anweisungen';
                }
                flash('Wiederhergestellt aus ' . $base . ': ' . ($info ? implode(', ', $info) : 'nichts ausgewählt')
                    . ". Der Zustand davor liegt als $safety bereit.");
                // After a DB restore the own session may be invalid – log in again cleanly
                if (in_array('db', $parts, true)) {
                    redirect('/login');
                }
                break;

            case 'delete':
                $base = (string)($_POST['backup'] ?? '');
                foreach (listBackups() as $b) {
                    if ($b['name'] === $base) {
                        foreach (['db', 'files'] as $k) {
                            if ($b[$k] && ($p = backupPath($b[$k]))) { unlink($p); }
                        }
                        flash('Backup gelöscht.');
                    }
                }
                break;
        }
    } catch (Throwable $ex) {
        error_log('ElTouro ops: ' . $ex->getMessage());
        flash('Fehler: ' . $ex->getMessage(), 'error');
    }
    if (!$log) {
        redirect('/admin/ops');
    }
}

$backups = listBackups();
$zipOk = class_exists('ZipArchive');
pageHeader('Betrieb');
require __DIR__ . '/_nav.php';
?>
<h1>Betrieb</h1>
<?php if (!$zipOk): ?><p class="alert alert-error">Die PHP-Erweiterung <code>zip</code> fehlt – Deployment und Datei-Backups gehen erst, wenn sie im KAS aktiv ist.</p><?php endif; ?>
<?php if ($log): ?>
  <h2>Protokoll der Migration</h2>
  <pre class="log"><?= e(implode("\n", $log)) ?></pre>
<?php endif; ?>

<?php
$packageId = (string)($_GET['package'] ?? '');
$package = $packageId !== '' ? stagedPackage($packageId) : null;
?>
<?php if ($package): $v = comparePackage($package); $fileView = (string)($_GET['file'] ?? ''); ?>
<section class="panel" id="preview">
  <h2>Paket prüfen</h2>
  <p><strong><?= count($v['changed']) ?></strong> geändert · <strong><?= count($v['new']) ?></strong> neu ·
     <?= count($v['same']) ?> unverändert · <?= count($v['serverOnly']) ?> nur auf dem Server.
     Gelöscht wird nie etwas. Häkchen entfernen, um einzelne Dateien auszulassen; ein Klick auf den Namen zeigt die Unterschiede.</p>

  <?php if ($fileView !== '' && (in_array($fileView, $v['changed'], true) || in_array($fileView, $v['new'], true))):
      $newContent = (string)packageFile($package, $fileView);
      $oldContent = is_file(APP_ROOT . '/' . $fileView) ? (string)file_get_contents(APP_ROOT . '/' . $fileView) : '';
      $isNew = in_array($fileView, $v['new'], true); ?>
    <div class="diff-header" id="diff">
      <h3><?= e($fileView) ?> <span class="badge<?= $isNew ? '' : ' muted' ?>"><?= $isNew ? 'neu' : 'geändert' ?></span></h3>
      <a href="?package=<?= e($packageId) ?>#preview">Vergleich schließen</a>
    </div>
    <?php if (!isTextFile($fileView, $newContent . $oldContent)): ?>
      <p class="muted">Binärdatei – kein Zeilenvergleich. Größe bisher: <?= e(formatSize(strlen($oldContent))) ?>, neu: <?= e(formatSize(strlen($newContent))) ?>.</p>
    <?php else: $diff = lineDiff($oldContent, $newContent); ?>
      <?php if ($diff === null): ?>
        <p class="muted">Die Datei ist für einen Vergleich im Browser zu groß.</p>
      <?php else: ?>
        <div class="diff"><table>
          <?php foreach ($diff as [$type, $oldNo, $newNo, $line]): ?>
            <?php if ($type === '…'): ?><tr class="d-gap"><td></td><td></td><td>⋯</td></tr>
            <?php else: ?><tr class="d-<?= $type === '+' ? 'plus' : ($type === '-' ? 'minus' : 'same') ?>"><td><?= $oldNo ?></td><td><?= $newNo ?></td><td><span><?= e($type) ?></span><?= e($line) ?></td></tr><?php endif; ?>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" class="form wide">
    <?= csrfField() ?><input type="hidden" name="action" value="deploy"><input type="hidden" name="package" value="<?= e($packageId) ?>">
    <?php foreach (['changed' => 'Geändert', 'new' => 'Neu'] as $kind => $label): if (!$v[$kind]) continue; ?>
      <fieldset class="file-list">
        <legend><?= $label ?> (<?= count($v[$kind]) ?>)</legend>
        <?php foreach ($v[$kind] as $rel): ?>
          <div class="file-row<?= $rel === $fileView ? ' active' : '' ?>">
            <input type="checkbox" name="files[]" value="<?= e($rel) ?>" id="f-<?= e(md5($rel)) ?>" checked>
            <label for="f-<?= e(md5($rel)) ?>" class="visually-hidden"><?= e($rel) ?> einspielen</label>
            <a href="?package=<?= e($packageId) ?>&amp;file=<?= e(rawurlencode($rel)) ?>#diff"><?= e($rel) ?></a>
          </div>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
    <?php if (!$v['changed'] && !$v['new']): ?><p class="alert alert-info">Das Paket ist identisch mit dem Server – es gibt nichts einzuspielen.</p><?php endif; ?>

    <?php if ($v['serverOnly']): ?>
      <details class="file-list"><summary>Nur auf dem Server (<?= count($v['serverOnly']) ?>) – bleiben unverändert</summary>
        <ul><?php foreach ($v['serverOnly'] as $rel): ?><li><?= e($rel) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
    <details class="file-list"><summary>Unverändert (<?= count($v['same']) ?>)</summary>
      <ul><?php foreach ($v['same'] as $rel): ?><li><?= e($rel) ?></li><?php endforeach; ?></ul></details>

    <div class="field"><label for="pw1">Dein Passwort zur Bestätigung</label><input id="pw1" name="password" type="password" required autocomplete="current-password"></div>
    <button type="submit">Ausgewählte Dateien einspielen</button>
  </form>
  <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="discard"><input type="hidden" name="package" value="<?= e($packageId) ?>"><button class="link danger">Paket verwerfen</button></form>
</section>
<?php else: ?>
<section class="panel">
  <h2>Deployment</h2>
  <p>ZIP-Paket hochladen (gebaut mit <code>git archive</code>, siehe README). Zuerst siehst du, welche Dateien neu oder geändert sind, mit Vergleich pro Datei,
     und wählst aus, was eingespielt wird. Danach: automatisches Vollbackup → ausgewählte Dateien einspielen → Migration.
     <code>config.php</code> und <code>data/</code> werden nie angefasst, und es wird nie etwas gelöscht.
     Auf dem Testserver läuft das Deployment automatisch per GitHub Action.</p>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrfField() ?><input type="hidden" name="action" value="check">
    <div class="field"><label for="package">ZIP-Paket</label><input id="package" name="package" type="file" accept=".zip" required>
      <p class="hint">Maximal <?= e(ini_get('upload_max_filesize')) ?> laut Server-Einstellung.</p></div>
    <button type="submit">Paket prüfen</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Release für Produktion</h2>
  <p>Packt den Stand, der auf diesem Server läuft, in ein ZIP (Ordner <code>eltouro-app/</code>) – genau das, was du auf Produktion brauchst, samt <code>assets/voice</code>.
     <code>config.php</code> und <code>data/</code> sind nie enthalten, die Produktions-Config und die Datenbank bleiben unberührt (die Migration legt nur fehlende Tabellen und Spalten an).
     Einspielen auf Produktion: <strong>Admin → Betrieb → Deployment</strong> (Vorschau, Vollbackup, Migration).</p>
  <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="release"><button type="submit">Release-Paket bauen</button></form>
  <?php $releases = listReleases(); if ($releases): ?>
    <ul class="list">
      <?php foreach ($releases as $rel): ?>
        <li><a href="?download=<?= e(rawurlencode($rel['name'])) ?>&amp;csrf=<?= e(csrfToken()) ?>"><?= e($rel['name']) ?></a> · <?= e(formatSize($rel['size'])) ?>
          <form method="post" class="inline" data-confirm="Release-Paket löschen?"><?= csrfField() ?><input type="hidden" name="action" value="release_delete"><input type="hidden" name="name" value="<?= e($rel['name']) ?>"><button class="link danger">löschen</button></form></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Datenbank</h2>
  <p>Legt fehlende Tabellen und Spalten an. Gefahrlos, beliebig oft ausführbar – bestehende Daten bleiben unberührt.</p>
  <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="migrate"><button type="submit">Migration ausführen</button></form>
</section>

<section class="panel">
  <h2>Backups</h2>
  <p>Ein Backup besteht aus einem Datenbank-Abzug und einem ZIP des Webroots (inklusive <code>config.php</code>, ohne <code>data/</code>).
     Es werden die letzten <?= (int)($CONFIG['ops']['max_backups'] ?? 10) ?> behalten. Downloads enthalten Zugangsdaten – sicher aufbewahren.</p>
  <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="backup"><button type="submit">Jetzt sichern</button></form>

  <?php if ($backups): ?>
  <table class="table">
    <thead><tr><th scope="col">Backup</th><th scope="col">Größe</th><th scope="col">Herunterladen</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr>
        <td><?= e($b['name']) ?></td>
        <td><?= e(formatSize($b['size'])) ?></td>
        <td>
          <?php if ($b['db']): ?><a href="?download=<?= e(rawurlencode($b['db'])) ?>&amp;csrf=<?= e(csrfToken()) ?>">DB</a><?php endif; ?>
          <?php if ($b['files']): ?> · <a href="?download=<?= e(rawurlencode($b['files'])) ?>&amp;csrf=<?= e(csrfToken()) ?>">Dateien</a><?php endif; ?>
        </td>
        <td>
          <details>
            <summary>Wiederherstellen …</summary>
            <form method="post" class="form compact">
              <?= csrfField() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="backup" value="<?= e($b['name']) ?>">
              <?php if ($b['db']): ?><label class="choice"><input type="checkbox" name="parts[]" value="db" checked> Datenbank (ersetzt alle Tabellen)</label><?php endif; ?>
              <?php if ($b['files']): ?><label class="choice"><input type="checkbox" name="parts[]" value="files"> Dateien</label>
                <label class="choice"><input type="checkbox" name="with_config" value="1"> auch config.php zurückspielen</label><?php endif; ?>
              <label for="pw-<?= e($b['name']) ?>">Dein Passwort</label>
              <input id="pw-<?= e($b['name']) ?>" name="password" type="password" required autocomplete="current-password">
              <button type="submit">Wiederherstellen</button>
            </form>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="backup" value="<?= e($b['name']) ?>"><button class="link danger">Backup löschen</button></form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="muted">Noch keine Backups.</p><?php endif; ?>
</section>
<?php pageFooter();
