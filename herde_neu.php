<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/herden_lib.php';
$ich = mussEingeloggtSein();

$fehler = '';
$w = ['name' => '', 'beschreibung' => '', 'region' => '', 'sichtbarkeit' => 'listed', 'beitritt' => 'request'];

if (istPost()) {
    pruefeCsrf();
    $w = ['name' => feld('name', 60), 'beschreibung' => feld('beschreibung', 2000), 'region' => feld('region', 100),
          'sichtbarkeit' => feld('sichtbarkeit'), 'beitritt' => feld('beitritt')];
    if (mb_strlen($w['name']) < 3) {
        $fehler = t('herde.fehler_name');
    } elseif (in_array($w['sichtbarkeit'], ['listed', 'secret'], true) && in_array($w['beitritt'], ['open', 'request', 'invite'], true)) {
        $pdo = db();
        $pdo->beginTransaction();
        $slug = erzeugeSlug($w['name']);
        ausfuehren('INSERT INTO rider_groups (slug, name, description, region, discoverability, join_policy, invite_code, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, $w['name'], $w['beschreibung'] ?: null, $w['region'] ?: null, $w['sichtbarkeit'], $w['beitritt'], neuerEinladungscode(), $ich['id']]);
        $gid = (int)$pdo->lastInsertId();
        ausfuehren("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'admin', 'active')", [$gid, $ich['id']]);
        $pdo->commit();
        meldung(t('herde.gegruendet'));
        weiterleiten('/herde.php?s=' . rawurlencode($slug));
    }
}

seitenKopf(t('herden.neu'));
?>
<section class="schmal">
  <h1><?= te('herden.neu') ?></h1>
  <?php if ($fehler): ?><p class="meldung meldung-fehler" role="alert"><?= e($fehler) ?></p><?php endif; ?>
  <form method="post" class="formular">
    <?= csrfFeld() ?>
    <?php require __DIR__ . '/herde_formular.php'; ?>
    <button type="submit"><?= te('herde.gruenden') ?></button>
  </form>
</section>
<?php seitenFuss();
