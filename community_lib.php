<?php
/**
 * Community building blocks for forum and crew talk: profile photos (avatars), emoji reactions.
 *
 * Avatars: uploaded images are decoded and drawn anew with GD (256×256, centre crop), so no metadata survives –
 * no GPS position from a phone photo, no camera details. Stored in data/avatars/<user>.webp (or .jpg without WebP
 * support) and served by avatar.php to logged-in users only. users.avatar_version is part of the URL, so browsers
 * may cache a picture forever and still see a new one at once.
 */
declare(strict_types=1);

const AVATAR_SIZE = 256;
const AVATAR_MAX_BYTES = 8 * 1024 * 1024;
// Order is the order of the buttons; 🐂 and 🛴 are ours
const REACTIONS = ['👍', '❤️', '😂', '🔥', '🐂', '🛴'];

/** Colour of the initials circle – the same for a rider everywhere. */
function avatarColor(int $userId): string
{
    $colors = ['#2F5E8C', '#8A6412', '#2F7D5B', '#7A3E8C', '#A34B2B', '#1F6F7A', '#5B6B2F', '#8C2F4E'];
    return $colors[$userId % count($colors)];
}

/** Photo or initials. $size: 'sm' (28 px), 'md' (40 px), 'lg' (96 px). */
function avatarHtml(int $userId, string $name, ?string $version, string $size = 'md'): string
{
    if ($version !== null && $version !== '') {
        return '<img class="avatar avatar-' . $size . '" src="/avatar/' . $userId . '?v=' . e($version) . '" alt="" loading="lazy">';
    }
    return '<span class="avatar avatar-' . $size . ' avatar-initials" style="background:' . avatarColor($userId) . '" aria-hidden="true">'
         . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
}

function avatarFile(int $userId): ?string
{
    foreach (['webp', 'jpg'] as $ext) {
        $f = DATA_DIR . '/avatars/' . $userId . '.' . $ext;
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/**
 * Takes an uploaded file ($_FILES entry). Returns null on success or an error key for lang.php (profile.avatar_*).
 */
function saveAvatar(int $userId, array $upload): ?string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        return ($upload['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($upload['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'avatar_too_big' : 'avatar_failed';
    }
    if ((int)$upload['size'] > AVATAR_MAX_BYTES) {
        return 'avatar_too_big';
    }
    if (!function_exists('imagecreatetruecolor')) {
        return 'avatar_failed';
    }
    $tmp = (string)$upload['tmp_name'];
    $info = @getimagesize($tmp);
    $type = $info[2] ?? 0;
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default        => false,
    };
    if (!$src) {
        return 'avatar_type';
    }
    // Phones store photos sideways and note the rotation in EXIF – turn the picture, then the EXIF is gone for good
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0) {
            $rot = imagerotate($src, $angle, 0);
            if ($rot) { imagedestroy($src); $src = $rot; }
        }
    }
    $w = imagesx($src); $h = imagesy($src);
    $side = min($w, $h);
    if ($side < 48) {
        imagedestroy($src);
        return 'avatar_small';
    }
    $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 0xE8, 0xEC, 0xF1));   // transparent PNGs get the chalk colour
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    imagedestroy($src);

    $dir = dataDir('avatars');
    foreach (['webp', 'jpg'] as $ext) {
        @unlink("$dir/$userId.$ext");
    }
    $ok = function_exists('imagewebp') ? imagewebp($dst, "$dir/$userId.webp", 85) : imagejpeg($dst, "$dir/$userId.jpg", 85);
    imagedestroy($dst);
    if (!$ok) {
        return 'avatar_failed';
    }
    dbExec('UPDATE users SET avatar_version = ? WHERE id = ?', [bin2hex(random_bytes(4)), $userId]);
    return null;
}

function deleteAvatar(int $userId): void
{
    foreach (['webp', 'jpg'] as $ext) {
        @unlink(DATA_DIR . '/avatars/' . $userId . '.' . $ext);
    }
    dbExec('UPDATE users SET avatar_version = NULL WHERE id = ?', [$userId]);
}

/** Toggles one emoji of one rider on a forum post. Returns true if it is set afterwards. */
function toggleForumReaction(int $postId, int $userId, string $emoji): bool
{
    if (!in_array($emoji, REACTIONS, true)) {
        return false;
    }
    if (dbExec('DELETE FROM forum_reactions WHERE post_id = ? AND user_id = ? AND emoji = ?', [$postId, $userId, $emoji]) > 0) {
        return false;
    }
    dbExec('INSERT IGNORE INTO forum_reactions (post_id, user_id, emoji) VALUES (?, ?, ?)', [$postId, $userId, $emoji]);
    return true;
}

/** [postId => [emoji => ['n' => count, 'mine' => bool, 'names' => [up to 8 display names]]]] */
function forumReactions(array $postIds, int $viewerId): array
{
    if (!$postIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $out = [];
    foreach (dbAll("SELECT r.post_id, r.emoji, r.user_id, u.display_name FROM forum_reactions r JOIN users u ON u.id = r.user_id
                     WHERE r.post_id IN ($in) ORDER BY r.created_at", array_map('intval', $postIds)) as $r) {
        $slot = &$out[(int)$r['post_id']][$r['emoji']];
        $slot['n'] = ($slot['n'] ?? 0) + 1;
        $slot['mine'] = ($slot['mine'] ?? false) || (int)$r['user_id'] === $viewerId;
        if (count($slot['names'] ?? []) < 8) {
            $slot['names'][] = $r['display_name'];
        }
        unset($slot);
    }
    return $out;
}

/**
 * The reaction bar of a forum post. Inside a form ($asForm) the buttons submit name="emoji" – that works without
 * JavaScript; community.js catches the clicks and toggles via the API instead.
 */
function reactionBar(int $postId, array $reactions, bool $canReact, bool $asForm = false): string
{
    $h = '<div class="reactions" data-post="' . $postId . '">';
    foreach (REACTIONS as $emoji) {
        $r = $reactions[$emoji] ?? null;
        $n = $r['n'] ?? 0;
        $title = $r ? implode(', ', $r['names']) . ($n > count($r['names']) ? ' …' : '') : '';
        $h .= '<button type="' . ($asForm ? 'submit" name="emoji" value="' . e($emoji) : 'button') . '" class="reaction' . ($r && $r['mine'] ? ' mine' : '') . ($n ? '' : ' empty') . '"'
            . ' data-emoji="' . e($emoji) . '"' . ($canReact ? '' : ' disabled') . ($title !== '' ? ' title="' . e($title) . '"' : '')
            . ' aria-pressed="' . ($r && $r['mine'] ? 'true' : 'false') . '">'
            . '<span aria-hidden="true">' . $emoji . '</span> <span class="reaction-n">' . ($n ?: '') . '</span></button>';
    }
    return $h . '</div>';
}

/** Script and settings for community.js (emoji picker, reactions, quoting) – once per page, before pageFooter(). */
function communityAssets(): string
{
    $texts = json_encode(['label' => t('emoji.label')], JSON_UNESCAPED_UNICODE);
    return '<div id="community" data-csrf="' . e(csrfToken()) . '" data-texts="' . e($texts) . '" data-quote-intro="' . te('forum.quote_intro') . '" hidden></div>'
         . '<script src="/assets/community.js?v=1"></script>';
}
