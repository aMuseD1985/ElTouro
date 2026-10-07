<?php
/**
 * Erstfassung der Rechtstexte. Wird von der Migration nur in LEERE Seiten geschrieben –
 * danach pflegst du alles unter Admin → Seiten. Platzhalter {{…}} füllt seite.php aus
 * Admin → Einstellungen (Betreiberdaten).
 *
 * ENTWURF, keine Rechtsberatung. Vor dem öffentlichen Start prüfen lassen.
 */
declare(strict_types=1);

return [
'impressum' => [
'de' => <<<'TXT'
## Angaben gemäß § 5 DDG

{{betreiber_name}}
{{betreiber_anschrift}}

## Kontakt

E-Mail: {{betreiber_email}}
Telefon: {{betreiber_telefon}}

## Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV

{{betreiber_name}}, Anschrift wie oben

## Kontaktstelle nach dem Digital Services Act

Zentrale Kontaktstelle für Behörden und Nutzer (Art. 11 und 12 DSA): {{betreiber_email}}. Wir kommunizieren auf Deutsch und Englisch.

## Hinweis zu Inhalten der Nutzer

ElTouro ist eine Plattform, auf der Nutzer eigene Inhalte veröffentlichen: Touren, Herden, Forums- und Herdenbeiträge. Für diese Inhalte sind die jeweiligen Nutzer verantwortlich. Wenn dir ein rechtswidriger Inhalt auffällt, nutze bitte die Funktion „Melden“ am Inhalt oder schreib uns.

## Verbraucherstreitbeilegung

Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.
TXT,
'en' => <<<'TXT'
## Information pursuant to Section 5 of the German Digital Services Act (DDG)

{{betreiber_name}}
{{betreiber_anschrift}}

## Contact

Email: {{betreiber_email}}
Phone: {{betreiber_telefon}}

## Responsible for content (Section 18(2) MStV)

{{betreiber_name}}, address as above

## Point of contact under the Digital Services Act

Single point of contact for authorities and users (Art. 11 and 12 DSA): {{betreiber_email}}. We communicate in German and English.

## User content

ElTouro is a platform where users publish their own content: routes, crews, forum and crew posts. The respective users are responsible for this content. If you notice illegal content, please use the “Report” link next to it or email us.

## Consumer dispute resolution

We are neither willing nor obliged to take part in dispute resolution proceedings before a consumer arbitration board.

This translation is provided for convenience; the German version is legally binding.
TXT,
],

'datenschutz' => [
'de' => <<<'TXT'
## 1. Wer ist verantwortlich?

{{betreiber_name}}
{{betreiber_anschrift}}
E-Mail: {{betreiber_email}}

Einen Datenschutzbeauftragten müssen wir nicht benennen. Für alle Fragen zum Datenschutz erreichst du uns unter der E-Mail-Adresse oben.

## 2. Das Wichtigste in Kürze

- Wir verarbeiten nur, was für ElTouro nötig ist: dein Konto, deine Inhalte und technische Daten für den sicheren Betrieb.
- Es gibt kein Tracking, keine Werbung, keine Analyse-Dienste und keine Social-Media-Plugins.
- Schriften, Skripte und Bilder kommen von unserem eigenen Server. Einzige Ausnahme sind die Kartenkacheln (Abschnitt 8).
- Wir verkaufen keine Daten und geben sie nicht zu Werbezwecken weiter.

## 3. Hosting und Server-Logfiles

ElTouro läuft bei ALL-INKL.COM – Neue Medien Münnich, Hauptstraße 68, 02742 Friedersdorf. Mit dem Hoster besteht ein Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO. Beim Aufruf jeder Seite verarbeitet der Server technisch notwendige Daten: IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, übertragene Datenmenge, Browser und Betriebssystem. Das ist nötig, um die Seiten auszuliefern und Angriffe abzuwehren (Art. 6 Abs. 1 lit. f DSGVO). Die Logfiles werden nach {{log_tage}} Tagen gelöscht.

## 4. Dein Konto

Für die Registrierung verarbeiten wir E-Mail-Adresse, Anzeigename, Geburtsdatum, Passwort (nur als verschlüsselter Hash) und deine Sprache. Das Geburtsdatum brauchen wir, um das Mindestalter von 16 Jahren zu prüfen; andere Nutzer sehen es nicht. Freiwillig kannst du im Profil angeben, wo du meistens fährst, und etwas über dich schreiben. Rechtsgrundlage ist die Erfüllung des Nutzungsvertrags (Art. 6 Abs. 1 lit. b DSGVO).

Zur Bestätigung deiner E-Mail-Adresse und zum Zurücksetzen des Passworts schicken wir dir E-Mails mit einmaligen Links. In unserer Datenbank liegt davon nur ein Prüfwert, nicht der Link selbst. Die E-Mails verschicken wir über den Mailserver unseres Hosters.

Zum Schutz vor Angriffen speichern wir fehlgeschlagene Anmeldeversuche mit IP-Adresse für höchstens 24 Stunden (Art. 6 Abs. 1 lit. f DSGVO).

Deine Kontodaten speichern wir, solange dein Konto besteht. Wenn du dein Konto löschen möchtest, schreib uns an {{betreiber_email}}; wir löschen es innerhalb von 30 Tagen. Beiträge in Foren und Herden werden dabei anonymisiert oder gelöscht.

## 5. Was andere Nutzer von dir sehen

Andere angemeldete Nutzer sehen deinen Anzeigenamen sowie die Inhalte, die du selbst veröffentlichst:

- **Herden:** Mitglieder einer Herde sehen die Mitgliederliste und die Beiträge in der Herde. Geheime Herden sind nur für ihre Mitglieder sichtbar.
- **Touren:** Du legst für jede Tour fest, ob nur du, deine Herde oder alle angemeldeten Fahrer sie sehen.
- **Forum:** Beiträge im Forum sehen alle angemeldeten Nutzer.

## 6. Touren und Standortdaten

Eine Tour besteht aus den Wegpunkten, die du auf der Karte setzt, und der daraus berechneten Strecke. Wir speichern sie, damit du sie wiederfindest und teilen kannst (Art. 6 Abs. 1 lit. b DSGVO). Achte darauf, dass Start und Ziel öffentlicher Touren nicht direkt vor deiner Haustür liegen.

Die Schaltfläche „Mein Standort“ fragt deinen Standort über deinen Browser ab, und nur nach deiner Zustimmung im Browser. Der Standort wird ausschließlich dazu genutzt, die Karte zu verschieben. Er wird nicht an uns übertragen und nicht gespeichert.

## 7. Routenberechnung

Zur Berechnung einer Route sendet unser Server die Wegpunkte an einen von uns selbst betriebenen Routing-Dienst (BRouter). Die Verbindung läuft über einen Tunnel der Cloudflare, Inc., die dabei die technische Verbindung vermittelt; übertragen werden nur die Koordinaten, keine Angaben zu deiner Person und nicht deine IP-Adresse.

## 8. Kartenkacheln

Die Kartenbilder lädt dein Browser direkt vom Kartendienst {{kartendienst}}. Dabei erhält der Dienst technisch bedingt deine IP-Adresse und die Information, welcher Kartenausschnitt geladen wird. Rechtsgrundlage ist unser berechtigtes Interesse an einer funktionierenden Karte (Art. 6 Abs. 1 lit. f DSGVO). {{kartendienst_hinweis}}

## 9. Cookies

Wir setzen nur technisch notwendige Cookies (§ 25 Abs. 2 Nr. 2 TDDDG):

- **eltouro_sid:** hält dich angemeldet; wird beim Schließen des Browsers gelöscht.
- **lang:** merkt sich deine Sprachwahl für ein Jahr.
- **et_zugang:** nur in Test-Umgebungen, merkt sich den Vorabzugang für 30 Tage.

## 10. Meldungen und Moderation

Wenn du einen Inhalt meldest, speichern wir die Meldung mit deinem Konto, dem gemeldeten Inhalt und deiner Begründung. Wir brauchen das, um Meldungen zu bearbeiten und unsere Pflichten nach dem Digital Services Act zu erfüllen (Art. 6 Abs. 1 lit. c und f DSGVO). Entscheidungen dokumentieren wir.

## 11. Datensicherung

Zur Absicherung gegen Datenverlust erstellen wir regelmäßig Sicherungskopien der Datenbank auf unserem Webspace. Es werden jeweils nur die letzten Sicherungen aufbewahrt; ältere werden automatisch gelöscht.

## 12. Deine Rechte

Du hast das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21). Schreib uns dafür einfach an {{betreiber_email}}.

Außerdem kannst du dich bei einer Datenschutz-Aufsichtsbehörde beschweren, zum Beispiel bei der Aufsichtsbehörde deines Wohnorts oder bei der für uns zuständigen: {{aufsichtsbehoerde}}.

## 13. Änderungen

Wenn sich ElTouro weiterentwickelt, passen wir diese Erklärung an. Es gilt die jeweils hier veröffentlichte Fassung.

Stand: {{stand}}
TXT,
'en' => <<<'TXT'
## 1. Who is responsible?

{{betreiber_name}}
{{betreiber_anschrift}}
Email: {{betreiber_email}}

## 2. The short version

- We only process what ElTouro needs: your account, your content and technical data for secure operation.
- No tracking, no ads, no analytics, no social media plugins.
- Fonts, scripts and images come from our own server. The only exception are the map tiles (section 8).
- We never sell data or share it for advertising.

## 3. Hosting and server logs

ElTouro is hosted by ALL-INKL.COM – Neue Medien Münnich, Hauptstraße 68, 02742 Friedersdorf, Germany, under a data processing agreement (Art. 28 GDPR). For every request the server processes technically necessary data (IP address, date and time, requested address, data volume, browser and operating system) to deliver pages and fend off attacks (Art. 6(1)(f) GDPR). Log files are deleted after {{log_tage}} days.

## 4. Your account

For sign-up we process your email address, display name, date of birth, password (only as an encrypted hash) and language. We need your date of birth to check the minimum age of 16; other users can’t see it. Optionally you can add where you usually ride and something about yourself. Legal basis: performance of the user agreement (Art. 6(1)(b) GDPR).

We send emails with one-time links to confirm your address and reset your password, via our host’s mail server. Only a check value is stored in our database, not the link itself. Failed login attempts are stored with the IP address for at most 24 hours to prevent attacks (Art. 6(1)(f) GDPR).

We keep your account data as long as your account exists. To delete your account, email {{betreiber_email}}; we’ll delete it within 30 days, and your forum and crew posts will be anonymised or deleted.

## 5. What other users see

Other logged-in users see your display name and the content you publish. Crew members see the member list and posts in the crew; secret crews are only visible to their members. For each route you decide whether only you, your crew or all logged-in riders can see it. Forum posts are visible to all logged-in users.

## 6. Routes and location

A route consists of the waypoints you set on the map and the calculated track. We store it so you can find and share it (Art. 6(1)(b) GDPR). Make sure start and finish of public routes aren’t right outside your front door. The “My location” button asks your browser for your location, only with your consent, and only uses it to move the map. It is not sent to us or stored.

## 7. Route calculation

To calculate a route, our server sends the waypoints to a routing service we run ourselves (BRouter), connected via a Cloudflare, Inc. tunnel. Only coordinates are transmitted – no personal details and not your IP address.

## 8. Map tiles

Your browser loads map images directly from {{kartendienst}}. The service technically receives your IP address and which map section is loaded (Art. 6(1)(f) GDPR). {{kartendienst_hinweis}}

## 9. Cookies

We only use strictly necessary cookies (Section 25(2) no. 2 TDDDG): eltouro_sid keeps you logged in until you close the browser; lang remembers your language for a year; et_zugang (test environments only) remembers preview access for 30 days.

## 10. Reports and moderation

When you report content, we store the report with your account, the reported content and your reason, to handle it and meet our obligations under the Digital Services Act (Art. 6(1)(c) and (f) GDPR).

## 11. Backups

We regularly back up the database on our web space. Only the most recent backups are kept; older ones are deleted automatically.

## 12. Your rights

You have the right of access, rectification, erasure, restriction, data portability and objection (Art. 15–18, 20, 21 GDPR). Just email {{betreiber_email}}. You can also complain to a data protection authority, e.g. the one where you live or ours: {{aufsichtsbehoerde}}.

Last updated: {{stand}}. This translation is provided for convenience; the German version is legally binding.
TXT,
],

