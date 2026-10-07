<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$columns = 't.id, t.title, t.distance_m, t.difficulty, t.style, t.visibility, u.display_name AS creator, g.name AS crew_name';
$from = "FROM tours t JOIN users u ON u.id = t.owner_user_id LEFT JOIN rider_groups g ON g.id = t.owner_group_id AND g.deleted_at IS NULL";

$mine = dbAll("SELECT $columns $from WHERE t.owner_user_id = ? AND t.deleted_at IS NULL ORDER BY t.updated_at DESC", [$uid]);
$crews = dbAll("SELECT $columns $from
                 JOIN group_members m ON m.group_id = t.owner_group_id AND m.user_id = ? AND m.status = 'active'
                WHERE t.visibility IN ('group','public') AND t.owner_user_id <> ? AND t.deleted_at IS NULL
                ORDER BY t.updated_at DESC LIMIT 50", [$uid, $uid]);
$public = dbAll("SELECT $columns $from WHERE t.visibility = 'public' AND t.owner_user_id <> ? AND t.deleted_at IS NULL
                 ORDER BY t.created_at DESC LIMIT 30", [$uid]);

function tourList(array $list): void
{
    if (!$list) { echo '<p class="muted">' . te('tours.none') . '</p>'; return; }
    echo '<ul class="cards">';
    foreach ($list as $t) {
        echo '<li class="card"><h3><a href="/tour.php?id=' . (int)$t['id'] . '">' . e($t['title']) . '</a></h3>'
           . '<p class="muted">' . e(formatKm((int)$t['distance_m'])) . ' · ' . te('tour.d_' . $t['difficulty']) . ' · ' . te('tour.s_' . $t['style']) . '</p>'
           . '<p class="muted">' . te('tour.by', ['name' => $t['creator']]) . ($t['crew_name'] ? ' · ' . e($t['crew_name']) : '') . '</p></li>';
    }
    echo '</ul>';
}

pageHeader(t('tours.title'));
?>
<div class="title-row">
  <h1><?= te('tours.title') ?></h1>
  <a class="btn" href="/tour_plan.php"><?= te('tour.new') ?></a>
</div>
<h2><?= te('tours.mine') ?></h2><?php tourList($mine); ?>
<h2><?= te('tours.crews') ?></h2><?php tourList($crews); ?>
<h2><?= te('tours.public') ?></h2><?php tourList($public); ?>
<?php pageFooter();
