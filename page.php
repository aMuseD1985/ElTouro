<?php
/**
 * Maintained content pages. Reachable as /imprint, /privacy, /terms, /page/slug
 * (rewrite in .htaccess) or /page/slug. Publicly readable.
 * Placeholders {{…}} are filled from Admin → Einstellungen and the config.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['s'] ?? ''));
$page = dbOne('SELECT title, body, updated_at FROM pages WHERE slug = ? AND locale = ?', [$slug, $LANG])
     ?? dbOne("SELECT title, body, updated_at FROM pages WHERE slug = ? AND locale = 'de'", [$slug]);

if ($page === null) {
    notFound();
}

/** Derive the map service from the tile URL so the privacy policy matches the configuration */
function mapService(): array
{
    global $CONFIG, $LANG;
    $host = (string)parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], (string)($CONFIG['map']['tiles'] ?? '')), PHP_URL_HOST);
    $de = $LANG === 'de';
    if (str_contains($host, 'openstreetmap')) {
        return ['OpenStreetMap Foundation, St John’s Innovation Centre, Cowley Road, Cambridge, CB4 0WS, ' . ($de ? 'Vereinigtes Königreich' : 'United Kingdom'),
                $de ? 'Für das Vereinigte Königreich besteht ein Angemessenheitsbeschluss der EU-Kommission.' : 'An EU adequacy decision exists for the United Kingdom.'];
    }
    if (str_contains($host, 'maptiler')) {
        return ['MapTiler AG, Höfnerstrasse 98, 6314 Unterägeri, ' . ($de ? 'Schweiz' : 'Switzerland'),
                $de ? 'Für die Schweiz besteht ein Angemessenheitsbeschluss der EU-Kommission.' : 'An EU adequacy decision exists for Switzerland.'];
    }
    return [$host !== '' ? $host : '–', ''];
}

function fillPlaceholders(string $text, string $updated): string
{
    global $LANG;
    $missing = $LANG === 'de' ? '[bitte in Admin → Einstellungen eintragen]' : '[not yet provided]';
    [$service, $serviceNote] = mapService();
    $values = [
        'operator_name'         => setting('operator_name'),
        'operator_address'      => setting('operator_address'),
        'operator_email'        => setting('operator_email'),
        'operator_phone'        => setting('operator_phone'),
        'supervisory_authority' => setting('supervisory_authority'),
        'log_days'              => setting('log_days', '7'),
        'map_service'           => $service,
        'map_service_note'      => $serviceNote,
        'updated'               => $updated,
    ];
    return preg_replace_callback('/\{\{([a-z_]+)\}\}/', function ($m) use ($values, $missing) {
        if (!array_key_exists($m[1], $values)) {
            return $m[0];
        }
        return $values[$m[1]] !== '' || $m[1] === 'map_service_note' ? $values[$m[1]] : $missing;
    }, $text);
}

$updated = (new DateTimeImmutable($page['updated_at']))->format($LANG === 'de' ? 'd.m.Y' : 'j F Y');
pageHeader($page['title']);
?>
<article class="text narrow-text">
  <h1><?= e($page['title']) ?></h1>
  <?= trim($page['body']) === '' ? '<p class="muted">' . te('page.empty') . '</p>' : formatText(fillPlaceholders($page['body'], $updated)) ?>
</article>
<?php pageFooter();
