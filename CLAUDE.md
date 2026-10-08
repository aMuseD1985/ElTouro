# ElTouro – Übergabe für Claude Code

Diese Datei ist der Einstiegspunkt für jede Session. Erst lesen, dann arbeiten. Stand: 07.10.2026.
Ansprechpartner und Product Owner: Marco. Kommunikation auf Deutsch, direkt und technisch.

## Worum es geht

ElTouro ist eine Community-Plattform für E-Scooter-Fahrer im DACH-Raum, Start in Deutschland. Nutzer planen Touren, gründen Herden (Gruppen), tauschen sich im Forum und in der Tränke ihrer Herde aus und verabreden sich zu Ausfahrten. Zuerst als PWA, später als native App. Claims: DE „Finde deine Tour. Finde deine Herde.“, EN „Find your ride. Find your crew.“.

Der Stand: Konten (E-Mail/Passwort oder Google), Herden mit Leitstier-Verwaltung, Routenplaner mit BRouter-Anbindung, Touren teilen (öffentlicher Link `/s/<token>`, auch für private Touren, ohne Interna), **Ausfahrten** (Termin, Treffpunkt, Teilnehmergrenze, Warteliste, Foto-Einwilligung, § 29 StVO), Forum, Tränke (Herden-Community im Discourse-light-Stil), Meldungen nach DSA, Rechtstexte, Admin-Backend mit Deployment, Backup und Restore.

## Repository, Umgebungen und Deployment

Repo: `github.com/aMuseD1985/ElTouro`, Branch `main`, Wurzel ist der App-Ordner (`eltouro-app/`). Die Landingpage `eltouro-warteliste` ist nicht im Repo.

Hosting bei all-inkl (Premium, Shared Hosting, PHP 8.5, MariaDB 10.11). Drei Orte:

- **eltouro.de** – Landingpage mit Warteliste. Eigene, separate Codebasis (`eltouro-warteliste`), wird per FTP gepflegt.
- **beta.eltouro.de** – Entwicklungsstand dieser App.
- **test.eltouro.de** – Testserver.

**Jeder Push auf `main` geht per GitHub Action (`.github/workflows/deploy-test.yml`) direkt per FTPS auf den Testserver** und führt danach die Migration aus. Pushen heißt also deployen – vorher testen. Die Action löscht nichts und fasst `config.php`, `data/` und `daten/` nicht an. Für andere Umgebungen: `git archive --prefix=eltouro-app/ -o ../eltouro-app.zip HEAD` und unter **Admin → Betrieb → Deployment** einspielen (Vorschau mit Diff, Vollbackup, Migration, es wird nie etwas gelöscht).

Jede Umgebung hat eine eigene `config.php` und eine eigene Datenbank. `env` in der Config (`beta`/`test`/`live`) steuert das farbige Hinweisband, `noindex` und den Zugangsschutz (Tester-Passwort, signiertes Cookie `et_access`). Server-Configs dürfen noch die alten deutschen Schlüssel haben (`zugang`, `karte`, `erster_admin`, `aktiv` …) – `bootstrap.php` übersetzt sie (`eltouroNormalizeConfig()`).

Routing läuft über BRouter auf einem Raspberry Pi 4 in Marcos Heimnetz, zweiter Pi als Reserve an einem anderen Anschluss, erreichbar per Cloudflare Tunnel. Die E-Scooter-Profile `escooter.brf` (aktuelle eKFV) und `escooter-2027.brf` (Regeln ab 01.03.2027) sind Entwürfe und noch nicht gegen echte Strecken getestet. Ist `brouter.url` leer, verbindet der Planer Punkte gerade und markiert sie als „freehand“.

## Tech-Stack und harte Regeln

