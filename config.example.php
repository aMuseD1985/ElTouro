<?php
/**
 * ElTouro App – Konfiguration. Nach config.php kopieren und ausfüllen.
 * Pro Umgebung (beta, test, später live) eine eigene config.php und eine EIGENE Datenbank.
 */
return [
    // 'beta' | 'test' | 'live' – steuert Hinweisband, noindex und Zugangsschutz-Standard
    'env' => 'beta',

    // Zeigt Fehlermeldungen direkt im Browser – nur in beta/test wirksam, nie in live.
    // Zum Einrichten auf true, danach wieder false.
    'debug' => true,

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=DEINE_BETA_DB;charset=utf8mb4',
        'user' => 'DEIN_DB_USER',
        'pass' => 'DEIN_DB_PASSWORT',
    ],

    'base_url' => 'https://beta.eltouro.de',

    // Zufälliger Schlüssel zum Signieren von Cookies: php -r 'echo bin2hex(random_bytes(32)), "\n";'
    'app_secret' => '',

    // Vorab-Zugangsschutz für beta/test. Ein gemeinsames Passwort für alle Tester.
    // Hash: php -r 'echo password_hash("DAS_TESTER_PASSWORT", PASSWORD_DEFAULT), "\n";'
    'zugang' => [
        'aktiv'         => true,
        'passwort_hash' => '',
    ],

    'mail_from'      => 'hallo@eltouro.de',
    'mail_from_name' => 'ElTouro Beta',
    'smtp' => [
        'host'       => 'wXXXXXXX.kasserver.com',
        'port'       => 587,
        'encryption' => 'tls',   // 'tls' | 'ssl' | 'none' (nur lokal)
        'user'       => 'hallo@eltouro.de',
        'pass'       => '',
        'debug'      => false,
    ],

    // E-Mail-Adresse, die beim ersten Login automatisch Plattform-Admin wird
    'erster_admin' => 'marco@example.de',

    'migrate_key' => '',

    // Betriebswerkzeuge im Admin (Deployment, Backup, Restore). In live bewusst entscheiden.
    'ops' => [
        'aktiv'       => true,
        'max_backups' => 10,
    ],

    // Kartenkacheln. Für beta/test sind die OSM-Kacheln ok (wenig Verkehr, mit Namensnennung).
    // Vor dem Livegang auf einen Anbieter wie MapTiler umstellen, z. B.
    // 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=DEIN_KEY'
    'karte' => [
        'kacheln'     => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende',
    ],

    // BRouter mit E-Scooter-Profilen (auf dem Pi, erreichbar per Cloudflare Tunnel).
    // Leer = Planer verbindet Wegpunkte gerade (Freihand) – zum Ausprobieren ohne Router.
    'brouter' => [
        'url'         => '',                 // z. B. 'https://brouter.eltouro.de/brouter'
        'profil'      => 'escooter',
        'profil_2027' => 'escooter-2027',
        'timeout'     => 10,
    ],
];
