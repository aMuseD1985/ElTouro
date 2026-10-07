<?php
/**
 * Betrieb: Backups, Restore und Deployment aus dem Admin-Bereich.
 *
 * Grundregeln:
 *  - config.php und der Ordner daten/ werden bei Deployment und Datei-Restore NIE überschrieben
 *    (Ausnahme: beim Restore kann config.php ausdrücklich mit wiederhergestellt werden).
 *  - Vor jedem Deployment und jedem Restore wird automatisch ein Backup angelegt.
 *  - ZIP-Einträge mit "..", absoluten Pfaden oder Steuerzeichen werden abgelehnt (Zip-Slip).
 *  - Backups liegen in daten/backups (per .htaccess gesperrt).
 */
declare(strict_types=1);

const APP_WURZEL = __DIR__;
const BACKUP_ORDNER = __DIR__ . '/daten/backups';
const SQL_TRENNER = "\n-- ;;ELTOURO;;\n";

/** Pfade relativ zur App-Wurzel, die weder überschrieben noch mitgesichert werden */
function istGeschuetzt(string $rel, bool $configErlaubt = false): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === 'config.php') {
        return !$configErlaubt;
    }
    return $rel === 'daten' || str_starts_with($rel, 'daten/');
}

function backupOrdner(): string
{
    if (!is_dir(BACKUP_ORDNER)) {
        mkdir(BACKUP_ORDNER, 0700, true);
    }
    return BACKUP_ORDNER;
}

function backupName(string $anlass): string
{
    // Zufallsanteil: Backup-Namen sollen nicht erratbar sein, falls ein Server die .htaccess ignoriert
    return gmdate('Ymd-His') . '_' . umgebung() . '_' . preg_replace('/[^a-z0-9-]/', '', strtolower($anlass)) . '-' . bin2hex(random_bytes(6));
}

/* ---------------- Datenbank ---------------- */

/** Schreibt einen vollständigen SQL-Dump (gzip) und gibt den Dateipfad zurück. */
function sichereDatenbank(string $name): string
{
    @set_time_limit(300);
    $pfad = backupOrdner() . '/' . $name . '.sql.gz';
    $gz = gzopen($pfad, 'wb6');
    if ($gz === false) {
        throw new RuntimeException('Backup-Datei kann nicht geschrieben werden: ' . $pfad);
    }
    $pdo = db();
    gzwrite($gz, '-- ElTouro DB-Backup ' . gmdate('c') . ' (' . umgebung() . ")\n");
    gzwrite($gz, 'SET FOREIGN_KEY_CHECKS=0' . SQL_TRENNER);
    gzwrite($gz, "SET NAMES utf8mb4" . SQL_TRENNER);

    $tabellen = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")
                    ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabellen as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, 'DROP TABLE IF EXISTS `' . $t . '`' . SQL_TRENNER . $create . SQL_TRENNER);

        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_NUM);
        $zeilen = [];
        while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
            $zeilen[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            if (count($zeilen) >= 200) {
                gzwrite($gz, 'INSERT INTO `' . $t . '` VALUES ' . implode(",\n", $zeilen) . SQL_TRENNER);
                $zeilen = [];
            }
        }
        if ($zeilen) {
            gzwrite($gz, 'INSERT INTO `' . $t . '` VALUES ' . implode(",\n", $zeilen) . SQL_TRENNER);
        }
    }
    gzwrite($gz, 'SET FOREIGN_KEY_CHECKS=1' . SQL_TRENNER);
    gzclose($gz);
    return $pfad;
}