Plain PHP ab 8.1 (läuft auf 8.5), MariaDB über PDO, Vanilla JS, kein Framework, kein Composer, kein Build-Schritt. Externe Bibliotheken nur als geprüfte, vendorte Einzeldateien mit `VERSION` und `LICENSE`: PHPMailer 6.12 (`lib/PHPMailer`), Leaflet 1.9.4 (`assets/vendor/leaflet`), Schriften Barlow und Barlow Condensed als WOFF2 (`assets/fonts`). **Keine CDNs, keine Google Fonts, keine Drittanbieter-Requests aus dem Browser** – einzige Ausnahme sind die Kartenkacheln aus `map.tiles`. Die Content-Security-Policy wird in `core.php` gesetzt (`sendSecurityHeaders()`), `script-src 'self'`, also keine Inline-Skripte und keine `onclick`-Attribute.

**Code-Stil: alles Englisch** – Dateinamen, URLs, Funktionen, Variablen, Konstanten, CSS-Klassen, Formularfelder, `lang.php`-Schlüssel, Kommentare (`loadCrew()`, `checkCsrf()`, `$membership`, `.btn`, `crew.join`). Deutsch bleiben nur die deutschen UI-Texte in `lang.php`, das Admin-Backend (Oberfläche bewusst nur deutsch), Rechtstexte und Doku (README, diese Datei). Kommentare nur dort, wo das Warum nicht offensichtlich ist.

## Aufbau

Jede Seite beginnt mit `declare(strict_types=1); require __DIR__ . '/bootstrap.php';`.

`bootstrap.php` ist bewusst in altem PHP geschrieben: Versionsprüfung, `config.php` laden, alte Config-Schlüssel übersetzen, Fehlerbehandlung mit lesbarer Fehlerseite (Details nur bei `'debug' => true` in beta/test), dann `require core.php`. `core.php` enthält DB-Helfer (`db()`, `dbOne()`, `dbAll()`, `dbExec()`), `dataDir()` (legt `data/` immer mit sperrender `.htaccess` an), Sessions (`data/sessions`), Zugangsschutz, CSP, Sprache, `t()`/`te()`/`tl()`(Text in fremder Sprache, z. B. für Mails)/`e()`, CSRF (`csrfField()`, `checkCsrf()`, `checkCsrfHeader()`), Nutzer (`currentUser()`, `requireLogin()`, `requireAdmin()`), `redirect()` (nur relative Ziele), `flash()`, `notFound()`, `setting()`, das Mini-Markdown `formatText()` und das Layout (`pageHeader()`, `pageFooter()`).

Seiten, die ohne Tester-Passwort erreichbar sein müssen (`access.php`, `migrate.php`, `check.php`, `share.php`), setzen vor dem `require` die Konstante `SKIP_ACCESS_GATE`.

**URLs sind sauber und englisch** (`/tour/plan`, `/tour/5/edit`, `/crew/<slug>/talk`, `/ride/3`, `/api/route` …) und stehen als `RewriteRule` in der `.htaccess`; Links im Code immer in dieser Form schreiben, nie mit `.php`. Neue Seite = Datei + Route in der `.htaccess` + Eintrag in `legacy.php`, falls es eine alte Adresse gab. Jeder direkte `.php`-Aufruf von außen landet in `legacy.php` (301 auf die neue Adresse, Parameter werden übersetzt).

