<?php
/** User management: grant/revoke admin rights, block/unblock accounts. */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$me = requireAdmin();

if (isPost()) {
    checkCsrf();
    $target = (int)($_POST['user'] ?? 0);
    if ($target === (int)$me['id']) {
        flash('Dein eigenes Konto kannst du hier nicht ändern.', 'error');
    } else {
        match ($_POST['action'] ?? '') {
            'admin_on'  => dbExec('UPDATE users SET is_admin = 1 WHERE id = ?', [$target]),
            'admin_off' => dbExec('UPDATE users SET is_admin = 0 WHERE id = ?', [$target]),
            'block'     => dbExec("UPDATE users SET status = 'blocked' WHERE id = ?", [$target]),
            'unblock'   => dbExec("UPDATE users SET status = 'active' WHERE id = ?", [$target]),
            default     => 0,
        };
        flash('Gespeichert.');
    }
    redirect('/admin/users.php' . (isset($_GET['q']) ? '?q=' . rawurlencode((string)$_GET['q']) : ''));
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$like = '%' . addcslashes($q, '%_\\') . '%';
$list = dbAll("SELECT id, email, display_name, is_admin, status, email_verified_at, created_at FROM users
                WHERE ? = '' OR email LIKE ? OR display_name LIKE ? ORDER BY created_at DESC LIMIT 100", [$q, $like, $like]);

function actionButton(int $id, string $action, string $label): string
{
    return '<form method="post" class="inline">' . csrfField() . '<input type="hidden" name="user" value="' . $id . '">'
         . '<input type="hidden" name="action" value="' . e($action) . '"><button class="link">' . e($label) . '</button></form>';
}

pageHeader('Nutzer');
require __DIR__ . '/_nav.php';
?>
<h1>Nutzer</h1>
<form method="get" class="search" role="search">
  <label for="q" class="visually-hidden">Suche</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="E-Mail oder Anzeigename">
  <button type="submit">Suchen</button>
</form>
<table class="table">
  <thead><tr><th scope="col">Name</th><th scope="col">E-Mail</th><th scope="col">Status</th><th scope="col">Seit</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($list as $u): $id = (int)$u['id']; ?>
    <tr>
      <td><?= e($u['display_name']) ?><?= $u['is_admin'] ? ' <span class="badge">Admin</span>' : '' ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= $u['status'] === 'blocked' ? 'gesperrt' : ($u['email_verified_at'] ? 'aktiv' : 'unbestätigt') ?></td>
      <td><?= e(substr($u['created_at'], 0, 10)) ?></td>
      <td><?php if ($id !== (int)$me['id']): ?>
        <?= $u['is_admin'] ? actionButton($id, 'admin_off', 'Admin entziehen') : actionButton($id, 'admin_on', 'Zum Admin machen') ?>
        <?= $u['status'] === 'blocked' ? actionButton($id, 'unblock', 'Entsperren') : actionButton($id, 'block', 'Sperren') ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php pageFooter();
