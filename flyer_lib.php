<?php
/**
 * Flyer (A4) for a ride: the page is HTML/CSS in millimetres, printed or saved as PDF by the browser. The brush strokes are
 * generated here as SVG polygons with ragged edges (deterministic per seed), so every flyer looks hand-made but identical when reloaded.
 */
declare(strict_types=1);

/** Ragged brush stroke inside the box x, y, w, h (mm); slant shifts the top edge. Returns an SVG <path>. */
function brushStroke(float $x, float $y, float $w, float $h, float $slant, int $seed, string $color, float $opacity = 1.0, float $rough = 1.0): string
{
    mt_srand($seed);
    $j = fn(float $a) => (mt_rand(-1000, 1000) / 1000) * $a * $rough;
    $pts = [];
    $n = max(8, (int)round($w / 4));
    // top edge: left to right, with a few bristle spikes at the ends
    for ($i = 0; $i <= $n; $i++) {
        $t = $i / $n;
        $pts[] = [$x + $slant + $t * $w + $j(0.6), $y + $j(1.0) + ($i === 0 || $i === $n ? $j(2.5) : 0)];
    }
    // right end: ragged, a stroke tail pulled out
    $pts[] = [$x + $w + $slant * 0.5 + 3 + $j(1.5), $y + $h * 0.25 + $j(1.2)];
    $pts[] = [$x + $w + $slant * 0.2 + 6 + $j(2), $y + $h * 0.5 + $j(1.5)];
    $pts[] = [$x + $w + $slant * 0.1 + 1.5 + $j(1.5), $y + $h * 0.75 + $j(1.2)];
    // bottom edge: right to left
    for ($i = $n; $i >= 0; $i--) {
        $t = $i / $n;
        $pts[] = [$x + $t * $w + $j(0.7), $y + $h + $j(1.1) + ($i === 0 || $i === $n ? $j(2.5) : 0)];
    }
    // left end: ragged with a pulled bristle
    $pts[] = [$x - 3 + $j(1.5), $y + $h * 0.7 + $j(1.2)];
    $pts[] = [$x - 6 + $slant * 0.5 + $j(2), $y + $h * 0.45 + $j(1.5)];
    $pts[] = [$x - 1.5 + $slant * 0.8 + $j(1.5), $y + $h * 0.2 + $j(1.2)];
    $d = 'M' . implode(' L', array_map(fn($p) => round($p[0], 2) . ' ' . round($p[1], 2), $pts)) . ' Z';
    return '<path d="' . $d . '" fill="' . $color . '"' . ($opacity < 1 ? ' fill-opacity="' . $opacity . '"' : '') . '/>';
}

/** Simple line icons (white strokes) for the info circles and the footer: calendar, pin, people, route, group, leaf, map, smile, scooter, link */
function flyerIcon(string $name, string $stroke = '#fff'): string
{
    $paths = [
        'calendar' => '<rect x="4" y="6" width="24" height="22" rx="3"/><path d="M4 13h24M10 3v6M22 3v6"/><path d="M10 18h2M15 18h2M20 18h2M10 23h2M15 23h2M20 23h2"/>',
        'pin'      => '<path d="M16 29s9-8.5 9-16a9 9 0 0 0-18 0c0 7.5 9 16 9 16z"/><circle cx="16" cy="13" r="3.5"/>',
        'people'   => '<circle cx="16" cy="11" r="4.5"/><path d="M7 27c0-5 4-8 9-8s9 3 9 8"/><circle cx="6.5" cy="13" r="3"/><circle cx="25.5" cy="13" r="3"/><path d="M1 24c0-3.5 2.5-5.5 5.5-5.5M31 24c0-3.5-2.5-5.5-5.5-5.5"/>',
        'route'    => '<circle cx="8" cy="24" r="3"/><circle cx="24" cy="7" r="3"/><path d="M11 24h9a4 4 0 0 0 0-8h-8a4 4 0 0 1 0-8h9" stroke-dasharray="2.5 2.5"/>',
        'leaf'     => '<path d="M6 26C6 13 14 6 27 5c0 12-6 20-18 20"/><path d="M6 27c4-7 9-11 15-14"/>',
        'map'      => '<path d="M3 8l8-3 10 3 8-3v19l-8 3-10-3-8 3z"/><path d="M11 5v19M21 8v19"/>',
        'smile'    => '<circle cx="16" cy="16" r="12"/><circle cx="11.5" cy="13" r="1" fill="' . $stroke . '"/><circle cx="20.5" cy="13" r="1" fill="' . $stroke . '"/><path d="M9.5 19.5c2 3.5 11 3.5 13 0"/>',
        'scooter'  => '<circle cx="7" cy="25" r="3.5"/><circle cx="26" cy="25" r="3.5"/><path d="M7 25h16l-3-18h-4M20 7h5"/>',
        'link'     => '<path d="M13 19a5 5 0 0 0 7 0l5-5a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M19 13a5 5 0 0 0-7 0l-5 5a5 5 0 0 0 7 7l1.5-1.5"/>',
    ];
    return '<svg viewBox="0 0 32 32" fill="none" stroke="' . $stroke . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Weekday + date + time in the language of the flyer, e.g. "So., 11.10.2026, 12:30 Uhr" */
function flyerWhen(DateTimeImmutable $d, string $lang): string
{
    if ($lang === 'de') {
        return ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'][(int)$d->format('w')] . ', ' . $d->format('d.m.Y, H:i') . ' Uhr';
    }
    return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int)$d->format('w')] . ', ' . $d->format('j M Y, H:i');
}
