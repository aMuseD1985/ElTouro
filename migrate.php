<?php
/**
 * Migration by key (for the initial setup, the test deployment and emergencies): /migrate.php?key=<migrate_key>
 * In day-to-day use more convenient via Admin → Betrieb.
 */
declare(strict_types=1);
const SKIP_ACCESS_GATE = true;
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrations.php';
header('Content-Type: text/plain; charset=utf-8');

if (($CONFIG['migrate_key'] ?? '') === '' || !hash_equals((string)$CONFIG['migrate_key'], (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Kein Zugriff.\n");
}
echo implode("\n", runMigrations()), "\nFertig.\n";
