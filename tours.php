<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tours_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

// Filters (GET): search in title and description, difficulty, length class, sort order
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$diff = in_array($_GET['d'] ?? '', ['easy', 'moderate', 'demanding'], true) ? (string)$_GET['d'] : '';
$len = in_array($_GET['len'] ?? '', ['short', 'mid', 'long'], true) ? (string)$_GET['len'] : '';
$sort = in_array($_GET['sort'] ?? '', ['new', 'short', 'long'], true) ? (string)$_GET['sort'] : 'new';
$where = ''; $params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (t.title LIKE ? OR t.description LIKE ?)';
    array_push($params, $like, $like);
}
if ($diff !== '') { $where .= ' AND t.difficulty = ?'; $params[] = $diff; }
if ($len === 'short') { $where .= ' AND t.distance_m < 10000'; }
elseif ($len === 'mid') { $where .= ' AND t.distance_m BETWEEN 10000 AND 30000'; }
elseif ($len === 'long') { $where .= ' AND t.distance_m > 30000'; }
$order = ['new' => 't.updated_at DESC', 'short' => 't.distance_m ASC', 'long' => 't.distance_m DESC'][$sort];
$filtered = $q !== '' || $diff !== '' || $len !== '';

$columns = 't.id, t.title, t.distance_m, t.ascent_m, t.difficulty, t.style, t.visibility, t.start_lat, t.start_lng, t.updated_at, u.display_name AS creator, g.name AS crew_name';
$from = "FROM tours t JOIN users u ON u.id = t.owner_user_id LEFT JOIN rider_groups g ON g.id = t.owner_group_id AND g.deleted_at IS NULL";

$mine = dbAll("SELECT $columns $from WHERE t.owner_user_id = ? AND t.deleted_at IS NULL$where ORDER BY $order", [$uid, ...$params]);
$crews = dbAll("SELECT $columns $from
                 JOIN group_members m ON m.group_id = t.owner_group_id AND m.user_id = ? AND m.status = 'active'
                WHERE t.visibility IN ('group','public') AND t.owner_user_id <> ? AND t.deleted_at IS NULL$where
                ORDER BY $order LIMIT 50", [$uid, $uid, ...$params]);
$public = dbAll("SELECT $columns $from WHERE t.visibility = 'public' AND t.owner_user_id <> ? AND t.deleted_at IS NULL$where
                 ORDER BY " . ($sort === 'new' ? 't.created_at DESC' : $order) . " LIMIT 30", [$uid, ...$params]);

/** A list of tour tiles with preview picture; $emptyCta = show "plan your first route" when nothing is there. */
function tourList(array $list, bool $emptyCta = false): void
{
    global $filtered;
    if (!$list) {
        echo '<p class="muted">' . te($filtered ? 'tours.none_filtered' : 'tours.none') . '</p>';
        if ($emptyCta && !$filtered) {
            echo '<p><a class="btn" href="/tour/plan">' . te('tours.first') . '</a></p>';
        }
        return;
    }
    echo '<ul class="cards tour-cards">';
    foreach ($list as $t) {
        $v = strtotime($t['updated_at'] . ' UTC');
        echo '<li class="card tour-card" data-lat="' . e((string)$t['start_lat']) . '" data-lng="' . e((string)$t['start_lng']) . '">'
           . '<a class="tour-thumb" href="/tour/' . (int)$t['id'] . '" tabindex="-1" aria-hidden="true"><img src="/tour/' . (int)$t['id'] . '/thumb.png?v=' . $v . '" alt="" loading="lazy" width="240" height="135"></a>'
           . '<div class="tour-body"><h3><a href="/tour/' . (int)$t['id'] . '">' . e($t['title']) . '</a></h3>'
           . '<p class="tour-meta"><strong>' . e(formatKm((int)$t['distance_m'])) . '</strong>'
           . ($t['ascent_m'] !== null ? ' · ↗ ' . (int)$t['ascent_m'] . ' m' : '')
           . ' · ' . te('tour.d_' . $t['difficulty']) . ' · ' . te('tour.s_' . $t['style']) . '</p>'
           . '<p class="muted tour-by">' . te('tour.by', ['name' => $t['creator']]) . ($t['crew_name'] ? ' · ' . e($t['crew_name']) : '') . '<span class="tour-dist" hidden></span></p></div></li>';
    }
    echo '</ul>';
}

pageHeader(t('tours.title'));
?>
<div class="title-row">
  <h1><?= te('tours.title') ?></h1>
  <a class="btn" href="/tour/plan"><?= te('tour.new') ?></a>
</div>
<p class="muted"><a href="/drives"><?= te('drives.title') ?></a> · <a href="/free"><?= te('free.start_link') ?></a></p>
<form class="filters" method="get" role="search">
  <div class="filters-row">
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="<?= te('tours.search') ?>" aria-label="<?= te('tours.search') ?>">
    <button type="submit" class="secondary-submit" aria-label="<?= te('tours.apply') ?>">🔍</button>
  </div>
  <details class="filters-more"<?= ($diff !== '' || $len !== '' || $sort !== 'new') ? ' open' : '' ?>>
    <summary><?= te('tours.filter_title') ?></summary>
    <div class="filters-grid">
      <select name="d" aria-label="<?= te('tour.difficulty') ?>"><option value=""><?= te('tours.any_difficulty') ?></option>
        <?php foreach (['easy', 'moderate', 'demanding'] as $o): ?><option value="<?= $o ?>" <?= $diff === $o ? 'selected' : '' ?>><?= te('tour.d_' . $o) ?></option><?php endforeach; ?></select>
      <select name="len" aria-label="<?= te('tour.length') ?>"><option value=""><?= te('tours.any_length') ?></option>
        <?php foreach (['short', 'mid', 'long'] as $o): ?><option value="<?= $o ?>" <?= $len === $o ? 'selected' : '' ?>><?= te('tours.len_' . $o) ?></option><?php endforeach; ?></select>
      <select name="sort" aria-label="<?= te('tours.sort') ?>">
        <?php foreach (['new', 'short', 'long'] as $o): ?><option value="<?= $o ?>" <?= $sort === $o ? 'selected' : '' ?>><?= te('tours.sort_' . $o) ?></option><?php endforeach; ?></select>
      <button type="submit" class="secondary-submit"><?= te('tours.apply') ?></button>
    </div>
  </details>
  <div class="filters-row">
    <button type="button" class="secondary-submit" id="near-me" data-error="<?= te('tours.near_error') ?>" data-km="<?= te('tours.near_km') ?>">📍 <?= te('tours.near') ?></button>
    <?php if ($filtered): ?><a href="/tours"><?= te('tours.reset') ?></a><?php endif; ?>
  </div>
</form>
<h2><?= te('tours.mine') ?></h2><?php tourList($mine, true); ?>
<h2><?= te('tours.crews') ?></h2><?php tourList($crews); ?>
<h2><?= te('tours.public') ?></h2><?php tourList($public); ?>
<script src="/assets/tours_near.js?v=1"></script>
<?php pageFooter();
