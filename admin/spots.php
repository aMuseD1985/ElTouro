<?php
/** Places from the community: new places to approve, notes to adopt (they then overlay the OpenStreetMap data), hidden places. */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../spots_lib.php';
$me = requireAdmin();

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    switch ($action) {
        case 'approve':
            dbExec("UPDATE spots SET status = 'approved' WHERE id = ? AND source = 'community'", [$id]);
            flash('Ort freigegeben – er erscheint ab jetzt in den Pausen-Vorschlägen.');
            break;
        case 'reject':
            dbExec("UPDATE spots SET status = 'rejected', hidden = 1 WHERE id = ? AND source = 'community'", [$id]);
            flash('Ort abgelehnt.');
            break;
        case 'adopt':
            $rep = dbOne("SELECT * FROM spot_reports WHERE id = ? AND status = 'open'", [$id]);
            if ($rep) {
                adoptSpotReport($rep, (int)$me['id']);
                flash('Übernommen: gilt jetzt für alle, die planen.');
            }
            break;
        case 'dismiss':
            dbExec("UPDATE spot_reports SET status = 'rejected', handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'", [$me['id'], $id]);
            flash('Hinweis abgelehnt.');
            break;
        case 'hide':
            dbExec('UPDATE spots SET hidden = 1 WHERE id = ?', [$id]);
            flash('Ort ausgeblendet.');
            break;
        case 'unhide':
            dbExec("UPDATE spots SET hidden = 0 WHERE id = ? AND status <> 'rejected'", [$id]);
            flash('Ort wieder sichtbar.');
            break;
        case 'revert':
            // drop an adopted value again (back to what OpenStreetMap says)
            $field = (string)($_POST['field'] ?? '');
            $spot = loadSpot($id);
            if ($spot && isset(SPOT_NOTE_FIELDS[$field])) {
                $o = spotOverrides($spot);
                unset($o[$field]);
                dbExec('UPDATE spots SET overrides = ? WHERE id = ?', [$o ? json_encode($o, JSON_UNESCAPED_UNICODE) : null, $id]);
                flash('Zurückgesetzt.');
            }
            break;
    }
    redirect('/admin/spots');
}

