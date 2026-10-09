<?php
/** Report content (DSA Art. 16). Reports go to the admins under "Meldungen". */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$me = requireLogin();

const REPORT_TYPES = ['post', 'thread', 'tour', 'group', 'user', 'herdpost', 'ride', 'rating', 'spot', 'photo'];
$type = (string)($_GET['type'] ?? $_POST['type'] ?? '');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!in_array($type, REPORT_TYPES, true) || $id <= 0) {
    redirect('/');
}
$error = '';

if (isPost()) {
    checkCsrf();
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (mb_strlen($reason) < 10) {
        $error = t('report.error');
    } else {
        $recent = (int)dbOne("SELECT COUNT(*) AS n FROM reports WHERE reporter_user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [$me['id']])['n'];
        if ($recent < 10) {
            dbExec('INSERT INTO reports (reporter_user_id, target_type, target_id, reason) VALUES (?, ?, ?, ?)',
                [$me['id'], $type, $id, mb_substr($reason, 0, 2000)]);
        }
        flash(t('report.thanks'));
        redirect('/');
    }
}

pageHeader(t('report.title'));
?>
<section class="narrow">
  <h1><?= te('report.title') ?></h1>
  <p><?= te('report.text') ?></p>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= csrfField() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label for="reason"><?= te('report.reason') ?></label><textarea id="reason" name="reason" rows="5" required minlength="10" maxlength="2000"></textarea></div>
    <button type="submit"><?= te('report.send') ?></button>
  </form>
</section>
<?php pageFooter();
