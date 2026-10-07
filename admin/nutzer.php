<?php
/** Nutzerverwaltung: Admin-Recht vergeben/entziehen, Konto sperren/entsperren. */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$ich = mussAdminSein();

if (istPost()) {
    pruefeCsrf();
    $ziel = (int)($_POST['user'] ?? 0);
    if ($ziel === (int)$ich['id']) {
        meldung('Dein eigenes Konto kannst du hier nicht ändern.', 'fehler');
    } else {
        match ($_POST['aktion'] ?? '') {
            'admin_an'  => ausfuehren('UPDATE users SET is_admin = 1 WHERE id = ?', [$ziel]),
            'admin_aus' => ausfuehren('UPDATE users SET is_admin = 0 WHERE id = ?', [$ziel]),
            'sperren'   => ausfuehren("UPDATE users SET status = 'blocked' WHERE id = ?", [$ziel]),
            'entsperren'=> ausfuehren("UPDATE users SET status = 'active' WHERE id = ?", [$ziel]),
            default     => 0,
        };
        meldung('Gespeichert.');
    }
    weiterleiten('/admin/nutzer.php' . (isset($_GET['q']) ? '?q=' . rawurlencode((string)$_GET['q']) : ''));
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$like = '%' . addcslashes($q, '%_\\') . '%';
$liste = alle("SELECT id, email, display_name, is_admin, status, email_verified_at, created_at FROM users
                WHERE ? = '' OR email LIKE ? OR display_name LIKE ? ORDER BY created_at DESC LIMIT 100", [$q, $like, $like]);

function knopf(int $id, string $aktion, string $text): string
{
    return '<form method="post" class="inline">' . csrfFeld() . '<input type="hidden" name="user" value="' . $id . '">'
         . '<input type="hidden" name="aktion" value="' . e($aktion) . '"><button class="link">' . e($text) . '</button></form>';
}

seitenKopf('Nutzer');
require __DIR__ . '/_nav.php';
?>
<h1>Nutzer</h1>
<form method="get" class="suche" role="search">
  <label for="q" class="unsichtbar">Suche</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="E-Mail oder Anzeigename">
  <button type="submit">Suchen</button>
</form>
<table class="tabelle">
  <thead><tr><th scope="col">Name</th><th scope="col">E-Mail</th><th scope="col">Status</th><th scope="col">Seit</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $u): $id = (int)$u['id']; ?>
    <tr>
      <td><?= e($u['display_name']) ?><?= $u['is_admin'] ? ' <span class="marke-klein">Admin</span>' : '' ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= $u['status'] === 'blocked' ? 'gesperrt' : ($u['email_verified_at'] ? 'aktiv' : 'unbestätigt') ?></td>
      <td><?= e(substr($u['created_at'], 0, 10)) ?></td>
      <td><?php if ($id !== (int)$ich['id']): ?>
        <?= $u['is_admin'] ? knopf($id, 'admin_aus', 'Admin entziehen') : knopf($id, 'admin_an', 'Zum Admin machen') ?>
        <?= $u['status'] === 'blocked' ? knopf($id, 'entsperren', 'Entsperren') : knopf($id, 'sperren', 'Sperren') ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php seitenFuss();
