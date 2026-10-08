<?php
/**
 * Sharing tours by link – also private ones, for social media.
 *
 * A shared tour is visible to anyone with the link, without login. It never reveals internals:
 * no creator, no crew, no description, no visibility setting, no waypoints – and the first and last
 * SHARE_PRIVACY_METERS of the track are cut off so start and finish (often someone's home) stay private.
 * The link can be revoked at any time (new link on the next share).
 *
 * Rights: whoever may edit the tour may create and revoke the link. For public tours every logged-in
 * user may create one (the content is public to members anyway); revoking stays with the editors.
 */
declare(strict_types=1);
require_once __DIR__ . '/tours_lib.php';

const SHARE_PRIVACY_METERS = 300;
const SHARE_IMAGE_W = 1200;
const SHARE_IMAGE_H = 630;

function canShareTour(array $tour, int $userId): bool
{
    return canEditTour($tour, $userId) || $tour['visibility'] === 'public';
}

function shareUrl(array $tour): ?string
{
    return $tour['share_token'] ? baseUrl() . '/s/' . $tour['share_token'] : null;
}

/** Creates the share link if there is none yet. Returns the token. */
function enableTourShare(int $tourId): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');   // 24 characters, URL-safe
    dbExec('UPDATE tours SET share_token = ?, share_created_at = UTC_TIMESTAMP() WHERE id = ? AND share_token IS NULL', [$token, $tourId]);
    return (string)dbOne('SELECT share_token FROM tours WHERE id = ?', [$tourId])['share_token'];
}

function disableTourShare(int $tourId): void
{
    $old = dbOne('SELECT share_token FROM tours WHERE id = ?', [$tourId])['share_token'] ?? null;
    dbExec('UPDATE tours SET share_token = NULL, share_created_at = NULL WHERE id = ?', [$tourId]);
    if ($old && is_file(DATA_DIR . '/share/' . $old . '.png')) {
        @unlink(DATA_DIR . '/share/' . $old . '.png');
    }
}

function loadSharedTour(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{24}$/', $token)) {
        return null;
    }
    return dbOne('SELECT id, title, distance_m, ascent_m, difficulty, style, rule_set, freehand_share_pct, geojson, updated_at, share_token
                    FROM tours WHERE share_token = ? AND deleted_at IS NULL', [$token]);
}

/**
 * Cuts $meters off both ends of the track (privacy zone). Short tours lose at most a quarter at each end.
 * Returns a FeatureCollection like the stored one.
 */
function trimRouteEnds(string $geojson, float $meters = SHARE_PRIVACY_METERS): array
{
    $fc = json_decode($geojson, true);
    $points = [];   // flat list of [lng, lat, freehand]
    foreach ($fc['features'] ?? [] as $f) {
        $free = !empty($f['properties']['freehand']);
        foreach ($f['geometry']['coordinates'] as $c) {
            $points[] = [(float)$c[0], (float)$c[1], $free];
        }
    }
    if (count($points) < 2) {
        return ['type' => 'FeatureCollection', 'features' => []];
    }
    $cum = [0.0];
    for ($i = 1; $i < count($points); $i++) {
        $cum[$i] = $cum[$i - 1] + distanceMeters($points[$i - 1][1], $points[$i - 1][0], $points[$i][1], $points[$i][0]);
    }
    $total = end($cum);
    $cut = min($meters, $total / 4);
    $from = $cut;
    $to = $total - $cut;

    $at = function (float $d) use ($points, $cum): array {
        for ($i = 1; $i < count($points); $i++) {
            if ($cum[$i] >= $d) {
                $seg = $cum[$i] - $cum[$i - 1];
                $r = $seg > 0 ? ($d - $cum[$i - 1]) / $seg : 0;
                return [$points[$i - 1][0] + ($points[$i][0] - $points[$i - 1][0]) * $r,
                        $points[$i - 1][1] + ($points[$i][1] - $points[$i - 1][1]) * $r, $points[$i][2]];
            }
        }
        return end($points);
    };

    // Rebuild sections (freehand or not) between the two cut points
    $kept = [$at($from)];
    for ($i = 0; $i < count($points); $i++) {
        if ($cum[$i] > $from && $cum[$i] < $to) {
            $kept[] = $points[$i];
        }
    }
    $kept[] = $at($to);
    $features = [];
    $line = [];
    $free = $kept[1][2] ?? $kept[0][2];
    foreach ($kept as $k => $p) {
        if ($k > 0 && $p[2] !== $free && count($line) >= 1) {
            $line[] = [round($p[0], 6), round($p[1], 6)];
            $features[] = ['type' => 'Feature', 'properties' => ['freehand' => $free], 'geometry' => ['type' => 'LineString', 'coordinates' => $line]];
            $line = [];
            $free = $p[2];
        }
        $line[] = [round($p[0], 6), round($p[1], 6)];
    }
    if (count($line) >= 2) {
        $features[] = ['type' => 'Feature', 'properties' => ['freehand' => $free], 'geometry' => ['type' => 'LineString', 'coordinates' => $line]];
    }
    return ['type' => 'FeatureCollection', 'features' => $features];
}

