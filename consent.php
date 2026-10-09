<?php
/**
 * /consent – before anybody takes part: what ElTouro stores and why, and the rider's consent (core.php consentGate()).
 * Also shows an existing consent with the option to withdraw it. Bump CONSENT_VERSION in core.php when something changes.
 */
declare(strict_types=1);
const NO_CONSENT_NEEDED = true;
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rewards_lib.php';
require_once __DIR__ . '/rides_lib.php';
$me = requireLogin();
$uid = (int)$me['id'];
$has = (int)$me['consent_version'] >= CONSENT_VERSION;

if (isPost()) {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'accept' && !$has) {
        if (($_POST['agree'] ?? '') !== '1') {
            flash(t('consent.error_agree'), 'error');
            redirect('/consent' . (isset($_GET['next']) ? '?next=' . rawurlencode((string)$_GET['next']) : ''));
        }
        dbExec('UPDATE users SET consent_version = ?, consent_at = UTC_TIMESTAMP() WHERE id = ?', [CONSENT_VERSION, $uid]);
        recordEvent($uid, 'consent_given', 'user', $uid, null, null, ['version' => CONSENT_VERSION]);
        $next = (string)($_GET['next'] ?? '/');
        redirect($next !== '' && !str_starts_with($next, '/consent') ? $next : '/');
    }
    if ($action === 'decline') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash(t('consent.declined'));
        redirect('/login');
    }
    if ($action === 'withdraw' && $has) {
        dbExec('UPDATE users SET consent_version = 0 WHERE id = ?', [$uid]);
        recordEvent($uid, 'consent_withdrawn', 'user', $uid);
        flash(t('consent.withdrawn'));
    }
    redirect('/consent');
}

$row = dbOne('SELECT consent_version, consent_at FROM users WHERE id = ?', [$uid]);
$vars = ['days' => RIDE_SIGNUP_RETENTION_DAYS, 'logdays' => setting('log_days', '7')];
pageHeader(t('consent.title'));
?>
<article class="text narrow-text consent<?= $has ? '' : ' is-gate' ?>">
  <h1><?= te('consent.title') ?></h1>
  <p class="consent-intro"><?= te('consent.intro') ?></p>
  <div class="consent-scroll" tabindex="0" role="region" aria-label="<?= te('consent.title') ?>">
  <?php for ($i = 1; $i <= 8; $i++): ?>
    <section class="consent-item">
      <h2><?= te('consent.i' . $i . '_title') ?></h2>
      <dl>
        <dt><?= te('consent.what') ?></dt><dd><?= e(t('consent.i' . $i . '_what')) ?></dd>
        <dt><?= te('consent.why') ?></dt><dd><?= e(t('consent.i' . $i . '_why')) ?></dd>
        <dt><?= te('consent.keep') ?></dt><dd><?= e(t('consent.i' . $i . '_keep', $vars)) ?></dd>
      </dl>
    </section>
  <?php endfor; ?>
  <p><?= t('consent.rights') ?></p>
  </div>

  <?php if ($has): ?>
    <p class="alert alert-info"><?= te('consent.given', ['when' => formatRideTime((string)$row['consent_at']), 'version' => (int)$row['consent_version']]) ?></p>
    <form method="post" class="inline-form">
      <?= csrfField() ?><input type="hidden" name="action" value="withdraw">
      <p class="muted"><?= te('consent.withdraw_hint') ?></p>
      <button type="submit" class="danger-submit"><?= te('consent.withdraw') ?></button>
    </form>
    <p><a href="/profile#data"><?= te('consent.link_profile') ?></a> · <a href="/account/delete"><?= te('consent.link_delete') ?></a></p>
  <?php else: ?>
    <form method="post" class="form consent-form" action="/consent<?= isset($_GET['next']) ? '?next=' . e(rawurlencode((string)$_GET['next'])) : '' ?>">
      <?= csrfField() ?>
      <label class="check"><input type="checkbox" name="agree" value="1" required> <?= te('consent.agree') ?></label>
      <div class="consent-buttons">
        <button type="submit" name="action" value="accept"><?= te('consent.accept') ?></button>
        <button type="submit" name="action" value="decline" class="secondary-submit" formnovalidate><?= te('consent.decline') ?></button>
      </div>
    </form>
    <p class="muted"><a href="/account/delete"><?= te('consent.link_delete') ?></a></p>
  <?php endif; ?>
</article>
<?php pageFooter();
