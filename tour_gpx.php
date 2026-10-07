<?php
/** GPX export of a tour (for navigation apps, Komoot import etc.). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
$me = requireLogin();

$tour = loadTour((int)($_GET['id'] ?? 0));
if ($tour === null || !canSeeTour($tour, (int)$me['id'])) {
    http_response_code(404);
    exit(t('error.not_found'));
}
$x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$file = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tour['title']) ?: 'tour';

header('Content-Type: application/gpx+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="eltouro-' . $file . '.gpx"');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<gpx version="1.1" creator="ElTouro" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
echo '<metadata><name>' . $x($tour['title']) . '</name></metadata>' . "\n";
echo '<trk><name>' . $x($tour['title']) . "</name>\n";
foreach (json_decode($tour['geojson'], true)['features'] as $f) {
    echo "<trkseg>\n";
    foreach ($f['geometry']['coordinates'] as $c) {
        echo '<trkpt lat="' . $c[1] . '" lon="' . $c[0] . '">' . (isset($c[2]) ? '<ele>' . $c[2] . '</ele>' : '') . "</trkpt>\n";
    }
    echo "</trkseg>\n";
}
echo "</trk>\n</gpx>\n";
