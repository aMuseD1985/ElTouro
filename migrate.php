<?php
/**
 * Migration per Schlüssel (für die Ersteinrichtung und Notfälle): /migrate.php?key=<migrate_key>
 * Im Alltag bequemer über Admin → Datenbank.
 */
declare(strict_types=1);
const OHNE_ZUGANGSSCHUTZ = true;
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrationen.php';
header('Content-Type: text/plain; charset=utf-8');

if (($CONFIG['migrate_key'] ?? '') === '' || !hash_equals((string)$CONFIG['migrate_key'], (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Kein Zugriff.\n");
}
echo implode("\n", fuehreMigrationenAus()), "\nFertig.\n";
