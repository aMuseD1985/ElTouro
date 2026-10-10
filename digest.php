<?php
/**
 * /digest?key=<cron_key> – for the hosting provider's cron job (e.g. hourly): sends the mail digests that are due.
 * Also: queues "ride starts soon" pushes and delivers waiting pushes. Key: config 'cron_key', otherwise 'migrate_key'.
 */
declare(strict_types=1);
const SKIP_ACCESS_GATE = true;
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/notify_lib.php';
header('Content-Type: text/plain; charset=utf-8');
$key = (string)($CONFIG['cron_key'] ?? ($CONFIG['migrate_key'] ?? ''));
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Forbidden\n");
}
require_once __DIR__ . '/push_lib.php';
echo 'Ride alerts queued: ' . sendRideSoonAlerts() . "\n";
echo 'Pushes sent: ' . processPushQueue() . "\n";
require_once __DIR__ . '/track_lib.php';
echo 'Recordings ended after 2 h of silence: ' . finishStaleRecordings() . "\n";
echo 'Digests sent: ' . sendDigests() . "\n";
