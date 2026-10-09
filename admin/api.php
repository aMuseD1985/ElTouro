<?php
/** API access: requests from riders, who may use it, tokens (the admin decides – the API is not open to everybody). */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$me = requireAdmin();

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'approve' || $action === 'reject') {
        $req = dbOne("SELECT * FROM api_requests WHERE id = ? AND status = 'open'", [$id]);
        if ($req) {
            dbExec('UPDATE api_requests SET status = ?, handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ?', [$action === 'approve' ? 'approved' : 'rejected', $me['id'], $id]);
            if ($action === 'approve') {
                dbExec('UPDATE users SET api_access = 1 WHERE id = ?', [$req['user_id']]);
            }
            flash($action === 'approve' ? 'API-Zugang freigegeben.' : 'Anfrage abgelehnt.');
        }
    } elseif ($action === 'revoke_access') {
        dbExec('UPDATE users SET api_access = 0 WHERE id = ? AND is_admin = 0', [$id]);
        dbExec('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL AND ? NOT IN (SELECT id FROM users WHERE is_admin = 1)', [$id, $id]);
        flash('Zugang entzogen, Tokens widerrufen.');
    } elseif ($action === 'revoke_token') {
        dbExec('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        flash('Token widerrufen.');
    } elseif ($action === 'grant') {
        $u = dbOne("SELECT id FROM users WHERE email = ? AND status = 'active'", [mb_strtolower(trim((string)($_POST['email'] ?? '')))]);
        if ($u) {
            dbExec('UPDATE users SET api_access = 1 WHERE id = ?', [$u['id']]);
            flash('API-Zugang freigegeben.');
        } else {
            flash('Kein aktives Konto mit dieser E-Mail-Adresse.', 'error');
        }
    }
    redirect('/admin/api');
}

$open = dbAll("SELECT r.*, u.display_name, u.email FROM api_requests r JOIN users u ON u.id = r.user_id WHERE r.status = 'open' ORDER BY r.created_at");
$users = dbAll("SELECT u.id, u.display_name, u.email, u.is_admin, (SELECT COUNT(*) FROM api_tokens t WHERE t.user_id = u.id AND t.revoked_at IS NULL) AS tokens
                  FROM users u WHERE u.api_access = 1 AND u.status = 'active' ORDER BY u.display_name");
$tokens = dbAll('SELECT t.id, t.name, t.prefix, t.created_at, t.last_used_at, u.display_name FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.revoked_at IS NULL ORDER BY t.last_used_at DESC, t.id DESC LIMIT 100');
pageHeader('API');
require __DIR__ . '/_nav.php';
?>
<h1>API-Zugang</h1>
<p class="muted">Die REST-API (<a href="/api/docs">/api/docs</a>) ist nur für Admins und von dir freigegebene Fahrer. Anfragen entscheidest du hier.</p>

<h2>Offene Anfragen (<?= count($open) ?>)</h2>
<?php if (!$open): ?><p class="muted">Keine.</p><?php endif; ?>
<?php foreach ($open as $r): ?>
<section class="panel">
  <p><strong><?= e($r['display_name']) ?></strong> (<?= e($r['email']) ?>) · <?= e(substr($r['created_at'], 0, 16)) ?> UTC</p>
  <p><?= nl2br(e($r['reason'])) ?></p>
  <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="approve">Freigeben</button> <button name="action" value="reject" class="secondary-submit">Ablehnen</button></form>
</section>
<?php endforeach; ?>

<h2>Direkt freigeben</h2>
<form method="post" class="form"><?= csrfField() ?><input type="hidden" name="action" value="grant">
  <div class="field"><label for="email">E-Mail-Adresse des Fahrers</label><input id="email" name="email" type="email" required></div><button type="submit">Freigeben</button></form>

<h2>Mit Zugang (<?= count($users) ?>)</h2>
<table class="table"><thead><tr><th scope="col">Fahrer</th><th scope="col">Tokens</th><th scope="col"></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><?= e($u['display_name']) ?> <span class="muted"><?= e($u['email']) ?></span><?= $u['is_admin'] ? ' · Admin' : '' ?></td><td><?= (int)$u['tokens'] ?></td>
  <td><?php if (!$u['is_admin']): ?><form method="post" class="inline" data-confirm="Zugang entziehen und alle Tokens widerrufen?"><?= csrfField() ?><input type="hidden" name="action" value="revoke_access"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link danger">Zugang entziehen</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table>

<h2>Aktive Tokens</h2>
<table class="table"><thead><tr><th scope="col">Fahrer</th><th scope="col">Name</th><th scope="col">Token</th><th scope="col">Zuletzt</th><th scope="col"></th></tr></thead><tbody>
<?php foreach ($tokens as $t): ?><tr><td><?= e($t['display_name']) ?></td><td><?= e($t['name']) ?></td><td><code><?= e($t['prefix']) ?>…</code></td><td><?= e((string)substr((string)$t['last_used_at'], 0, 16)) ?: '–' ?></td>
  <td><form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="link danger">widerrufen</button></form></td></tr><?php endforeach; ?>
</tbody></table>
<?php pageFooter();
