<?php
/**
 * Name suggestions for tours ("Rodeo Rheinhausen", "Bullenrunde Kaldenhausen").
 *
 * The place comes from a reverse geocoder (default: Nominatim of the OpenStreetMap Foundation), asked by
 * our server – never by the browser. Only the middle of the track is sent, rounded to about 100 m, so the
 * start and finish (often someone's home) never leave the server. Results are cached in data/geocode, and
 * requests are spaced at least a second apart as the Nominatim usage policy demands.
 * Set 'geocoder' => ['url' => ''] in config.php to switch it off; names are then built without a place.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';

const GEOCODER_DEFAULT_URL = 'https://nominatim.openstreetmap.org/reverse';
// Number of templates per pool in lang.php (tourname.<pool>_<n>), the same in every language
const TOUR_NAME_POOLS = ['any' => 10, 'loop' => 3, 'short' => 2, 'long' => 2, 'hilly' => 2, 'noplace' => 3];

/** Facts about a validated track (from validateGeometry()) that the name is built from. */
function tourNameFacts(array $geo): array
{
    $coords = [];
    foreach (json_decode($geo['geojson'], true)['features'] as $f) {
        foreach ($f['geometry']['coordinates'] as $c) {
            $coords[] = $c;
        }
    }
    // Point at half the distance
    $half = $geo['distance'] / 2;
    $walked = 0.0;
    $middle = $coords[0];
    for ($i = 1; $i < count($coords); $i++) {
        $walked += distanceMeters($coords[$i - 1][1], $coords[$i - 1][0], $coords[$i][1], $coords[$i][0]);
        if ($walked >= $half) {
            $middle = $coords[$i];
            break;
        }
    }
    $last = end($coords);
    return [
        'middle' => [(float)$middle[1], (float)$middle[0]],   // lat, lng
        'km'     => $geo['distance'] / 1000,
        'ascent' => computeAscent($geo['geojson']) ?? 0,
        'loop'   => $geo['distance'] > 1000 && distanceMeters($coords[0][1], $coords[0][0], $last[1], $last[0]) < 300,
    ];
}

/** Picks a name. $exclude is the previous suggestion, so "roll again" gives a different one. */
function suggestTourName(array $facts, ?string $place, string $exclude = ''): string
{
    if ($place === null || $place === '') {
        $pools = ['noplace'];
    } else {
        $special = [];
        if ($facts['loop']) $special[] = 'loop';
        if ($facts['km'] < 8) $special[] = 'short';
        if ($facts['km'] > 40) $special[] = 'long';
        if ($facts['ascent'] >= 250) $special[] = 'hilly';
        // Fitting templates come up more often, the general ones keep it varied
        $pools = $special && random_int(1, 10) <= 6 ? $special : array_merge(['any'], $special);
    }
    $candidates = [];
    foreach ($pools as $pool) {
        for ($n = 1; $n <= TOUR_NAME_POOLS[$pool]; $n++) {
            $candidates[] = t("tourname.{$pool}_$n", ['place' => (string)$place, 'km' => (int)round($facts['km'])]);
        }
    }
    $fresh = array_values(array_filter($candidates, fn($c) => $c !== $exclude));
    $list = $fresh ?: $candidates;
    return mb_substr($list[random_int(0, count($list) - 1)], 0, 120);
}

/** Name of the quarter, village or town at a point – or null if unknown, switched off or unreachable. */
function placeNear(float $lat, float $lng, string $lang): ?string
{
    global $CONFIG;
    $url = (string)($CONFIG['geocoder']['url'] ?? GEOCODER_DEFAULT_URL);
    if ($url === '') {
        return null;
    }
    // Rounded to three decimals (about 100 m): enough for a place name, and it makes the cache work
    $lat = round($lat, 3);
    $lng = round($lng, 3);
    $cache = dataDir('geocode') . '/' . sprintf('%.3f_%.3f_%s.json', $lat, $lng, $lang === 'en' ? 'en' : 'de');
    if (is_file($cache)) {
        $hit = json_decode((string)file_get_contents($cache), true);
        $maxAge = ($hit['place'] ?? null) === null ? 86400 : 90 * 86400;
        if (is_array($hit) && time() - (int)($hit['at'] ?? 0) < $maxAge) {
            return $hit['place'];
        }
    }

    geocoderThrottle();
    $contact = setting('operator_email');
    $ch = curl_init($url . '?' . http_build_query(['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 14,
                                                   'addressdetails' => 1, 'accept-language' => $lang]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
                            CURLOPT_USERAGENT => 'ElTouro/1.0 (+' . baseUrl() . ($contact !== '' ? '; ' . $contact : '') . ')']);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    if (!is_string($raw) || $status !== 200) {
        return null;   // not cached: next time we try again
    }
    $address = json_decode($raw, true)['address'] ?? [];
    $place = null;
    foreach (['suburb', 'village', 'town', 'hamlet', 'city_district', 'city', 'municipality'] as $key) {
        if (!empty($address[$key]) && is_string($address[$key])) {
            $place = mb_substr(trim($address[$key]), 0, 60);
            break;
        }
    }
    file_put_contents($cache, json_encode(['place' => $place, 'at' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $place;
}

/** At most one request per second to the geocoder, across all PHP processes. */
function geocoderThrottle(): void
{
    $fh = fopen(dataDir('geocode') . '/.last', 'c+');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    $last = (float)stream_get_contents($fh);
    $wait = $last + 1.1 - microtime(true);
    if ($wait > 0) {
        usleep((int)(min($wait, 2) * 1_000_000));
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)microtime(true));
    flock($fh, LOCK_UN);
    fclose($fh);
}
