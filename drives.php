<?php
/** /drives – all recorded rides of the rider ("Meine Fahrten"). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/drive_lib.php';
$me = requireLogin();
$list = ownDrives((int)$me['id']);
$sum = array_sum(array_column($list, 'distance_m'));
pageHeader(t('drives.title'));
?>
<div class="title-row">
  <h1><?= te('drives.title') ?></h1>
  <a class="btn" href="/free">⏺ <?= te('free.start_link') ?></a>
</div>
<?php if (!$list): ?>
  <p class="muted"><?= te('drives.none') ?></p>
<?php else: ?>
  <p class="muted"><?= te('drives.sum', ['n' => count($list), 'km' => formatKm((int)$sum)]) ?></p>
  <?= driveCards($list) ?>
<?php endif; ?>
<?php pageFooter();
