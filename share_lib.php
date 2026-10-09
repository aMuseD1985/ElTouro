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
function enableTourShare(int $tourId, int $userId): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');   // 24 characters, URL-safe
    // Whoever creates the link is credited for riders who sign up through it (docs/rewards.md)
    dbExec('UPDATE tours SET share_token = ?, share_created_at = UTC_TIMESTAMP(), share_created_by = ? WHERE id = ? AND share_token IS NULL',
        [$token, $userId, $tourId]);
    return (string)dbOne('SELECT share_token FROM tours WHERE id = ?', [$tourId])['share_token'];
}

function disableTourShare(int $tourId): void
{
    $old = dbOne('SELECT share_token FROM tours WHERE id = ?', [$tourId])['share_token'] ?? null;
    dbExec('UPDATE tours SET share_token = NULL, share_created_at = NULL, share_created_by = NULL WHERE id = ?', [$tourId]);
    foreach ($old ? (glob(DATA_DIR . '/share/' . $old . '*.png') ?: []) : [] as $f) {
        @unlink($f);   // the preview image (all versions)
    }
}

function loadSharedTour(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{24}$/', $token)) {
        return null;
    }
    return dbOne('SELECT id, title, distance_m, ascent_m, difficulty, style, rule_set, vehicle_class, freehand_share_pct, geojson, updated_at, share_token, share_created_by,
                    content_lang, waypoints_json, stops_json
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
 * The stops (charging, food, break, sight) of a shared tour: only places on the visible part of the track –
 * those in the privacy zones at both ends are left out. The other waypoints are never exposed.
 * @return array<int, array{lat: float, lng: float, type: string, name: string, label: string}>
 */
function sharedStops(array $tour): array
{
    require_once __DIR__ . '/poi_lib.php';
    $total = (float)$tour['distance_m'];
    $out = [];
    foreach (tourStops($tour) as $s) {
        $m = $s['km'] * 1000;
        if ($m < SHARE_PRIVACY_METERS || $m > $total - SHARE_PRIVACY_METERS || !in_array($s['type'], STOP_TYPES, true)) {
            continue;
        }
        $out[] = ['lat' => $s['lat'], 'lng' => $s['lng'], 'type' => $s['type'], 'name' => mb_substr((string)($s['name'] ?? ''), 0, 80),
                  'label' => t('stop.type_' . $s['type'])];
    }
    return $out;
}

/**
 * The preview picture for WhatsApp, Telegram & co. (1200×630): light map-like ground, the route with its stops, and a
 * night-blue band with the ELTOURO.de logo, the claim in the language of the tour and the mascot. Texts are pre-rendered
 * (assets/img/share/) because GD cannot draw our WOFF2 fonts. Cached per tour state; the file name carries the design version.
 */
function shareImagePath(array $tour, array $trimmed): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $file = dataDir('share') . '/' . $tour['share_token'] . '-v2.png';
    if (is_file($file) && filemtime($file) >= strtotime($tour['updated_at'] . ' UTC')) {
        return $file;
    }
    $w = SHARE_IMAGE_W; $h = SHARE_IMAGE_H;
    $bandTop = 500;                                   // the night-blue band starts here
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);
    $col = fn(int $hex) => imagecolorallocate($img, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255);
    $night = $col(0x14263F); $denim = $col(0x2F5E8C); $gold = $col(0xD7A845); $chalk = $col(0xE8ECF1);
    $white = $col(0xFFFFFF); $grid = $col(0xD3DBE5); $red = $col(0xC2553F); $block = $col(0xDDE3EB);

    // Ground: chalk with a faint street grid and a few lighter blocks
    imagefilledrectangle($img, 0, 0, $w, $bandTop, $chalk);
    mt_srand(crc32((string)$tour['share_token']));    // the same tour always gets the same ground
    for ($i = 0; $i < 22; $i++) {
        $bx = mt_rand(0, 19) * 60; $by = mt_rand(0, 7) * 60;
        imagefilledrectangle($img, $bx + 6, $by + 6, $bx + 54 + 60 * mt_rand(0, 1), $by + 54, $block);
    }
    imagesetthickness($img, 2);
    for ($x = 0; $x < $w; $x += 60) { imageline($img, $x, 0, $x, $bandTop, $grid); }
    for ($y = 0; $y < $bandTop; $y += 60) { imageline($img, 0, $y, $w, $y, $grid); }

    $all = [];
    foreach ($trimmed['features'] as $f) {
        foreach ($f['geometry']['coordinates'] as $c) { $all[] = $c; }
    }
    if (count($all) >= 2) {
        // Equirectangular projection, scaled by cos(latitude), fitted into the map area left of the mascot
        $areaX = 70; $areaW = 860; $areaY = 60; $areaH = $bandTop - 2 * 60;
        $lats = array_column($all, 1); $lngs = array_column($all, 0);
        $k = cos(deg2rad((min($lats) + max($lats)) / 2));
        $minX = min($lngs) * $k; $maxX = max($lngs) * $k; $minY = min($lats); $maxY = max($lats);
        $scale = min($areaW / max($maxX - $minX, 1e-9), $areaH / max($maxY - $minY, 1e-9));
        $offX = $areaX + ($areaW - ($maxX - $minX) * $scale) / 2; $offY = $areaY + ($areaH - ($maxY - $minY) * $scale) / 2;
        $px = fn(float $lng, float $lat) => [(int)round($offX + ($lng * $k - $minX) * $scale), (int)round($offY + ($maxY - $lat) * $scale)];

        foreach ([[22, $white], [11, null]] as [$thick, $color]) {
            imagesetthickness($img, $thick);
            foreach ($trimmed['features'] as $f) {
                $c = $color ?? (!empty($f['properties']['freehand']) ? $red : $denim);
                $prev = null;
                foreach ($f['geometry']['coordinates'] as $pt) {
                    $p = $px($pt[0], $pt[1]);
                    if ($prev) { imageline($img, $prev[0], $prev[1], $p[0], $p[1], $c); }
                    $prev = $p;
                }
            }
        }
        $disc = function (array $p, int $d, int $ring, int $fill) use ($img, $white) {
            imagefilledellipse($img, $p[0], $p[1], $d + 8, $d + 8, $ring);
            imagefilledellipse($img, $p[0], $p[1], $d, $d, $fill);
        };
        $s = $px($all[0][0], $all[0][1]); $e = $px(end($all)[0], end($all)[1]);
        $disc($s, 22, $night, $gold);
        $disc($e, 22, $white, $night);

        // Stops: coloured discs with a simple glyph (GD has no emoji)
        $stopColor = ['charge' => $col(0xC28F1F), 'food' => $col(0xC2703A), 'break' => $col(0x4F8A6B), 'sight' => $col(0x7A5C9E)];
        foreach (sharedStops($tour) as $st) {
            [$cx, $cy] = $px($st['lng'], $st['lat']);
            imagefilledellipse($img, $cx, $cy + 3, 50, 50, $grid);                 // soft shadow
            $disc([$cx, $cy], 42, $white, $stopColor[$st['type']]);
            imagesetthickness($img, 1);
            switch ($st['type']) {
                case 'charge':
                    imagefilledpolygon($img, [$cx + 4, $cy - 15, $cx - 8, $cy + 2, $cx - 1, $cy + 2, $cx - 4, $cy + 15, $cx + 8, $cy - 3, $cx + 1, $cy - 3], $white);
                    break;
                case 'food':
                    imagefilledellipse($img, $cx, $cy, 26, 26, $white);
                    imagefilledellipse($img, $cx, $cy, 17, 17, $stopColor['food']);
                    imagefilledellipse($img, $cx, $cy, 7, 7, $white);
                    break;
                case 'break':
                    imagefilledrectangle($img, $cx - 11, $cy - 7, $cx + 5, $cy + 9, $white);
                    imagesetthickness($img, 3);
                    imagearc($img, $cx + 8, $cy + 1, 14, 14, 270, 90, $white);
                    imagesetthickness($img, 1);
                    break;
                case 'sight':
                    imagefilledellipse($img, $cx, $cy, 30, 18, $white);
                    imagefilledellipse($img, $cx, $cy, 11, 11, $stopColor['sight']);
                    imagefilledellipse($img, $cx, $cy, 4, 4, $white);
                    break;
            }
        }
    }

    // Band with logo and claim, mascot standing on it
    imagefilledrectangle($img, 0, $bandTop, $w, $h, $night);
    imagefilledrectangle($img, 0, $bandTop - 6, $w, $bandTop, $gold);
    $put = function (string $path, int $x, int $y, int $targetH) use ($img): void {
        $src = is_file($path) ? @imagecreatefrompng($path) : false;
        if (!$src) { return; }
        $tw = (int)round(imagesx($src) * $targetH / imagesy($src));
        imagecopyresampled($img, $src, $x, $y, 0, 0, $tw, $targetH, imagesx($src), imagesy($src));
    };
    $lang = ($tour['content_lang'] ?? 'de') === 'en' ? 'en' : 'de';
    $put(__DIR__ . '/assets/img/share/logo-' . $lang . '.png', 60, $bandTop + 14, 104);
    $put(__DIR__ . '/assets/img/share/mascot.png', $w - 60 - 232, $h - 300, 300);

    imagepng($img, $file . '.tmp', 6);
    rename($file . '.tmp', $file);
    return $file;
}

