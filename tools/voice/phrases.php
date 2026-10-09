<?php
/**
 * Voice clips for ride mode (assets/voice.js).
 *
 *   php tools/voice/phrases.php list de   > phrases-de.tsv     id <TAB> text  (everything the bull may say)
 *   php tools/voice/phrases.php list en   > phrases-en.tsv
 *   php tools/voice/phrases.php manifest                       scans assets/voice/<lang>/ and writes manifest.json
 *   php tools/voice/phrases.php cues                           lists the sound cues (assets/voice/sfx/<name>.mp3)
 *
 * Save the clip for each line as assets/voice/<lang>/<id>.mp3 (or .ogg/.m4a/.wav), then run "manifest".
 * Missing clips are no problem: the app then uses the device voice for that sentence.
 * The texts mirror what assets/navigate.js says – if you change a text in lang.php, run "list" again.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
$texts = require $root . '/lang.php';

// Spoken distances in real riding (navigate.js spokenDistance(): 60–90 m in steps of 10, then 50, then 100)
const DISTANCES = [60, 70, 80, 90, 100, 150, 200, 250, 300, 400, 500];
const MAX_KM = 60;      // "{km}" is spoken as whole kilometres; longer tours use the device voice
const MAX_EXIT = 8;

const CUES = [
    'start'   => 'when the ride starts (a moo, a snort, an engine hum)',
    'turn'    => 'short signal before every "Jetzt links/rechts …" (a bell, a little horn)',
    'stop'    => 'before a break announcement',
    'halfway' => 'at half time',
    'arrive'  => 'at the finish (cheer, a long moo)',
    'offroute' => 'when the rider left the route (warning)',
    'back'    => 'back on the route',
];

function clipId(string $text): string { return substr(sha1($text), 0, 12); }

/** @return array<string, string> text => note */
function phrases(array $T): array
{
    $out = [];
    foreach ($T as $key => $text) {
        if (!str_starts_with($key, 'nav.')) { continue; }
        $k = substr($key, 4);
        $isFar = str_starts_with($k, 'far_') && $k !== 'far_stop';
        $isNow = str_starts_with($k, 'now_') && !str_starts_with($k, 'now_stop_');
        if (in_array($k, ['arrive', 'offroute', 'back', 'rerouted', 'gps_error', 'no_hints'], true) || $isNow) {
            foreach (str_contains($text, '{n}') ? range(1, MAX_EXIT) : [0] as $n) {
                $out[str_replace('{n}', (string)$n, $text)] = $key;
            }
        } elseif ($isFar) {
            foreach (DISTANCES as $m) {
                foreach (str_contains($text, '{n}') ? range(1, MAX_EXIT) : [0] as $n) {
                    $dist = str_replace('{n}', (string)$m, $T['nav.in_m']);
                    $out[str_replace(['{d}', '{n}'], [$dist, (string)$n], $text)] = $key;
                }
            }
        } elseif (in_array($k, ['start', 'start_sim', 'halfway'], true)) {
            for ($km = 1; $km <= MAX_KM; $km++) {
                $out[str_replace('{km}', (string)$km, $text)] = $key;
            }
        }
    }
    return $out;
}

$cmd = $argv[1] ?? '';
if ($cmd === 'list') {
    $lang = $argv[2] ?? 'de';
    $list = phrases($texts[$lang] ?? []);
    foreach ($list as $text => $key) {
        echo clipId($text), "\t", $text, "\n";
    }
    fwrite(STDERR, count($list) . " lines ($lang). Dynamic parts that stay on the device voice: stop names (nav.far_stop, nav.now_stop_*), tours over " . MAX_KM . " km.\n");
} elseif ($cmd === 'manifest') {
    foreach (['de', 'en'] as $lang) {
        $dir = "$root/assets/voice/$lang";
        $clips = [];
        foreach (phrases($texts[$lang]) as $text => $key) {
            foreach (['mp3', 'ogg', 'm4a', 'wav'] as $ext) {
                if (is_file("$dir/" . clipId($text) . ".$ext")) { $clips[$text] = clipId($text) . ".$ext"; break; }
            }
        }
        $cues = [];
        foreach (array_keys(CUES) as $c) {
            if (is_file("$root/assets/voice/sfx/$c.mp3")) { $cues[] = $c; }
        }
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        file_put_contents("$dir/manifest.json", json_encode(['clips' => (object)$clips, 'cues' => $cues], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        echo "$lang: " . count($clips) . ' clips, ' . count($cues) . " cues\n";
    }
} elseif ($cmd === 'cues') {
    foreach (CUES as $name => $what) { echo str_pad("$name.mp3", 14), $what, "\n"; }
} else {
    fwrite(STDERR, "Usage: php tools/voice/phrases.php list <de|en> | manifest | cues\n");
    exit(1);
}