| Bereich | Dateien |
|---|---|
| Konto | `register.php`, `verify.php`, `login.php`, `logout.php`, `password_forgot.php`, `password_reset.php`, `account_lib.php` (Tokens, Mails, `mailHtml()`, `displayNameError()`, `validBirthDate()`, `promoteFirstAdmin()`), `profile.php` |
| Google | `google_lib.php` (OIDC mit PKCE/state/nonce, Button), `auth_google.php` (Start und Callback), `register_google.php` (Abschluss: Name, Geburtsdatum, AGB) |
| Teilen | `share_lib.php` (Rechte, Token, `trimRouteEnds()`, Vorschaubild mit GD), `share.php` (`/s/<token>`), `assets/share.js` |
| Herden | `crews_lib.php` (Zugriffsschicht), `crews.php`, `crew_new.php`, `crew.php`, `_crew_form.php` |
| Touren | `tours_lib.php` (Zugriff, Geometrie-Prüfung, Kennzahlen), `tours.php`, `tour_plan.php`, `tour.php`, `tour_gpx.php`, `route.php` (BRouter-Proxy), `assets/planner.js`, `assets/tour_map.js` |
| Ausfahrten | `rides_lib.php` (Zugriff, Anmeldung/Warteliste mit Zeilensperre, Mails, Tränke-Posts), `rides.php`, `ride.php`, `ride_edit.php`, `assets/ride_form.js` |
| Forum | `forum_lib.php`, `forum.php`, `forum_category.php`, `forum_topic.php`, `forum_new.php` |
| Tränke | `talk_lib.php`, `talk.php`, `talk_topic.php`, `talk_api.php` (JSON), `assets/talk.js` |
| Inhalte | `page.php` (Platzhalter `{{operator_name}}` usw. aus den Einstellungen; Routen `/imprint`, `/privacy`, `/terms`, `/page/x`), `legal_texts.php` (Erstfassung DE/EN) |
| Moderation | `report.php`, `admin/reports.php` |
| Admin | `admin/index.php`, `pages.php`, `settings.php` (inkl. Betreiberdaten), `users.php`, `forum.php`, `ops.php` |
| Betrieb | `ops_lib.php` (Backup, Restore, Paket-Vorschau, Diff, sicheres Entpacken), `migrations.php`, `migrate.php`, `check.php` |
| Texte | `lang.php` – reines `return [...]`, nichts danach |

Alte deutsche Adressen (`/herde.php`, `/bestaetigen.php`, `/impressum` …) leiten in `.htaccess` per 301 auf die neuen um und machen die alten Dateien auf dem Server unerreichbar. Diese Regeln nicht entfernen, solange alte Mail- und Einladungslinks im Umlauf sein können.

## Zugriff und Sicherheit

Jede Sichtbarkeitsentscheidung läuft über die jeweilige Zugriffsschicht, nie über eigene Abfragen in Seiten. **Herden:** auffindbar oder geheim; geheime Herden liefern Außenstehenden einen 404 (kein 403, sonst verrät man die Existenz). Mitgliederliste und Tränke nur für aktive Mitglieder. Leitstier = `group_members.role = 'admin'` mit `status = 'active'`; der letzte Leitstier kann nicht austreten. **Touren:** `private` (Ersteller), `group` (aktive Herdenmitglieder), `public` (alle angemeldeten). Kennzahlen wie Länge, Anstieg, Start und Bounding Box rechnet immer der Server aus der Geometrie; Zahlen aus dem Browser werden ignoriert. **Geteilte Touren:** jeder mit dem Link, ohne Anmeldung – aber nur Name, Kennzahlen und die Strecke ohne die ersten/letzten 300 m (`SHARE_PRIVACY_METERS`); nie Ersteller, Herde, Beschreibung, Sichtbarkeit oder Tour-ID. Link erzeugen: wer bearbeiten darf, bei öffentlichen Touren alle; widerrufen: nur Bearbeiter. **Google-Login:** Verknüpfung nur über die Google-Kontokennung (`user_identities`) oder eine **bestätigte** gleiche E-Mail; unbestätigte Konten werden beim Abschluss zurückgesetzt (Schutz vor Pre-Hijacking). **Ausfahrten:** `group` (aktive Mitglieder der Herde + Organisator) oder `public`; die Tour muss für die Zielgruppe sichtbar sein (private Touren gehen nie). Teilnehmernamen sehen nur Teilnehmer, Organisator, Herde und Admins. Verwalten dürfen Organisator, Leitstiere der Herde und Admins; Tour und Zielgruppe sind nach dem Anlegen fest. **Forum:** lesen und schreiben für alle angemeldeten Nutzer, moderieren nur Plattform-Admins. **Tränke:** nur aktive Herdenmitglieder; moderieren Leitstiere und Admins.

