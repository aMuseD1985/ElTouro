# ElTouro App (beta/test)

Community für E-Scooter-Fahrer: Konten mit E-Mail-Bestätigung und Passwort-Reset oder **Anmeldung mit Google**, Profil, Herden (auffindbar oder geheim, offen, geschlossen oder nur per Einladung) mit Leitstier-Verwaltung, Routenplaner mit BRouter-Anbindung, **Teilen von Touren** (auch für Social Media), **Ausfahrten** mit Termin, Treffpunkt, Teilnehmergrenze und Warteliste, Forum, Tränke (Austausch in der Herde), Meldungen nach DSA und ein Admin-Backend mit Deployment, Backup und Restore. Plain PHP 8.1+, MariaDB, kein Framework, keine externen Ressourcen.

Code, Dateinamen und Adressen sind englisch, die Oberfläche ist deutsch und englisch (`lang.php`), das Admin-Backend bewusst nur deutsch.

## Adressen

Saubere URLs ohne `.php`, definiert in der `.htaccess`: `/tours`, `/tour/plan`, `/tour/5`, `/tour/5/edit`, `/tour/5/gpx`, `/rides`, `/ride/new`, `/ride/3`, `/ride/3/edit`, `/crews`, `/crews/new`, `/crew/<slug>`, `/crew/<slug>/talk`, `/talk/<id>`, `/forum`, `/forum/<kategorie>`, `/forum/<kategorie>/new`, `/forum/topic/<id>`, `/login`, `/register`, `/verify`, `/password/forgot`, `/password/reset`, `/profile`, `/report`, `/imprint`, `/privacy`, `/terms`, `/page/<slug>`, `/admin/…`, `/s/<token>` (geteilte Tour), `/join/<code>` (persönlicher Einladungslink), APIs unter `/api/route` und `/api/talk`, `/migrate`, `/check`.

Jeder direkte Aufruf einer `.php`-Adresse (alte deutsche Seiten wie `/herde.php?s=…`, Links aus verschickten Mails) landet in `legacy.php` und wird mit den passenden Parametern dauerhaft (301) auf die neue Adresse umgeleitet.

## Umgebungen und Deployment

Dieselbe Codebasis läuft auf `beta.eltouro.de` (Entwicklung) und `test.eltouro.de` (kurz vor Release). Jede Umgebung hat ihre **eigene** `config.php` und ihre **eigene Datenbank**. `env` in der Config steuert das farbige Hinweisband (Beta gelb, Test rot) und setzt `noindex`. Die `robots.txt` sperrt Suchmaschinen komplett; für den Livegang wird sie ersetzt.

**Testserver:** Jeder Push auf `main` wird per GitHub Action (`.github/workflows/deploy-test.yml`) per FTPS hochgeladen und danach migriert. Die Action prüft vorher die PHP-Syntax und ob jeder Text in beiden Sprachen existiert. Es wird nichts gelöscht; `config.php`, `data/` und das alte `daten/` bleiben unberührt. Benötigte Secrets stehen oben in der Workflow-Datei.

**Andere Umgebungen (live):** ZIP bauen und unter Admin → Betrieb einspielen:

```
git archive --prefix=eltouro-app/ -o ../eltouro-app.zip HEAD
```

Das Paket enthält weder `config.php` noch `data/`, `README.md`, `CLAUDE.md` oder `.github/`.

## Zugangsschutz

Solange `access.enabled` true ist, sieht niemand etwas außer der Zugangsseite. Alle Tester nutzen ein gemeinsames Passwort; das Cookie (`et_access`) gilt 30 Tage. Passwort ändern (neuen Hash in die Config) sperrt sofort alle bisherigen Tester aus. Nach 10 Fehlversuchen in 15 Minuten ist die IP kurz gesperrt.

## Einrichtung

1. Im KAS die Subdomain anlegen (eigenes Verzeichnis) und eine Datenbank.
2. `config.example.php` nach `config.php` kopieren und ausfüllen. Hashes und Schlüssel erzeugen:
   `php -r 'echo password_hash("TESTER_PASSWORT", PASSWORD_DEFAULT), "\n";'` und `php -r 'echo bin2hex(random_bytes(32)), "\n";'`