/** Spielt einen mit sichereDatenbank() erzeugten Dump ein. Ersetzt ALLE enthaltenen Tabellen. */
function stelleDatenbankWiederHer(string $pfad): int
{
    @set_time_limit(300);
    $inhalt = gzdecode((string)file_get_contents($pfad));
    if ($inhalt === false || !str_starts_with($inhalt, '-- ElTouro DB-Backup')) {
        throw new RuntimeException('Keine gültige ElTouro-DB-Sicherung.');
    }
    $pdo = db();
    $anzahl = 0;
    foreach (explode(SQL_TRENNER, $inhalt) as $stmt) {
        $stmt = trim(preg_replace('/^--[^\n]*\n?/m', '', $stmt));
        if ($stmt !== '') {
            $pdo->exec($stmt);
            $anzahl++;
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return $anzahl;
}

/* ---------------- Dateien ---------------- */

/** Packt den Webroot (ohne daten/) in ein ZIP. config.php wird mitgesichert. */
function sichereDateien(string $name): string
{
    @set_time_limit(300);
    $pfad = backupOrdner() . '/' . $name . '.dateien.zip';
    $zip = new ZipArchive();
    if ($zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP kann nicht angelegt werden: ' . $pfad);
    }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_WURZEL, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $datei) {
        $rel = ltrim(str_replace('\\', '/', substr($datei->getPathname(), strlen(APP_WURZEL))), '/');
        if ($datei->isFile() && !istGeschuetzt($rel, true)) {
            $zip->addFile($datei->getPathname(), $rel);
        }
    }
    $zip->close();
    return $pfad;
}

/**
 * Liest ein ElTouro-Paket und liefert die Einträge als [relativer Pfad => Index im ZIP].
 * Prüft jeden Pfad (kein "..", keine absoluten Pfade, keine Steuerzeichen) und entfernt einen
 * gemeinsamen Oberordner (z. B. "eltouro-app/"). Geschützte Pfade (config.php, daten/) fehlen im Ergebnis.
 */
function paketEintraege(ZipArchive $zip, bool $configErlaubt = false): array
{
    $namen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($n === '' || str_contains($n, '..') || str_starts_with($n, '/') || preg_match('/[\x00-\x1F]|^[A-Za-z]:/', $n)) {
            throw new RuntimeException('Unsicherer Pfad im Archiv: ' . $n);
        }
        $namen[$i] = $n;
    }
    $erste = array_map(fn($n) => explode('/', $n, 2)[0], $namen);
    $praefix = '';
    if (count(array_unique($erste)) === 1 && !in_array(reset($erste), $namen, true)) {
        $praefix = reset($erste) . '/';
    }
    if (!in_array($praefix . 'bootstrap.php', $namen, true)) {
        throw new RuntimeException('Das Archiv enthält keine bootstrap.php – ist das wirklich ein ElTouro-Paket?');
    }
    $eintraege = [];
    foreach ($namen as $i => $n) {
        $rel = substr($n, strlen($praefix));
        if ($rel !== '' && !str_ends_with($rel, '/') && !istGeschuetzt($rel, $configErlaubt)) {
            $eintraege[$rel] = $i;
        }
    }
    ksort($eintraege, SORT_NATURAL | SORT_FLAG_CASE);
    return $eintraege;
}

function oeffnePaket(string $zipPfad): ZipArchive
{
    $zip = new ZipArchive();
    if ($zip->open($zipPfad) !== true) {
        throw new RuntimeException('Das ist kein lesbares ZIP-Archiv.');
    }
    return $zip;
}

/**
 * Entpackt ein Paket sicher in den Webroot. Es wird NIE etwas gelöscht:
 * Dateien, die im Archiv fehlen, bleiben auf dem Server unverändert liegen.
 * @param string[]|null $nur  Nur diese relativen Pfade einspielen (null = alle)
 * @return int Anzahl geschriebener Dateien
 */
function entpackeInWebroot(string $zipPfad, bool $configErlaubt = false, ?array $nur = null): int
{
    @set_time_limit(300);
    $zip = oeffnePaket($zipPfad);
    try {
        $eintraege = paketEintraege($zip, $configErlaubt);
        $geschrieben = 0;
        foreach ($eintraege as $rel => $i) {
            if ($nur !== null && !in_array($rel, $nur, true)) {
                continue;
            }
            $ziel = APP_WURZEL . '/' . $rel;
            if (!is_dir(dirname($ziel)) && !mkdir(dirname($ziel), 0755, true)) {
                throw new RuntimeException('Ordner kann nicht angelegt werden: ' . dirname($rel));
            }
            $daten = $zip->getFromIndex($i);
            if ($daten === false) {
                throw new RuntimeException('Datei im Archiv defekt: ' . $rel);
            }
            // Erst in Temp-Datei schreiben, dann umbenennen – so gibt es keine halb geschriebenen PHP-Dateien
            $tmp = $ziel . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $daten) === false || !rename($tmp, $ziel)) {
                @unlink($tmp);
                throw new RuntimeException('Datei kann nicht geschrieben werden: ' . $rel);
            }
            $geschrieben++;
        }
    } finally {
        $zip->close();
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return $geschrieben;
}

/* ---------------- Deployment-Vorschau ---------------- */

const DEPLOY_ORDNER = __DIR__ . '/daten/deploy';

