<?php
/** /spot/<id> – one place (stop): data, community notes with confirmations, report a correction, rating. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/spots_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$spot = loadSpot((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($spot === null || $spot['hidden'] && (int)$me['is_admin'] !== 1 || ($spot['status'] !== 'approved' && (int)$spot['created_by'] !== $uid && (int)$me['is_admin'] !== 1)) {
    notFound();
}
$id = (int)$spot['id'];
$self = '/spot/' . $id;

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'note') {
        $field = (string)($_POST['field'] ?? '');
        $value = trim((string)($_POST['value'] ?? ''));
        $recent = (int)dbOne("SELECT COUNT(*) AS n FROM spot_reports WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [$uid])['n'];
        if (!isset(SPOT_NOTE_FIELDS[$field])) {
            flash(t('spot.error_field'), 'error');
        } elseif ($recent >= 15) {
            flash(t('forum.too_fast'), 'error');
        } else {
            if ($field === 'website') {
                $value = cleanWebsite($value);
            }
            $value = mb_substr($value, 0, SPOT_NOTE_FIELDS[$field]);
            if ($value === '' && $field !== 'closed') {
                flash(t('spot.error_value'), 'error');
            } else {
                dbExec('INSERT INTO spot_reports (spot_id, user_id, field, value) VALUES (?, ?, ?, ?)', [$id, $uid, $field, $value]);
                recordEvent($uid, 'spot_note', 'spot', $id, null, null, ['field' => $field]);
                flash(t('spot.note_thanks'));
            }
        }
    } elseif ($action === 'vote') {
        $report = dbOne("SELECT id, user_id FROM spot_reports WHERE id = ? AND spot_id = ? AND status = 'open'", [(int)($_POST['report'] ?? 0), $id]);
        $vote = (int)($_POST['vote'] ?? 0) === -1 ? -1 : 1;
        if ($report && (int)$report['user_id'] !== $uid) {
            dbExec('INSERT INTO spot_votes (report_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), created_at = UTC_TIMESTAMP()', [$report['id'], $uid, $vote]);
        }
    }
    redirect($self . '#notes');
}

$eff = spotEffective($spot);
$notes = spotNotes($id, $uid);
$kinds = SPOT_NOTE_FIELDS;
$fieldLabel = fn(string $f) => t('spot.field_' . $f);
pageHeader($eff['name'] !== '' ? $eff['name'] : t('stop.kind_' . $spot['kind']));
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<p class="breadcrumb"><a href="javascript:history.back()" class="back-link"><?= te('spot.back') ?></a></p>
<h1><?= e($eff['name'] !== '' ? $eff['name'] : t('stop.kind_' . $spot['kind'])) ?></h1>
<p class="muted"><?= te('stop.type_' . $spot['type']) ?> · <?= te('stop.kind_' . $spot['kind']) ?> · <?= $spot['source'] === 'community' ? te('spot.from_community') : te('spot.from_osm') ?>
  <?php if ($spot['status'] === 'pending'): ?> · <strong><?= te('spot.pending') ?></strong><?php endif; ?></p>
<?php if (!empty($eff['closed'])): ?><p class="alert alert-info"><?= te('spot.closed_note', ['text' => $eff['closed']]) ?></p><?php endif; ?>

<div id="spot-map" class="map-medium" data-lat="<?= e((string)$spot['lat']) ?>" data-lng="<?= e((string)$spot['lng']) ?>" <?= mapData() ?>></div>
<dl class="facts">
  <?php if (!empty($eff['website'])): ?><div><dt><?= te('spot.field_website') ?></dt><dd><a href="<?= e($eff['website']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e(preg_replace('#^https?://(www\.)?#i', '', $eff['website'])) ?></a></dd></div><?php endif; ?>
  <?php if (!empty($eff['opening_hours'])): ?><div><dt><?= te('spot.field_opening_hours') ?></dt><dd><?= e($eff['opening_hours']) ?></dd></div><?php endif; ?>
  <?php if (!empty($eff['phone'])): ?><div><dt><?= te('spot.field_phone') ?></dt><dd><?= e($eff['phone']) ?></dd></div><?php endif; ?>
  <?php if (!empty($eff['note'])): ?><div><dt><?= te('spot.field_note') ?></dt><dd><?= e($eff['note']) ?></dd></div><?php endif; ?>
</dl>
<p class="hint"><a target="_blank" rel="noopener noreferrer" href="https://www.google.com/maps?q=<?= e((string)$spot['lat']) ?>,<?= e((string)$spot['lng']) ?>"><?= te('spot.open_maps') ?></a></p>

<?= ratingBlock('spot', $id, $uid, $spot['status'] === 'approved', $self) ?>

<section class="panel" id="notes">
  <h2><?= te('spot.notes_title') ?></h2>
  <p class="muted"><?= te('spot.notes_intro', ['n' => SPOT_CONFIRMS_NEEDED]) ?></p>
  <?php if (!$notes): ?><p class="muted"><?= te('spot.notes_none') ?></p><?php endif; ?>
  <ul class="notes">
    <?php foreach ($notes as $n): ?>
      <li class="note<?= $n['confirmed'] ? ' is-confirmed' : '' ?>">
        <p><strong><?= e($fieldLabel($n['field'])) ?>:</strong> <?= $n['field'] === 'closed' && $n['value'] === '' ? te('spot.closed_default') : e($n['value']) ?></p>
        <p class="muted small"><?= e($n['display_name']) ?> · <?= e(relativeTime($n['created_at'])) ?>
          · <?= $n['confirmed'] ? '<strong>' . te('spot.confirmed') . '</strong>' : te('spot.unconfirmed') ?>
          · 👍 <?= $n['helpful'] ?> · 👎 <?= $n['unhelpful'] ?></p>
        <?php if ((int)$n['user_id'] !== $uid): ?>
          <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="report" value="<?= (int)$n['id'] ?>">
            <button name="vote" value="1" class="secondary-submit<?= $n['mine'] === 1 ? ' is-on' : '' ?>">👍 <?= te('spot.vote_yes') ?></button>
            <button name="vote" value="-1" class="secondary-submit<?= $n['mine'] === -1 ? ' is-on' : '' ?>">👎 <?= te('spot.vote_no') ?></button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($spot['status'] === 'approved'): ?>
  <h3><?= te('spot.report_title') ?></h3>
  <form method="post" class="form">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note">
    <div class="field"><label for="nfield"><?= te('spot.report_what') ?></label>
      <select id="nfield" name="field"><?php foreach (array_keys($kinds) as $f): ?><option value="<?= e($f) ?>"><?= e($fieldLabel($f)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="nvalue"><?= te('spot.report_value') ?></label><input id="nvalue" name="value" maxlength="500"><p class="hint"><?= te('spot.report_hint') ?></p></div>
    <button type="submit"><?= te('spot.report_send') ?></button>
  </form>
  <?php endif; ?>
</section>
<?php if ((int)$me['is_admin'] === 1): ?>
<form method="post" action="/admin/spots" class="spaced"><?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
  <?php if ($spot['hidden']): ?><button name="action" value="unhide" class="link">Admin: Ort wieder einblenden</button>
  <?php else: ?><button name="action" value="hide" class="link danger">Admin: Ort ausblenden</button><?php endif; ?></form>
<?php endif; ?>
<p class="hint"><a href="/report?type=spot&amp;id=<?= $id ?>"><?= te('report.link') ?></a></p>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/spot_map.js?v=2"></script>
<?php pageFooter();
