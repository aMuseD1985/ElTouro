<?php
/** GPX-Export einer Tour (für Navi-Apps, Komoot-Import usw.). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/touren_lib.php';
$ich = mussEingeloggtSein();

$tour = ladeTour((int)($_GET['id'] ?? 0));
if ($tour === null || !darfTourSehen($tour, (int)$ich['id'])) {
    http_response_code(404);
    exit(t('fehler.nicht_gefunden'));
}
$x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$datei = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tour['title']) ?: 'tour';

header('Content-Type: application/gpx+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="eltouro-' . $datei . '.gpx"');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<gpx version="1.1" creator="ElTouro" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
echo '<metadata><name>' . $x($tour['title']) . '</name></metadata>' . "\n";
echo '<trk><name>' . $x($tour['title']) . "</name>\n";
foreach (json_decode($tour['geojson'], true)['features'] as $f) {
    echo "<trkseg>\n";
    foreach ($f['geometry']['coordinates'] as $k) {
        echo '<trkpt lat="' . $k[1] . '" lon="' . $k[0] . '">' . (isset($k[2]) ? '<ele>' . $k[2] . '</ele>' : '') . "</trkpt>\n";
    }
    echo "</trkseg>\n";
}
echo "</trk>\n</gpx>\n";
