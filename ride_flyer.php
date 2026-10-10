<?php
/** /ride/<code>/flyer – A4 flyer of a ride with QR code, ready to print or save as PDF (organiser, crew lead, admin). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rides_lib.php';
require_once __DIR__ . '/flyer_lib.php';
$me = requireLogin();
$ride = loadRideFromRequest();
if ($ride === null || !canSeeRide($ride, (int)$me['id']) || !canManageRide($ride, $me)) {
    notFound();
}
$tour = loadTour((int)$ride['tour_id']);
$lang = $ride['content_lang'] === 'en' ? 'en' : 'de';          // the flyer speaks the language of the ride
$f = fn(string $k, array $v = []) => tl($lang, $k, $v);
$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$showName = ($_GET['name'] ?? '1') !== '0';
$when = flyerWhen(utcToLocal($ride['starts_at']), $lang);
$url = baseUrl() . rideUrl($ride);
$km = $tour ? number_format((int)$tour['distance_m'] / 1000, 1, $lang === 'de' ? ',' : '.', '') . ' km' : '';
$routeLine = $tour ? $km . ' | ' . $f('flyer.difficulty') . ': ' . $f('tour.d_' . $tour['difficulty']) : (string)$ride['tour_title'];
$rules = $tour ? $f('tour.r_' . $tour['rule_set']) : '';
$forWhom = $ride['group_id'] !== null && $ride['visibility'] === 'group'
    ? $f('flyer.for_crew', ['crew' => (string)$ride['crew_name'], 'n' => (int)$ride['capacity']])
    : $f('flyer.for_all', ['n' => (int)$ride['capacity']]);
$hero = is_file(__DIR__ . '/assets/img/flyer-hero.jpg') ? '/assets/img/flyer-hero.jpg?v=' . filemtime(__DIR__ . '/assets/img/flyer-hero.jpg') : null;
$logo = is_file(__DIR__ . '/assets/img/logo-claim-' . $lang . '.webp') ? '/assets/img/logo-claim-' . $lang . '.webp' : '/assets/img/logo-claim-de.webp';   // the logo with its claim as on eltouro.de (add logo-claim-en.webp for English flyers)
$Y = '#F7C948'; $N = '#14263F'; $B = '#2F8FD0'; $L = '#CDEBFA';

$rows = [
    ['calendar', $f('flyer.when'), $when, ''],
    ['pin', $f('flyer.where'), $ride['meeting_point'], ''],
    ['people', $f('flyer.who'), $showName ? $ride['organizer'] . ' ' . $f('flyer.organiser') : $f('flyer.organiser_only'), ''],
    ['route', $f('flyer.route'), $routeLine, $rules],
    ['people', $f('flyer.for'), $forWhom, ''],
];
$footer = [['leaf', 'flyer.f1', 'flyer.f1s'], ['people', 'flyer.f2', 'flyer.f2s'], ['map', 'flyer.f3', 'flyer.f3s'], ['smile', 'flyer.f4', 'flyer.f4s']];
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($f('flyer.page_title', ['title' => $ride['title']])) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="/assets/flyer.css?v=2">
</head>
<body>
<div class="flyer-toolbar">
  <a href="<?= rideUrl($ride) ?>">← <?= te('flyer.back') ?></a>
  <button type="button" id="flyer-print"><?= te('flyer.print') ?></button>
  <label class="choice"><input type="checkbox" id="flyer-name" <?= $showName ? 'checked' : '' ?> data-code="<?= $e(rideCode($ride)) ?>"> <?= te('flyer.show_name') ?></label>
  <span class="hint"><?= te('flyer.print_hint') ?></span>
</div>
<div class="flyer-stage" id="flyer-stage">
<div class="flyer" id="flyer" lang="<?= $lang ?>">
  <svg class="flyer-bg" viewBox="0 0 210 297" preserveAspectRatio="none" aria-hidden="true">
    <defs>
      <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8CCBF0"/><stop offset=".55" stop-color="#CFEAF9"/><stop offset="1" stop-color="#F3F9FD"/></linearGradient>
      <linearGradient id="lake" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7FBFE3"/><stop offset="1" stop-color="#4E9DCB"/></linearGradient>
      <radialGradient id="sun" cx=".75" cy=".18" r=".5"><stop offset="0" stop-color="#fff" stop-opacity=".9"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
    </defs>
    <rect width="210" height="297" fill="#fff"/>
    <?php if ($hero): ?>
      <image href="<?= $e($hero) ?>" x="0" y="0" width="210" height="165" preserveAspectRatio="xMidYMid slice"/>
    <?php else: ?>
      <rect width="210" height="170" fill="url(#sky)"/><rect width="210" height="170" fill="url(#sun)"/>
      <!-- clouds -->
      <g fill="#fff" fill-opacity=".75"><ellipse cx="40" cy="48" rx="26" ry="4"/><ellipse cx="62" cy="52" rx="20" ry="3"/><ellipse cx="150" cy="30" rx="30" ry="4"/><ellipse cx="120" cy="36" rx="18" ry="2.6"/></g>
      <!-- far shore and lake -->
      <path d="M0 98 Q30 90 60 96 T120 94 T180 97 L210 95 L210 112 L0 112Z" fill="#5E8F6B" fill-opacity=".85"/>
      <rect y="108" width="210" height="48" fill="url(#lake)"/>
      <g stroke="#fff" stroke-opacity=".6" stroke-width=".5"><path d="M20 118h26M70 124h34M130 116h40M40 132h30M120 138h50M160 126h30"/></g>
      <!-- left trees and bushes -->
      <g><ellipse cx="14" cy="92" rx="22" ry="26" fill="#3F7A45"/><ellipse cx="36" cy="106" rx="20" ry="18" fill="#4F8F52"/><ellipse cx="6" cy="124" rx="20" ry="22" fill="#2F6A3C"/><ellipse cx="30" cy="136" rx="18" ry="12" fill="#6FA85E"/></g>
      <!-- reeds on the right -->
      <g stroke="#7B9A4B" stroke-width=".7" stroke-linecap="round"><path d="M178 150l-1-22M182 150l1-26M186 150l-2-20M190 150l2-24M194 150l-1-18M198 150l2-22M202 150l-1-20M206 150l1-24"/></g>
      <g fill="#B88A55"><ellipse cx="177" cy="127" rx=".9" ry="3"/><ellipse cx="183" cy="123" rx=".9" ry="3"/><ellipse cx="190" cy="125" rx=".9" ry="3"/><ellipse cx="202" cy="121" rx=".9" ry="3"/></g>
      <!-- path -->
      <path d="M70 168 Q120 140 210 134 L210 170 Z" fill="#CBB79A"/><path d="M90 170 Q140 148 210 144 L210 170Z" fill="#B9A482" fill-opacity=".7"/>
    <?php endif; ?>
    <!-- brush strokes behind the texts -->
    <?= brushStroke(2, 94, 108, 46, -6, 23, $Y, 1, 1.1) ?>
    <?= brushStroke(-6, 139, 104, 21, 4, 37, $N, 1, 1.2) ?>
    <?= brushStroke(146, 39, 52, 2.4, -2, 41, $Y, 1, .5) ?>
    <?= brushStroke(132, 160, 70, 12, -3, 51, $B, 1, .9) ?>
    <?= brushStroke(124, 246, 82, 15, -2, 61, $L, 1, 1) ?>
    <!-- light shards behind the info area -->
    <path d="M0 176 L28 168 L22 190 Z M205 190 L210 176 L210 214Z" fill="<?= $L ?>" fill-opacity=".5"/>
    <?php foreach ([0, 1, 2, 3, 4] as $i): ?><?= brushStroke(26, 166.4 + $i * 19.3, 34, 6.0, -2, 70 + $i, $L, .9, .45) ?><?php endforeach; ?>
    <!-- footer -->
    <?= brushStroke(-6, 262, 224, 42, 0, 81, $N, 1, .5) ?>
    <?= brushStroke(172, 288.6, 40, 2.4, -7, 91, $Y, 1, .7) ?>
    <?= brushStroke(180, 292.8, 34, 2.2, -7, 92, $Y, 1, .7) ?>
  </svg>

  <img class="f-logoimg" src="<?= $e($logo) ?>" alt="ElTouro.de – <?= $e($f('flyer.logo_claim')) ?>">
  <div class="f-shout"><?= $e($f('flyer.shout')) ?></div>
  <img class="f-mascot" src="/assets/img/mascot/side-large.webp" alt="">
  <div class="f-head"><span><?= $e($f('flyer.head1')) ?></span><span><?= $e($f('flyer.head2')) ?></span></div>
  <div class="f-join"><?= $e($f('flyer.join')) ?></div>

  <div class="f-rows">
    <?php foreach ($rows as [$icon, $label, $value, $sub]): ?>
      <div class="f-row">
        <span class="f-icon"><?= flyerIcon($icon) ?></span>
        <div><div class="f-label"><?= $e($label) ?></div><div class="f-value"><?= $e($value) ?></div><?php if ($sub): ?><div class="f-value f-sub"><?= $e($sub) ?></div><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="f-divider"></div>
  <div class="f-signup"><?= $e($f('flyer.signup')) ?></div>
  <svg class="f-arrow" viewBox="0 0 30 30" fill="none" stroke="<?= $B ?>" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M3 4C16 4 24 10 24 24M24 24l-5-6M24 24l5-5"/></svg>
  <div class="f-qrbox"><div id="flyer-qr" data-url="<?= $e($url) ?>"></div></div>
  <div class="f-button"><span class="f-btn-icon"><?= flyerIcon('link') ?></span><?= $e($f('flyer.direct')) ?></div>
  <div class="f-url"><?= $e($url) ?></div>
  <div class="f-moreclaim"><span class="f-btn-icon dark"><?= flyerIcon('scooter', $N) ?></span><?= $e($f('flyer.claim')) ?></div>

  <div class="f-foot">
    <?php foreach ($footer as [$icon, $t, $s]): ?>
      <div class="f-foot-col"><?= flyerIcon($icon) ?><strong><?= $e($f($t)) ?></strong><span><?= $e($f($s)) ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="f-foot-line"></div>
  <div class="f-foot-logo"><img src="<?= $e($logo) ?>" alt="ElTouro.de"></div>
  <div class="f-foot-tag"><?= $e($f('flyer.community')) ?></div>
</div>
</div>
<script src="/assets/vendor/qrcode/qrcode.js"></script>
<script src="/assets/flyer.js?v=1"></script>
</body>
</html>