$pending = dbAll("SELECT s.*, u.display_name FROM spots s LEFT JOIN users u ON u.id = s.created_by WHERE s.source = 'community' AND s.status = 'pending' ORDER BY s.created_at");
$reports = dbAll("SELECT r.*, s.name AS spot_name, s.kind, s.ref, u.display_name,
                    (SELECT COUNT(*) FROM spot_votes v WHERE v.report_id = r.id AND v.vote = 1 AND v.user_id <> r.user_id) AS helpful,
                    (SELECT COUNT(*) FROM spot_votes v WHERE v.report_id = r.id AND v.vote = -1) AS unhelpful
                  FROM spot_reports r JOIN spots s ON s.id = r.spot_id JOIN users u ON u.id = r.user_id WHERE r.status = 'open'
                  ORDER BY helpful DESC, r.created_at DESC LIMIT 100");
$overlaid = dbAll("SELECT id, name, kind, overrides FROM spots WHERE overrides IS NOT NULL ORDER BY id DESC LIMIT 100");
$hidden = dbAll('SELECT id, name, kind, source, status FROM spots WHERE hidden = 1 ORDER BY id DESC LIMIT 100');
$f = fn(string $k) => t('spot.field_' . $k);

pageHeader('Orte');
require __DIR__ . '/_nav.php';
?>
<h1>Orte aus der Community</h1>
<p class="muted">Neue Orte von Fahrern, Korrekturen mit den Bestätigungen anderer Fahrer – und was bereits die OpenStreetMap-Daten überlagert.</p>

<h2>Neue Orte zur Freigabe (<?= count($pending) ?>)</h2>
<?php if (!$pending): ?><p class="muted">Nichts offen.</p><?php endif; ?>
<?php foreach ($pending as $s): ?>
<section class="panel">
  <p><strong><?= e($s['name'] ?: '(ohne Namen)') ?></strong> · <?= e(t('stop.type_' . $s['type'])) ?> / <?= e(t('stop.kind_' . $s['kind'])) ?> · von <?= e((string)$s['display_name']) ?> · <?= e(substr($s['created_at'], 0, 16)) ?> UTC</p>
  <p><a href="/spot/<?= (int)$s['id'] ?>" target="_blank">Ansehen</a> · <a href="https://www.openstreetmap.org/?mlat=<?= e((string)$s['lat']) ?>&amp;mlon=<?= e((string)$s['lng']) ?>#map=18/<?= e((string)$s['lat']) ?>/<?= e((string)$s['lng']) ?>" target="_blank" rel="noopener noreferrer">Karte</a>
    <?php if ($s['website']): ?> · <a href="<?= e($s['website']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($s['website']) ?></a><?php endif; ?></p>
  <?php if ($s['opening_hours'] || $s['note']): ?><p><?= e((string)$s['opening_hours']) ?> <?= e((string)$s['note']) ?></p><?php endif; ?>
  <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button name="action" value="approve">Freigeben</button>
    <button name="action" value="reject" class="secondary-submit">Ablehnen</button></form>
</section>
<?php endforeach; ?>

<h2>Hinweise zu Orten (<?= count($reports) ?>)</h2>
<?php if (!$reports): ?><p class="muted">Nichts offen.</p><?php endif; ?>
<?php foreach ($reports as $r): $conf = (int)$r['helpful'] >= SPOT_CONFIRMS_NEEDED && (int)$r['helpful'] > (int)$r['unhelpful']; ?>
<section class="panel">
  <p><a href="/spot/<?= (int)$r['spot_id'] ?>" target="_blank"><strong><?= e($r['spot_name'] ?: t('stop.kind_' . $r['kind'])) ?></strong></a> · <?= e($f($r['field'])) ?>:
     <strong><?= e($r['value'] !== '' ? $r['value'] : '(als geschlossen/verschwunden gemeldet)') ?></strong></p>
  <p class="muted small">von <?= e($r['display_name']) ?> · <?= e(substr($r['created_at'], 0, 16)) ?> UTC · 👍 <?= (int)$r['helpful'] ?> · 👎 <?= (int)$r['unhelpful'] ?><?= $conf ? ' · <strong>von der Community bestätigt</strong>' : '' ?></p>
  <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="adopt">Übernehmen</button>
    <button name="action" value="dismiss" class="secondary-submit">Ablehnen</button></form>
</section>
<?php endforeach; ?>

<h2>Aktive Überlagerungen (<?= count($overlaid) ?>)</h2>
<p class="muted">Diese Werte ersetzen die OpenStreetMap-Daten für alle, die planen – bis du sie zurücksetzt.</p>
<?php foreach ($overlaid as $s): foreach (spotOverrides($s) as $field => $value): ?>
  <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="revert"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="field" value="<?= e($field) ?>">
    <a href="/spot/<?= (int)$s['id'] ?>"><?= e($s['name'] ?: t('stop.kind_' . $s['kind'])) ?></a> · <?= e($f($field)) ?>: <?= e((string)$value) ?> <button class="link danger">zurücksetzen</button></form><br>
<?php endforeach; endforeach; ?>

<h2>Ausgeblendete Orte (<?= count($hidden) ?>)</h2>
<?php foreach ($hidden as $s): ?>
  <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><a href="/spot/<?= (int)$s['id'] ?>"><?= e($s['name'] ?: t('stop.kind_' . $s['kind'])) ?></a> (<?= e($s['source']) ?>/<?= e($s['status']) ?>)
    <?php if ($s['status'] !== 'rejected'): ?><button name="action" value="unhide" class="link">wieder einblenden</button><?php endif; ?></form><br>
<?php endforeach; ?>
<?php pageFooter();
