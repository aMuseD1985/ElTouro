# ElTouro App (beta/test)

Erster Kern der Community: Konten mit E-Mail-Bestätigung und Passwort-Reset, Profil, Herden (auffindbar oder geheim, offen, geschlossen oder nur per Einladung) mit Leitstier-Verwaltung, dazu ein Admin-Backend für Inhaltsseiten, Einstellungen und Nutzer. Plain PHP 8.1+, MariaDB, kein Framework, keine externen Ressourcen.

## Umgebungen

Dieselbe Codebasis läuft auf `beta.eltouro.de` (Entwicklung) und `test.eltouro.de` (kurz vor Release). Jede Umgebung bekommt ihre **eigene** `config.php` und ihre **eigene Datenbank**, damit Tests nie Echtdaten berühren. `env` in der Config steuert das farbige Hinweisband (Beta gelb, Test rot) und setzt `noindex`. Die mitgelieferte `robots.txt` sperrt Suchmaschinen komplett; für den Livegang wird sie ersetzt.

## Zugangsschutz

Solange `zugang.aktiv` true ist, sieht niemand etwas außer der Zugangsseite. Alle Tester nutzen ein gemeinsames Passwort; das Cookie gilt 30 Tage. Passwort ändern (neuen Hash in die Config) sperrt sofort alle bisherigen Tester aus. Nach 10 Fehlversuchen in 15 Minuten ist die IP kurz gesperrt. Statische Dateien (CSS, Schriften, Icons) liegen außerhalb des Schutzes, das ist unkritisch.

Alternative ohne Code: KAS → Verzeichnisschutz auf das Subdomain-Verzeichnis. Dann aber `zugang.aktiv` auf false stellen, sonst gibt es zwei Passwortabfragen.

## Einrichtung

1. Im KAS die Subdomains `beta` und `test` anlegen, jeweils mit eigenem Verzeichnis, und zwei Datenbanken.
2. `config.example.php` nach `config.php` kopieren und ausfüllen. Hashes und Schlüssel erzeugen:
   `php -r 'echo password_hash("TESTER_PASSWORT", PASSWORD_DEFAULT), "\n";'` und `php -r 'echo bin2hex(random_bytes(32)), "\n";'`
3. `erster_admin` auf deine E-Mail-Adresse setzen. Wer sich mit dieser Adresse registriert und bestätigt, wird automatisch Plattform-Admin.
4. Hochladen, dann einmal `/migrate.php?key=DEIN_MIGRATE_KEY` aufrufen (idempotent, beliebig oft).
5. Registrieren, Mail bestätigen, unter „Admin“ → „Seiten“ Impressum, Datenschutz und Nutzungsbedingungen in beiden Sprachen füllen.

## Betrieb im Admin (Admin → Betrieb)

**Deployment:** ZIP-Paket hochladen → Vorschau mit allen geänderten, neuen, unveränderten und nur auf dem Server vorhandenen Dateien. Ein Klick auf eine Datei zeigt den Zeilenvergleich (Binärdateien nur mit Größe). Per Häkchen lassen sich einzelne Dateien auslassen. Mit Passwort bestätigen: automatisches Vollbackup, nur die ausgewählten Dateien einspielen, Migration. Es wird nie etwas gelöscht; `config.php` und `daten/` werden nie angefasst. Geprüfte Pakete liegen bis zu einem Tag in `daten/deploy`. Pfade mit `..` im Archiv werden abgelehnt. Die maximale Upload-Größe bestimmt der Server (`upload_max_filesize` im KAS).

**Datenbank:** „Migration ausführen“ legt fehlende Tabellen an, beliebig oft, ohne Datenverlust. Alle Schritte stehen in `migrationen.php` – Änderungen immer unten anhängen.

**Backups:** Datenbank-Abzug (`.sql.gz`) plus ZIP des Webroots inklusive `config.php`, ohne `daten/`. Die letzten `ops.max_backups` bleiben erhalten, die Namen enthalten einen Zufallsanteil. Downloads enthalten Zugangsdaten.

**Restore:** Datenbank und/oder Dateien aus einem Backup, auf Wunsch auch `config.php`. Vorher wird automatisch der aktuelle Stand gesichert, du kannst also jederzeit zurück. Nach einem DB-Restore meldest du dich neu an.

Für live bewusst entscheiden, ob `ops.aktiv` an bleibt. Die Werkzeuge verlangen Admin-Recht und Passwort, sind aber mächtig.

## Routenplaner

Karte mit Leaflet (lokal in `assets/vendor/leaflet`), Kacheln aus `karte.kacheln`. Für beta/test sind die OSM-Kacheln in Ordnung, vor live auf einen Anbieter wie MapTiler umstellen. Klick setzt Wegpunkte, `route.php` fragt den BRouter mit dem E-Scooter-Profil (aktuelle eKFV oder ab 2027). Ist `brouter.url` leer oder ein Abschnitt nicht routbar, wird gerade verbunden und rot gestrichelt als „freihand“ markiert. Länge, Anstieg, Start und Bounding Box rechnet der Server aus der Geometrie, Zahlen aus dem Browser werden nicht übernommen. Touren sind privat, für eine Herde oder für alle angemeldeten Fahrer sichtbar und lassen sich als GPX exportieren.

