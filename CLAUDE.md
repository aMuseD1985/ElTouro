# ElTouro – Übergabe für Claude Code

Diese Datei ist der Einstiegspunkt für jede Session. Erst lesen, dann arbeiten. Stand: 28.09.2026.
Ansprechpartner und Product Owner: Marco. Kommunikation auf Deutsch, direkt und technisch.

## Worum es geht

ElTouro ist eine Community-Plattform für E-Scooter-Fahrer im DACH-Raum, Start in Deutschland. Nutzer planen Touren, gründen Herden (Gruppen), tauschen sich im Forum und in der Tränke ihrer Herde aus und verabreden sich zu Ausfahrten. Zuerst als PWA, später als native App. Claims: DE „Finde deine Tour. Finde deine Herde.“, EN „Find your ride. Find your crew.“.

Der Stand: Konten, Herden mit Leitstier-Verwaltung, Routenplaner mit BRouter-Anbindung, Forum, Tränke (Herden-Community im Discourse-light-Stil), Meldungen nach DSA, Rechtstexte, Admin-Backend mit Deployment, Backup und Restore. Ausfahrten mit Termin und Anmeldung sind der nächste große Baustein.

## Umgebungen und Betrieb

Hosting bei all-inkl (Premium, Shared Hosting, PHP 8.5, MariaDB 10.11). Drei Orte:

- **eltouro.de** – Landingpage mit Warteliste. Eigene, separate Codebasis (`eltouro-warteliste`), wird per FTP gepflegt.
- **beta.eltouro.de** – Entwicklungsstand dieser App.
- **test.eltouro.de** – Stand kurz vor Release.

Jede Umgebung hat eine eigene `config.php` und eine eigene Datenbank. `env` in der Config (`beta`/`test`/`live`) steuert das farbige Hinweisband, `noindex` und den Zugangsschutz (Tester-Passwort, signiertes Cookie). Marco spielt neue Stände über **Admin → Betrieb → Deployment** ein: ZIP hochladen, Vorschau mit Diff pro Datei, Auswahl per Häkchen, dann Vollbackup, Einspielen und Migration. Es wird dabei nie etwas gelöscht, und `config.php` sowie `daten/` werden nie angefasst. Lieferform an Marco ist deshalb immer ein ZIP mit dem Ordner `eltouro-app/` (ohne `config.php`, ohne Inhalte von `daten/`).

Routing läuft über BRouter auf einem Raspberry Pi 4 in Marcos Heimnetz, zweiter Pi als Reserve an einem anderen Anschluss, erreichbar per Cloudflare Tunnel. Die E-Scooter-Profile `escooter.brf` (aktuelle eKFV) und `escooter-2027.brf` (Regeln ab 01.03.2027) sind Entwürfe und noch nicht gegen echte Strecken getestet. Ist `brouter.url` leer, verbindet der Planer Punkte gerade und markiert sie als „freihand“.

## Tech-Stack und harte Regeln

Plain PHP ab 8.1 (läuft auf 8.5), MariaDB über PDO, Vanilla JS, kein Framework, kein Composer, kein Build-Schritt. Externe Bibliotheken nur als geprüfte, vendorte Einzeldateien mit `VERSION` und `LICENSE`: PHPMailer 6.12 (`lib/PHPMailer`), Leaflet 1.9.4 (`assets/vendor/leaflet`), Schriften Barlow und Barlow Condensed als WOFF2 (`assets/fonts`). **Keine CDNs, keine Google Fonts, keine Drittanbieter-Requests aus dem Browser** – einzige Ausnahme sind die Kartenkacheln aus `karte.kacheln`. Die Content-Security-Policy wird in `kern.php` gesetzt (`sendeSicherheitsHeader()`), `script-src 'self'`, also keine Inline-Skripte und keine `onclick`-Attribute.

