<?php
/**
 * Inhaltsseiten pflegen (Impressum, Datenschutz, Nutzungsbedingungen, eigene Seiten), je Sprache.
 * Format: kleines Markdown (## Überschrift, - Liste, **fett**, [Text](https://…)), kein HTML.
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$ich = mussAdminSein();

$slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['s'] ?? $_POST['s'] ?? ''));
$loc  = in_array($_GET['l'] ?? $_POST['l'] ?? '', SPRACHEN, true) ? ($_GET['l'] ?? $_POST['l']) : 'de';

if (istPost()) {
    pruefeCsrf();
    $titel = feld('title', 120);
    $body  = str_replace("\r", '', (string)($_POST['body'] ?? ''));
    if ($slug === '' || $titel === '') {
        meldung('Kurzname und Titel dürfen nicht leer sein.', 'fehler');
    } else {
        ausfuehren('INSERT INTO pages (slug, locale, title, body, updated_by) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), updated_by = VALUES(updated_by)',
            [$slug, $loc, $titel, $body, $ich['id']]);
        meldung('Gespeichert.');
    }
    weiterleiten('/admin/seiten.php?s=' . rawurlencode($slug) . '&l=' . $loc);
}

$seiten = alle("SELECT slug, locale, title, updated_at, TRIM(body) = '' AS leer FROM pages ORDER BY slug, locale");
$aktuell = $slug !== '' ? einzeln('SELECT * FROM pages WHERE slug = ? AND locale = ?', [$slug, $loc]) : null;

seitenKopf('Seiten');
require __DIR__ . '/_nav.php';
?>
<h1>Seiten</h1>
<table class="tabelle">
  <thead><tr><th scope="col">Seite</th><th scope="col">Sprache</th><th scope="col">Titel</th><th scope="col">Stand</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($seiten as $s): ?>
    <tr>
      <td><?= e($s['slug']) ?></td><td><?= e(strtoupper($s['locale'])) ?></td>
      <td><?= e($s['title']) ?><?= $s['leer'] ? ' <span class="marke-klein gefahr">leer</span>' : '' ?></td>
      <td><?= e(substr($s['updated_at'], 0, 16)) ?></td>
      <td><a href="?s=<?= e($s['slug']) ?>&amp;l=<?= e($s['locale']) ?>">Bearbeiten</a> · <a href="/seite.php?s=<?= e($s['slug']) ?>&amp;lang=<?= e($s['locale']) ?>" target="_blank">Ansehen</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2><?= $aktuell ? 'Bearbeiten: ' . e($slug) . ' (' . e(strtoupper($loc)) . ')' : 'Neue Seite' ?></h2>
<form method="post" class="formular breit">
  <?= csrfFeld() ?>
  <div class="zeile">
    <div class="feld"><label for="s">Kurzname (Adresse)</label>
      <input id="s" name="s" required pattern="[a-z0-9-]+" maxlength="60" value="<?= e($slug) ?>" <?= $aktuell ? 'readonly' : '' ?>>
      <p class="hinweis">Kleinbuchstaben, Ziffern, Bindestrich. Aufruf dann über /seite.php?s=kurzname</p></div>
    <div class="feld"><label for="l">Sprache</label>
      <select id="l" name="l"><option value="de" <?= $loc === 'de' ? 'selected' : '' ?>>Deutsch</option><option value="en" <?= $loc === 'en' ? 'selected' : '' ?>>Englisch</option></select></div>
  </div>
  <div class="feld"><label for="title">Titel</label>
    <input id="title" name="title" required maxlength="120" value="<?= e($aktuell['title'] ?? '') ?>"></div>
  <div class="feld"><label for="body">Inhalt</label>
    <textarea id="body" name="body" rows="22" class="mono"><?= e($aktuell['body'] ?? '') ?></textarea>
    <p class="hinweis">Absätze durch eine Leerzeile trennen. <code>## Überschrift</code>, <code>### Unterüberschrift</code>, <code>- Listenpunkt</code>, <code>**fett**</code>, <code>[Linktext](https://…)</code> oder <code>[E-Mail](mailto:…)</code>.</p></div>
  <button type="submit">Speichern</button>
</form>
<?php seitenFuss();
