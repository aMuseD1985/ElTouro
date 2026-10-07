<?php
/**
 * JSON-Schnittstelle der Tränke. Alle Aufrufe: angemeldet, aktives Herdenmitglied, POST mit X-CSRF-Header
 * (auch Lesezugriffe – so bleibt die Prüfung an einer Stelle einheitlich).
 *
 * aktion=themen      {herde, offset}        → {html, offset, mehr}
 * aktion=beitraege   {thema, vor|nach}      → {html, mehr}
 * aktion=neue        {thema, seit}          → {anzahl}
 * aktion=antworten   {thema, text, bezug}   → {html, id}
 * aktion=like        {beitrag}              → {mag, anzahl}
 * aktion=loeschen    {beitrag}              → {html}
 * aktion=gelesen     {thema, bis}           → {ok}
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/traenke_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json(int $code, array $daten): never
{
    http_response_code($code);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ich = aktuellerNutzer();
if ($ich === null) json(401, ['fehler' => 'login']);
if (!istPost() || !hash_equals(csrfToken(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json(400, ['fehler' => 'csrf']);
$uid = (int)$ich['id'];
$e = json_decode((string)file_get_contents('php://input'), true) ?: [];
$aktion = (string)($_GET['aktion'] ?? '');

/** Thema laden und Mitgliedschaft prüfen */
function themaMitZugang(int $id, array $ich): array
{
    $thema = ladeTraenkeThema($id);
    $herde = $thema ? ladeHerde($thema['herde_slug']) : null;
    [$darf, $mod] = $herde ? traenkeZugang($herde, $ich) : [false, false];
    if (!$darf) json(404, ['fehler' => 'nicht_gefunden']);
    return [$thema, $mod];
}

/** Beitrag + Thema laden und Mitgliedschaft prüfen */
function beitragMitZugang(int $id, array $ich): array
{
    $p = einzeln('SELECT * FROM herd_posts WHERE id = ?', [$id]);
    if ($p === null) json(404, ['fehler' => 'nicht_gefunden']);
    [$thema, $mod] = themaMitZugang((int)$p['topic_id'], $ich);
    return [$p, $thema, $mod];
}

function renderListe(array $beitraege, int $uid, bool $mod): string
{
    return implode('', array_map(fn($p) => renderBeitrag($p, $uid, $mod), $beitraege));
}

