<?php
/**
 * Geocoding for the planner: place names along a track (tour_name_lib.php) and the place search.
 *
 * Default service: Nominatim of the OpenStreetMap Foundation, always asked by our server – never by the browser,
 * so the rider's IP address and account stay with us. Results are cached in data/geocode, and requests are spaced
 * at least a second apart as the Nominatim usage policy demands (which also forbids search-as-you-type).
 * config.php: 'geocoder' => ['url' => '…/reverse', 'search_url' => '…/search']; 'url' => '' switches it off.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';

const GEOCODER_DEFAULT_URL = 'https://nominatim.openstreetmap.org/reverse';
// Countries the search looks in: DACH and the neighbours the planner's map data covers
const GEOCODER_COUNTRIES = 'de,at,ch,nl,be,lu,fr,dk,cz,pl,it';

/** Endpoint for 'reverse' or 'search' – '' if geocoding is switched off. */
function geocoderUrl(string $kind): string
{
    global $CONFIG;
    $reverse = (string)($CONFIG['geocoder']['url'] ?? GEOCODER_DEFAULT_URL);
    if ($reverse === '') {
        return '';
    }
    return $kind === 'search' ? (string)($CONFIG['geocoder']['search_url'] ?? preg_replace('#/reverse/?$#', '/search', $reverse)) : $reverse;
}

/** One GET request, decoded JSON or null. */
function geocoderGet(string $url, array $query): ?array
{
    geocoderThrottle();
    $contact = setting('operator_email');
    $ch = curl_init($url . '?' . http_build_query($query));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
                            CURLOPT_USERAGENT => 'ElTouro/1.0 (+' . baseUrl() . ($contact !== '' ? '; ' . $contact : '') . ')']);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);   // curl_close() is deprecated as of PHP 8.5
    $data = is_string($raw) && $status === 200 ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

/**
 * Search for a town, street or address. Returns up to 5 hits [{label, lat, lng, bbox: [south, west, north, east]}],
 * [] for nothing found, null if the service is off or unreachable.
 */
function searchPlaces(string $q, string $lang): ?array
{
    $url = geocoderUrl('search');
    $q = trim(preg_replace('/\s+/u', ' ', $q));
    if ($url === '' || mb_strlen($q) < 2) {
        return $url === '' ? null : [];
    }
    $lang = $lang === 'en' ? 'en' : 'de';
    $cache = dataDir('geocode') . '/search_' . hash('sha256', $lang . '|' . mb_strtolower($q)) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < 30 * 86400) {
        $hit = json_decode((string)file_get_contents($cache), true);
        if (is_array($hit)) {
            return $hit;
        }
    }
    $data = geocoderGet($url, ['format' => 'jsonv2', 'q' => mb_substr($q, 0, 120), 'limit' => 5, 'countrycodes' => GEOCODER_COUNTRIES,
                               'accept-language' => $lang, 'dedupe' => 1, 'addressdetails' => 1]);
    if ($data === null) {
        return null;
    }
    $hits = [];
    foreach ($data as $r) {
        if (!isset($r['lat'], $r['lon']) || !isValidCoordinate((float)$r['lat'], (float)$r['lon'])) {
            continue;
        }
        $label = placeLabel($r);
        if ($label === '' || in_array($label, array_column($hits, 'label'), true)) {
            continue;   // the same town as boundary and as centre point, for example
        }
        $bb = array_map('floatval', (array)($r['boundingbox'] ?? []));
        $hits[] = ['label' => $label, 'lat' => round((float)$r['lat'], 6), 'lng' => round((float)$r['lon'], 6),
                   'bbox' => count($bb) === 4 ? [$bb[0], $bb[2], $bb[1], $bb[3]] : null];
    }
    file_put_contents($cache, json_encode($hits, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $hits;
}

/** Readable label for a hit: "Königsallee 1, Düsseldorf", "Moers, Nordrhein-Westfalen", "Landschaftspark, Duisburg". */
function placeLabel(array $r): string
{
    $a = (array)($r['address'] ?? []);
    $parts = [];
    $add = function (?string $p) use (&$parts): void {
        $p = trim((string)$p);
        if ($p !== '' && !in_array($p, $parts, true)) {
            $parts[] = $p;
        }
    };
    $street = trim(($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? '') . ' ' . ($a['house_number'] ?? ''));
    $name = (string)($r['name'] ?? '');
    if ($name !== '' && $name !== ($a['road'] ?? null) && $name !== ($a['house_number'] ?? null)) {
        $add($name);
    }
    $add($street);
    $add($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? null);
    if (count($parts) < 2) {
        $add($a['state'] ?? $a['country'] ?? null);
    }
    if (!$parts) {
        $parts = array_slice(array_map('trim', explode(',', (string)($r['display_name'] ?? ''))), 0, 3);
    }
    return mb_substr(implode(', ', $parts), 0, 120);
}

/** Name of the quarter, village or town at a point – or null if unknown, switched off or unreachable. */
function placeNear(float $lat, float $lng, string $lang): ?string
{
    $url = geocoderUrl('reverse');
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

    $data = geocoderGet($url, ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 14, 'addressdetails' => 1, 'accept-language' => $lang]);
    if ($data === null) {
        return null;   // not cached: next time we try again
    }
    $address = $data['address'] ?? [];
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
