<?php
/** /tour/import – make a tour from a GPX file (own recordings, tours from friends, other apps). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/gpx_lib.php';
require_once __DIR__ . '/crews_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$crews = activeCrewsOf($uid);
$error = '';
$v = ['title' => '', 'description' => '', 'visibility' => 'private', 'group_id' => 0, 'difficulty' => 'easy', 'style' => 'social'];

if (isPost()) {
    checkCsrf();
    foreach (['title' => 120, 'description' => 4000] as $f => $max) {
        $v[$f] = $f === 'description' ? mb_substr(trim((string)($_POST[$f] ?? '')), 0, $max) : postField($f, $max);
    }
    foreach (['visibility', 'difficulty', 'style'] as $f) {
        $v[$f] = (string)($_POST[$f] ?? $v[$f]);
    }
    $v['group_id'] = (int)($_POST['group_id'] ?? 0);
    $file = $_FILES['gpx'] ?? null;
    if ($v['visibility'] === 'group' && !isActiveMember(membership($v['group_id'], $uid))) {
        $error = t('gpx.error_crew');
    } elseif (empty($_POST['rights'])) {
        $error = t('gpx.error_rights');
    } elseif (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        $error = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? t('gpx.error_too_big') : t('gpx.error_nofile');
    } else {
        $r = importGpxTour((string)file_get_contents((string)$file['tmp_name']), $uid, $v);
        if (is_int($r)) {
            flash(t('gpx.done'));
            redirect('/tour/' . $r);
        }
        $error = t('gpx.error_' . $r);
    }
}
pageHeader(t('gpx.title'));
?>
<h1><?= te('gpx.title') ?></h1>
<p class="muted"><?= te('gpx.intro') ?></p>
<?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="form narrow">
  <?= csrfField() ?>
  <div class="field"><label for="gpx"><?= te('gpx.file') ?></label><input id="gpx" name="gpx" type="file" accept=".gpx,application/gpx+xml,application/xml,text/xml" required>
    <p class="hint"><?= te('gpx.hint', ['mb' => GPX_MAX_BYTES / 1048576]) ?></p></div>
  <div class="field"><label for="title"><?= te('tour.name') ?></label><input id="title" name="title" maxlength="120" value="<?= e($v['title']) ?>" placeholder="<?= te('gpx.title_hint') ?>"></div>
  <div class="field"><label for="description"><?= te('tour.description') ?></label><textarea id="description" name="description" rows="3" maxlength="4000"><?= e($v['description']) ?></textarea></div>
  <div class="field"><label for="visibility"><?= te('tour.visibility') ?></label>
    <select id="visibility" name="visibility">
      <option value="private" <?= $v['visibility'] === 'private' ? 'selected' : '' ?>><?= te('tour.v_private') ?></option>
      <?php if ($crews): ?><option value="group" <?= $v['visibility'] === 'group' ? 'selected' : '' ?>><?= te('tour.v_group') ?></option><?php endif; ?>
      <option value="public" <?= $v['visibility'] === 'public' ? 'selected' : '' ?>><?= te('tour.v_public') ?></option></select></div>
  <?php if ($crews): ?><div class="field"><label for="group_id"><?= te('tour.crew') ?></label>
    <select id="group_id" name="group_id"><?php foreach ($crews as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$v['group_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="field"><label for="difficulty"><?= te('tour.difficulty') ?></label>
    <select id="difficulty" name="difficulty"><?php foreach (['easy', 'moderate', 'demanding'] as $d): ?><option value="<?= $d ?>" <?= $v['difficulty'] === $d ? 'selected' : '' ?>><?= te('tour.d_' . $d) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="style"><?= te('tour.style') ?></label>
    <select id="style" name="style"><?php foreach (['relaxed', 'social', 'sporty'] as $s): ?><option value="<?= $s ?>" <?= $v['style'] === $s ? 'selected' : '' ?>><?= te('tour.s_' . $s) ?></option><?php endforeach; ?></select></div>
  <label class="choice"><input type="checkbox" name="rights" value="1" required> <?= te('gpx.rights') ?></label>
  <button type="submit"><?= te('gpx.import') ?></button>
</form>
<?php pageFooter();