/** Our link with the channel marker (?via=…) that tells where a new rider came from. */
function viaUrl(string $url, string $channel): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'via=' . $channel;
}

/**
 * Share targets as plain links – no third-party scripts, nothing is sent before the user clicks.
 * Each carries its own channel marker so sign-ups can be attributed to social media (docs/rewards.md).
 */
function shareTargets(string $url, string $text): array
{
    $u = fn(string $ch) => rawurlencode(viaUrl($url, $ch));
    $tx = rawurlencode($text);
    return [
        'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($text . ' ' . viaUrl($url, 'whatsapp')),
        'Telegram' => 'https://t.me/share/url?url=' . $u('telegram') . '&text=' . $tx,
        'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u('facebook'),
        'X'        => 'https://x.com/intent/post?text=' . $tx . '&url=' . $u('x'),
        'E-Mail'   => 'mailto:?subject=' . $tx . '&body=' . rawurlencode($text . "\n\n" . viaUrl($url, 'email')),
    ];
}

/**
 * The complete share box (link field, copy, device share sheet, share targets) – used for tours and
 * for the personal invite link. Needs assets/share.js on the page.
 */
function shareBox(string $url, string $title, string $text, bool $withPreview = true): string
{
    $h = '<div class="share-link"><label for="share-url" class="visually-hidden">' . te('share.link') . '</label>'
       . '<input id="share-url" class="copy-field" readonly value="' . e(viaUrl($url, 'copy')) . '">'
       . '<button type="button" class="secondary-submit" id="share-copy" data-done="' . te('share.copied') . '">' . te('share.copy') . '</button>'
       . '<button type="button" id="share-native" data-title="' . e($title) . '" data-text="' . e($text) . '" data-url="' . e(viaUrl($url, 'native')) . '" hidden>' . te('share.native') . '</button></div>'
       . '<ul class="share-targets">';
    foreach (shareTargets($url, $text) as $name => $href) {
        $h .= '<li><a href="' . e($href) . '" target="_blank" rel="noopener noreferrer">' . e($name) . '</a></li>';
    }
    if ($withPreview) {
        $h .= '<li><a href="' . e($url) . '" target="_blank" rel="noopener">' . te('share.preview') . '</a></li>';
    }
    return $h . '</ul>';
}
