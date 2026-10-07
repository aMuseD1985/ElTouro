<?php
/** Gemeldete Inhalte (DSA): prüfen, entscheiden, Entscheidung dokumentieren. */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$ich = mussAdminSein();

if (istPost()) {
    pruefeCsrf();
    $r = einzeln('SELECT * FROM reports WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    if ($r) {
        $entscheidung = feld('entscheidung', 2000);
        if (($_POST['entfernen'] ?? '') === '1') {
            match ($r['target_type']) {
                'post'   => ausfuehren('UPDATE forum_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'thread' => ausfuehren('UPDATE forum_threads SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'tour'   => ausfuehren('UPDATE tours SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'group'  => ausfuehren('UPDATE rider_groups SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'user'   => ausfuehren("UPDATE users SET status = 'blocked' WHERE id = ? AND is_admin = 0", [$r['target_id']]),
                'herdpost' => ausfuehren('UPDATE herd_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
            };
            $entscheidung = '[Inhalt entfernt] ' . $entscheidung;
        }
        ausfuehren("UPDATE reports SET status = 'done', decision = ?, handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ?",
            [$entscheidung ?: '–', $ich['id'], $r['id']]);
        meldung('Meldung abgeschlossen.');
    }
    weiterleiten('/admin/meldungen.php');
}

/** Link und Vorschau zum gemeldeten Inhalt */
function ziel(array $r): array
{
    return match ($r['target_type']) {
        'post'   => (function () use ($r) {
            $p = einzeln('SELECT p.body, p.thread_id, u.display_name FROM forum_posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$r['target_id']]);
            return $p ? ['/forum_thema.php?t=' . $p['thread_id'] . '#b' . $r['target_id'], $p['display_name'] . ': ' . mb_strimwidth($p['body'], 0, 200, '…')] : ['', '(nicht mehr vorhanden)'];
        })(),
        'tour'   => ['/tour.php?id=' . $r['target_id'], (string)(einzeln('SELECT title FROM tours WHERE id = ?', [$r['target_id']])['title'] ?? '(nicht mehr vorhanden)')],
        'thread' => ['/forum_thema.php?t=' . $r['target_id'], (string)(einzeln('SELECT title FROM forum_threads WHERE id = ?', [$r['target_id']])['title'] ?? '')],
        'group'  => ['', (string)(einzeln('SELECT name FROM rider_groups WHERE id = ?', [$r['target_id']])['name'] ?? '')],
        'user'   => ['', (string)(einzeln('SELECT display_name FROM users WHERE id = ?', [$r['target_id']])['display_name'] ?? '')],
        'herdpost' => (function () use ($r) {
            $p = einzeln('SELECT p.body, p.topic_id, u.display_name, g.name AS herde FROM herd_posts p JOIN users u ON u.id = p.user_id
                            JOIN herd_topics t ON t.id = p.topic_id JOIN rider_groups g ON g.id = t.group_id WHERE p.id = ?', [$r['target_id']]);
            // Admins sehen den gemeldeten Beitrag hier als Text – die Tränke selbst bleibt Mitgliedern vorbehalten
            return $p ? ['', '[' . $p['herde'] . '] ' . $p['display_name'] . ': ' . mb_strimwidth($p['body'], 0, 300, '…')] : ['', '(nicht mehr vorhanden)'];
        })(),
    };
}

$offen = alle("SELECT r.*, u.display_name AS melder FROM reports r LEFT JOIN users u ON u.id = r.reporter_user_id WHERE r.status = 'open' ORDER BY r.created_at");
$erledigt = alle("SELECT r.*, u.display_name AS melder FROM reports r LEFT JOIN users u ON u.id = r.reporter_user_id WHERE r.status = 'done' ORDER BY r.handled_at DESC LIMIT 30");

seitenKopf('Meldungen');
require __DIR__ . '/_nav.php';
?>
<h1>Meldungen</h1>
<?php if (!$offen): ?><p class="leise">Keine offenen Meldungen.</p><?php endif; ?>
<?php foreach ($offen as $r): [$link, $vorschau] = ziel($r); ?>
<section class="verwaltung">
  <p><strong><?= e($r['target_type']) ?> #<?= (int)$r['target_id'] ?></strong> · gemeldet von <?= e((string)$r['melder']) ?> am <?= e(substr($r['created_at'], 0, 16)) ?> UTC</p>
  <p><?= $link ? '<a href="' . e($link) . '" target="_blank">' . e($vorschau) . '</a>' : e($vorschau) ?></p>
  <p><em>Grund:</em> <?= nl2br(e($r['reason'])) ?></p>
  <form method="post" class="formular breit">
    <?= csrfFeld() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <div class="feld"><label for="ent-<?= (int)$r['id'] ?>">Entscheidung und Begründung</label>
      <textarea id="ent-<?= (int)$r['id'] ?>" name="entscheidung" rows="3" maxlength="2000"></textarea></div>
    <label class="wahl"><input type="checkbox" name="entfernen" value="1"> Inhalt entfernen (bei Nutzern: sperren)</label>
    <button type="submit">Abschließen</button>
  </form>
</section>
<?php endforeach; ?>

<?php if ($erledigt): ?>
<h2>Zuletzt erledigt</h2>
<table class="tabelle">
  <thead><tr><th scope="col">Inhalt</th><th scope="col">Entscheidung</th><th scope="col">Wann</th></tr></thead>
  <tbody><?php foreach ($erledigt as $r): ?><tr><td><?= e($r['target_type']) ?> #<?= (int)$r['target_id'] ?></td><td><?= e((string)$r['decision']) ?></td><td><?= e(substr((string)$r['handled_at'], 0, 16)) ?></td></tr><?php endforeach; ?></tbody>
</table>
<?php endif; ?>
<?php seitenFuss();