3. `first_admin` auf deine E-Mail-Adresse setzen. Wer sich mit dieser Adresse registriert und bestätigt, wird automatisch Plattform-Admin.
4. Hochladen, dann einmal `/migrate?key=DEIN_MIGRATE_KEY` aufrufen (idempotent, beliebig oft).
5. Registrieren, Mail bestätigen, unter „Admin“ → „Seiten“ Impressum, Datenschutz und Nutzungsbedingungen prüfen.

Ältere `config.php` mit deutschen Schlüsseln (`zugang`, `karte`, `erster_admin`, `aktiv` …) funktionieren weiter; `/check` weist darauf hin. Fehlen die Kartenkacheln in der Config, nutzt die App die OSM-Kacheln.

## Umstellung auf englischen Code (Oktober 2026)

- Alte Adressen (`/herde.php`, `/bestaetigen.php`, `/impressum` …) leiten per `.htaccess` dauerhaft auf die neuen um – Links in verschickten Mails und Einladungslinks funktionieren weiter. Alte Dateien, die das FTP-Deployment auf dem Server liegen lässt, sind dadurch nicht mehr erreichbar und können bei Gelegenheit per FTP gelöscht werden.
- Die Migration benennt Einstellungen, Seiten-Kurznamen und Platzhalter in den Rechtstexten um, verschiebt Backups von `daten/backups` nach `data/backups` und ergänzt Datenschutzerklärung und Nutzungsbedingungen um die Ausfahrten (nur wenn der Text nie von Hand geändert wurde – sonst steht ein Hinweis auf der Admin-Startseite).
- Einmalig müssen sich alle neu anmelden und Tester das Tester-Passwort erneut eingeben (neuer Sitzungsordner, neuer Cookie-Name).

## Anmeldung mit Google

In der `config.php` den Block `google` mit `client_id` und `client_secret` füllen (Google Cloud Console → APIs & Dienste → Anmeldedaten → OAuth-Client-ID, Typ „Webanwendung“). Autorisierte Weiterleitungs-URI: `https://<umgebung>/auth/google/callback`. Ohne Eintrag erscheint kein Google-Button.

Ablauf: OpenID Connect mit PKCE, `state` und `nonce`. Ein bereits verknüpftes Google-Konto meldet direkt an. Gibt es ein **bestätigtes** ElTouro-Konto mit derselben E-Mail, wird es verknüpft. Sonst folgt ein kurzes Formular (Anzeigename, Geburtsdatum, Nutzungsbedingungen). Ein **unbestätigtes** Konto mit derselben Adresse wird dabei nicht einfach übernommen, sondern zurückgesetzt – sonst könnte jemand mit fremder Adresse vorregistrieren und das Passwort kennen. Wer sich nur mit Google angemeldet hat, kann über „Passwort vergessen“ zusätzlich ein Passwort setzen.

## Touren teilen

Auf jeder Tour gibt es „Tour teilen“: Ein Klick erzeugt einen Link `/s/<token>`, auch für private Touren. Die Seite ist ohne Anmeldung und ohne Tester-Passwort erreichbar und zeigt nur Name, Länge, Anstieg, Schwierigkeit, Fahrstil, Regelwerk und die Strecke **ohne die ersten und letzten 300 m** – kein Ersteller, keine Herde, keine Beschreibung. Für Vorschauen in WhatsApp, Facebook & Co. gibt es Open-Graph-Tags und ein Vorschaubild (`/s/<token>/image.png`, mit GD gezeichnet, in `data/share` zwischengespeichert). Teilen-Knöpfe für WhatsApp, Telegram, Facebook, X und E-Mail sind reine Links ohne fremde Skripte; am Handy öffnet „Teilen …“ das Teilen-Menü des Geräts. Der Link lässt sich jederzeit deaktivieren. Teilen dürfen alle, die die Tour bearbeiten dürfen, bei öffentlichen Touren alle angemeldeten Fahrer; deaktivieren nur Bearbeiter.

