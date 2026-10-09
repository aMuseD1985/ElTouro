<?php
/**
 * Operations: backups, restore and deployment from the admin area.
 *
 * Ground rules:
 *  - config.php and the data/ directory are NEVER overwritten by a deployment or a file restore
 *    (exception: a restore may explicitly bring back config.php). The pre-refactor directory daten/
 *    is protected the same way.
 *  - Before every deployment and every restore a backup is created automatically.
 *  - ZIP entries with "..", absolute paths or control characters are rejected (zip slip).
 *  - Backups live in data/backups (blocked by .htaccess).
 */
declare(strict_types=1);

const APP_ROOT = __DIR__;
const SQL_SEPARATOR = "\n-- ;;ELTOURO;;\n";   // unchanged since the first backups – keeps old dumps restorable
const BACKUP_FILE_PATTERN = '/^(\d{8}-\d{6}_[a-z]+_[a-z0-9-]*)\.(sql\.gz|files\.zip|dateien\.zip)$/';

/** Paths relative to the app root that are neither overwritten nor backed up */
function isProtectedPath(string $rel, bool $configAllowed = false): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === 'config.php') {
        return !$configAllowed;
    }
    foreach (['data', 'daten'] as $dir) {
        if ($rel === $dir || str_starts_with($rel, $dir . '/')) {
            return true;
        }
    }
    return false;
}

function backupDir(): string
{
    return dataDir('backups');
}

function backupName(string $occasion): string
{
    // Random part: backup names must not be guessable in case a server ignores the .htaccess
    return gmdate('Ymd-His') . '_' . environment() . '_' . preg_replace('/[^a-z0-9-]/', '', strtolower($occasion)) . '-' . bin2hex(random_bytes(6));
}

/* ---------------- Database ---------------- */

/** Writes a complete SQL dump (gzip) and returns the file path. */
function backupDatabase(string $name): string
{
    @set_time_limit(300);
    $path = backupDir() . '/' . $name . '.sql.gz';
    $gz = gzopen($path, 'wb6');
    if ($gz === false) {
        throw new RuntimeException('Backup-Datei kann nicht geschrieben werden: ' . $path);
    }
    $pdo = db();
    gzwrite($gz, '-- ElTouro DB-Backup ' . gmdate('c') . ' (' . environment() . ")\n");
    gzwrite($gz, 'SET FOREIGN_KEY_CHECKS=0' . SQL_SEPARATOR);
    gzwrite($gz, "SET NAMES utf8mb4" . SQL_SEPARATOR);

    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")
                  ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, 'DROP TABLE IF EXISTS `' . $t . '`' . SQL_SEPARATOR . $create . SQL_SEPARATOR);

        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_NUM);
        $rows = [];
        while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
            $rows[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            if (count($rows) >= 200) {
                gzwrite($gz, 'INSERT INTO `' . $t . '` VALUES ' . implode(",\n", $rows) . SQL_SEPARATOR);
                $rows = [];
            }
        }
        if ($rows) {
            gzwrite($gz, 'INSERT INTO `' . $t . '` VALUES ' . implode(",\n", $rows) . SQL_SEPARATOR);
        }
    }
    gzwrite($gz, 'SET FOREIGN_KEY_CHECKS=1' . SQL_SEPARATOR);
    gzclose($gz);
    return $path;
}

/** Restores a dump created by backupDatabase(). Replaces ALL tables it contains. */
function restoreDatabase(string $path): int
{
    @set_time_limit(300);
    $content = gzdecode((string)file_get_contents($path));
    if ($content === false || !str_starts_with($content, '-- ElTouro DB-Backup')) {
        throw new RuntimeException('Keine gültige ElTouro-DB-Sicherung.');
    }
    $pdo = db();
    $count = 0;
    foreach (explode(SQL_SEPARATOR, $content) as $stmt) {
        $stmt = trim(preg_replace('/^--[^\n]*\n?/m', '', $stmt));
        if ($stmt !== '') {
            $pdo->exec($stmt);
            $count++;
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return $count;
}

/* ---------------- Files ---------------- */

/** Packs the webroot (without data/) into a ZIP. config.php is included. */
function backupFiles(string $name): string
{
    @set_time_limit(300);
    $path = backupDir() . '/' . $name . '.files.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP kann nicht angelegt werden: ' . $path);
    }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(APP_ROOT))), '/');
        if ($file->isFile() && !isProtectedPath($rel, true)) {
            $zip->addFile($file->getPathname(), $rel);
        }
    }
    $zip->close();
    return $path;
}