Weitere feste Regeln: CSRF-Token in jedem Formular und als `X-CSRF`-Header bei jedem `fetch`. Einmal-Tokens nur als SHA-256 in der DB. Passwörter mit `password_hash`, Session-Wechsel beim Login. Gleiche Antworten bei „Adresse existiert/existiert nicht“. Login-Bremse pro IP (Versuche nach 24 h gelöscht). Nutzertexte nur über `formatText()`: alles wird escaped, erlaubt sind `##`, `###`, `- `, `**fett**` und Links mit `https://`, `mailto:` oder `/`. Nie HTML aus Nutzereingaben ausgeben. Löschen von Inhalten ist weich (`deleted_at`). Kritische Admin-Aktionen (Deployment, Restore) verlangen das Passwort erneut.

## Datenbank

Schema-Änderungen ausschließlich in `migrations.php`, Funktion `runMigrations()`. Jeder Schritt ist idempotent (prüft selbst, ob er nötig ist). **Neue Schritte immer unten anhängen, bestehende nie umschreiben.** Einzige Ausnahme ist Block 0 (Umbenennungen der Englisch-Umstellung), der vor allem anderen läuft. Ausgeführt wird per Admin → Betrieb, `/migrate.php?key=…` oder automatisch durch die GitHub Action. Tabellen: `users`, `user_profiles`, `auth_tokens`, `login_attempts`, `rider_groups`, `group_members`, `pages`, `settings`, `tours` (inkl. `share_token`), `rides`, `ride_signups`, `user_identities`, `forum_categories`, `forum_threads`, `forum_posts`, `reports`, `herd_topics`, `herd_posts`, `herd_reactions`, `herd_reads`. `groups` ist in MySQL 8 reserviert, deshalb `rider_groups`. Zeiten in der DB immer UTC, Anzeige in `Europe/Berlin`. Keine nativen Spatial-Typen: Geometrie als GeoJSON (Abschnitte mit `properties.freehand`), Umkreissuche über indizierte Bounding-Box-Spalten.

Wer Rechtstexte in `legal_texts.php` ändert: SHA-256 des bisherigen Standardtexts vorher in `migrations.php` als „earlier default“ eintragen – dann ersetzt die Migration unveränderte Seiten automatisch. Von Hand geänderte Seiten meldet `legalPagesNeedingUpdate()` (Marker-Sätze pro Abschnitt) im Migrationsprotokoll und auf der Admin-Startseite; neue Abschnitte dort mit einem Marker ergänzen.

## Sprache, Vokabular, Tonalität

Die App ist von Anfang an Deutsch und Englisch; jeder Schlüssel in `lang.php` existiert in beiden Sprachen (die GitHub Action prüft das). Das Admin-Backend ist bewusst nur deutsch. Wir duzen. **Kein Gendern** – ausdrückliche Vorgabe von Marco (Fahrer, Teilnehmer, Organisator). Sätze nie aus Bausteinen zusammensetzen, Platzhalter wie `{n}` verwenden. Mails an andere Nutzer in deren Sprache (`tl()`).

| Bedeutung | Deutsch | Englisch | Code |
|---|---|---|---|
| Gruppe | Herde | crew | `crew*`, Tabelle `rider_groups` |
| Gruppen-Admin | Leitstier | crew lead | `isCrewLead()`, `group_members.role = 'admin'` |
| Herden-Community | Tränke | crew talk | `talk*`, Tabellen `herd_*` |
| Strecke | Tour | route | `tour*`, Tabelle `tours` |
| Termin mit Treffpunkt | Ausfahrt | ride | `ride*`, Tabellen `rides`, `ride_signups` |

Marke: „ElTouro“ in einem Wort. Farben: Nacht `#14263F`, Jeans `#2F5E8C`, Gold `#D7A845`, Kreide `#E8ECF1` (CSS: `--night`, `--denim`, `--gold`, `--chalk`).

