<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/crews_lib.php';
$me = requireLogin();

$error = '';
$w = ['name' => '', 'description' => '', 'region' => '', 'visibility' => 'listed', 'joining' => 'request'];

if (isPost()) {
    checkCsrf();
    $w = ['name' => postField('name', 60), 'description' => postField('description', 2000), 'region' => postField('region', 100),
          'visibility' => postField('visibility'), 'joining' => postField('joining')];
    if (mb_strlen($w['name']) < 3) {
        $error = t('crew.error_name');
    } elseif (in_array($w['visibility'], ['listed', 'secret'], true) && in_array($w['joining'], ['open', 'request', 'invite'], true)) {
        $pdo = db();
        $pdo->beginTransaction();
        $slug = makeSlug($w['name']);
        dbExec('INSERT INTO rider_groups (slug, name, description, region, discoverability, join_policy, invite_code, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, $w['name'], $w['description'] ?: null, $w['region'] ?: null, $w['visibility'], $w['joining'], newInviteCode(), $me['id']]);
        $gid = (int)$pdo->lastInsertId();
        dbExec("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'admin', 'active')", [$gid, $me['id']]);
        $pdo->commit();
        flash(t('crew.founded'));
        redirect('/crew.php?s=' . rawurlencode($slug));
    }
}

pageHeader(t('crews.new'));
?>
<section class="narrow">
  <h1><?= te('crews.new') ?></h1>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= csrfField() ?>
    <?php require __DIR__ . '/_crew_form.php'; ?>
    <button type="submit"><?= te('crew.found') ?></button>
  </form>
</section>
<?php pageFooter();