/** "12,4 km · Einfach · Gemütlich" – the text for previews and share buttons. */
function shareSummary(array $tour): string
{
    $parts = [formatKm((int)$tour['distance_m'])];
    if ($tour['ascent_m'] !== null) {
        $parts[] = '↗ ' . (int)$tour['ascent_m'] . ' m';
    }
    $parts[] = t('tour.d_' . $tour['difficulty']);
    $parts[] = t('tour.s_' . $tour['style']);
    return implode(' · ', $parts);
}

/**
 * Preview image (PNG, 1200×630) for social media: the trimmed track on the ElTouro night blue.
 * Cached in data/share/<token>.png and rebuilt when the tour changes. Null if GD is missing.
 */
function shareImagePath(array $tour, array $trimmed): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $file = dataDir('share') . '/' . $tour['share_token'] . '.png';
    if (is_file($file) && filemtime($file) >= strtotime($tour['updated_at'] . ' UTC')) {
        return $file;
    }
    $w = SHARE_IMAGE_W; $h = SHARE_IMAGE_H; $pad = 90;
    $img = imagecreatetruecolor($w, $h);
    $night = imagecolorallocate($img, 0x14, 0x26, 0x3F);
    $denim = imagecolorallocate($img, 0x2F, 0x5E, 0x8C);
    $gold = imagecolorallocate($img, 0xD7, 0xA8, 0x45);
    $chalk = imagecolorallocate($img, 0xE8, 0xEC, 0xF1);
    $red = imagecolorallocate($img, 0xC2, 0x55, 0x3F);
    imagefilledrectangle($img, 0, 0, $w, $h, $night);
    imagefilledrectangle($img, 0, $h - 14, $w, $h, $gold);
    // faint grid as a hint of a map
    imagesetthickness($img, 1);
    for ($x = 0; $x < $w; $x += 60) { imageline($img, $x, 0, $x, $h - 15, $denim); }
    for ($y = 0; $y < $h - 14; $y += 60) { imageline($img, 0, $y, $w, $y, $denim); }

    $all = [];
    foreach ($trimmed['features'] as $f) {
        foreach ($f['geometry']['coordinates'] as $c) { $all[] = $c; }
    }
    if (count($all) >= 2) {
        // Equirectangular projection, scaled by cos(latitude) and fitted into the image
        $lats = array_column($all, 1); $lngs = array_column($all, 0);
        $k = cos(deg2rad((min($lats) + max($lats)) / 2));
        $minX = min($lngs) * $k; $maxX = max($lngs) * $k; $minY = min($lats); $maxY = max($lats);
        $scale = min(($w - 2 * $pad) / max($maxX - $minX, 1e-9), ($h - 2 * $pad - 14) / max($maxY - $minY, 1e-9));
        $offX = ($w - ($maxX - $minX) * $scale) / 2; $offY = ($h - 14 - ($maxY - $minY) * $scale) / 2;
        $px = fn(array $c) => [(int)round($offX + ($c[0] * $k - $minX) * $scale), (int)round($h - 14 - $offY - ($c[1] - $minY) * $scale)];

        foreach ([[16, $denim], [8, null]] as [$thick, $color]) {
            imagesetthickness($img, $thick);
            foreach ($trimmed['features'] as $f) {
                $c = $color ?? (!empty($f['properties']['freehand']) ? $red : $gold);
                $prev = null;
                foreach ($f['geometry']['coordinates'] as $pt) {
                    $p = $px($pt);
                    if ($prev) { imageline($img, $prev[0], $prev[1], $p[0], $p[1], $c); }
                    $prev = $p;
                }
            }
        }
        $s = $px($all[0]); $e = $px(end($all));
        imagefilledellipse($img, $s[0], $s[1], 30, 30, $chalk);
        imagefilledellipse($img, $s[0], $s[1], 18, 18, $gold);
        imagefilledellipse($img, $e[0], $e[1], 30, 30, $chalk);
        imagefilledellipse($img, $e[0], $e[1], 18, 18, $night);
    }
    imagepng($img, $file . '.tmp', 6);
    rename($file . '.tmp', $file);
    return $file;
}

/** Share targets as plain links – no third-party scripts, nothing is sent before the user clicks. */
function shareTargets(string $url, string $text): array
{
    $u = rawurlencode($url);
    $tx = rawurlencode($text);
    return [
        'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($text . ' ' . $url),
        'Telegram' => 'https://t.me/share/url?url=' . $u . '&text=' . $tx,
        'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
        'X'        => 'https://x.com/intent/post?text=' . $tx . '&url=' . $u,
        'E-Mail'   => 'mailto:?subject=' . $tx . '&body=' . rawurlencode($text . "\n\n" . $url),
    ];
}
