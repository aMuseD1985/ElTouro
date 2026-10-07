<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
mussAdminSein();

$zahlen = [
    'Nutzer (bestätigt)'   => einzeln('SELECT COUNT(*) AS n FROM users WHERE email_verified_at IS NOT NULL')['n'],
    'Nutzer (unbestätigt)' => einzeln('SELECT COUNT(*) AS n FROM users WHERE email_verified_at IS NULL')['n'],
    'Herden'               => einzeln('SELECT COUNT(*) AS n FROM rider_groups WHERE deleted_at IS NULL')['n'],
    'davon geheim'         => einzeln("SELECT COUNT(*) AS n FROM rider_groups WHERE deleted_at IS NULL AND discoverability = 'secret'")['n'],
    'Offene Beitrittsanfragen' => einzeln("SELECT COUNT(*) AS n FROM group_members WHERE status = 'pending'")['n'],
    'Touren'               => einzeln('SELECT COUNT(*) AS n FROM tours WHERE deleted_at IS NULL')['n'],
    'Forumsbeiträge'       => einzeln('SELECT COUNT(*) AS n FROM forum_posts WHERE deleted_at IS NULL')['n'],
    'Offene Meldungen'     => einzeln("SELECT COUNT(*) AS n FROM reports WHERE status = 'open'")['n'],
];
$leer = alle("SELECT slug, locale FROM pages WHERE TRIM(body) = '' ORDER BY slug, locale");

seitenKopf('Admin');
require __DIR__ . '/_nav.php';
?>
<h1>Admin</h1>
<table class="tabelle">
  <tbody><?php foreach ($zahlen as $k => $v): ?><tr><th scope="row"><?= e($k) ?></th><td><?= (int)$v ?></td></tr><?php endforeach; ?></tbody>
</table>
<?php if ($leer): ?>
  <p class="meldung meldung-fehler">Noch leere Pflichtseiten:
  <?php foreach ($leer as $s): ?><a href="/admin/seiten.php?s=<?= e($s['slug']) ?>&amp;l=<?= e($s['locale']) ?>"><?= e($s['slug']) ?> (<?= e(strtoupper($s['locale'])) ?>)</a> <?php endforeach; ?></p>
<?php endif; ?>
<?php seitenFuss();
