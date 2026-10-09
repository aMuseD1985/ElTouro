<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/forum_lib.php';
$me = requireLogin();

$cat = dbOne('SELECT * FROM forum_categories WHERE slug = ?', [(string)($_GET['c'] ?? $_POST['c'] ?? '')]);
if ($cat === null) {
    redirect('/forum');
}
$error = '';
$subject = '';
$text = '';

if (isPost()) {
    checkCsrf();
    $subject = postField('subject', 150);
    $text = trim(str_replace("\r", '', (string)($_POST['text'] ?? '')));
    if (mb_strlen($subject) < 5) {
        $error = t('forum.error_subject');
    } elseif (mb_strlen($text) < 2 || mb_strlen($text) > FORUM_POST_MAX) {
        $error = t('forum.error_text', ['max' => FORUM_POST_MAX]);
    } elseif (!canPostNow((int)$me['id'])) {
        $error = t('forum.too_fast');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        dbExec('INSERT INTO forum_threads (category_id, user_id, title, post_count, last_post_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())',
            [$cat['id'], $me['id'], $subject]);
        $tid = (int)$pdo->lastInsertId();
        dbExec('INSERT INTO forum_posts (thread_id, user_id, body) VALUES (?, ?, ?)', [$tid, $me['id'], $text]);
        $pdo->commit();
        redirect('/forum/topic/' . $tid);
    }
}

pageHeader(t('forum.new_topic'));
?>
<p class="breadcrumbs"><a href="/forum"><?= te('forum.title') ?></a> › <a href="/forum/<?= e(rawurlencode($cat['slug'])) ?>"><?= e(categoryName($cat)) ?></a> ›</p>
<h1><?= te('forum.new_topic') ?></h1>
<?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form wide">
  <?= csrfField() ?><input type="hidden" name="c" value="<?= e($cat['slug']) ?>">
  <div class="field"><label for="subject"><?= te('forum.subject') ?></label><input id="subject" name="subject" required minlength="5" maxlength="150" value="<?= e($subject) ?>"></div>
  <div class="field"><label for="text"><?= te('forum.text') ?></label><textarea id="text" name="text" rows="10" required maxlength="<?= FORUM_POST_MAX ?>" data-emoji><?= e($text) ?></textarea>
    <p class="hint"><?= te('forum.format') ?></p></div>
  <button type="submit"><?= te('forum.publish') ?></button>
</form>
<?php require_once __DIR__ . '/community_lib.php'; echo communityAssets(); ?>
<?php pageFooter();
