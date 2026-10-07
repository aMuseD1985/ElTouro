<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/touren_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];

$spalten = 't.id, t.title, t.distance_m, t.difficulty, t.style, t.visibility, u.display_name AS ersteller, g.name AS herde_name';
$basis = "FROM tours t JOIN users u ON u.id = t.owner_user_id LEFT JOIN rider_groups g ON g.id = t.owner_group_id AND g.deleted_at IS NULL";

$meine = alle("SELECT $spalten $basis WHERE t.owner_user_id = ? AND t.deleted_at IS NULL ORDER BY t.updated_at DESC", [$uid]);
$herde = alle("SELECT $spalten $basis
                 JOIN group_members m ON m.group_id = t.owner_group_id AND m.user_id = ? AND m.status = 'active'
                WHERE t.visibility IN ('group','public') AND t.owner_user_id <> ? AND t.deleted_at IS NULL
                ORDER BY t.updated_at DESC LIMIT 50", [$uid, $uid]);
$oeffentlich = alle("SELECT $spalten $basis WHERE t.visibility = 'public' AND t.owner_user_id <> ? AND t.deleted_at IS NULL
                     ORDER BY t.created_at DESC LIMIT 30", [$uid]);

function tourListe(array $liste): void
{
    if (!$liste) { echo '<p class="leise">' . te('touren.keine') . '</p>'; return; }
    echo '<ul class="karten">';
    foreach ($liste as $t) {
        echo '<li class="karte"><h3><a href="/tour.php?id=' . (int)$t['id'] . '">' . e($t['title']) . '</a></h3>'
           . '<p class="leise">' . e(formatiereKm((int)$t['distance_m'])) . ' · ' . te('tour.d_' . $t['difficulty']) . ' · ' . te('tour.s_' . $t['style']) . '</p>'
           . '<p class="leise">' . te('tour.von', ['name' => $t['ersteller']]) . ($t['herde_name'] ? ' · ' . e($t['herde_name']) : '') . '</p></li>';
    }
    echo '</ul>';
}

seitenKopf(t('touren.titel'));
?>
<div class="kopfzeile">
  <h1><?= te('touren.titel') ?></h1>
  <a class="knopf" href="/tour_planen.php"><?= te('tour.neu') ?></a>
</div>
<h2><?= te('touren.meine') ?></h2><?php tourListe($meine); ?>
<h2><?= te('touren.herden') ?></h2><?php tourListe($herde); ?>
<h2><?= te('touren.oeffentlich') ?></h2><?php tourListe($oeffentlich); ?>
<?php seitenFuss();