Code-Stil: deutsche Funktions- und Variablennamen (`ladeHerde()`, `pruefeCsrf()`, `$mitgliedschaft`), englische Tabellen- und Spaltennamen. Kommentare auf Deutsch und nur dort, wo das Warum nicht offensichtlich ist.

## Aufbau

Jede Seite beginnt mit `declare(strict_types=1); require __DIR__ . '/bootstrap.php';`.

`bootstrap.php` ist bewusst in altem PHP geschrieben: Versionsprüfung, `config.php` laden, Fehlerbehandlung mit lesbarer Fehlerseite (Details nur bei `'debug' => true` in beta/test), dann `require kern.php`. `kern.php` enthält DB-Helfer (`db()`, `einzeln()`, `alle()`, `ausfuehren()`), Sessions (eigener Ordner `daten/sitzungen`), Zugangsschutz, CSP, Sprache, `t()`/`te()`/`e()`, CSRF (`csrfFeld()`, `pruefeCsrf()`), Nutzer (`aktuellerNutzer()`, `mussEingeloggtSein()`, `mussAdminSein()`), `weiterleiten()` (nur relative Ziele), Einstellungen, das Mini-Markdown `formatiereText()` und das Layout (`seitenKopf()`, `seitenFuss()`).

Seiten, die ohne Tester-Passwort erreichbar sein müssen (`zugang.php`, `migrate.php`, `pruefen.php`), setzen vor dem `require` die Konstante `OHNE_ZUGANGSSCHUTZ`.

| Bereich | Dateien |
|---|---|
| Konto | `registrieren.php`, `bestaetigen.php`, `login.php`, `logout.php`, `passwort_vergessen.php`, `passwort_neu.php`, `konto.php` (Tokens, Mails), `profil.php` |
| Herden | `herden_lib.php` (Zugriffsschicht), `herden.php`, `herde_neu.php`, `herde.php`, `herde_formular.php` |
| Touren | `touren_lib.php` (Zugriff, Geometrie-Prüfung, Kennzahlen), `touren.php`, `tour_planen.php`, `tour.php`, `tour_gpx.php`, `route.php` (BRouter-Proxy), `assets/planer.js`, `assets/tourkarte.js` |
| Forum | `forum_lib.php`, `forum.php`, `forum_kategorie.php`, `forum_thema.php`, `forum_neu.php` |
| Tränke | `traenke_lib.php`, `traenke.php`, `traenke_thema.php`, `traenke_api.php` (JSON), `assets/traenke.js` |
| Inhalte | `seite.php` (Platzhalter `{{betreiber_name}}` usw. aus den Einstellungen), `rechtstexte.php` (Erstfassung DE/EN) |
| Moderation | `melden.php`, `admin/meldungen.php` |
| Admin | `admin/index.php`, `seiten.php`, `einstellungen.php` (inkl. Betreiberdaten), `nutzer.php`, `forum.php`, `betrieb.php` |
| Betrieb | `betrieb_lib.php` (Backup, Restore, Paket-Vorschau, Diff, sicheres Entpacken), `migrationen.php`, `migrate.php`, `pruefen.php` |
| Texte | `lang.php` – reines `return [...]`, nichts danach |

## Zugriff und Sicherheit

Jede Sichtbarkeitsentscheidung läuft über die jeweilige Zugriffsschicht, nie über eigene Abfragen in Seiten. **Herden:** auffindbar oder geheim; geheime Herden liefern Außenstehenden einen 404 (kein 403, sonst verrät man die Existenz). Mitgliederliste und Tränke nur für aktive Mitglieder. Leitstier = `group_members.role = 'admin'` mit `status = 'active'`; der letzte Leitstier kann nicht austreten. **Touren:** `private` (Ersteller), `group` (aktive Herdenmitglieder), `public` (alle angemeldeten). Kennzahlen wie Länge, Anstieg, Start und Bounding Box rechnet immer der Server aus der Geometrie; Zahlen aus dem Browser werden ignoriert. **Forum:** lesen und schreiben für alle angemeldeten Nutzer, moderieren nur Plattform-Admins. **Tränke:** nur aktive Herdenmitglieder; moderieren Leitstiere und Admins.