/**
 * Reads an ElTouro package and returns its entries as [relative path => index in the ZIP].
 * Checks every path (no "..", no absolute paths, no control characters) and strips a common
 * top folder (e.g. "eltouro-app/"). Protected paths (config.php, data/) are left out.
 */
function packageEntries(ZipArchive $zip, bool $configAllowed = false): array
{
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($n === '' || str_contains($n, '..') || str_starts_with($n, '/') || preg_match('/[\x00-\x1F]|^[A-Za-z]:/', $n)) {
            throw new RuntimeException('Unsicherer Pfad im Archiv: ' . $n);
        }
        $names[$i] = $n;
    }
    $firsts = array_map(fn($n) => explode('/', $n, 2)[0], $names);
    $prefix = '';
    if (count(array_unique($firsts)) === 1 && !in_array(reset($firsts), $names, true)) {
        $prefix = reset($firsts) . '/';
    }
    if (!in_array($prefix . 'bootstrap.php', $names, true)) {
        throw new RuntimeException('Das Archiv enthält keine bootstrap.php – ist das wirklich ein ElTouro-Paket?');
    }
    $entries = [];
    foreach ($names as $i => $n) {
        $rel = substr($n, strlen($prefix));
        if ($rel !== '' && !str_ends_with($rel, '/') && !isProtectedPath($rel, $configAllowed)) {
            $entries[$rel] = $i;
        }
    }
    ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);
    return $entries;
}

function openPackage(string $zipPath): ZipArchive
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Das ist kein lesbares ZIP-Archiv.');
    }
    return $zip;
}

/**
 * Extracts a package safely into the webroot. NOTHING is ever deleted:
 * files missing from the archive stay on the server unchanged.
 * @param string[]|null $only  Only extract these relative paths (null = all)
 * @return int Number of files written
 */
function extractToWebroot(string $zipPath, bool $configAllowed = false, ?array $only = null): int
{
    @set_time_limit(300);
    $zip = openPackage($zipPath);
    try {
        $entries = packageEntries($zip, $configAllowed);
        $written = 0;
        foreach ($entries as $rel => $i) {
            if ($only !== null && !in_array($rel, $only, true)) {
                continue;
            }
            $target = APP_ROOT . '/' . $rel;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                throw new RuntimeException('Ordner kann nicht angelegt werden: ' . dirname($rel));
            }
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                throw new RuntimeException('Datei im Archiv defekt: ' . $rel);
            }
            // Write to a temp file first, then rename – so there are no half-written PHP files
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $data) === false || !rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('Datei kann nicht geschrieben werden: ' . $rel);
            }
            $written++;
        }
    } finally {
        $zip->close();
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return $written;
}

/* ---------------- Deployment preview ---------------- */

/** Stores an uploaded package for review and returns its id. */
function stagePackage(string $tmpFile): string
{
    $dir = dataDir('deploy');
    // Cleanup: staged packages older than a day
    foreach (glob($dir . '/*.zip') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) { @unlink($old); }
    }
    $zip = openPackage($tmpFile);
    try {
        packageEntries($zip);   // throws for unsafe or foreign archives
    } finally {
        $zip->close();
    }
    $id = bin2hex(random_bytes(12));
    if (!move_uploaded_file($tmpFile, $dir . '/' . $id . '.zip')) {
        throw new RuntimeException('Paket konnte nicht zwischengespeichert werden.');
    }
    return $id;
}

function stagedPackage(string $id): ?string
{
    if (!preg_match('/^[0-9a-f]{24}$/', $id)) {
        return null;
    }
    $p = DATA_DIR . '/deploy/' . $id . '.zip';
    return is_file($p) ? $p : null;
}

/** Files of the webroot (without protected paths and temp files) as relative paths */
function webrootFiles(): array
{
    $list = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(APP_ROOT))), '/');
        if ($file->isFile() && !isProtectedPath($rel) && !preg_match('/\.tmp-[0-9a-f]{8}$/', $rel)) {
            $list[] = $rel;
        }
    }
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