/** Legt ein hochgeladenes Paket zur Prüfung ab und gibt seine Kennung zurück. */
function parkePaket(string $tmpDatei): string
{
    if (!is_dir(DEPLOY_ORDNER)) {
        mkdir(DEPLOY_ORDNER, 0700, true);
    }
    // Aufräumen: geparkte Pakete älter als einen Tag
    foreach (glob(DEPLOY_ORDNER . '/*.zip') ?: [] as $alt) {
        if (filemtime($alt) < time() - 86400) { @unlink($alt); }
    }
    $zip = oeffnePaket($tmpDatei);
    try {
        paketEintraege($zip);   // wirft bei unsicheren oder fremden Archiven
    } finally {
        $zip->close();
    }
    $id = bin2hex(random_bytes(12));
    if (!move_uploaded_file($tmpDatei, DEPLOY_ORDNER . '/' . $id . '.zip')) {
        throw new RuntimeException('Paket konnte nicht zwischengespeichert werden.');
    }
    return $id;
}

function geparktesPaket(string $id): ?string
{
    if (!preg_match('/^[0-9a-f]{24}$/', $id)) {
        return null;
    }
    $p = DEPLOY_ORDNER . '/' . $id . '.zip';
    return is_file($p) ? $p : null;
}

/** Dateien des Webroots (ohne geschützte Pfade und ohne Temp-Dateien) als relative Pfade */
function webrootDateien(): array
{
    $liste = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_WURZEL, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $datei) {
        $rel = ltrim(str_replace('\\', '/', substr($datei->getPathname(), strlen(APP_WURZEL))), '/');
        if ($datei->isFile() && !istGeschuetzt($rel) && !preg_match('/\.tmp-[0-9a-f]{8}$/', $rel)) {
            $liste[] = $rel;
        }
    }
    sort($liste, SORT_NATURAL | SORT_FLAG_CASE);
    return $liste;
}

/**
 * Vergleicht Paket und Server.
 * @return array{neu:string[], geaendert:string[], gleich:string[], nurServer:string[]}
 */
function vergleichePaket(string $zipPfad): array
{
    $zip = oeffnePaket($zipPfad);
    $ergebnis = ['neu' => [], 'geaendert' => [], 'gleich' => [], 'nurServer' => []];
    try {
        $eintraege = paketEintraege($zip);
        foreach ($eintraege as $rel => $i) {
            $ziel = APP_WURZEL . '/' . $rel;
            if (!is_file($ziel)) {
                $ergebnis['neu'][] = $rel;
            } elseif (hash('sha256', (string)$zip->getFromIndex($i)) !== hash_file('sha256', $ziel)) {
                $ergebnis['geaendert'][] = $rel;
            } else {
                $ergebnis['gleich'][] = $rel;
            }
        }
        $ergebnis['nurServer'] = array_values(array_diff(webrootDateien(), array_keys($eintraege)));
    } finally {
        $zip->close();
    }
    return $ergebnis;
}

/** Inhalt einer Datei aus dem Paket (null, wenn nicht enthalten) */
function paketDatei(string $zipPfad, string $rel): ?string
{
    $zip = oeffnePaket($zipPfad);
    try {
        $eintraege = paketEintraege($zip);
        return isset($eintraege[$rel]) ? (string)$zip->getFromIndex($eintraege[$rel]) : null;
    } finally {
        $zip->close();
    }
}

function istTextdatei(string $rel, string $inhalt): bool
{
    if (preg_match('/\.(php|js|css|html?|md|txt|json|sql|xml|svg|brf|htaccess|example)$/i', $rel) || str_ends_with($rel, '.htaccess')) {
        return !str_contains(substr($inhalt, 0, 8000), "\0");
    }
    return false;
}

/**
 * Zeilen-Diff (LCS) mit Kontext. Liefert Blöcke aus [typ, altNr, neuNr, zeile], typ ∈ ' ', '-', '+', '…'.
 * Gemeinsamer Anfang/Ende wird vorab abgeschnitten, damit auch große Dateien mit kleinen Änderungen schnell sind.
 */
