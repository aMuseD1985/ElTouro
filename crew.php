<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/crews_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$slug = (string)($_GET['s'] ?? '');
$code = isset($_GET['code']) ? (string)$_GET['code'] : (isset($_POST['code']) ? (string)$_POST['code'] : null);
$crew = loadCrew($slug);
$m = $crew ? membership((int)$crew['id'], $uid) : null;

if ($crew === null || !canSeeCrew($crew, $m, $code)) {
    notFound();
}
$gid = (int)$crew['id'];
$self = '/crew.php?s=' . rawurlencode($crew['slug']);

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    $target = (int)($_POST['user'] ?? 0);

    switch ($action) {
        case 'join':
            if ($m === null && ($status = joinStatus($crew, $code)) !== null) {
                dbExec("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', ?)", [$gid, $uid, $status]);
                flash(t($status === 'active' ? 'crew.joined' : 'crew.requested'));
            }
            break;

        case 'leave':
            if ($m !== null) {
                if (isCrewLead($m) && countCrewLeads($gid) <= 1) {
                    flash(t('crew.last_lead'), 'error');
                    break;
                }
                dbExec('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$gid, $uid]);
                flash(t('crew.left'));
                redirect($crew['discoverability'] === 'secret' ? '/crews.php' : $self);
            }
            break;

        // From here on crew leads only
        case 'accept':
        case 'decline':
        case 'remove':
        case 'promote':
        case 'settings':
        case 'new_code':
            if (!isCrewLead($m)) {
                http_response_code(403);
                exit;
            }
            if ($action === 'accept') {
                dbExec("UPDATE group_members SET status = 'active' WHERE group_id = ? AND user_id = ? AND status = 'pending'", [$gid, $target]);
            } elseif ($action === 'decline') {
                dbExec("DELETE FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'pending'", [$gid, $target]);
            } elseif ($action === 'remove' && $target !== $uid) {
                dbExec('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$gid, $target]);
            } elseif ($action === 'promote') {
                dbExec("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ? AND status = 'active'", [$gid, $target]);
            } elseif ($action === 'new_code') {
                dbExec('UPDATE rider_groups SET invite_code = ? WHERE id = ?', [newInviteCode(), $gid]);
            } elseif ($action === 'settings') {
                $name = postField('name', 60);
                $visibility = postField('visibility');
                $joining = postField('joining');
                if (mb_strlen($name) >= 3 && in_array($visibility, ['listed', 'secret'], true) && in_array($joining, ['open', 'request', 'invite'], true)) {
                    dbExec('UPDATE rider_groups SET name = ?, description = ?, region = ?, discoverability = ?, join_policy = ? WHERE id = ?',
                        [$name, postField('description', 2000) ?: null, postField('region', 100) ?: null, $visibility, $joining, $gid]);
                    flash(t('crew.saved'));
                } else {
                    flash(t('crew.error_name'), 'error');
                }
            }
            break;
    }
    redirect($self . ($code !== null && $m === null ? '&code=' . rawurlencode($code) : ''));
}

$isMember = isActiveMember($m);
$isLead = isCrewLead($m);
$count = (int)dbOne("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND status = 'active'", [$gid])['n'];
$members = $isMember ? dbAll("SELECT u.id, u.display_name, m.role FROM group_members m JOIN users u ON u.id = m.user_id
                              WHERE m.group_id = ? AND m.status = 'active' ORDER BY m.role = 'admin' DESC, u.display_name", [$gid]) : [];
$requests = $isLead ? dbAll("SELECT u.id, u.display_name FROM group_members m JOIN users u ON u.id = m.user_id
                             WHERE m.group_id = ? AND m.status = 'pending' ORDER BY m.created_at", [$gid]) : [];
$canJoin = $m === null ? joinStatus($crew, $code) : null;

pageHeader($crew['name']);
?>
<header class="crew-header">
  <h1><?= e($crew['name']) ?></h1>
  <p class="muted"><?= $crew['region'] ? e($crew['region']) . ' · ' : '' ?><?= te('crews.members', ['n' => $count]) ?></p>
  <?php if ($crew['description']): ?><p class="description"><?= nl2br(e($crew['description'])) ?></p><?php endif; ?>

  <?php if ($m === null): ?>
    <?php if ($canJoin !== null): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="join">
        <?php if ($code !== null): ?><input type="hidden" name="code" value="<?= e($code) ?>"><?php endif; ?>
        <button type="submit"><?= te($canJoin === 'active' ? 'crew.join' : 'crew.request_join') ?></button></form>
    <?php else: ?>
      <p class="muted"><?= te('crew.invite_only') ?></p>
    <?php endif; ?>
  <?php elseif ($m['status'] === 'pending'): ?>
    <p class="alert alert-info"><?= te('crew.request_pending') ?></p>
  <?php endif; ?>
</header>

<?php if ($isMember):
  require_once __DIR__ . '/talk_lib.php';
  $unread = talkUnreadCount($gid, $uid);
  $latest = dbAll('SELECT id, title, last_post_at FROM herd_topics WHERE group_id = ? AND deleted_at IS NULL ORDER BY last_post_at DESC LIMIT 3', [$gid]); ?>
<section class="talk-teaser">
  <div class="title-row">
    <h2><?= te('talk.title') ?><?php if ($unread > 0): ?> <span class="tt-new"><?= te('talk.n_new', ['n' => $unread]) ?></span><?php endif; ?></h2>
    <a class="btn" href="/talk.php?s=<?= e(rawurlencode($crew['slug'])) ?>"><?= te('talk.open') ?></a>
  </div>
  <?php if ($latest): ?><ul class="list"><?php foreach ($latest as $lt): ?>
    <li><a href="/talk_topic.php?id=<?= (int)$lt['id'] ?>"><?= e($lt['title']) ?></a> <span class="muted">· <?= e(relativeTime($lt['last_post_at'])) ?></span></li>
  <?php endforeach; ?></ul>
  <?php else: ?><p class="muted"><?= te('talk.empty') ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($isMember):
  require_once __DIR__ . '/rides_lib.php';
  $crewRides = visibleRides($uid, true, 'r.group_id = ?', [$gid], 12); ?>
<section>
  <div class="title-row">
    <h2><?= te('crew.rides') ?></h2>
    <a class="btn secondary" href="/ride_edit.php"><?= te('ride.new') ?></a>
  </div>
  <?php rideCards($crewRides, 'crew.no_rides'); ?>
</section>
<?php endif; ?>

<section>
  <h2><?= te('crew.tours') ?></h2>
  <?php if ($isMember):
    require_once __DIR__ . '/tours_lib.php';
    $tours = dbAll("SELECT id, title, distance_m FROM tours WHERE owner_group_id = ? AND visibility IN ('group','public') AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 20", [$gid]); ?>
    <ul class="list">
      <?php foreach ($tours as $tr): ?><li><a href="/tour.php?id=<?= (int)$tr['id'] ?>"><?= e($tr['title']) ?></a> <span class="muted">· <?= e(formatKm((int)$tr['distance_m'])) ?></span></li><?php endforeach; ?>
    </ul>
    <p><a class="btn secondary" href="/tour_plan.php?crew=<?= $gid ?>"><?= te('crew.tour_new') ?></a></p>
  <?php else: ?>
    <p class="muted"><?= te('crew.members_only') ?></p>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('crew.members_title') ?></h2>
  <?php if (!$isMember): ?>
    <p class="muted"><?= te('crew.members_only') ?></p>
  <?php else: ?>
    <ul class="list">
    <?php foreach ($members as $p): ?>
      <li><?= e($p['display_name']) ?><?= (int)$p['id'] === $uid ? ' (' . te('crew.you') . ')' : '' ?>
        <?php if ($p['role'] === 'admin'): ?><span class="badge"><?= te('crew.lead') ?></span><?php endif; ?>
        <?php if ($isLead && (int)$p['id'] !== $uid): ?>
          <span class="actions">
            <?php if ($p['role'] !== 'admin'): ?>
              <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="promote"><input type="hidden" name="user" value="<?= (int)$p['id'] ?>"><button class="link"><?= te('crew.promote') ?></button></form>
            <?php endif; ?>
            <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user" value="<?= (int)$p['id'] ?>"><button class="link danger"><?= te('crew.remove') ?></button></form>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($isLead): ?>
<section class="panel">
  <?php if ($requests): ?>
    <h2><?= te('crew.requests_title') ?></h2>
    <ul class="list">
    <?php foreach ($requests as $r): ?>
      <li><?= e($r['display_name']) ?>
        <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="user" value="<?= (int)$r['id'] ?>"><button class="link"><?= te('crew.accept') ?></button></form>
        <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="decline"><input type="hidden" name="user" value="<?= (int)$r['id'] ?>"><button class="link danger"><?= te('crew.decline') ?></button></form>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2><?= te('crew.invite_link') ?></h2>
  <p class="muted"><?= te('crew.invite_hint') ?></p>
  <p><input class="copy-field" readonly value="<?= e(inviteLink($crew)) ?>" aria-label="<?= te('crew.invite_link') ?>"></p>
  <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="new_code"><button class="link"><?= te('crew.invite_new') ?></button></form>

  <h2><?= te('crew.settings') ?></h2>
  <form method="post" class="form">
    <?= csrfField() ?><input type="hidden" name="action" value="settings">
    <?php $w = ['name' => $crew['name'], 'description' => (string)$crew['description'], 'region' => (string)$crew['region'],
                'visibility' => $crew['discoverability'], 'joining' => $crew['join_policy']];
          require __DIR__ . '/_crew_form.php'; ?>
    <button type="submit"><?= te('crew.save') ?></button>
  </form>
</section>
<?php endif; ?>

<?php if ($m !== null): ?>
<form method="post" class="spaced"><?= csrfField() ?><input type="hidden" name="action" value="leave"><button class="link danger"><?= te('crew.leave') ?></button></form>
<?php endif; ?>
<?php pageFooter();