## Rechtliche Leitplanken (Produktentscheidungen, nicht verhandelbar)

ElTouro vermittelt, veranstaltet aber keine Fahrten; nichts als „geprüft“ oder „sicher“ kennzeichnen, Schwierigkeit ist „Einschätzung des Erstellers“. Routing meidet Fußwege, Fußgängerzonen, Wald- und Feldwege (auch nach 2027), Autobahnen und Kraftfahrstraßen; „Radverkehr frei“ und Nebeneinanderfahren erst ab 01.03.2027. Ausfahrten nur mit zugelassenen Scootern (jede Anmeldung bestätigt das); Teilnehmergrenze ist Pflicht, ab 15 Plätzen (`STVO29_THRESHOLD`) bestätigt der Organisator die Prüfung nach § 29 StVO. Foto-/Video-Einwilligung freiwillig, jederzeit widerrufbar, nie Voraussetzung für die Teilnahme. Mindestalter 16. Standort aus dem Browser nur zum Verschieben der Karte, nie speichern. Fotos (kommen später): EXIF/GPS beim Upload entfernen, nur in der Herde sichtbar, Teilen nur über widerrufbaren Link. Die Datenschutzerklärung (`legal_texts.php`) beschreibt das tatsächliche Verhalten – **wer Datenflüsse ändert, passt sie mit an.**

## Lokal entwickeln und testen

`php -S` ignoriert `.htaccess`. Bewährtes Vorgehen: MariaDB per Docker (`docker run -d --name eltouro-db -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=eltouro -e MARIADB_USER=eltouro -e MARIADB_PASSWORD=eltouro -p 127.0.0.1:3307:3306 mariadb:10.11`), eine Test-`config.php` mit `'smtp' => ['host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none', 'user' => '', 'pass' => '']`, Mails mit `aiosmtpd` abfangen, für Routing einen kleinen Fake-BRouter (PHP-Skript, das eine GeoJSON-Linie zurückgibt) unter `brouter.url` eintragen, und `php -S` mit einem Router-Skript starten, das die Regeln aus der `.htaccess` nachbildet (RewriteCond `%{THE_REQUEST}`, RewriteRule mit F/G/R=301/QSA, FilesMatch, DirectoryIndex). Wichtig: Der Router muss die Seite auf oberster Ebene einbinden (nicht in einer Funktion), sonst sind `$CONFIG` & Co. nicht global; und ein Ziel ohne `?` behält wie bei Apache den Query-String. Google lokal: ein Fake-OIDC-Server (authorize/token, prüft PKCE) und in der Test-Config `google.auth_url`, `token_url`, `issuer` darauf zeigen lassen. Abläufe per HTTP-Client mit Cookie-Jar durchspielen (CSRF-Token aus dem Formular bzw. `data-csrf` lesen), JavaScript im Browser prüfen (Konsole in einem frischen Tab lesen). Vor jedem Push: `php -l` auf alle Dateien und ein Abgleich, dass `lang.php` in beiden Sprachen dieselben Schlüssel hat.

Hintergrundprozesse (Datenbank, `php -S`) mit `nohup … &` starten, sonst hängt die Shell.

## Stolperfallen, die schon einmal passiert sind