function zeilenDiff(string $alt, string $neu, int $kontext = 3): ?array
{
    $a = explode("\n", str_replace("\r\n", "\n", $alt));
    $b = explode("\n", str_replace("\r\n", "\n", $neu));
    $start = 0;
    while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) { $start++; }
    $endeA = count($a) - 1; $endeB = count($b) - 1;
    while ($endeA >= $start && $endeB >= $start && $a[$endeA] === $b[$endeB]) { $endeA--; $endeB--; }
    $mitteA = array_slice($a, $start, $endeA - $start + 1);
    $mitteB = array_slice($b, $start, $endeB - $start + 1);
    $n = count($mitteA); $m = count($mitteB);
    if ($n * $m > 1_500_000) {
        return null;   // zu groß für den Vergleich im Browser
    }

    // LCS-Tabelle zeilenweise als Längen
    $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $l[$i][$j] = $mitteA[$i] === $mitteB[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
        }
    }
    $zeilen = [];
    for ($k = 0; $k < $start; $k++) { $zeilen[] = [' ', $k + 1, $k + 1, $a[$k]]; }
    $i = 0; $j = 0;
    while ($i < $n || $j < $m) {
        if ($i < $n && $j < $m && $mitteA[$i] === $mitteB[$j]) {
            $zeilen[] = [' ', $start + $i + 1, $start + $j + 1, $mitteA[$i]]; $i++; $j++;
        } elseif ($j < $m && ($i >= $n || $l[$i][$j + 1] >= $l[$i + 1][$j])) {
            $zeilen[] = ['+', null, $start + $j + 1, $mitteB[$j]]; $j++;
        } else {
            $zeilen[] = ['-', $start + $i + 1, null, $mitteA[$i]]; $i++;
        }
    }
    for ($k = $endeA + 1, $q = $endeB + 1; $k < count($a); $k++, $q++) { $zeilen[] = [' ', $k + 1, $q + 1, $a[$k]]; }

    // Auf Änderungen plus Kontext eindampfen
    $zeigen = [];
    foreach ($zeilen as $idx => $z) {
        if ($z[0] !== ' ') {
            for ($c = max(0, $idx - $kontext); $c <= min(count($zeilen) - 1, $idx + $kontext); $c++) { $zeigen[$c] = true; }
        }
    }
    $aus = []; $letzter = -1;
    foreach ($zeilen as $idx => $z) {
        if (!isset($zeigen[$idx])) { continue; }
        if ($letzter >= 0 && $idx > $letzter + 1) { $aus[] = ['…', null, null, '']; }
        $aus[] = $z; $letzter = $idx;
    }
    return $aus;
}

/* ---------------- Verwaltung ---------------- */

/** @return array<int, array{name:string, dateien:?string, db:?string, groesse:int, zeit:int}> */
function listeBackups(): array
{
    $gruppen = [];
    foreach (glob(backupOrdner() . '/*') ?: [] as $pfad) {
        $datei = basename($pfad);
        if (!preg_match('/^(\d{8}-\d{6}_[a-z]+_[a-z0-9-]*)\.(sql\.gz|dateien\.zip)$/', $datei, $m)) {
            continue;
        }
        $g = &$gruppen[$m[1]];
        $g['name'] = $m[1];
        $g[$m[2] === 'sql.gz' ? 'db' : 'dateien'] = $datei;
        $g['groesse'] = ($g['groesse'] ?? 0) + filesize($pfad);
        $g['zeit'] = max($g['zeit'] ?? 0, filemtime($pfad));
        unset($g);
    }
    krsort($gruppen);
    return array_values(array_map(fn($g) => $g + ['db' => null, 'dateien' => null], $gruppen));
}

/** Pfad einer Backup-Datei aus ihrem Namen – nur Dateien aus dem Backup-Ordner. */
function backupPfad(string $datei): ?string
{
    if (!preg_match('/^\d{8}-\d{6}_[a-z]+_[a-z0-9-]*\.(sql\.gz|dateien\.zip)$/', $datei)) {
        return null;
    }
    $p = backupOrdner() . '/' . $datei;
    return is_file($p) ? $p : null;
}

/** Hält die Anzahl der Backups klein (älteste zuerst löschen). */
function raeumeBackupsAuf(int $behalten): void
{
    $liste = listeBackups();
    foreach (array_slice($liste, max(1, $behalten)) as $b) {
        foreach (['db', 'dateien'] as $k) {
            if ($b[$k] && ($p = backupPfad($b[$k]))) {
                @unlink($p);
            }
        }
    }
}

function vollbackup(string $anlass): string
{
    global $CONFIG;
    $name = backupName($anlass);
    sichereDatenbank($name);
    sichereDateien($name);
    raeumeBackupsAuf((int)($CONFIG['ops']['max_backups'] ?? 10));
    return $name;
}

function formatiereGroesse(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format($bytes / 1024, 0, ',', '.') . ' KB';
}