switch ($aktion) {
    case 'themen':
        $herde = ladeHerde((string)($e['herde'] ?? ''));
        [$darf] = $herde ? traenkeZugang($herde, $ich) : [false];
        if (!$darf) json(404, ['fehler' => 'nicht_gefunden']);
        $offset = max(0, (int)($e['offset'] ?? 0));
        $liste = ladeTraenkeThemen((int)$herde['id'], $uid, $offset);
        json(200, ['html' => implode('', array_map('renderThemaZeile', $liste)),
                   'offset' => $offset + count($liste), 'mehr' => count($liste) === TRAENKE_THEMEN_SEITE]);

    case 'beitraege':
        [$thema, $mod] = themaMitZugang((int)($e['thema'] ?? 0), $ich);
        if (isset($e['vor'])) {
            $liste = ladeTraenkeBeitraege((int)$thema['id'], $uid, 'p.id < ?', [(int)$e['vor']], true);
        } else {
            $liste = ladeTraenkeBeitraege((int)$thema['id'], $uid, 'p.id > ?', [(int)($e['nach'] ?? 0)]);
        }
        json(200, ['html' => renderListe($liste, $uid, $mod), 'mehr' => count($liste) === TRAENKE_SEITE]);

    case 'neue':
        [$thema] = themaMitZugang((int)($e['thema'] ?? 0), $ich);
        $n = (int)einzeln('SELECT COUNT(*) AS n FROM herd_posts WHERE topic_id = ? AND id > ? AND user_id <> ?',
            [$thema['id'], (int)($e['seit'] ?? 0), $uid])['n'];
        json(200, ['anzahl' => $n]);

    case 'antworten':
        [$thema, $mod] = themaMitZugang((int)($e['thema'] ?? 0), $ich);
        if ($thema['is_locked'] && !$mod) json(403, ['fehler' => t('forum.gesperrt_text')]);
        $text = trim(str_replace("\r", '', (string)($e['text'] ?? '')));
        if (mb_strlen($text) < 1 || mb_strlen($text) > TRAENKE_MAX) json(422, ['fehler' => t('forum.fehler_text', ['max' => TRAENKE_MAX])]);
        if (!darfJetztInTraenkeSchreiben($uid)) json(429, ['fehler' => t('forum.zu_schnell')]);
        $bezug = (int)($e['bezug'] ?? 0);
        if ($bezug && !einzeln('SELECT 1 AS x FROM herd_posts WHERE id = ? AND topic_id = ?', [$bezug, $thema['id']])) {
            $bezug = 0;   // Bezug nur innerhalb desselben Themas
        }
        ausfuehren('INSERT INTO herd_posts (topic_id, user_id, reply_to_id, body) VALUES (?, ?, ?, ?)', [$thema['id'], $uid, $bezug ?: null, $text]);
        $pid = (int)db()->lastInsertId();
        ausfuehren('UPDATE herd_topics SET post_count = post_count + 1, last_post_at = UTC_TIMESTAMP(), last_post_id = ?, last_post_user_id = ? WHERE id = ?',
            [$pid, $uid, $thema['id']]);
        ausfuehren('INSERT INTO herd_reads (topic_id, user_id, last_read_post_id) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE last_read_post_id = GREATEST(last_read_post_id, VALUES(last_read_post_id))', [$thema['id'], $uid, $pid]);
        $neu = ladeTraenkeBeitraege((int)$thema['id'], $uid, 'p.id = ?', [$pid]);
        json(200, ['html' => renderListe($neu, $uid, $mod), 'id' => $pid]);

    case 'like':
        [$p] = beitragMitZugang((int)($e['beitrag'] ?? 0), $ich);
        if ($p['deleted_at']) json(410, ['fehler' => 'geloescht']);
        $pdo = db();
        $pdo->beginTransaction();
        if (ausfuehren('DELETE FROM herd_reactions WHERE post_id = ? AND user_id = ?', [$p['id'], $uid])) {
            ausfuehren('UPDATE herd_posts SET like_count = GREATEST(like_count, 1) - 1 WHERE id = ?', [$p['id']]);
            $mag = false;
        } else {
            ausfuehren('INSERT INTO herd_reactions (post_id, user_id) VALUES (?, ?)', [$p['id'], $uid]);
            ausfuehren('UPDATE herd_posts SET like_count = like_count + 1 WHERE id = ?', [$p['id']]);
            $mag = true;
        }
        $anzahl = (int)einzeln('SELECT like_count FROM herd_posts WHERE id = ?', [$p['id']])['like_count'];
        $pdo->commit();
        json(200, ['mag' => $mag, 'anzahl' => $anzahl]);

    case 'loeschen':
        [$p, $thema, $mod] = beitragMitZugang((int)($e['beitrag'] ?? 0), $ich);
        if ((int)$p['user_id'] !== $uid && !$mod) json(403, ['fehler' => 'verboten']);
        ausfuehren('UPDATE herd_posts SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$p['id']]);
        $neu = ladeTraenkeBeitraege((int)$thema['id'], $uid, 'p.id = ?', [(int)$p['id']]);
        json(200, ['html' => renderListe($neu, $uid, $mod)]);

    case 'gelesen':
        [$thema] = themaMitZugang((int)($e['thema'] ?? 0), $ich);
        $bis = (int)($e['bis'] ?? 0);
        // Nur IDs akzeptieren, die es in diesem Thema wirklich gibt
        $bis = (int)(einzeln('SELECT MAX(id) AS m FROM herd_posts WHERE topic_id = ? AND id <= ?', [$thema['id'], $bis])['m'] ?? 0);
        if ($bis > 0) {
            ausfuehren('INSERT INTO herd_reads (topic_id, user_id, last_read_post_id) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE last_read_post_id = GREATEST(last_read_post_id, VALUES(last_read_post_id))', [$thema['id'], $uid, $bis]);
        }
        json(200, ['ok' => true]);
}
json(400, ['fehler' => 'aktion']);
