<?php
/** GPX export of a tour (for navigation apps, Komoot import etc.): stops, planned waypoints as a route, and the track. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tours_lib.php';
require_once __DIR__ . '/gpx_lib.php';
$me = requireLogin();

$tour = loadTour((int)($_GET['id'] ?? 0));
if ($tour === null || !canSeeTour($tour, (int)$me['id'])) {
    http_response_code(404);
    exit(t('error.not_found'));
}
$file = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tour['title']) ?: 'tour';

header('Content-Type: application/gpx+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="eltouro-' . $file . '.gpx"');
echo gpxExport($tour);
