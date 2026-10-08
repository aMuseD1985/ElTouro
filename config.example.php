<?php
/**
 * ElTouro App – configuration. Copy to config.php and fill in.
 * One config.php per environment (beta, test, later live) and a SEPARATE database each.
 * Older configs with the German keys (zugang, karte, erster_admin, aktiv …) keep working.
 */
return [
    // 'beta' | 'test' | 'live' – controls the notice band, noindex and the access gate default
    'env' => 'beta',

    // Shows errors right in the browser – only effective in beta/test, never in live.
    // Set to true while setting up, then back to false.
    'debug' => true,

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=YOUR_BETA_DB;charset=utf8mb4',
        'user' => 'YOUR_DB_USER',
        'pass' => 'YOUR_DB_PASSWORD',
    ],

    'base_url' => 'https://beta.eltouro.de',

    // Random key for signing cookies: php -r 'echo bin2hex(random_bytes(32)), "\n";'
    'app_secret' => '',

    // Preview access gate for beta/test. One shared password for all testers.
    // Hash: php -r 'echo password_hash("THE_TESTER_PASSWORD", PASSWORD_DEFAULT), "\n";'
    'access' => [
        'enabled'       => true,
        'password_hash' => '',
    ],

    'mail_from'      => 'hallo@eltouro.de',
    'mail_from_name' => 'ElTouro Beta',
    'smtp' => [
        'host'       => 'wXXXXXXX.kasserver.com',
        'port'       => 587,
        'encryption' => 'tls',   // 'tls' | 'ssl' | 'none' (local only)
        'user'       => 'hallo@eltouro.de',
        'pass'       => '',
        'debug'      => false,
    ],

    // "Sign in with Google" – empty = button hidden. Google Cloud console → APIs & Services → Credentials →
    // OAuth client ID (web application), authorised redirect URI: <base_url>/auth/google/callback
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
    ],

    // Email address that automatically becomes platform admin on first confirmation
    'first_admin' => 'marco@example.de',

    // Key for /migrate and /check – also used by the GitHub Action after a test deployment
    'migrate_key' => '',

    // Operations tools in the admin area (deployment, backup, restore). Decide consciously for live.
    'ops' => [
        'enabled'     => true,
        'max_backups' => 10,
    ],

    // Map tiles. OSM tiles are fine for beta/test (little traffic, with attribution).
    // Before going live switch to a provider like MapTiler, e.g.
    // 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=YOUR_KEY'
    'map' => [
        'tiles'       => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende',
    ],

    // BRouter with e-scooter profiles (on the Pi, reachable via Cloudflare Tunnel).
    // Empty = the planner connects waypoints with straight lines (freehand) – for trying it out without a router.
    'brouter' => [
        'url'          => '',                 // e.g. 'https://brouter.eltouro.de/brouter'
        'profile'      => 'escooter',
        'profile_2027' => 'escooter-2027',
        'timeout'      => 30,                 // seconds per request; long routes on a home PC need a while
        'max_leg_km'   => 50,                 // longest crow-flies distance between two waypoints
    ],

    // Place names for tour name suggestions (server-side reverse geocoding, see tour_name_lib.php).
    // Missing block = Nominatim of the OpenStreetMap Foundation; '' = off (names without a place).
    'geocoder' => [
        'url' => 'https://nominatim.openstreetmap.org/reverse',
    ],
];
