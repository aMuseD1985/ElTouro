<?php
/** Maintain forum categories */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireAdmin();

if (isPost()) {
    checkCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(postField('slug', 60)));
    $values = [postField('name_de', 80), postField('name_en', 80), postField('description_de', 255) ?: null, postField('description_en', 255) ?: null, (int)($_POST['sort'] ?? 0)];
    if (($_POST['action'] ?? '') === 'delete') {
        $n = (int)dbOne('SELECT COUNT(*) AS n FROM forum_threads WHERE category_id = ?', [$id])['n'];
        if ($n > 0) {
            flash('Die Kategorie enthält noch Themen und kann nicht gelöscht werden.', 'error');
        } else {
            dbExec('DELETE FROM forum_categories WHERE id = ?', [$id]);
            flash('Kategorie gelöscht.');
        }
    } elseif ($slug === '' || $values[0] === '' || $values[1] === '') {
        flash('Kurzname und beide Namen sind Pflicht.', 'error');
    } elseif ($id > 0) {
        dbExec('UPDATE forum_categories SET slug = ?, name_de = ?, name_en = ?, description_de = ?, description_en = ?, sort = ? WHERE id = ?', [$slug, ...$values, $id]);
        flash('Gespeichert.');
    } else {
        dbExec('INSERT INTO forum_categories (slug, name_de, name_en, description_de, description_en, sort) VALUES (?, ?, ?, ?, ?, ?)', [$slug, ...$values]);
        flash('Kategorie angelegt.');
    }
    redirect('/admin/forum.php');
}

$list = dbAll('SELECT c.*, (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = c.id) AS topics FROM forum_categories c ORDER BY sort, id');
$blank = ['id' => 0, 'slug' => '', 'name_de' => '', 'name_en' => '', 'description_de' => '', 'description_en' => '', 'sort' => (count($list) + 1) * 10, 'topics' => 0];

pageHeader('Forum');
require __DIR__ . '/_nav.php';
?>
<h1>Forumskategorien</h1>
<?php foreach ([...$list, $blank] as $c): ?>
<details class="panel" <?= $c['id'] ? '' : 'open' ?>>
  <summary><strong><?= $c['id'] ? e($c['name_de']) . ' · ' . (int)$c['topics'] . ' Themen' : 'Neue Kategorie' ?></strong></summary>
  <form method="post" class="form wide">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <div class="row3">
      <div class="field"><label>Kurzname<input name="slug" required pattern="[a-z0-9-]+" value="<?= e($c['slug']) ?>"></label></div>
      <div class="field"><label>Name DE<input name="name_de" required maxlength="80" value="<?= e($c['name_de']) ?>"></label></div>
      <div class="field"><label>Name EN<input name="name_en" required maxlength="80" value="<?= e($c['name_en']) ?>"></label></div>
    </div>
    <div class="row3">
      <div class="field"><label>Beschreibung DE<input name="description_de" maxlength="255" value="<?= e((string)$c['description_de']) ?>"></label></div>
      <div class="field"><label>Beschreibung EN<input name="description_en" maxlength="255" value="<?= e((string)$c['description_en']) ?>"></label></div>
      <div class="field"><label>Reihenfolge<input name="sort" type="number" value="<?= (int)$c['sort'] ?>"></label></div>
    </div>
    <button type="submit">Speichern</button>
  </form>
  <?php if ($c['id'] && !(int)$c['topics']): ?>
    <form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="delete"><button class="link danger">Kategorie löschen</button></form>
  <?php endif; ?>
</details>
<?php endforeach; ?>
<?php pageFooter();
