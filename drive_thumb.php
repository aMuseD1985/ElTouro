<?php
/** GET /drive/<id>/thumb.png – preview of an own recorded ride. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/drive_lib.php';
$me = currentUser();
$drive = $me ? loadOwnDrive((int)($_GET['id'] ?? 0), (int)$me['id']) : null;
$file = $drive ? driveThumbPath($drive) : null;
if ($file === null) { http_response_code(404); exit; }
session_write_close();
header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
readfile($file);
