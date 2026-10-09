<?php
/** GET /ride/<id>/ics – the ride as a calendar entry (RFC 5545). Only for riders who may see the ride. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rides_lib.php';
$me = requireLogin();
$ride = loadRide((int)($_GET['id'] ?? 0));
if ($ride === null || !canSeeRide($ride, (int)$me['id'])) {
    notFound();
}
$tour = dbOne('SELECT distance_m FROM tours WHERE id = ?', [$ride['tour_id']]);
session_write_close();

$start = new DateTimeImmutable($ride['starts_at'], new DateTimeZone('UTC'));
// Length: riding time at 18 km/h plus half an hour for gathering and breaks, at least one hour
$minutes = max(60, (int)round(((int)($tour['distance_m'] ?? 0)) / 1000 / 18 * 60) + 30);
$end = $start->modify('+' . $minutes . ' minutes');
$url = baseUrl() . '/ride/' . (int)$ride['id'];

$esc = fn(string $s) => str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\;", "\\,", "\\n", "\\n", "\\n"], $s);
$fold = function (string $line): string {
    $out = '';
    while (strlen($line) > 74) {
        $cut = 74;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) { $cut--; }   // do not split a UTF-8 character
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
};
$desc = trim(($ride['description'] ?? '') . "\n\n" . t('ride.ics_link') . ' ' . $url);
$lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ElTouro//Ausfahrten//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:ride-' . (int)$ride['id'] . '@' . (parse_url(baseUrl(), PHP_URL_HOST) ?: 'eltouro'),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'SEQUENCE:' . (int)strtotime((string)$ride['updated_at'] . ' UTC'),
    'DTSTART:' . $start->format('Ymd\THis\Z'),
    'DTEND:' . $end->format('Ymd\THis\Z'),
    'SUMMARY:' . $esc((string)$ride['title']),
    'LOCATION:' . $esc((string)$ride['meeting_point']),
    'DESCRIPTION:' . $esc($desc),
    'URL:' . $url,
    'STATUS:' . ($ride['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
];
if ($ride['meeting_lat'] !== null && $ride['meeting_lng'] !== null) {
    $lines[] = sprintf('GEO:%.6F;%.6F', (float)$ride['meeting_lat'], (float)$ride['meeting_lng']);
}
// A reminder one hour before
array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc((string)$ride['title']), 'TRIGGER:-PT1H', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR');

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="eltouro-ausfahrt-' . (int)$ride['id'] . '.ics"');
header('Cache-Control: no-store');
echo implode("\r\n", array_map($fold, $lines)) . "\r\n";
