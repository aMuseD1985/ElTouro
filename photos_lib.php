<?php
/**
 * Photo wall of a crew: a plain gallery with likes. Only active members of the crew see and add photos.
 * Every upload is re-drawn with GD (so EXIF/GPS and camera data are gone for good), turned upright, shrunk to PHOTO_MAX_SIDE and
 * stored as JPEG in data/photos/<id>.jpg (+ <id>_t.jpg thumbnail), served by photo_file.php to members only.
 * Sharing outside the crew is not possible. Delete: the photographer, the crew lead, platform admins – files go at once.
 */
declare(strict_types=1);
require_once __DIR__ . '/crews_lib.php';
require_once __DIR__ . '/talk_lib.php';   // relativeTime()
require_once __DIR__ . '/rewards_lib.php';

const PHOTO_MAX_SIDE = 1600;
const PHOTO_THUMB_SIDE = 480;
const PHOTO_MAX_BYTES = 12 * 1024 * 1024;
const PHOTO_PER_DAY = 30;
const PHOTO_PAGE = 24;

/** [allowed, may moderate] for a crew and a rider */
function photoAccess(array $crew, array $me): array
{
    $m = membership((int)$crew['id'], (int)$me['id']);
    if (!isActiveMember($m)) {
        return [false, false];
    }
    return [true, isCrewLead($m) || (int)$me['is_admin'] === 1];
}

function photoFile(int $id, bool $thumb): ?string
{
    $f = DATA_DIR . '/photos/' . $id . ($thumb ? '_t' : '') . '.jpg';
    return is_file($f) ? $f : null;
}

/** Re-draws one uploaded image. Returns the new photo id or an error key (photo.error_*). */
function savePhoto(int $groupId, int $userId, array $upload, string $caption): int|string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_big' : 'failed';
    }
    if ((int)$upload['size'] > PHOTO_MAX_BYTES) {
        return 'too_big';
    }
    if (!function_exists('imagecreatetruecolor')) {
        return 'failed';
    }
    if ((int)dbOne('SELECT COUNT(*) AS n FROM crew_photos WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY', [$userId])['n'] >= PHOTO_PER_DAY) {
        return 'limit';
    }
    $tmp = (string)$upload['tmp_name'];
    $info = @getimagesize($tmp);
    $type = $info[2] ?? 0;
    if (($info[0] ?? 0) * ($info[1] ?? 0) > 60_000_000) {
        return 'too_big';   // decoding it would eat the memory
    }
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default        => false,
    };
    if (!$src) {
        return 'type';
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0 && ($rot = imagerotate($src, $angle, 0))) {
            imagedestroy($src);
            $src = $rot;
        }
    }
    $w = imagesx($src); $h = imagesy($src);
    if (min($w, $h) < 100) {
        imagedestroy($src);
        return 'small';
    }
    $draw = function (int $side) use ($src, $w, $h) {
        $scale = min(1.0, $side / max($w, $h));
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 0xE8, 0xEC, 0xF1));   // transparent PNGs get the chalk colour
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return [$dst, $nw, $nh];
    };
    dbExec('INSERT INTO crew_photos (group_id, user_id, caption, width, height) VALUES (?, ?, ?, 1, 1)', [$groupId, $userId, $caption !== '' ? mb_substr($caption, 0, 200) : null]);
    $id = (int)db()->lastInsertId();
    $dir = dataDir('photos');
    [$full, $fw, $fh] = $draw(PHOTO_MAX_SIDE);
    [$thumb] = $draw(PHOTO_THUMB_SIDE);
    imagedestroy($src);
    $ok = imagejpeg($full, "$dir/$id.jpg", 82) && imagejpeg($thumb, "$dir/{$id}_t.jpg", 78);
    imagedestroy($full); imagedestroy($thumb);
    if (!$ok) {
        dbExec('DELETE FROM crew_photos WHERE id = ?', [$id]);
        @unlink("$dir/$id.jpg"); @unlink("$dir/{$id}_t.jpg");
        return 'failed';
    }
    dbExec('UPDATE crew_photos SET width = ?, height = ?, bytes = ? WHERE id = ?', [$fw, $fh, (int)filesize("$dir/$id.jpg"), $id]);
    recordEvent($userId, 'photo_added', 'crew_photo', $id);
    return $id;
}

function deletePhoto(int $id): void
{
    @unlink(DATA_DIR . '/photos/' . $id . '.jpg');
    @unlink(DATA_DIR . '/photos/' . $id . '_t.jpg');
    dbExec('UPDATE crew_photos SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
}

/** Files without a live photo row (crew or account deleted, cascade) are removed */
function purgeOrphanPhotos(): int
{
    $n = 0;
    foreach (glob(DATA_DIR . '/photos/*.jpg') ?: [] as $f) {
        $id = (int)basename($f);
        if ($id > 0 && dbOne('SELECT 1 AS x FROM crew_photos WHERE id = ? AND deleted_at IS NULL', [$id]) === null) {
            @unlink($f);
            $n++;
        }
    }
    return $n;
}

function toggleLike(int $photoId, int $userId): bool
{
    $has = dbOne('SELECT 1 AS x FROM crew_photo_likes WHERE photo_id = ? AND user_id = ?', [$photoId, $userId]) !== null;
    if ($has) {
        dbExec('DELETE FROM crew_photo_likes WHERE photo_id = ? AND user_id = ?', [$photoId, $userId]);
    } else {
        dbExec('INSERT IGNORE INTO crew_photo_likes (photo_id, user_id) VALUES (?, ?)', [$photoId, $userId]);
    }
    dbExec('UPDATE crew_photos SET like_count = (SELECT COUNT(*) FROM crew_photo_likes WHERE photo_id = ?) WHERE id = ?', [$photoId, $photoId]);
    return !$has;
}
