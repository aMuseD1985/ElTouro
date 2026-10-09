<?php
/** /notifications – the bell: what happened since you last looked. Opening the page marks everything as read. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/notify_lib.php';
require __DIR__ . '/forum_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$list = dbAll('SELECT id, type, link, vars, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$uid]);
dbExec('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [$uid]);
pageHeader(t('notif.title'));
?>
<div class="title-row">
  <h1><?= te('notif.title') ?></h1>
  <a class="btn secondary" href="/profile#notifications"><?= te('notif.settings') ?></a>
</div>
<?php if (!$list): ?>
  <p class="muted"><?= te('notif.none') ?></p>
<?php else: ?>
  <ul class="notif-list">
    <?php foreach ($list as $n): ?>
      <li class="<?= $n['read_at'] === null ? 'is-new' : '' ?>">
        <a href="<?= e($n['link']) ?>"><?= e(notificationText($n)) ?></a>
        <small class="muted"><?= e(formatDateTime($n['created_at'])) ?></small>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php pageFooter();
