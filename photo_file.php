<?php
/** /photo/<id>/thumb|full – a photo of a crew wall, for active members of that crew only. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/photos_lib.php';
$me = currentUser();
if ($me === null) {
    http_response_code(403);
    exit;
}
session_write_close();
$p = dbOne('SELECT id, group_id FROM crew_photos WHERE id = ? AND deleted_at IS NULL', [(int)($_GET['id'] ?? 0)]);
$crew = $p ? loadCrewById((int)$p['group_id']) : null;
[$ok] = $crew ? photoAccess($crew, $me) : [false];
// moderation: an admin may look at a photo that has been reported (and only then)
if (!$ok && $p && (int)$me['is_admin'] === 1 && dbOne("SELECT 1 AS x FROM reports WHERE target_type = 'photo' AND target_id = ? AND status = 'open'", [$p['id']]) !== null) {
    $ok = true;
}
$file = $ok ? photoFile((int)$p['id'], ($_GET['v'] ?? 'full') === 'thumb') : null;
if ($file === null) {
    http_response_code(404);   // outsiders cannot tell whether a photo exists
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
readfile($file);
