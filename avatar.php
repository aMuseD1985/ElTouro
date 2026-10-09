<?php
/** /avatar/<user id>?v=<version> – profile photo, for logged-in users only (see community_lib.php). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/community_lib.php';
if (currentUser() === null) {
    http_response_code(403);
    exit;
}
session_write_close();
$file = avatarFile((int)($_GET['id'] ?? 0));
if ($file === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg'));
// The version is part of the URL, so a cached picture never goes stale – but it stays private to the browser
header('Cache-Control: private, max-age=31536000, immutable');
header('Content-Length: ' . filesize($file));
readfile($file);
