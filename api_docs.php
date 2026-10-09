<?php
/** /api/docs – Swagger UI, API token management and the access request. /api/docs/openapi.json – the OpenAPI document. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/api_routes.php';
$me = requireLogin();
$uid = (int)$me['id'];
$user = dbOne('SELECT * FROM users WHERE id = ?', [$uid]);
$may = apiMayUse($user);

if (isset($_GET['spec'])) {
    if (!$may) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(apiOpenApi(apiRoutes()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$newToken = null;
if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'request' && !$may) {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            flash(t('api.reason_short'), 'error');
        } elseif (dbOne("SELECT 1 AS x FROM api_requests WHERE user_id = ? AND status = 'open'", [$uid]) === null) {
            dbExec('INSERT INTO api_requests (user_id, reason) VALUES (?, ?)', [$uid, mb_substr($reason, 0, 500)]);
            flash(t('api.requested'));
        }
        redirect('/api/docs');
    }
    if ($may && $action === 'token') {
        if ((int)dbOne('SELECT COUNT(*) AS n FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL', [$uid])['n'] >= 5) {
            flash(t('api.too_many_tokens'), 'error');
            redirect('/api/docs');
        }
        $newToken = apiNewToken($uid, postField('name', 60));
    }
    if ($may && $action === 'revoke') {
        dbExec('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $uid]);
        flash(t('api.revoked'));
        redirect('/api/docs');
    }
}

$tokens = $may ? dbAll('SELECT id, name, prefix, created_at, last_used_at FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL ORDER BY id', [$uid]) : [];
$open = dbOne("SELECT created_at FROM api_requests WHERE user_id = ? AND status = 'open'", [$uid]);
$rejected = dbOne("SELECT 1 AS x FROM api_requests WHERE user_id = ? AND status = 'rejected' ORDER BY id DESC LIMIT 1", [$uid]);
pageHeader(t('api.title'));
?>
<h1><?= te('api.title') ?></h1>
<?php if (!$may): ?>
  <p><?= te('api.intro') ?></p>
  <?php if ($open): ?><p class="alert alert-info"><?= te('api.pending') ?></p>
  <?php else: ?>
    <?php if ($rejected): ?><p class="alert alert-info"><?= te('api.rejected') ?></p><?php endif; ?>
    <form method="post" class="form narrow"><?= csrfField() ?><input type="hidden" name="action" value="request">
      <div class="field"><label for="reason"><?= te('api.reason') ?></label><textarea id="reason" name="reason" rows="4" minlength="10" maxlength="500" required></textarea></div>
      <button type="submit"><?= te('api.request') ?></button></form>
  <?php endif; ?>
<?php else: ?>
  <?php if ($newToken): ?>
    <p class="alert alert-info"><?= te('api.token_once') ?><br><code class="token-show selectable"><?= e($newToken) ?></code></p>
  <?php endif; ?>
  <section class="panel narrow">
    <h2><?= te('api.tokens') ?></h2>
    <?php if ($tokens): ?><ul class="list">
      <?php foreach ($tokens as $tk): ?><li><strong><?= e($tk['name']) ?></strong> <code><?= e($tk['prefix']) ?>…</code> · <?= te('api.last_used') ?>: <?= $tk['last_used_at'] ? e(relativeTime($tk['last_used_at'])) : '–' ?>
        <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>"><button class="link danger"><?= te('api.revoke') ?></button></form></li><?php endforeach; ?>
    </ul><?php endif; ?>
    <form method="post" class="form"><?= csrfField() ?><input type="hidden" name="action" value="token">
      <div class="field"><label for="tname"><?= te('api.token_name') ?></label><input id="tname" name="name" maxlength="60" placeholder="z. B. Home Assistant"></div>
      <button type="submit"><?= te('api.token_new') ?></button></form>
    <p class="hint"><?= te('api.hint') ?></p>
  </section>
  <link rel="stylesheet" href="/assets/vendor/swagger-ui/swagger-ui.css">
  <div id="swagger-ui" class="swagger-box"></div>
  <script src="/assets/vendor/swagger-ui/swagger-ui-bundle.js"></script>
  <script src="/assets/api_docs.js?v=2"></script>
<?php endif; ?>
<?php pageFooter();