## Einladungen und Belohnungssystem (Stufe 1)

Jeder Fahrer hat im Profil einen persönlichen Einladungslink (`/join/<code>`) mit Teilen-Knöpfen. Alle Teilen-Knöpfe hängen den Kanal an (`?via=whatsapp`, `facebook`, `x`, `telegram`, `email`, `copy`, `native`). Bei der Registrierung (auch über Google) wird gespeichert, über welchen Link und Kanal jemand kam (`referrals`, erster Kontakt zählt), und wichtige Aktivitäten landen als Ereignisse in `activity_events`. Punkte, Ränge und Abzeichen werden daraus später berechnet – Konzept und Stand in `docs/rewards.md`.

## Ausfahrten

Eine Ausfahrt ist eine Tour mit Termin: Datum und Uhrzeit, Treffpunkt (Text plus optionaler Pin auf der Karte), Teilnehmergrenze (Pflicht), Warteliste, Fahrstil. Zielgruppe ist eine Herde oder alle angemeldeten Fahrer; die Tour muss für diese Zielgruppe sichtbar sein. Herden-Ausfahrten stehen auf der Herdenseite und bekommen ein eigenes Thema in der Tränke.

- Anmeldung nur mit Bestätigung „zugelassener und versicherter E-Scooter“. Die Foto-/Video-Einwilligung ist freiwillig und jederzeit änderbar; Teilnehmer ohne Einwilligung sind mit „keine Fotos“ markiert.
- Volle Ausfahrt → Warteliste. Meldet sich jemand ab oder erhöht der Organisator die Plätze, rücken Wartende automatisch nach und bekommen eine Mail. Überbuchung ist durch eine Zeilensperre ausgeschlossen.
- Ab 15 Plätzen bestätigt der Organisator, die Erlaubnispflicht nach § 29 StVO geprüft zu haben.
- Änderungen an Termin oder Treffpunkt und Absagen gehen per Mail an alle Angemeldeten (in ihrer Sprache) und als Beitrag in die Tränke.
- Teilnehmernamen sehen nur Teilnehmer, Organisator und bei Herden-Ausfahrten die Herde. Anmeldungen werden 180 Tage nach dem Termin gelöscht.
- ElTouro veranstaltet nichts: Jede Ausfahrt nennt den Organisator und weist auf Eigenverantwortung und Verkehrsregeln hin.

## Betrieb im Admin (Admin → Betrieb)

**Deployment:** ZIP-Paket hochladen → Vorschau mit allen geänderten, neuen, unveränderten und nur auf dem Server vorhandenen Dateien, Zeilenvergleich pro Datei, Auswahl per Häkchen. Mit Passwort bestätigen: automatisches Vollbackup, ausgewählte Dateien einspielen, Migration. Es wird nie etwas gelöscht; `config.php` und `data/` werden nie angefasst.

**Datenbank:** „Migration ausführen“ legt fehlende Tabellen an, beliebig oft, ohne Datenverlust. Alle Schritte stehen in `migrations.php` – Änderungen immer unten anhängen.

**Backups:** Datenbank-Abzug (`.sql.gz`) plus ZIP des Webroots (`.files.zip`) inklusive `config.php`, ohne `data/`. Die letzten `ops.max_backups` bleiben erhalten. Downloads enthalten Zugangsdaten. Backups von vor der Umstellung (`.dateien.zip`) lassen sich weiter wiederherstellen.

**Restore:** Datenbank und/oder Dateien aus einem Backup, auf Wunsch auch `config.php`. Vorher wird automatisch der aktuelle Stand gesichert.

## Wenn etwas nicht geht

`/check?key=DEIN_MIGRATE_KEY` zeigt ohne Tester-Passwort, was fehlt: PHP-Version, Erweiterungen, HTTPS, Config-Werte, Datenbank, fehlende Tabellen, SMTP. Mit `&mail=deine@adresse.de` schickt sie zusätzlich eine Testmail.