Weitere feste Regeln: CSRF-Token in jedem Formular und als `X-CSRF`-Header bei jedem `fetch`. Einmal-Tokens nur als SHA-256 in der DB. Passwörter mit `password_hash`, Session-Wechsel beim Login. Gleiche Antworten bei „Adresse existiert/existiert nicht“. Login-Bremse pro IP (Versuche nach 24 h gelöscht). Nutzertexte nur über `formatiereText()`: alles wird escaped, erlaubt sind `##`, `###`, `- `, `**fett**` und Links mit `https://`, `mailto:` oder `/`. Nie HTML aus Nutzereingaben ausgeben. Löschen von Inhalten ist weich (`deleted_at`). Kritische Admin-Aktionen (Deployment, Restore) verlangen das Passwort erneut.

## Datenbank

Schema-Änderungen ausschließlich in `migrationen.php`, Funktion `fuehreMigrationenAus()`. Jeder Schritt ist idempotent (prüft selbst, ob er nötig ist). **Neue Schritte immer unten anhängen, bestehende nie umschreiben.** Ausgeführt wird per Admin → Betrieb oder `/migrate.php?key=…`. Tabellen: `users`, `user_profiles`, `auth_tokens`, `login_attempts`, `rider_groups`, `group_members`, `pages`, `settings`, `tours`, `forum_categories`, `forum_threads`, `forum_posts`, `reports`, `herd_topics`, `herd_posts`, `herd_reactions`, `herd_reads`. `groups` ist in MySQL 8 reserviert, deshalb `rider_groups`. Keine nativen Spatial-Typen: Geometrie als GeoJSON, Umkreissuche über indizierte Bounding-Box-Spalten.

## Sprache, Vokabular, Tonalität

Die App ist von Anfang an Deutsch und Englisch; jeder Schlüssel in `lang.php` existiert in beiden Sprachen (prüfen!). Das Admin-Backend ist bewusst nur deutsch. Wir duzen. **Kein Gendern** – ausdrückliche Vorgabe von Marco (Fahrer, Teilnehmer, Organisator). Sätze nie aus Bausteinen zusammensetzen, Platzhalter wie `{n}` verwenden.

| Bedeutung | Deutsch | Englisch | Code |
|---|---|---|---|
| Gruppe | Herde | crew | `rider_groups` |
| Gruppen-Admin | Leitstier | crew lead | `group_members.role = 'admin'` |
| Herden-Community | Tränke | crew talk | `herd_*` |
| Strecke | Tour | route | `tours` |
| Termin mit Treffpunkt | Ausfahrt | ride | (kommt: `tour_events`) |

Marke: „ElTouro“ in einem Wort. Farben: Nacht `#14263F`, Jeans `#2F5E8C`, Gold `#D7A845`, Kreide `#E8ECF1`.

## Rechtliche Leitplanken (Produktentscheidungen, nicht verhandelbar)

ElTouro vermittelt, veranstaltet aber keine Fahrten; nichts als „geprüft“ oder „sicher“ kennzeichnen, Schwierigkeit ist „Einschätzung des Erstellers“. Routing meidet Fußwege, Fußgängerzonen, Wald- und Feldwege (auch nach 2027), Autobahnen und Kraftfahrstraßen; „Radverkehr frei“ und Nebeneinanderfahren erst ab 01.03.2027. Öffentliche Ausfahrten nur mit zugelassenen Scootern; Teilnehmergrenze ist Pflicht, ab etwa 15–20 Teilnehmern Hinweis auf § 29 StVO mit Bestätigung durch den Organisator. Mindestalter 16. Standort aus dem Browser nur zum Verschieben der Karte, nie speichern. Fotos (kommen später): EXIF/GPS beim Upload entfernen, nur in der Herde sichtbar, Teilen nur über widerrufbaren Link. Die Datenschutzerklärung (`rechtstexte.php`) beschreibt das tatsächliche Verhalten – **wer Datenflüsse ändert, passt sie mit an.**

