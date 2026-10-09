<?php
/** POST /rate – save a rating of a tour or a place (one per rider and target, can be changed). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/spots_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
if (!isPost()) {
    redirect('/');
}
checkCsrf();
$type = (string)($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$back = (string)($_POST['back'] ?? '/');
$back = preg_match('#^/(tour|spot)/\d+$#', $back) ? $back : '/';
$ok = false;
if ($type === 'tour') {
    $tour = loadTour($id);
    // anybody who can see a tour may rate it, except the creator (no rating of one's own route)
    $ok = $tour !== null && canSeeTour($tour, $uid) && (int)$tour['owner_user_id'] !== $uid;
} elseif ($type === 'spot') {
    $spot = loadSpot($id);
    $ok = $spot !== null && $spot['status'] === 'approved' && !$spot['hidden'];
}
$stars = (int)($_POST['stars'] ?? 0);
if (!$ok || $stars < 1 || $stars > 5) {
    flash(t('rating.error'), 'error');
    redirect($back);
}
$recent = (int)dbOne("SELECT COUNT(*) AS n FROM ratings WHERE user_id = ? AND COALESCE(updated_at, created_at) > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [$uid])['n'];
if ($recent >= 30) {
    flash(t('forum.too_fast'), 'error');
    redirect($back);
}
saveRating($type, $id, $uid, $stars, postField('comment', 600));
recordEvent($uid, 'rated', $type, $id, null, null, ['stars' => $stars]);
flash(t('rating.thanks'));
redirect($back . '#ratings');
