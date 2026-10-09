<?php
/** Crew talk of a crew: topic list with infinite scroll, start a new topic. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/talk_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];

$crew = loadCrew((string)($_GET['s'] ?? $_POST['s'] ?? ''));
[$allowed] = $crew ? talkAccess($crew, $me) : [false];
if (!$allowed) {
    notFound(t('talk.members_only'));
}
$error = '';
$subject = '';
$text = '';

if (isPost()) {
    checkCsrf();
    $subject = postField('subject', 150);
    $text = trim(str_replace("\r", '', (string)($_POST['text'] ?? '')));
    if (mb_strlen($subject) < 3) {
        $error = t('talk.error_subject');
    } elseif (mb_strlen($text) < 1 || mb_strlen($text) > TALK_POST_MAX) {
        $error = t('forum.error_text', ['max' => TALK_POST_MAX]);
    } elseif (!canTalkNow($uid)) {
        $error = t('forum.too_fast');
    } else {
        [$tid] = createTalkTopic((int)$crew['id'], $uid, $subject, $text);
        redirect('/talk/' . $tid);
    }
}

$topics = loadTalkTopics((int)$crew['id'], $uid, 0);
pageHeader(t('talk.title') . ' · ' . $crew['name']);
?>
<p class="breadcrumbs"><a href="/crew/<?= e(rawurlencode($crew['slug'])) ?>"><?= e($crew['name']) ?></a> ›</p>
<div class="title-row">
  <h1><?= te('talk.title') ?></h1>
</div>
<p class="muted"><?= te('talk.intro') ?></p>

<details class="compose" <?= $error ? 'open' : '' ?>>
  <summary class="btn"><?= te('talk.new_topic') ?></summary>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form wide">
    <?= csrfField() ?><input type="hidden" name="s" value="<?= e($crew['slug']) ?>">
    <div class="field"><label for="subject"><?= te('forum.subject') ?></label><input id="subject" name="subject" required minlength="3" maxlength="150" value="<?= e($subject) ?>"></div>
    <div class="field"><label for="text"><?= te('forum.text') ?></label><textarea id="text" name="text" rows="6" required maxlength="<?= TALK_POST_MAX ?>" data-emoji><?= e($text) ?></textarea>
      <p class="hint"><?= te('forum.format') ?></p></div>
    <button type="submit"><?= te('forum.publish') ?></button>
  </form>
</details>

<?php if (!$topics): ?><p class="muted"><?= te('talk.empty') ?></p><?php endif; ?>
<ul class="topic-list" id="topics"
    data-crew="<?= e($crew['slug']) ?>" data-offset="<?= count($topics) ?>"
    data-more="<?= count($topics) === TALK_TOPICS_PAGE_SIZE ? '1' : '0' ?>">
  <?php foreach ($topics as $t) echo renderTalkTopicRow($t); ?>
</ul>
<div id="topics-more" class="loader" aria-live="polite"></div>
<script src="/assets/talk.js?v=2"></script>
<?= communityAssets() ?>
<?php pageFooter();