/**
 * Compares package and server.
 * @return array{new:string[], changed:string[], same:string[], serverOnly:string[]}
 */
function comparePackage(string $zipPath): array
{
    $zip = openPackage($zipPath);
    $result = ['new' => [], 'changed' => [], 'same' => [], 'serverOnly' => []];
    try {
        $entries = packageEntries($zip);
        foreach ($entries as $rel => $i) {
            $target = APP_ROOT . '/' . $rel;
            if (!is_file($target)) {
                $result['new'][] = $rel;
            } elseif (hash('sha256', (string)$zip->getFromIndex($i)) !== hash_file('sha256', $target)) {
                $result['changed'][] = $rel;
            } else {
                $result['same'][] = $rel;
            }
        }
        $result['serverOnly'] = array_values(array_diff(webrootFiles(), array_keys($entries)));
    } finally {
        $zip->close();
    }
    return $result;
}

/** Content of a file from the package (null if not contained) */
function packageFile(string $zipPath, string $rel): ?string
{
    $zip = openPackage($zipPath);
    try {
        $entries = packageEntries($zip);
        return isset($entries[$rel]) ? (string)$zip->getFromIndex($entries[$rel]) : null;
    } finally {
        $zip->close();
    }
}

function isTextFile(string $rel, string $content): bool
{
    if (preg_match('/\.(php|js|css|html?|md|txt|json|sql|xml|svg|brf|htaccess|example|yml)$/i', $rel) || str_ends_with($rel, '.htaccess')) {
        return !str_contains(substr($content, 0, 8000), "\0");
    }
    return false;
}

/**
 * Line diff (LCS) with context. Returns rows [type, oldNo, newNo, line], type ∈ ' ', '-', '+', '…'.
 * Common head/tail is cut off first so that big files with small changes stay fast.
 */
function lineDiff(string $old, string $new, int $context = 3): ?array
{
    $a = explode("\n", str_replace("\r\n", "\n", $old));
    $b = explode("\n", str_replace("\r\n", "\n", $new));
    $start = 0;
    while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) { $start++; }
    $endA = count($a) - 1; $endB = count($b) - 1;
    while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) { $endA--; $endB--; }
    $midA = array_slice($a, $start, $endA - $start + 1);
    $midB = array_slice($b, $start, $endB - $start + 1);
    $n = count($midA); $m = count($midB);
    if ($n * $m > 1_500_000) {
        return null;   // too big for a comparison in the browser
    }

    // LCS table of lengths
    $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $l[$i][$j] = $midA[$i] === $midB[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
        }
    }
    $rows = [];
    for ($k = 0; $k < $start; $k++) { $rows[] = [' ', $k + 1, $k + 1, $a[$k]]; }
    $i = 0; $j = 0;
    while ($i < $n || $j < $m) {
        if ($i < $n && $j < $m && $midA[$i] === $midB[$j]) {
            $rows[] = [' ', $start + $i + 1, $start + $j + 1, $midA[$i]]; $i++; $j++;
        } elseif ($j < $m && ($i >= $n || $l[$i][$j + 1] >= $l[$i + 1][$j])) {
            $rows[] = ['+', null, $start + $j + 1, $midB[$j]]; $j++;
        } else {
            $rows[] = ['-', $start + $i + 1, null, $midA[$i]]; $i++;
        }
    }
    for ($k = $endA + 1, $q = $endB + 1; $k < count($a); $k++, $q++) { $rows[] = [' ', $k + 1, $q + 1, $a[$k]]; }

    // Reduce to changes plus context
    $show = [];
    foreach ($rows as $idx => $r) {
        if ($r[0] !== ' ') {
            for ($c = max(0, $idx - $context); $c <= min(count($rows) - 1, $idx + $context); $c++) { $show[$c] = true; }
        }
    }
    $out = []; $last = -1;
    foreach ($rows as $idx => $r) {
        if (!isset($show[$idx])) { continue; }
        if ($last >= 0 && $idx > $last + 1) { $out[] = ['…', null, null, '']; }
        $out[] = $r; $last = $idx;
    }
    return $out;
}

/* ---------------- Management ---------------- */