## Lokal entwickeln und testen

`php -S` ignoriert `.htaccess`: Rewrites (`/impressum`) und Dateisperren lassen sich lokal nicht testen. Bewährtes Vorgehen: MariaDB lokal starten, eine Test-`config.php` mit `'smtp' => ['host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none', 'user' => '', 'pass' => '']`, Mails mit `aiosmtpd` abfangen, für Routing einen kleinen Fake-BRouter (PHP-Skript, das eine GeoJSON-Linie zurückgibt) unter `brouter.url` eintragen. Abläufe per `curl` mit Cookie-Jar durchspielen (CSRF-Token aus dem Formular lesen), JavaScript-Features mit Playwright prüfen. Vor jeder Lieferung: `php -l` auf alle Dateien und ein Abgleich, dass `lang.php` in beiden Sprachen dieselben Schlüssel hat.

Hintergrundprozesse (Datenbank, `php -S`) mit `setsid nohup … &` starten, sonst hängt die Shell.

## Stolperfallen, die schon einmal passiert sind

- PHP läuft bei all-inkl als CGI: `SCRIPT_NAME` ist unzuverlässig (führte zur Endlosschleife am Zugangsschutz). Ausnahmen deshalb per Konstante.
- HTTPS nicht per `.htaccess` erzwingen, sondern im KAS („SSL erzwingen“), sonst Weiterleitungsschleife.
- Sessions im gemeinsamen Temp-Ordner des Hosters gingen verloren, deshalb `daten/sitzungen`.
- `curl_close()` ist ab PHP 8.5 veraltet.
- Nach `];` in `lang.php` darf nichts mehr stehen, sonst ist die ganze App kaputt.
- CSS-Regeln mit `display` überschreiben das `hidden`-Attribut; global gilt `[hidden] { display: none !important; }`.
- In Mails werden Links quoted-printable umbrochen; beim Testen den Mail-Text dekodieren, nicht roh greppen.

## Backlog (Reihenfolge mit Marco abstimmen)

1. **Ausfahrten:** Tour als Termin anbieten (Datum, Treffpunkt, Kapazität, Warteliste, Fahrprofil, Mindestanforderung „zugelassener Scooter“), Anmeldung mit Einwilligung zu Foto/Video, Hinweis § 29 StVO, Anzeige auf Herdenseite und in der Tränke.
2. **Konto selbst löschen** im Profil (die Datenschutzerklärung verspricht Löschung, aktuell nur per Mail).
3. **BRouter anbinden** und die Profile mit echten Strecken prüfen; vor live Kacheln auf MapTiler umstellen.
4. **Fuhrpark** im Profil (Hersteller, Modell, Straßenzulassung ja/nein).
5. **GPS-Aufzeichnung** aus Marcos GPS-Rallye-App übernehmen (`watchPosition` + Wake Lock), mit Privatzonen um Start/Ziel.
6. Forum und Tränke: Beiträge bearbeiten, Benachrichtigungen (Mail-Digest), Erwähnungen.
7. Fotowand pro Herde (aus der Rallye-App, inkl. EXIF-Entfernung und `wall_status`-Moderation).
8. Deployment-Vorschau: Häkchen beim Wechsel zwischen Diffs merken.

## Verwandte Projekte

Marcos GPS-Rallye (games.anderheyden.de/gps) ist dieselbe Technikfamilie und Quelle für Karte, Aufzeichnung, Spielleitung, Foto-Pipeline und `Lang`-Klasse. Deren `Scope`-Mandantenlogik (`null`-Mandant sieht alles) bewusst **nicht** übernehmen – ElTouro nutzt die Zugriffsschichten oben.