## Forum

Kategorien pflegst du unter Admin → Forum (DE/EN), fünf sind vorbelegt. Themen und Antworten mit Mini-Markdown, 25 Beiträge pro Seite, 15 Sekunden Mindestabstand zwischen Beiträgen. Eigene Beiträge kann man löschen; Admins können Themen anpinnen, schließen und löschen. Jeder Beitrag und jede Tour hat „Melden“; Meldungen bearbeitest du unter Admin → Meldungen mit dokumentierter Entscheidung.

## Wenn etwas nicht geht

`/pruefen.php?key=DEIN_MIGRATE_KEY` zeigt ohne Tester-Passwort, was fehlt: PHP-Version, Erweiterungen, HTTPS, Config-Werte, Datenbankverbindung, fehlende Tabellen, SMTP. Mit `&mail=deine@adresse.de` schickt sie zusätzlich eine Testmail.

Mit `'debug' => true` in der Config zeigt jede Seite statt eines leeren 500ers die Fehlermeldung samt konkretem Hinweis (nur in beta/test, in live nie). Ohne Debug steht eine Referenznummer auf der Fehlerseite, unter der der Fehler im PHP-Log zu finden ist.

## Aufbau

| Datei | Zweck |
|---|---|
| `bootstrap.php` | Versionsprüfung, Config laden, Fehlerbehandlung – bewusst in altem PHP geschrieben, damit auch bei falscher PHP-Version eine lesbare Meldung kommt |
| `kern.php` | DB-Helfer, Session, Zugangsschutz, Sprache, CSRF, Nutzer, Mini-Markdown, Layout |
| `konto.php` | Einmal-Tokens (nur Hash in der DB) und Konto-Mails |
| `herden_lib.php` | **Zugriffsschicht** für Herden – jede Sichtbarkeits- und Rechteentscheidung läuft hier durch |
| `registrieren.php`, `bestaetigen.php`, `login.php`, `logout.php`, `passwort_vergessen.php`, `passwort_neu.php` | Konto |
| `index.php`, `profil.php` | Start, Profil |
| `herden.php`, `herde_neu.php`, `herde.php`, `herde_formular.php` | Herden |
| `seite.php` | Öffentliche Inhaltsseiten aus der DB |
| `admin/` | Übersicht, Seiten, Einstellungen (Registrierung auf/zu, Hinweisband DE/EN), Nutzer (Admin-Recht, Sperren) |
| `migrate.php` | Einzige Quelle für Schema-Änderungen |
| `pruefen.php` | Diagnose der Einrichtung |
| `migrationen.php` | Alle Schema-Schritte (von `migrate.php` und Admin → Betrieb genutzt) |
| `betrieb_lib.php` | Backup, Restore, sicheres Entpacken für Deployments |
| `touren_lib.php`, `touren.php`, `tour_planen.php`, `tour.php`, `tour_gpx.php`, `route.php` | Routenplaner |
| `assets/planer.js`, `assets/tourkarte.js` | Karte im Browser |
| `forum_lib.php`, `forum.php`, `forum_kategorie.php`, `forum_thema.php`, `forum_neu.php`, `melden.php` | Forum und Meldungen |
| `admin/betrieb.php`, `admin/forum.php`, `admin/meldungen.php` | Betrieb, Forumskategorien, Meldungen |
| `lang.php` | Alle Texte DE/EN (Admin bewusst nur Deutsch). Duzen, nicht gendern. Herde/crew, Leitstier/crew lead |

## Regeln der Herden

Auffindbare Herden sieht jeder angemeldete Nutzer. Geheime Herden existieren für Außenstehende nicht (404), sichtbar nur für Mitglieder und Leute mit gültigem Einladungslink. Mit Einladungslink ist man sofort drin, sonst entscheidet die Beitrittsregel (offen: sofort, geschlossen: Anfrage an den Leitstier, nur Einladung: gar nicht). Die Mitgliederliste sehen nur Mitglieder. Der letzte Leitstier kann die Herde nicht verlassen. Ein neuer Einladungslink macht den alten ungültig.

## Sicherheit

Passwörter mit `password_hash`, Session-Wechsel beim Login, CSRF-Token in jedem Formular, Login-Bremse pro IP, gleiche Antworten bei „E-Mail vorhanden/nicht vorhanden“, Tokens nur als SHA-256 gespeichert, Weiterleitungen nur relativ. Inhaltsseiten nutzen ein Mini-Markdown, das alles escaped – HTML und `javascript:`-Links kommen nicht durch.