/** @return array<int, array{name:string, files:?string, db:?string, size:int, time:int}> */
function listBackups(): array
{
    $groups = [];
    foreach (glob(backupDir() . '/*') ?: [] as $path) {
        $file = basename($path);
        if (!preg_match(BACKUP_FILE_PATTERN, $file, $m)) {
            continue;
        }
        $g = &$groups[$m[1]];
        $g['name'] = $m[1];
        $g[$m[2] === 'sql.gz' ? 'db' : 'files'] = $file;
        $g['size'] = ($g['size'] ?? 0) + filesize($path);
        $g['time'] = max($g['time'] ?? 0, filemtime($path));
        unset($g);
    }
    krsort($groups);
    return array_values(array_map(fn($g) => $g + ['db' => null, 'files' => null], $groups));
}

/** Path of a backup file from its name – only files from the backup directory. */
function backupPath(string $file): ?string
{
    if (!preg_match(BACKUP_FILE_PATTERN, $file)) {
        return null;
    }
    $p = backupDir() . '/' . $file;
    return is_file($p) ? $p : null;
}

/** Keeps the number of backups small (deletes the oldest first). */
function pruneBackups(int $keep): void
{
    foreach (array_slice(listBackups(), max(1, $keep)) as $b) {
        foreach (['db', 'files'] as $k) {
            if ($b[$k] && ($p = backupPath($b[$k]))) {
                @unlink($p);
            }
        }
    }
}

function fullBackup(string $occasion): string
{
    global $CONFIG;
    $name = backupName($occasion);
    backupDatabase($name);
    backupFiles($name);
    pruneBackups((int)($CONFIG['ops']['max_backups'] ?? 10));
    return $name;
}

function formatSize(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format($bytes / 1024, 0, ',', '.') . ' KB';
}


// ---------------------------------------------------------------- release package for production

/** Parts of the app tree that do not belong in a production package (tools are blocked by .htaccess anyway) */
function isReleaseExcluded(string $rel): bool
{
    if (isProtectedPath($rel) || preg_match('/\.tmp-[0-9a-f]{8}$/', $rel) || str_ends_with($rel, '.DS_Store') || str_starts_with(basename($rel), '.git')) {
        return true;
    }
    foreach (['tools/', '.github/', '.git/', 'docs/'] as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            return true;
        }
    }
    return false;
}

function releaseDir(): string
{
    return dataDir('releases');
}

/**
 * Packs the app as it runs here into one ZIP with the top folder "eltouro-app/" – ready for Admin → Betrieb → Deployment
 * on production. config.php and data/ are never in it, so neither the production config nor the database is touched
 * (the migration only adds missing tables and columns).
 */
function buildRelease(): array
{
    @set_time_limit(300);
    $files = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(APP_ROOT))), '/');
        if ($file->isFile() && !isReleaseExcluded($rel) && $rel !== 'RELEASE.txt') {
            $files[$rel] = $file->getPathname();
        }
    }
    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    $hash = hash_init('sha256');
    foreach ($files as $rel => $path) {
        hash_update($hash, $rel . "\0" . hash_file('sha256', $path));
    }
    $id = substr(hash_final($hash), 0, 10);
    $name = 'eltouro-release-' . gmdate('Ymd-His') . '-' . $id . '.zip';
    $path = releaseDir() . '/' . $name;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP kann nicht angelegt werden');
    }
    foreach ($files as $rel => $abs) {
        $zip->addFile($abs, 'eltouro-app/' . $rel);
    }
    $zip->addFromString('eltouro-app/RELEASE.txt', "ElTouro release $id\nBuilt " . gmdate('Y-m-d H:i:s') . " UTC on " . ($_SERVER['HTTP_HOST'] ?? 'cli')
        . "\nFiles: " . count($files) . "\nNot included: config.php, data/ (the production config and database stay untouched)\n");
    $zip->close();
    return ['name' => $name, 'files' => count($files), 'size' => (int)filesize($path), 'id' => $id];
}

function listReleases(): array
{
    $out = [];
    foreach (glob(releaseDir() . '/eltouro-release-*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function releasePath(string $name): ?string
{
    if (!preg_match('/^eltouro-release-\d{8}-\d{6}-[0-9a-f]{10}\.zip$/', $name)) {
        return null;
    }
    $p = releaseDir() . '/' . $name;
    return is_file($p) ? $p : null;
}
