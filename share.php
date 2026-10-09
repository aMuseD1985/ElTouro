<?php
/**
 * Public page of a shared tour: /s/<token> (and /s/<token>/image.png for the preview image).
 * Reachable without login and without the tester password – it is meant for social media.
 * Shows only what share_lib.php allows: no creator, crew, description or exact start/finish.
 */
declare(strict_types=1);
const PUBLIC_PAGE = true;   // legal texts / shared tours: a normal web page, no app lock
const SKIP_ACCESS_GATE = true;
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/share_lib.php';
require __DIR__ . '/rewards_lib.php';

$tour = loadSharedTour((string)($_GET['token'] ?? ''));
if ($tour === null) {
    notFound(t('share.gone'));
}
$trimmed = trimRouteEnds($tour['geojson']);

if (isset($_GET['image'])) {
    $file = shareImagePath($tour, $trimmed);
    if ($file === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

$me = currentUser();
rememberReferral('share_link', $tour['share_created_by'] !== null ? (int)$tour['share_created_by'] : null, 'tour', (int)$tour['id'], $_GET['via'] ?? null);
$url = baseUrl() . '/s/' . $tour['share_token'];
$summary = shareSummary($tour);
$meta = [
    'description'         => t('share.meta_description', ['summary' => $summary]),
    'og:type'             => 'website',
    'og:site_name'        => 'ElTouro',
    'og:title'            => $tour['title'],
    'og:description'      => t('share.meta_description', ['summary' => $summary]),
    'og:url'              => $url,
    'og:locale'           => $LANG === 'de' ? 'de_DE' : 'en_GB',
    'twitter:card'        => 'summary_large_image',
    'twitter:title'       => $tour['title'],
    'twitter:description' => t('share.meta_description', ['summary' => $summary]),
];
if (function_exists('imagecreatetruecolor')) {
    $meta += ['og:image' => $url . '/image.png', 'og:image:width' => (string)SHARE_IMAGE_W, 'og:image:height' => (string)SHARE_IMAGE_H,
              'og:image:alt' => t('share.image_alt'), 'twitter:image' => $url . '/image.png'];
}
// Logged-in riders who may see the full tour get a link to it – the id is never shown to anyone else
$fullTour = $me ? loadTour((int)$tour['id']) : null;
$canOpen = $fullTour !== null && canSeeTour($fullTour, (int)$me['id']);
$reportMail = setting('operator_email');

pageHeader($tour['title'], $meta);
?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<p class="share-kicker"><?= te('share.kicker') ?></p>
<h1><?= e($tour['title']) ?></h1>

<dl class="facts">
  <div><dt><?= te('tour.length') ?></dt><dd><?= e(formatKm((int)$tour['distance_m'])) ?></dd></div>
  <?php if ($tour['ascent_m'] !== null): ?><div><dt><?= te('tour.ascent') ?></dt><dd><?= (int)$tour['ascent_m'] ?> m</dd></div><?php endif; ?>
  <div><dt><?= te('tour.difficulty') ?></dt><dd><?= te('tour.d_' . $tour['difficulty']) ?></dd></div>
  <div><dt><?= te('tour.style') ?></dt><dd><?= te('tour.s_' . $tour['style']) ?></dd></div>
  <div><dt><?= te('tour.rule_set') ?></dt><dd><?= te('tour.r_' . $tour['rule_set']) ?></dd></div>
  <div><dt><?= te('tour.vehicle_class') ?></dt><dd><?= te('tour.vc_' . VEHICLE_CLASSES[vehicleClass($tour['vehicle_class'] ?? 2)]) ?></dd></div>
</dl>

<?php if ($trimmed['features']): ?>
<div id="tour-map" class="map-large" data-geojson="<?= e(json_encode($trimmed, JSON_UNESCAPED_SLASHES)) ?>"
     <?= mapData() ?>
     data-stops="<?= e(json_encode(sharedStops($tour), JSON_UNESCAPED_UNICODE)) ?>"
     data-start="" data-finish=""></div>
<?php endif; ?>
<p class="hint"><?= te('share.privacy_note', ['m' => SHARE_PRIVACY_METERS]) ?></p>
<?php if ((int)$tour['freehand_share_pct'] > 0): ?><p class="alert alert-info"><?= te('tour.freehand_warning', ['p' => (int)$tour['freehand_share_pct']]) ?></p><?php endif; ?>
<p class="alert alert-info"><?= te('tour.on_site') ?></p>

<section class="panel share-cta">
  <?php if ($canOpen): ?>
    <p><a class="btn" href="/tour/<?= (int)$tour['id'] ?>"><?= te('share.open_tour') ?></a></p>
  <?php elseif ($me): ?>
    <p><a class="btn" href="/tours"><?= te('share.discover') ?></a></p>
  <?php else: ?>
    <h2><?= te('home.guest_title') ?></h2>
    <p><?= te('share.cta_text') ?></p>
    <p><a class="btn" href="/register"><?= te('nav.register') ?></a> <a class="btn secondary" href="/login"><?= te('nav.login') ?></a></p>
  <?php endif; ?>
</section>
<?php if ($reportMail !== ''): ?>
<p class="spaced muted"><a href="mailto:<?= e($reportMail) ?>?subject=<?= e(rawurlencode(t('share.report_subject') . ' ' . $url)) ?>"><?= te('share.report') ?></a></p>
<?php endif; ?>
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/tour_map.js?v=7"></script>
<?php pageFooter();