Mit `'debug' => true` zeigt jede Seite statt eines leeren 500ers die Fehlermeldung samt Hinweis (nur in beta/test). Ohne Debug steht eine Referenznummer auf der Fehlerseite, unter der der Fehler im PHP-Log steht.

## Aufbau

| Datei | Zweck |
|---|---|
| `bootstrap.php` | Versionsprüfung, Config laden (inkl. Übersetzung alter Config-Schlüssel), Fehlerbehandlung – bewusst in altem PHP |
| `core.php` | DB-Helfer, `data/`, Session, Zugangsschutz, Sprache, CSRF, Nutzer, Mini-Markdown, Layout |
| `account_lib.php`, `mailer.php` | Einmal-Tokens (nur Hash in der DB), Konto-Mails, SMTP über PHPMailer |
| `crews_lib.php` | **Zugriffsschicht** für Herden |
| `tours_lib.php` | **Zugriffsschicht** und Geometrie-Prüfung für Touren |
| `rides_lib.php` | **Zugriffsschicht** für Ausfahrten, Anmeldung, Warteliste, Benachrichtigungen |
| `talk_lib.php` | **Zugriffsschicht** und Darstellung der Tränke |
| `register.php`, `verify.php`, `login.php`, `logout.php`, `password_forgot.php`, `password_reset.php`, `access.php` | Konto und Zugang |
| `google_lib.php`, `auth_google.php`, `register_google.php` | Anmeldung mit Google |
| `share_lib.php`, `share.php`, `assets/share.js` | Touren teilen (öffentliche Seite, Vorschaubild, Teilen-Knöpfe) |
| `legacy.php` | Weiterleitung aller alten `.php`-Adressen |
| `index.php`, `profile.php` | Start, Profil |
| `crews.php`, `crew_new.php`, `crew.php`, `_crew_form.php` | Herden |
| `tours.php`, `tour_plan.php`, `tour.php`, `tour_gpx.php`, `route.php` | Routenplaner (`route.php` ist der BRouter-Proxy) |
| `rides.php`, `ride.php`, `ride_edit.php` | Ausfahrten |
| `forum.php`, `forum_category.php`, `forum_topic.php`, `forum_new.php`, `forum_lib.php` | Forum |
| `talk.php`, `talk_topic.php`, `talk_api.php` | Tränke |
| `report.php`, `admin/reports.php` | Meldungen (DSA) |
| `page.php`, `legal_texts.php` | Inhaltsseiten (`/imprint`, `/privacy`, `/terms`, `/page/kurzname`) und Erstfassung der Rechtstexte |
| `admin/` | Übersicht, Seiten, Einstellungen, Nutzer, Forum, Meldungen, Betrieb |
| `migrate.php`, `migrations.php`, `ops_lib.php`, `check.php` | Migration, Backup/Restore/Deployment, Diagnose |
| `assets/planner.js`, `assets/tour_map.js`, `assets/ride_form.js`, `assets/talk.js` | Skripte im Browser |
| `lang.php` | Alle Texte DE/EN. Duzen, nicht gendern |

## Regeln der Herden

Auffindbare Herden sieht jeder angemeldete Nutzer. Geheime Herden existieren für Außenstehende nicht (404), sichtbar nur für Mitglieder und Leute mit gültigem Einladungslink. Mit Einladungslink ist man sofort drin, sonst entscheidet die Beitrittsregel (offen: sofort, geschlossen: Anfrage an den Leitstier, nur Einladung: gar nicht). Die Mitgliederliste sehen nur Mitglieder. Der letzte Leitstier kann die Herde nicht verlassen. Ein neuer Einladungslink macht den alten ungültig.

## Sicherheit

Passwörter mit `password_hash`, Session-Wechsel beim Login, CSRF-Token in jedem Formular und als `X-CSRF`-Header bei jedem `fetch`, Login-Bremse pro IP, gleiche Antworten bei „E-Mail vorhanden/nicht vorhanden“, Tokens nur als SHA-256, Weiterleitungen nur relativ. Nutzertexte laufen durch ein Mini-Markdown, das alles escaped. `data/`, `lib/` und alle Bibliotheksdateien sind per `.htaccess` gesperrt.
