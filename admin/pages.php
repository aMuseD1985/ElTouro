<?php
/**
 * Maintain content pages (imprint, privacy, terms, custom pages), per language.
 * Format: small Markdown (## heading, - list, **bold**, [text](https://…)), no HTML.
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$me = requireAdmin();

$slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['s'] ?? $_POST['s'] ?? ''));
$loc  = in_array($_GET['l'] ?? $_POST['l'] ?? '', LANGUAGES, true) ? ($_GET['l'] ?? $_POST['l']) : 'de';

if (isPost()) {
    checkCsrf();
    $title = postField('title', 120);
    $body  = str_replace("\r", '', (string)($_POST['body'] ?? ''));
    if ($slug === '' || $title === '') {
        flash('Kurzname und Titel dürfen nicht leer sein.', 'error');
    } else {
        dbExec('INSERT INTO pages (slug, locale, title, body, updated_by) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), updated_by = VALUES(updated_by)',
            [$slug, $loc, $title, $body, $me['id']]);
        flash('Gespeichert.');
    }
    redirect('/admin/pages?s=' . rawurlencode($slug) . '&l=' . $loc);
}

$pages = dbAll("SELECT slug, locale, title, updated_at, TRIM(body) = '' AS is_empty FROM pages ORDER BY slug, locale");
$current = $slug !== '' ? dbOne('SELECT * FROM pages WHERE slug = ? AND locale = ?', [$slug, $loc]) : null;

pageHeader('Seiten');
require __DIR__ . '/_nav.php';
?>
<h1>Seiten</h1>
<table class="table">
  <thead><tr><th scope="col">Seite</th><th scope="col">Sprache</th><th scope="col">Titel</th><th scope="col">Stand</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($pages as $s): ?>
    <tr>
      <td><?= e($s['slug']) ?></td><td><?= e(strtoupper($s['locale'])) ?></td>
      <td><?= e($s['title']) ?><?= $s['is_empty'] ? ' <span class="badge danger">leer</span>' : '' ?></td>
      <td><?= e(substr($s['updated_at'], 0, 16)) ?></td>
      <td><a href="?s=<?= e($s['slug']) ?>&amp;l=<?= e($s['locale']) ?>">Bearbeiten</a> · <a href="/page/<?= e($s['slug']) ?>&amp;lang=<?= e($s['locale']) ?>" target="_blank">Ansehen</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2><?= $current ? 'Bearbeiten: ' . e($slug) . ' (' . e(strtoupper($loc)) . ')' : 'Neue Seite' ?></h2>
<form method="post" class="form wide">
  <?= csrfField() ?>
  <div class="row">
    <div class="field"><label for="s">Kurzname (Adresse)</label>
      <input id="s" name="s" required pattern="[a-z0-9-]+" maxlength="60" value="<?= e($slug) ?>" <?= $current ? 'readonly' : '' ?>>
      <p class="hint">Kleinbuchstaben, Ziffern, Bindestrich. Aufruf dann über /page/kurzname</p></div>
    <div class="field"><label for="l">Sprache</label>
      <select id="l" name="l"><option value="de" <?= $loc === 'de' ? 'selected' : '' ?>>Deutsch</option><option value="en" <?= $loc === 'en' ? 'selected' : '' ?>>Englisch</option></select></div>
  </div>
  <div class="field"><label for="title">Titel</label>
    <input id="title" name="title" required maxlength="120" value="<?= e($current['title'] ?? '') ?>"></div>
  <div class="field"><label for="body">Inhalt</label>
    <textarea id="body" name="body" rows="22" class="mono"><?= e($current['body'] ?? '') ?></textarea>
    <p class="hint">Absätze durch eine Leerzeile trennen. <code>## Überschrift</code>, <code>### Unterüberschrift</code>, <code>- Listenpunkt</code>, <code>**fett**</code>, <code>[Linktext](https://…)</code> oder <code>[E-Mail](mailto:…)</code>.</p></div>
  <button type="submit">Speichern</button>
</form>
<?php pageFooter();
