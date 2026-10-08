<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireAdmin();

$numbers = [
    'Nutzer (bestätigt)'   => dbOne('SELECT COUNT(*) AS n FROM users WHERE email_verified_at IS NOT NULL')['n'],
    'Nutzer (unbestätigt)' => dbOne('SELECT COUNT(*) AS n FROM users WHERE email_verified_at IS NULL')['n'],
    'Herden'               => dbOne('SELECT COUNT(*) AS n FROM rider_groups WHERE deleted_at IS NULL')['n'],
    'davon geheim'         => dbOne("SELECT COUNT(*) AS n FROM rider_groups WHERE deleted_at IS NULL AND discoverability = 'secret'")['n'],
    'Offene Beitrittsanfragen' => dbOne("SELECT COUNT(*) AS n FROM group_members WHERE status = 'pending'")['n'],
    'Touren'               => dbOne('SELECT COUNT(*) AS n FROM tours WHERE deleted_at IS NULL')['n'],
    'Kommende Ausfahrten'  => dbOne("SELECT COUNT(*) AS n FROM rides WHERE deleted_at IS NULL AND status = 'planned' AND starts_at > UTC_TIMESTAMP()")['n'],
    'Forumsbeiträge'       => dbOne('SELECT COUNT(*) AS n FROM forum_posts WHERE deleted_at IS NULL')['n'],
    'Offene Meldungen'     => dbOne("SELECT COUNT(*) AS n FROM reports WHERE status = 'open'")['n'],
];
$empty = dbAll("SELECT slug, locale FROM pages WHERE TRIM(body) = '' ORDER BY slug, locale");
require __DIR__ . '/../migrations.php';
$legalTodo = legalPagesNeedingUpdate();

pageHeader('Admin');
require __DIR__ . '/_nav.php';
?>
<h1>Admin</h1>
<table class="table">
  <tbody><?php foreach ($numbers as $k => $v): ?><tr><th scope="row"><?= e($k) ?></th><td><?= (int)$v ?></td></tr><?php endforeach; ?></tbody>
</table>
<?php if ($empty): ?>
  <p class="alert alert-error">Noch leere Pflichtseiten:
  <?php foreach ($empty as $s): ?><a href="/admin/pages?s=<?= e($s['slug']) ?>&amp;l=<?= e($s['locale']) ?>"><?= e($s['slug']) ?> (<?= e(strtoupper($s['locale'])) ?>)</a> <?php endforeach; ?></p>
<?php endif; ?>
<?php if ($legalTodo): ?>
  <p class="alert alert-error">Diese Rechtstexte wurden von Hand angepasst und beschreiben noch nicht alles, was die App tut – bitte die Abschnitte aus <code>legal_texts.php</code> übernehmen:
  <?php foreach ($legalTodo as $p => $sections): [$s, $l] = explode('/', $p); ?><a href="/admin/pages?s=<?= e($s) ?>&amp;l=<?= e($l) ?>"><?= e($s) ?> (<?= e(strtoupper($l)) ?>): <?= e(implode(', ', $sections)) ?></a> <?php endforeach; ?></p>
<?php endif; ?>
<?php pageFooter();
