<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/herden_lib.php';
$ich = mussEingeloggtSein();
$uid = (int)$ich['id'];

$slug = (string)($_GET['s'] ?? '');
$code = isset($_GET['code']) ? (string)$_GET['code'] : (isset($_POST['code']) ? (string)$_POST['code'] : null);
$herde = ladeHerde($slug);
$m = $herde ? mitgliedschaft((int)$herde['id'], $uid) : null;

if ($herde === null || !darfHerdeSehen($herde, $m, $code)) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}
$gid = (int)$herde['id'];
$selbst = '/herde.php?s=' . rawurlencode($herde['slug']);

if (istPost()) {
    pruefeCsrf();
    $aktion = (string)($_POST['aktion'] ?? '');
    $ziel = (int)($_POST['user'] ?? 0);

    switch ($aktion) {
        case 'beitreten':
            if ($m === null && ($status = beitrittsStatus($herde, $code)) !== null) {
                ausfuehren("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', ?)", [$gid, $uid, $status]);
                meldung(t($status === 'active' ? 'herde.beigetreten' : 'herde.angefragt'));
            }
            break;

        case 'verlassen':
            if ($m !== null) {
                if (istLeitstier($m) && anzahlLeitstiere($gid) <= 1) {
                    meldung(t('herde.letzter_leitstier'), 'fehler');
                    break;
                }
                ausfuehren('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$gid, $uid]);
                meldung(t('herde.verlassen_ok'));
                weiterleiten($herde['discoverability'] === 'secret' ? '/herden.php' : $selbst);
            }
            break;

        // Ab hier nur für den Leitstier
        case 'annehmen':
        case 'ablehnen':
        case 'entfernen':
        case 'befoerdern':
        case 'einstellungen':
        case 'code_neu':
            if (!istLeitstier($m)) {
                http_response_code(403);
                exit;
            }
            if ($aktion === 'annehmen') {
                ausfuehren("UPDATE group_members SET status = 'active' WHERE group_id = ? AND user_id = ? AND status = 'pending'", [$gid, $ziel]);
            } elseif ($aktion === 'ablehnen') {
                ausfuehren("DELETE FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'pending'", [$gid, $ziel]);
            } elseif ($aktion === 'entfernen' && $ziel !== $uid) {
                ausfuehren('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$gid, $ziel]);
            } elseif ($aktion === 'befoerdern') {
                ausfuehren("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ? AND status = 'active'", [$gid, $ziel]);
            } elseif ($aktion === 'code_neu') {
                ausfuehren('UPDATE rider_groups SET invite_code = ? WHERE id = ?', [neuerEinladungscode(), $gid]);
            } elseif ($aktion === 'einstellungen') {
                $name = feld('name', 60);
                $sicht = feld('sichtbarkeit');
                $beitritt = feld('beitritt');
                if (mb_strlen($name) >= 3 && in_array($sicht, ['listed', 'secret'], true) && in_array($beitritt, ['open', 'request', 'invite'], true)) {
                    ausfuehren('UPDATE rider_groups SET name = ?, description = ?, region = ?, discoverability = ?, join_policy = ? WHERE id = ?',
                        [$name, feld('beschreibung', 2000) ?: null, feld('region', 100) ?: null, $sicht, $beitritt, $gid]);
                    meldung(t('herde.gespeichert'));
                } else {
                    meldung(t('herde.fehler_name'), 'fehler');
                }
            }
            break;
    }
    weiterleiten($selbst . ($code !== null && $m === null ? '&code=' . rawurlencode($code) : ''));
}

$istMitglied = istAktivesMitglied($m);
$leitstier = istLeitstier($m);
$anzahl = (int)einzeln("SELECT COUNT(*) AS n FROM group_members WHERE group_id = ? AND status = 'active'", [$gid])['n'];
$mitglieder = $istMitglied ? alle("SELECT u.id, u.display_name, m.role FROM group_members m JOIN users u ON u.id = m.user_id
                                   WHERE m.group_id = ? AND m.status = 'active' ORDER BY m.role = 'admin' DESC, u.display_name", [$gid]) : [];
$anfragen = $leitstier ? alle("SELECT u.id, u.display_name FROM group_members m JOIN users u ON u.id = m.user_id
                               WHERE m.group_id = ? AND m.status = 'pending' ORDER BY m.created_at", [$gid]) : [];
$kannBeitreten = $m === null ? beitrittsStatus($herde, $code) : null;

seitenKopf($herde['name']);
?>
<header class="herde-kopf">
  <h1><?= e($herde['name']) ?></h1>
  <p class="leise"><?= $herde['region'] ? e($herde['region']) . ' · ' : '' ?><?= te('herden.mitglieder', ['n' => $anzahl]) ?></p>
  <?php if ($herde['description']): ?><p class="beschreibung"><?= nl2br(e($herde['description'])) ?></p><?php endif; ?>

  <?php if ($m === null): ?>
    <?php if ($kannBeitreten !== null): ?>
      <form method="post"><?= csrfFeld() ?><input type="hidden" name="aktion" value="beitreten">
        <?php if ($code !== null): ?><input type="hidden" name="code" value="<?= e($code) ?>"><?php endif; ?>
        <button type="submit"><?= te($kannBeitreten === 'active' ? 'herde.beitreten' : 'herde.anfragen') ?></button></form>
    <?php else: ?>
      <p class="leise"><?= te('herde.nur_einladung') ?></p>
    <?php endif; ?>
  <?php elseif ($m['status'] === 'pending'): ?>
    <p class="meldung meldung-info"><?= te('herde.anfrage_offen') ?></p>
  <?php endif; ?>
</header>

<?php if ($istMitglied):
  require_once __DIR__ . '/traenke_lib.php';
  $ungelesen = traenkeUngelesen($gid, $uid);
  $letzte = alle('SELECT id, title, last_post_at FROM herd_topics WHERE group_id = ? AND deleted_at IS NULL ORDER BY last_post_at DESC LIMIT 3', [$gid]); ?>
<section class="traenke-teaser">
  <div class="kopfzeile">
    <h2><?= te('traenke.titel') ?><?php if ($ungelesen > 0): ?> <span class="tt-neu"><?= te('traenke.n_neu', ['n' => $ungelesen]) ?></span><?php endif; ?></h2>
    <a class="knopf" href="/traenke.php?s=<?= e(rawurlencode($herde['slug'])) ?>"><?= te('traenke.oeffnen') ?></a>
  </div>
  <?php if ($letzte): ?><ul class="liste"><?php foreach ($letzte as $lt): ?>
    <li><a href="/traenke_thema.php?id=<?= (int)$lt['id'] ?>"><?= e($lt['title']) ?></a> <span class="leise">· <?= e(relativeZeit($lt['last_post_at'])) ?></span></li>
  <?php endforeach; ?></ul>
  <?php else: ?><p class="leise"><?= te('traenke.leer') ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section>
  <h2><?= te('herde.ausfahrten') ?></h2>
  <?php if ($istMitglied):
    $touren = alle("SELECT id, title, distance_m FROM tours WHERE owner_group_id = ? AND visibility IN ('group','public') AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 20", [$gid]); ?>
    <p class="leise"><?= te('herde.ausfahrten_bald') ?></p>
    <ul class="liste">
      <?php foreach ($touren as $tr): ?><li><a href="/tour.php?id=<?= (int)$tr['id'] ?>"><?= e($tr['title']) ?></a> <span class="leise">· <?= number_format($tr['distance_m'] / 1000, 1, ',', '.') ?> km</span></li><?php endforeach; ?>
    </ul>
    <p><a class="knopf zweit" href="/tour_planen.php?herde=<?= $gid ?>"><?= te('herde.tour_neu') ?></a></p>
  <?php else: ?>
    <p class="leise"><?= te('herde.mitglieder_nur') ?></p>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('herde.mitglieder_titel') ?></h2>
  <?php if (!$istMitglied): ?>
    <p class="leise"><?= te('herde.mitglieder_nur') ?></p>
  <?php else: ?>
    <ul class="liste">
    <?php foreach ($mitglieder as $p): ?>
      <li><?= e($p['display_name']) ?><?= (int)$p['id'] === $uid ? ' (' . te('herde.du') . ')' : '' ?>
        <?php if ($p['role'] === 'admin'): ?><span class="marke-klein"><?= te('herde.leitstier') ?></span><?php endif; ?>
        <?php if ($leitstier && (int)$p['id'] !== $uid): ?>
          <span class="aktionen">
            <?php if ($p['role'] !== 'admin'): ?>
              <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="aktion" value="befoerdern"><input type="hidden" name="user" value="<?= (int)$p['id'] ?>"><button class="link"><?= te('herde.befoerdern') ?></button></form>
            <?php endif; ?>
            <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="aktion" value="entfernen"><input type="hidden" name="user" value="<?= (int)$p['id'] ?>"><button class="link gefahr"><?= te('herde.entfernen') ?></button></form>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($leitstier): ?>
<section class="verwaltung">
  <?php if ($anfragen): ?>
    <h2><?= te('herde.anfragen_titel') ?></h2>
    <ul class="liste">
    <?php foreach ($anfragen as $a): ?>
      <li><?= e($a['display_name']) ?>
        <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="aktion" value="annehmen"><input type="hidden" name="user" value="<?= (int)$a['id'] ?>"><button class="link"><?= te('herde.annehmen') ?></button></form>
        <form method="post" class="inline"><?= csrfFeld() ?><input type="hidden" name="aktion" value="ablehnen"><input type="hidden" name="user" value="<?= (int)$a['id'] ?>"><button class="link gefahr"><?= te('herde.ablehnen') ?></button></form>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2><?= te('herde.einladung') ?></h2>
  <p class="leise"><?= te('herde.einladung_hint') ?></p>
  <p><input class="kopierfeld" readonly value="<?= e(einladungsLink($herde)) ?>" aria-label="<?= te('herde.einladung') ?>"></p>
  <form method="post"><?= csrfFeld() ?><input type="hidden" name="aktion" value="code_neu"><button class="link"><?= te('herde.einladung_neu') ?></button></form>

  <h2><?= te('herde.einstellungen') ?></h2>
  <form method="post" class="formular">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="einstellungen">
    <?php $w = ['name' => $herde['name'], 'beschreibung' => (string)$herde['description'], 'region' => (string)$herde['region'],
                'sichtbarkeit' => $herde['discoverability'], 'beitritt' => $herde['join_policy']];
          require __DIR__ . '/herde_formular.php'; ?>
    <button type="submit"><?= te('herde.speichern') ?></button>
  </form>
</section>
<?php endif; ?>

<?php if ($m !== null): ?>
<form method="post" class="abseits"><?= csrfFeld() ?><input type="hidden" name="aktion" value="verlassen"><button class="link gefahr"><?= te('herde.verlassen') ?></button></form>
<?php endif; ?>
<?php seitenFuss();