- PHP läuft bei all-inkl als CGI: `SCRIPT_NAME` ist unzuverlässig (führte zur Endlosschleife am Zugangsschutz). Ausnahmen deshalb per Konstante.
- HTTPS nicht per `.htaccess` erzwingen, sondern im KAS („SSL erzwingen“), sonst Weiterleitungsschleife.
- Sessions im gemeinsamen Temp-Ordner des Hosters gingen verloren, deshalb `data/sessions`.
- `curl_close()` ist ab PHP 8.5 veraltet.
- Nach `];` in `lang.php` darf nichts mehr stehen, sonst ist die ganze App kaputt.
- CSS-Regeln mit `display` überschreiben das `hidden`-Attribut; global gilt `[hidden] { display: none !important; }`.
- In Mails werden Links quoted-printable umbrochen; beim Testen den Mail-Text dekodieren, nicht roh greppen.
- Leaflet: Kartenausschnitt (`fitBounds`/`setView`) **vor** dem ersten `addTo(map)` einer Ebene setzen, sonst bricht `_clipPoints` ab und nichts wird gezeichnet.
- Die Tränke-Bremse (5 s zwischen Beiträgen) zählt auch automatische Beiträge (Änderungen an Ausfahrten).
- Ohne Kachel-URL in der Server-Config bleibt die Karte grau (war auf test.eltouro.de so) – `bootstrap.php` fällt jetzt auf OSM zurück.
- Deployments löschen nie – alte Dateien (z. B. `seite.php`) bleiben auf dem Server. Mit Apaches MultiViews beantworteten sie `/seite/x` und `/impressum`, bevor die Rewrite-Regeln griffen. Deshalb `Options -Indexes -MultiViews` in der `.htaccess`.
- lftp ersetzt keine Umgebungsvariablen: In einem Heredoc mit Anführungszeichen (`<<'LFTP'`) landete der Upload in einem Ordner, der wörtlich `$FTP_DIR` heißt – die Action war trotzdem grün. Der Workflow prüft seitdem über `deploy-version.txt`, ob die Version wirklich auf dem Server ankommt.
- GD kann nur TTF-Schriften rendern; die Schriften liegen als WOFF2 vor, deshalb hat das Vorschaubild beim Teilen keinen Text.

## Backlog (Reihenfolge mit Marco abstimmen)

**Belohnungssystem:** Konzept und Entscheidungen in `docs/rewards.md` (Ereignisse speichern, Punkte berechnen; Ränge Becerro → Leyenda; vorerst ohne Geldwert). Nächster Schritt dort: Stufe 1 „Fundament“ – Herkunft der Anmeldung muss erfasst sein, bevor sie verloren geht.

1. **Konto selbst löschen** im Profil (die Datenschutzerklärung verspricht Löschung, aktuell nur per Mail). Dabei Anmeldungen zu Ausfahrten und eigene Ausfahrten mitbedenken.
2. **BRouter anbinden** und die Profile mit echten Strecken prüfen; vor live Kacheln auf MapTiler umstellen.
3. **Fuhrpark** im Profil (Hersteller, Modell, Straßenzulassung ja/nein) – könnte die Bestätigung „zugelassener Scooter“ bei Ausfahrten vorbelegen.
4. Ausfahrten ausbauen: Kalender-Export (ICS), Teilnehmer durch den Organisator entfernen, Erinnerungsmail am Vortag, Umkreissuche für öffentliche Ausfahrten.
5. **GPS-Aufzeichnung** aus Marcos GPS-Rallye-App übernehmen (`watchPosition` + Wake Lock), mit Privatzonen um Start/Ziel.
6. Forum und Tränke: Beiträge bearbeiten, Benachrichtigungen (Mail-Digest), Erwähnungen.
7. Fotowand pro Herde (aus der Rallye-App, inkl. EXIF-Entfernung und `wall_status`-Moderation) – nur Fotos von Teilnehmern mit Einwilligung.
8. Deployment-Vorschau: Häkchen beim Wechsel zwischen Diffs merken.
9. Saubere URLs ohne `.php` (Front-Controller) – erst wenn nötig.

## Verwandte Projekte

Marcos GPS-Rallye (games.anderheyden.de/gps) ist dieselbe Technikfamilie und Quelle für Karte, Aufzeichnung, Spielleitung, Foto-Pipeline und `Lang`-Klasse. Deren `Scope`-Mandantenlogik (`null`-Mandant sieht alles) bewusst **nicht** übernehmen – ElTouro nutzt die Zugriffsschichten oben.
