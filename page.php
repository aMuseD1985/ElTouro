<?php
/**
 * Maintained content pages. Reachable as /imprint, /privacy, /terms, /page/slug
 * (rewrite in .htaccess) or /page/slug. Publicly readable.
 * Placeholders {{…}} are filled from Admin → Einstellungen and the config.
 */
declare(strict_types=1);
const PUBLIC_PAGE = true;   // legal texts / shared tours: a normal web page, no app lock
const NO_CONSENT_NEEDED = true;   // legal texts must be readable before consenting
require __DIR__ . '/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['s'] ?? ''));
$page = dbOne('SELECT title, body, updated_at FROM pages WHERE slug = ? AND locale = ?', [$slug, $LANG])
     ?? dbOne("SELECT title, body, updated_at FROM pages WHERE slug = ? AND locale = 'de'", [$slug]);

if ($page === null) {
    notFound();
}

/**
 * The map services of all styles riders can choose (mapStyles() in core.php), so the privacy policy matches the
 * configuration: "Standard, Dunkel: OpenStreetMap Foundation, …; Radwege: OpenStreetMap France, …".
 * Returns [list, notes on transfers to third countries].
 */
function mapService(): array
{
    global $LANG;
    $de = $LANG === 'de';
    $providers = [];
    foreach (mapStyles() as $style) {
        $host = tileHost((string)$style['tiles']);
        if (str_contains($host, 'maptiler')) {
            $name = 'MapTiler AG, Höfnerstrasse 98, 6314 Unterägeri, ' . ($de ? 'Schweiz' : 'Switzerland');
            $note = $de ? 'Für die Schweiz besteht ein Angemessenheitsbeschluss der EU-Kommission.' : 'An EU adequacy decision exists for Switzerland.';
        } elseif (str_ends_with($host, 'openstreetmap.fr')) {
            $name = 'OpenStreetMap France (' . ($de ? 'Verein, Frankreich' : 'association, France') . ')';
            $note = '';
        } elseif (str_contains($host, 'openstreetmap')) {
            $name = 'OpenStreetMap Foundation, St John’s Innovation Centre, Cowley Road, Cambridge, CB4 0WS, ' . ($de ? 'Vereinigtes Königreich' : 'United Kingdom');
            $note = $de ? 'Für das Vereinigte Königreich besteht ein Angemessenheitsbeschluss der EU-Kommission.' : 'An EU adequacy decision exists for the United Kingdom.';
        } else {
            $name = $host !== '' ? $host : '–';
            $note = '';
        }
        $providers[$name]['styles'][] = $style['label'];
        $providers[$name]['note'] = $note;
    }
    $list = [];
    $notes = [];
    foreach ($providers as $name => $p) {
        $list[] = implode(', ', $p['styles']) . ': ' . $name;
        if ($p['note'] !== '') {
            $notes[$p['note']] = true;
        }
    }
    return [implode('; ', $list), implode(' ', array_keys($notes))];
}

function fillPlaceholders(string $text, string $updated): string
{
    global $LANG;
    // Optional contact details: a line with an empty optional placeholder disappears instead of showing "[please enter]" (the e-mail address is the contact)
    foreach (['operator_phone'] as $optional) {
        if (setting($optional) === '') {
            $text = preg_replace('/^[^\n]*\{\{' . $optional . '\}\}[^\n]*\n?/m', '', $text) ?? $text;
        }
    }
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
