<?php
/**
 * Gepflegte Inhaltsseiten. Erreichbar als /impressum, /datenschutz, /nutzungsbedingungen
 * (Rewrite in .htaccess) oder /seite.php?s=kurzname. Öffentlich lesbar.
 * Platzhalter {{…}} werden aus Admin → Einstellungen und der Config gefüllt.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['s'] ?? ''));
$seite = einzeln('SELECT title, body, updated_at FROM pages WHERE slug = ? AND locale = ?', [$slug, $LANG])
      ?? einzeln("SELECT title, body, updated_at FROM pages WHERE slug = ? AND locale = 'de'", [$slug]);

if ($seite === null) {
    http_response_code(404);
    seitenKopf(t('fehler.nicht_gefunden'));
    echo '<h1>' . te('fehler.nicht_gefunden') . '</h1>';
    seitenFuss();
    exit;
}

/** Kartendienst aus der Kachel-Adresse ableiten, damit die Datenschutzerklärung zur Konfiguration passt */
function kartendienst(): array
{
    global $CONFIG, $LANG;
    $host = (string)parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], (string)($CONFIG['karte']['kacheln'] ?? '')), PHP_URL_HOST);
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

function fuellePlatzhalter(string $text, string $stand): string
{
    global $LANG;
    $fehlt = $LANG === 'de' ? '[bitte in Admin → Einstellungen eintragen]' : '[not yet provided]';
    [$dienst, $dienstHinweis] = kartendienst();
    $werte = [
        'betreiber_name'      => einstellung('betreiber_name'),
        'betreiber_anschrift' => einstellung('betreiber_anschrift'),
        'betreiber_email'     => einstellung('betreiber_email'),
        'betreiber_telefon'   => einstellung('betreiber_telefon'),
        'aufsichtsbehoerde'   => einstellung('aufsichtsbehoerde'),
        'log_tage'            => einstellung('log_tage', '7'),
        'kartendienst'        => $dienst,
        'kartendienst_hinweis'=> $dienstHinweis,
        'stand'               => $stand,
    ];
    return preg_replace_callback('/\{\{([a-z_]+)\}\}/', function ($m) use ($werte, $fehlt) {
        if (!array_key_exists($m[1], $werte)) {
            return $m[0];
        }
        return $werte[$m[1]] !== '' || $m[1] === 'kartendienst_hinweis' ? $werte[$m[1]] : $fehlt;
    }, $text);
}

$stand = (new DateTimeImmutable($seite['updated_at']))->format($LANG === 'de' ? 'd.m.Y' : 'j F Y');
seitenKopf($seite['title']);
?>
<article class="text schmal-text">
  <h1><?= e($seite['title']) ?></h1>
  <?= trim($seite['body']) === '' ? '<p class="leise">' . te('seite.leer') . '</p>' : formatiereText(fuellePlatzhalter($seite['body'], $stand)) ?>
</article>
<?php seitenFuss();
