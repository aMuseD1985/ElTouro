<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$ich = aktuellerNutzer();
seitenKopf(t('start.titel'));

if ($ich === null): ?>
  <section class="schmal">
    <h1 class="claim-klein"><?= te('start.gast_titel') ?></h1>
    <p><?= te('start.gast_text') ?></p>
    <p><a class="knopf" href="/login.php"><?= te('nav.login') ?></a>
    <?php if (einstellung('registrierung_offen', '1') === '1'): ?> <a class="knopf zweit" href="/registrieren.php"><?= te('nav.registrieren') ?></a><?php endif; ?></p>
  </section>
<?php else:
  $meine = alle("SELECT g.slug, g.name, g.region, m.status FROM group_members m
                   JOIN rider_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
                  WHERE m.user_id = ? ORDER BY g.name", [$ich['id']]); ?>
  <h1><?= te('start.hallo', ['name' => $ich['display_name']]) ?></h1>
  <section>
    <h2><?= te('start.meine_herden') ?></h2>
    <?php if (!$meine): ?>
      <p><?= te('start.keine_herden') ?></p>
      <p><a class="knopf" href="/herden.php"><?= te('herden.entdecken') ?></a> <a class="knopf zweit" href="/herde_neu.php"><?= te('herden.neu') ?></a></p>
    <?php else: ?>
      <ul class="liste">
      <?php foreach ($meine as $h): ?>
        <li><a href="/herde.php?s=<?= e(rawurlencode($h['slug'])) ?>"><?= e($h['name']) ?></a>
          <?php if ($h['status'] === 'pending'): ?><span class="marke-klein leise"><?= te('herde.anfrage_offen') ?></span><?php endif; ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif;
seitenFuss();
