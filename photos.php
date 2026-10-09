<?php
/** /crew/<slug>/photos – the photo wall of a crew: upload, like, delete. Members only. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/photos_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$crew = loadCrew((string)($_GET['s'] ?? $_POST['s'] ?? ''));
[$allowed, $moderator] = $crew ? photoAccess($crew, $me) : [false, false];
if (!$allowed) {
    notFound(t('photo.members_only'));
}
$gid = (int)$crew['id'];
$self = '/crew/' . rawurlencode($crew['slug']) . '/photos';

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'upload') {
        if (empty($_POST['rights'])) {
            flash(t('photo.error_rights'), 'error');
        } else {
            $files = $_FILES['photos'] ?? null;
            $count = 0; $err = '';
            if ($files && is_array($files['name'])) {
                foreach (array_slice(array_keys($files['name']), 0, 10) as $i) {
                    $r = savePhoto($gid, $uid, ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]], postField('caption', 200));
                    is_int($r) ? $count++ : $err = $r;
                    if ($err === 'limit') { break; }
                }
            }
            if ($count > 0) {
                flash(t('photo.added', ['n' => $count]));
            }
            if ($err !== '' && $err !== 'no_file') {
                flash(t('photo.error_' . $err), 'error');
            } elseif ($count === 0) {
                flash(t('photo.error_none'), 'error');
            }
        }
    } elseif ($action === 'delete') {
        $p = dbOne('SELECT id, user_id FROM crew_photos WHERE id = ? AND group_id = ? AND deleted_at IS NULL', [(int)($_POST['photo'] ?? 0), $gid]);
        if ($p && ((int)$p['user_id'] === $uid || $moderator)) {
            deletePhoto((int)$p['id']);
            flash(t('photo.deleted'));
        }
    } elseif ($action === 'like') {
        $p = dbOne('SELECT id FROM crew_photos WHERE id = ? AND group_id = ? AND deleted_at IS NULL', [(int)($_POST['photo'] ?? 0), $gid]);
        if ($p) {
            toggleLike((int)$p['id'], $uid);
        }
    }
    redirect($self . (isset($_POST['p']) ? '?p=' . max(1, (int)$_POST['p']) : ''));
}

$page = max(1, (int)($_GET['p'] ?? 1));
$total = (int)dbOne('SELECT COUNT(*) AS n FROM crew_photos WHERE group_id = ? AND deleted_at IS NULL', [$gid])['n'];
$photos = dbAll('SELECT p.*, u.display_name, (SELECT 1 FROM crew_photo_likes l WHERE l.photo_id = p.id AND l.user_id = ?) AS liked
                   FROM crew_photos p JOIN users u ON u.id = p.user_id WHERE p.group_id = ? AND p.deleted_at IS NULL
                  ORDER BY p.id DESC LIMIT ' . PHOTO_PAGE . ' OFFSET ' . (($page - 1) * PHOTO_PAGE), [$uid, $gid]);
pageHeader(t('photo.title') . ' · ' . $crew['name']);
?>
<p class="breadcrumbs"><a href="/crew/<?= e(rawurlencode($crew['slug'])) ?>"><?= e($crew['name']) ?></a> ›</p>
<div class="title-row"><h1><?= te('photo.title') ?></h1></div>
<details class="panel" <?= $total === 0 ? 'open' : '' ?>>
  <summary><?= te('photo.add') ?></summary>
  <form method="post" enctype="multipart/form-data" class="form" id="photo-upload">
    <?= csrfField() ?><input type="hidden" name="action" value="upload">
    <div class="field"><label for="photos"><?= te('photo.choose') ?></label><input id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
      <p class="hint"><?= te('photo.hint', ['mb' => PHOTO_MAX_BYTES / 1048576]) ?></p></div>
    <div class="field"><label for="caption"><?= te('photo.caption') ?></label><input id="caption" name="caption" maxlength="200"></div>
    <label class="choice"><input type="checkbox" name="rights" value="1" required> <?= te('photo.rights') ?></label>
    <button type="submit"><?= te('photo.upload') ?></button>
  </form>
</details>
<?php if (!$photos): ?><p class="muted"><?= te('photo.empty') ?></p><?php endif; ?>
<ul class="photo-grid" id="photo-grid" data-csrf="<?= e(csrfToken()) ?>">
  <?php foreach ($photos as $p): ?>
  <li class="photo-card" data-id="<?= (int)$p['id'] ?>">
    <a class="photo-link" href="/photo/<?= (int)$p['id'] ?>/full" data-w="<?= (int)$p['width'] ?>" data-h="<?= (int)$p['height'] ?>" data-caption="<?= e((string)$p['caption']) ?>" data-by="<?= e($p['display_name']) ?>">
      <img src="/photo/<?= (int)$p['id'] ?>/thumb" alt="<?= e($p['caption'] ?: t('photo.alt', ['name' => $p['display_name']])) ?>" loading="lazy" width="<?= (int)$p['width'] ?>" height="<?= (int)$p['height'] ?>"></a>
    <div class="photo-meta">
      <span class="muted small"><?= e($p['display_name']) ?> · <?= e(relativeTime($p['created_at'])) ?></span>
      <form method="post" class="inline photo-like-form"><?= csrfField() ?><input type="hidden" name="action" value="like"><input type="hidden" name="photo" value="<?= (int)$p['id'] ?>"><input type="hidden" name="p" value="<?= $page ?>">
        <button type="submit" class="like-btn<?= $p['liked'] ? ' is-on' : '' ?>" aria-pressed="<?= $p['liked'] ? 'true' : 'false' ?>" aria-label="<?= te('photo.like') ?>">♥ <span class="like-n"><?= (int)$p['like_count'] ?></span></button></form>
    </div>
    <?php if ($p['caption']): ?><p class="photo-caption"><?= e($p['caption']) ?></p><?php endif; ?>
    <p class="photo-actions small">
      <?php if ((int)$p['user_id'] === $uid || $moderator): ?>
        <form method="post" class="inline" data-confirm="<?= te('photo.delete_confirm') ?>"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="photo" value="<?= (int)$p['id'] ?>"><button class="link danger"><?= te('photo.delete') ?></button></form>
      <?php endif; ?>
      <?php if ((int)$p['user_id'] !== $uid): ?><a class="muted" href="/report?type=photo&amp;id=<?= (int)$p['id'] ?>"><?= te('report.link') ?></a><?php endif; ?>
    </p>
  </li>
  <?php endforeach; ?>
</ul>
<?php if ($total > $page * PHOTO_PAGE): ?><p><a class="btn secondary" href="<?= $self ?>?p=<?= $page + 1 ?>"><?= te('photo.older') ?></a></p><?php endif; ?>
<?php if ($page > 1): ?><p><a class="btn secondary" href="<?= $self ?>?p=<?= $page - 1 ?>"><?= te('photo.newer') ?></a></p><?php endif; ?>
<dialog id="photo-view" class="photo-view"><button type="button" class="photo-close" aria-label="<?= te('photo.close') ?>">✕</button><img alt=""><p class="photo-view-caption"></p></dialog>
<script src="/assets/photos.js?v=1"></script>
<?php pageFooter();
