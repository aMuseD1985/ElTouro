<?php
/** GET /tour/<id>/thumb.png – list preview of a tour, only for riders who may see the tour. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tours_lib.php';
$me = currentUser();
$tour = $me ? loadTour((int)($_GET['id'] ?? 0)) : null;
if ($tour === null || !canSeeTour($tour, (int)$me['id'])) {
    http_response_code(404);
    exit;
}
session_write_close();
$file = tourThumbPath($tour);
if ($file === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