'nutzungsbedingungen' => [
'de' => <<<'TXT'
## 1. Worum es geht

ElTouro ist eine Community für E-Scooter-Fahrer. Du kannst Touren planen und teilen, Herden gründen oder ihnen beitreten und dich im Forum und in deiner Herde austauschen. Betreiber ist {{betreiber_name}} (siehe [Impressum](/impressum)). Die Nutzung ist kostenlos.

ElTouro befindet sich im Aufbau. Funktionen können sich ändern, zeitweise ausfallen oder wegfallen.

## 2. Wer mitmachen darf

Du musst mindestens 16 Jahre alt sein. Jede Person darf nur ein Konto haben. Deine Angaben bei der Registrierung müssen stimmen, und dein Passwort hältst du geheim.

## 3. ElTouro vermittelt – ihr fahrt selbst

ElTouro stellt eine Plattform bereit, auf der Nutzer Touren und Ausfahrten miteinander teilen und verabreden. **ElTouro veranstaltet keine Fahrten, leitet sie nicht und beaufsichtigt sie nicht.** Wer eine Tour oder Ausfahrt anbietet, ist dafür selbst verantwortlich. Jeder Teilnehmer fährt auf eigene Verantwortung.

Routen sind Vorschläge. Sie werden mit Kartendaten berechnet, die unvollständig oder veraltet sein können, und rot gestrichelte Abschnitte sind gar nicht geprüft. **Vor Ort gelten immer die Verkehrszeichen und die Straßenverkehrsordnung.** Angaben zu Schwierigkeit und Fahrstil sind Einschätzungen der Ersteller.

## 4. Deine Pflichten im Straßenverkehr

Wenn du über ElTouro Touren teilst oder an Ausfahrten teilnimmst, sagst du zu,

- nur mit einem für den Straßenverkehr zugelassenen und versicherten E-Scooter im öffentlichen Raum zu fahren,
- die geltenden Verkehrsregeln einzuhalten, insbesondere zu erlaubten Wegen, Höchstgeschwindigkeit und Alkohol,
- keine Fahrten mit entdrosselten oder nicht zugelassenen Fahrzeugen im öffentlichen Straßenverkehr zu verabreden oder zu bewerben.

Wer eine Ausfahrt mit vielen Teilnehmern organisiert, prüft selbst, ob dafür eine Erlaubnis der Straßenverkehrsbehörde nötig ist (§ 29 StVO), und kümmert sich um diese.

## 5. Was du veröffentlichst

Für deine Inhalte bist du selbst verantwortlich. Nicht erlaubt sind insbesondere Inhalte, die

- gegen Gesetze verstoßen oder Rechte anderer verletzen (zum Beispiel Urheber-, Persönlichkeits- oder Markenrechte),
- beleidigen, bedrohen, hetzen oder diskriminieren,
- zu gefährlichem oder verbotenem Verhalten im Straßenverkehr anleiten, etwa zum Entdrosseln für die Straße,
- Werbung oder Spam sind, oder
- personenbezogene Daten anderer ohne deren Einverständnis enthalten.

Du räumst uns das einfache, unentgeltliche Recht ein, deine Inhalte auf ElTouro im Rahmen der von dir gewählten Sichtbarkeit anzuzeigen und dafür technisch zu verarbeiten. Das Recht endet, wenn du den Inhalt löschst; Kopien in Sicherungen werden mit dem regulären Löschzyklus entfernt.

## 6. Melden und Moderation

Jeder kann Inhalte über „Melden“ anzeigen. Wir prüfen Meldungen und können Inhalte entfernen, ihre Sichtbarkeit einschränken oder Konten vorübergehend oder dauerhaft sperren, wenn gegen diese Bedingungen oder Gesetze verstoßen wird. Betroffene informieren wir über die Entscheidung und ihre Gründe und können sich per E-Mail an {{betreiber_email}} dagegen wenden. Leitstiere können in ihrer Herde Beiträge und Mitglieder verwalten.

## 7. Haftung

Wir haften unbeschränkt bei Vorsatz und grober Fahrlässigkeit sowie für Schäden aus der Verletzung von Leben, Körper oder Gesundheit. Bei leichter Fahrlässigkeit haften wir nur bei Verletzung einer wesentlichen Vertragspflicht und begrenzt auf den typischen, vorhersehbaren Schaden. Für Inhalte der Nutzer, für von Nutzern organisierte Fahrten und für die Richtigkeit von Kartendaten übernehmen wir keine Haftung, soweit das gesetzlich zulässig ist.

## 8. Konto beenden

Du kannst dein Konto jederzeit löschen lassen (Mail an {{betreiber_email}}). Wir können den Nutzungsvertrag mit einer Frist von zwei Wochen kündigen und bei schweren Verstößen sofort.

## 9. Änderungen

Wir können diese Bedingungen anpassen, etwa wenn neue Funktionen hinzukommen. Über wesentliche Änderungen informieren wir dich rechtzeitig vorher per E-Mail oder in der App.

## 10. Schlussbestimmungen

Es gilt deutsches Recht. Zwingende Verbraucherschutzvorschriften deines Wohnsitzstaats bleiben unberührt.

Stand: {{stand}}
TXT,
'en' => <<<'TXT'
## 1. What this is about

ElTouro is a community for e-scooter riders: plan and share routes, start or join crews, and talk in the forum and your crew. Operator: {{betreiber_name}} (see [legal notice](/impressum)). Use is free of charge. ElTouro is under development; features may change, be temporarily unavailable or be removed.

## 2. Who may join

You must be at least 16. One account per person. Your sign-up details must be correct, and you keep your password secret.

## 3. ElTouro connects – you ride yourselves

ElTouro provides a platform where users share and arrange routes and rides. **ElTouro does not organise, lead or supervise rides.** Whoever offers a route or ride is responsible for it, and every participant rides at their own risk. Routes are suggestions based on map data that may be incomplete; red dashed sections are unchecked. **Road signs and traffic law on site always take priority.**

## 4. Your duties on the road

You agree to ride in public only with a road-legal, insured e-scooter, to follow traffic rules, and not to arrange or promote public rides with de-restricted or unapproved vehicles. Organisers of large rides check themselves whether a permit is required (Section 29 StVO).

## 5. What you publish

You are responsible for your content. Not allowed: illegal content or content infringing others’ rights, insults, threats, hate or discrimination, instructions for dangerous or prohibited behaviour on the road, advertising or spam, and personal data of others without consent. You grant us a simple, free right to display and technically process your content on ElTouro within the visibility you choose, ending when you delete it.

## 6. Reporting and moderation

Anyone can report content. We review reports and may remove content, restrict visibility or suspend accounts for violations. We inform those affected about our decision and reasons; you can object by emailing {{betreiber_email}}. Crew leads can manage posts and members in their crew.

## 7. Liability

We are fully liable for intent and gross negligence and for injury to life, body or health. For slight negligence we are only liable for breach of essential contractual obligations, limited to typical foreseeable damage. To the extent permitted by law we are not liable for user content, user-organised rides or the accuracy of map data.

## 8. Ending your account

You can have your account deleted at any time ({{betreiber_email}}). We may terminate with two weeks’ notice, and immediately for serious violations.

## 9. Changes and final provisions

We may update these terms and will inform you about significant changes in advance. German law applies; mandatory consumer protection rules of your country of residence remain unaffected.

Last updated: {{stand}}. This translation is provided for convenience; the German version is legally binding.
TXT,
],
];
