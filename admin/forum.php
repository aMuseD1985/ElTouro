<?php
/** Forumskategorien pflegen */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
mussAdminSein();

if (istPost()) {
    pruefeCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(feld('slug', 60)));
    $werte = [feld('name_de', 80), feld('name_en', 80), feld('description_de', 255) ?: null, feld('description_en', 255) ?: null, (int)($_POST['sort'] ?? 0)];
    if (($_POST['aktion'] ?? '') === 'loeschen') {
        $n = (int)einzeln('SELECT COUNT(*) AS n FROM forum_threads WHERE category_id = ?', [$id])['n'];
        if ($n > 0) {
            meldung('Die Kategorie enthält noch Themen und kann nicht gelöscht werden.', 'fehler');
        } else {
            ausfuehren('DELETE FROM forum_categories WHERE id = ?', [$id]);
            meldung('Kategorie gelöscht.');
        }
    } elseif ($slug === '' || $werte[0] === '' || $werte[1] === '') {
        meldung('Kurzname und beide Namen sind Pflicht.', 'fehler');
    } elseif ($id > 0) {
        ausfuehren('UPDATE forum_categories SET slug = ?, name_de = ?, name_en = ?, description_de = ?, description_en = ?, sort = ? WHERE id = ?', [$slug, ...$werte, $id]);
        meldung('Gespeichert.');
    } else {
        ausfuehren('INSERT INTO forum_categories (slug, name_de, name_en, description_de, description_en, sort) VALUES (?, ?, ?, ?, ?, ?)', [$slug, ...$werte]);
        meldung('Kategorie angelegt.');
    }
    weiterleiten('/admin/forum.php');
}

$liste = alle('SELECT c.*, (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id) AS themen FROM forum_categories c ORDER BY sort, id');
$leer = ['id' => 0, 'slug' => '', 'name_de' => '', 'name_en' => '', 'description_de' => '', 'description_en' => '', 'sort' => (count($liste) + 1) * 10, 'themen' => 0];

seitenKopf('Forum');
require __DIR__ . '/_nav.php';
?>
<h1>Forumskategorien</h1>
<?php foreach ([...$liste, $leer] as $k): ?>
<details class="verwaltung" <?= $k['id'] ? '' : 'open' ?>>
  <summary><strong><?= $k['id'] ? e($k['name_de']) . ' · ' . (int)$k['themen'] . ' Themen' : 'Neue Kategorie' ?></strong></summary>
  <form method="post" class="formular breit">
    <?= csrfFeld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
    <div class="zeile3">
      <div class="feld"><label>Kurzname<input name="slug" required pattern="[a-z0-9-]+" value="<?= e($k['slug']) ?>"></label></div>
      <div class="feld"><label>Name DE<input name="name_de" required maxlength="80" value="<?= e($k['name_de']) ?>"></label></div>
      <div class="feld"><label>Name EN<input name="name_en" required maxlength="80" value="<?= e($k['name_en']) ?>"></label></div>
    </div>
    <div class="zeile3">
      <div class="feld"><label>Beschreibung DE<input name="description_de" maxlength="255" value="<?= e((string)$k['description_de']) ?>"></label></div>
      <div class="feld"><label>Beschreibung EN<input name="description_en" maxlength="255" value="<?= e((string)$k['description_en']) ?>"></label></div>
      <div class="feld"><label>Reihenfolge<input name="sort" type="number" value="<?= (int)$k['sort'] ?>"></label></div>
    </div>
    <button type="submit">Speichern</button>
  </form>
  <?php if ($k['id'] && !(int)$k['themen']): ?>
    <form method="post"><?= csrfFeld() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="aktion" value="loeschen"><button class="link gefahr">Kategorie löschen</button></form>
  <?php endif; ?>
</details>
<?php endforeach; ?>
<?php seitenFuss();
