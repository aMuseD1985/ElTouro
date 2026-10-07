<?php
/** Reported content (DSA): review, decide, document the decision. */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
$me = requireAdmin();

if (isPost()) {
    checkCsrf();
    $r = dbOne('SELECT * FROM reports WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    if ($r) {
        $decision = postField('decision', 2000);
        if (($_POST['remove'] ?? '') === '1') {
            match ($r['target_type']) {
                'post'     => dbExec('UPDATE forum_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'thread'   => dbExec('UPDATE forum_threads SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'tour'     => dbExec('UPDATE tours SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'group'    => dbExec('UPDATE rider_groups SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'user'     => dbExec("UPDATE users SET status = 'blocked' WHERE id = ? AND is_admin = 0", [$r['target_id']]),
                'herdpost' => dbExec('UPDATE herd_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
                'ride'     => dbExec('UPDATE rides SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]),
            };
            $decision = '[Inhalt entfernt] ' . $decision;
        }
        dbExec("UPDATE reports SET status = 'done', decision = ?, handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ?",
            [$decision ?: '–', $me['id'], $r['id']]);
        flash('Meldung abgeschlossen.');
    }
    redirect('/admin/reports.php');
}

/** Link and preview of the reported content */
function reportTarget(array $r): array
{
    $gone = '(nicht mehr vorhanden)';
    return match ($r['target_type']) {
        'post'   => (function () use ($r, $gone) {
            $p = dbOne('SELECT p.body, p.thread_id, u.display_name FROM forum_posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$r['target_id']]);
            return $p ? ['/forum_topic.php?t=' . $p['thread_id'] . '#b' . $r['target_id'], $p['display_name'] . ': ' . mb_strimwidth($p['body'], 0, 200, '…')] : ['', $gone];
        })(),
        'tour'   => ['/tour.php?id=' . $r['target_id'], (string)(dbOne('SELECT title FROM tours WHERE id = ?', [$r['target_id']])['title'] ?? $gone)],
        'thread' => ['/forum_topic.php?t=' . $r['target_id'], (string)(dbOne('SELECT title FROM forum_threads WHERE id = ?', [$r['target_id']])['title'] ?? '')],
        'group'  => ['', (string)(dbOne('SELECT name FROM rider_groups WHERE id = ?', [$r['target_id']])['name'] ?? '')],
        'user'   => ['', (string)(dbOne('SELECT display_name FROM users WHERE id = ?', [$r['target_id']])['display_name'] ?? '')],
        'herdpost' => (function () use ($r, $gone) {
            $p = dbOne('SELECT p.body, p.topic_id, u.display_name, g.name AS crew FROM herd_posts p JOIN users u ON u.id = p.user_id
                          JOIN herd_topics t ON t.id = p.topic_id JOIN rider_groups g ON g.id = t.group_id WHERE p.id = ?', [$r['target_id']]);
            // Admins see the reported post here as text – crew talk itself stays reserved for members
            return $p ? ['', '[' . $p['crew'] . '] ' . $p['display_name'] . ': ' . mb_strimwidth($p['body'], 0, 300, '…')] : ['', $gone];
        })(),
        'ride'   => (function () use ($r, $gone) {
            $x = dbOne('SELECT r.title, r.description, r.visibility, u.display_name FROM rides r JOIN users u ON u.id = r.organizer_user_id WHERE r.id = ?', [$r['target_id']]);
            if (!$x) return ['', $gone];
            // Crew-only rides are shown as text like crew talk; public ones can be opened
            $text = $x['display_name'] . ': ' . $x['title'] . ($x['description'] ? ' – ' . mb_strimwidth($x['description'], 0, 250, '…') : '');
            return [$x['visibility'] === 'public' ? '/ride.php?id=' . $r['target_id'] : '', $text];
        })(),
        default  => ['', $gone],
    };
}

$open = dbAll("SELECT r.*, u.display_name AS reporter FROM reports r LEFT JOIN users u ON u.id = r.reporter_user_id WHERE r.status = 'open' ORDER BY r.created_at");
$done = dbAll("SELECT r.*, u.display_name AS reporter FROM reports r LEFT JOIN users u ON u.id = r.reporter_user_id WHERE r.status = 'done' ORDER BY r.handled_at DESC LIMIT 30");

pageHeader('Meldungen');
require __DIR__ . '/_nav.php';
?>
<h1>Meldungen</h1>
<?php if (!$open): ?><p class="muted">Keine offenen Meldungen.</p><?php endif; ?>
<?php foreach ($open as $r): [$link, $preview] = reportTarget($r); ?>
<section class="panel">
  <p><strong><?= e($r['target_type']) ?> #<?= (int)$r['target_id'] ?></strong> · gemeldet von <?= e((string)$r['reporter']) ?> am <?= e(substr($r['created_at'], 0, 16)) ?> UTC</p>
  <p><?= $link ? '<a href="' . e($link) . '" target="_blank">' . e($preview) . '</a>' : e($preview) ?></p>
  <p><em>Grund:</em> <?= nl2br(e($r['reason'])) ?></p>
  <form method="post" class="form wide">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <div class="field"><label for="dec-<?= (int)$r['id'] ?>">Entscheidung und Begründung</label>
      <textarea id="dec-<?= (int)$r['id'] ?>" name="decision" rows="3" maxlength="2000"></textarea></div>
    <label class="choice"><input type="checkbox" name="remove" value="1"> Inhalt entfernen (bei Nutzern: sperren)</label>
    <button type="submit">Abschließen</button>
  </form>
</section>
<?php endforeach; ?>

<?php if ($done): ?>
<h2>Zuletzt erledigt</h2>
<table class="table">
  <thead><tr><th scope="col">Inhalt</th><th scope="col">Entscheidung</th><th scope="col">Wann</th></tr></thead>
  <tbody><?php foreach ($done as $r): ?><tr><td><?= e($r['target_type']) ?> #<?= (int)$r['target_id'] ?></td><td><?= e((string)$r['decision']) ?></td><td><?= e(substr((string)$r['handled_at'], 0, 16)) ?></td></tr><?php endforeach; ?></tbody>
</table>
<?php endif; ?>
<?php pageFooter();
