# Belohnungssystem – Konzept

Stand: 08.10.2026 · Status: **Konzept, noch nichts gebaut** · Lebendes Dokument – bei jeder Erweiterung oben im Entscheidungsprotokoll nachtragen.

## Ziel

Wer die Community voranbringt, soll das sehen und zeigen können: Leute in die Community holen, gemeinsam fahren, Ausfahrten organisieren, gute Touren anlegen. Daraus entsteht ein **Rang**, der den sozialen Status in der Community zeigt, und **Abzeichen**, die zeigen, *wofür* jemand steht.

## Entscheidungen

| Datum | Entscheidung |
|---|---|
| 08.10.2026 | Vorerst **keine Belohnungen mit Geldwert** (keine Rabatte, kein Merch, keine Auszahlung). Später möglich – dann Missbrauchsschutz, Steuer- und Gewinnspielrecht neu bewerten. |
| 08.10.2026 | Ränge mit **spanischen Namen** (passend zu ElTouro). |
| 08.10.2026 | Der Rang ist **für alle angemeldeten Nutzer sichtbar**. |
| 08.10.2026 | Grundprinzip: **Ereignisse speichern, Punkte berechnen** (siehe unten). |

## Grundprinzipien – damit wir uns nichts verbauen

1. **Ereignisse statt Punkte.** Gespeichert wird, *was passiert ist* (Ausfahrt gefahren, Nutzer geworben …), nie direkt ein Punktestand. Punkte, Ränge und Abzeichen werden aus den Ereignissen berechnet. Regeln dürfen sich ändern; dann wird neu berechnet. Kein Ereignis geht verloren.
2. **Herkunft ab Tag 1 erfassen.** Wer wen geholt hat und über welchen Kanal, lässt sich später nicht rekonstruieren. Das muss zuerst gebaut werden – auch wenn noch keine Punkte sichtbar sind.
3. **Erst Wirkung, dann Belohnung.** Belohnt wird, was der Community nützt: ein Geworbener, der wirklich mitfährt; eine Ausfahrt, die wirklich stattfand. Bloße Anmeldungen, angelegte Touren oder Klicks bringen wenig.
4. **Gemeinsam zählt mehr als allein.** Kilometer in der Gruppe sind mehr wert als allein gefahrene.
5. **Nie gefährliches Verhalten belohnen.** Keine Geschwindigkeit, keine Bestzeiten, keine Kilometer-Rennen an einem Tag, nichts, was zu großen Gruppen ohne § 29 StVO verleitet.
6. **Regeln sind versioniert und transparent.** Jede Punktezeile weiß, nach welcher Regel und Version sie entstand. Nutzer können sehen, wofür sie Punkte bekommen haben.

## Bausteine

### 1. Ereignisprotokoll `activity_events`

Unveränderlich (nur „entwerten“ ist möglich). Jedes Ereignis ist idempotent: dasselbe Ereignis zum selben Objekt wird nur einmal gespeichert.

| Spalte | Bedeutung |
|---|---|
| `id` | |
| `user_id` | wem das Ereignis gehört |
| `type` | z. B. `ride_attended` (Katalog unten) |
| `subject_type`, `subject_id` | worauf es sich bezieht (`ride` 12, `tour` 5, `user` 88 …) |
| `related_user_id` | zweite Person, z. B. der Geworbene beim Werber |
| `value` | Zahlenwert, z. B. Kilometer |
| `meta` | JSON mit Details zum Zeitpunkt des Ereignisses (Teilnehmerzahl, Kanal …) – Regeln können später darauf zugreifen |
| `occurred_at` | wann es passiert ist (UTC) |
| `voided_at`, `voided_by`, `void_reason` | Entwertung durch Admins (Missbrauch), bleibt nachvollziehbar |
| Eindeutig | (`type`, `user_id`, `subject_type`, `subject_id`) |

**Ereigniskatalog (erste Fassung)**

| Typ | Für wen | Auslöser |
|---|---|---|
| `user_verified` | neuer Nutzer | E-Mail bestätigt oder Google-Anmeldung abgeschlossen |
| `referral_verified` | Werber | ein von ihm geholter Nutzer ist bestätigt |
| `referral_activated` | Werber | der Geworbene hat seine **erste Ausfahrt gefahren** |
| `ride_attended` | Teilnehmer | Teilnahme an einer stattgefundenen Ausfahrt bestätigt (`value` = km) |
| `ride_hosted` | Organisator | eigene Ausfahrt fand statt (`meta.attendees`) |
| `tour_created` | Ersteller | Tour angelegt (nur Herde/öffentlich zählt für Punkte) |
| `tour_ridden_by_others` | Tour-Ersteller | eine Ausfahrt eines anderen auf seiner Tour fand statt |
| `share_link_created` | Teilender | Teilen-Link erzeugt (nur fürs Protokoll, keine Punkte) |

Neue Ereignistypen kommen einfach dazu (z. B. später `recording_finished` aus der GPS-Aufzeichnung, `photo_shared`, `forum_answer_liked`).

### 2. Herkunft `referrals`

Eine Zeile pro neuem Nutzer, der über jemanden kam. **Erster Kontakt zählt** und wird danach nie überschrieben.

| Spalte | Bedeutung |
|---|---|
| `user_id` (Primärschlüssel) | der Geworbene |
| `referrer_user_id` | der Werber (null bei Kanal ohne Person) |
| `source` | `invite_link` (persönlicher Link), `crew_invite` (Herden-Einladung), `share_link` (geteilte Tour), `ride_share` (später: geteilte Ausfahrt) |
| `channel` | `whatsapp`, `telegram`, `facebook`, `x`, `email`, `copy`, `native`, `unknown` – **Markierung für Social Media** |
| `ref_type`, `ref_id` | z. B. `tour` 17 bzw. `crew` 3 – woran es lag |
| `created_at` | |

**Wie die Herkunft erfasst wird**

- Jeder Nutzer bekommt einen **persönlichen Einladungslink** `/join/<code>` (neue Spalte `users.invite_code`), sichtbar im Profil mit denselben Teilen-Knöpfen wie bei Touren.
- Die Teilen-Knöpfe hängen den Kanal an: `/s/<token>?via=whatsapp`. Der Teilen-Link selbst ist dem Teilenden zugeordnet (wer den Link erzeugt hat bzw. der Ersteller der Tour – Detail festlegen).
- Herden-Einladungslinks (`/crew/<slug>?code=…`) zählen für den Leitstier, der sie geteilt hat, bzw. die Herde.
- Weitergabe bis zur Registrierung: Die Links führen mit `?ref=…` zur Registrierung; zusätzlich merkt sich die **bestehende Sitzung** (kein zusätzliches Cookie) den ersten Kontakt für den Fall, dass jemand erst herumklickt. Gilt auch für die Anmeldung mit Google.
- Selbstwerbung ist ausgeschlossen (gleiche Person, gleiche E-Mail-Domain + gleiche IP am selben Tag wird markiert, nicht automatisch abgelehnt).

### 3. Teilnahme bestätigen (Voraussetzung für Kilometer)

Heute wissen wir nur, wer sich angemeldet hat – nicht, wer gefahren ist. Dafür:

- Nach dem Termin bestätigt der **Organisator**, wer dabei war (`ride_signups.attended_at`, `attendance_confirmed_by`). Standard: alle Bestätigten sind angehakt, er nimmt Fehlende heraus.
- Teilnehmer können ihre eigene Teilnahme **widersprechen** („war nicht dabei“) – Schutz gegen Organisatoren, die Freunde „mitfahren lassen“.
- Erst danach entstehen `ride_attended`, `ride_hosted` und `tour_ridden_by_others`.
- Später belegt die **GPS-Aufzeichnung** auch Solo-Kilometer (Backlog). Geplante Touren allein zählen nie als gefahren.

### 4. Punkte `points_ledger` (abgeleitet, jederzeit neu berechenbar)

| Spalte | Bedeutung |
|---|---|
| `user_id`, `event_id` | |
| `rule_key`, `rule_version` | welche Regel in welcher Version |
| `points` | |
| `created_at` | |

Die Regeln stehen **im Code** (`rewards_rules.php`, versioniert), nicht in der Datenbank – so sind sie getestet und im Git-Verlauf nachvollziehbar. Neuberechnung: Ledger eines Nutzers leeren, alle gültigen Ereignisse durch die aktuellen Regeln schicken. Ein Admin-Knopf „Punkte neu berechnen“ macht das für alle.

**Regeln v1 (Vorschlag, Werte zum Diskutieren)** – die Punkte heißen „**Herdenpunkte**“.

| Regel | Punkte | Bedingungen |
|---|---|---|
| Geworbener bestätigt (`referral_verified`) | 10 | |
| Geworbener fährt erste Ausfahrt (`referral_activated`) | 50 | Hauptbelohnung fürs Werben |
| Ausfahrt gefahren (`ride_attended`) | 1 pro km × Gruppenfaktor | Gruppenfaktor: 3–4 Fahrer ×1,0 · 5–9 ×1,25 · ab 10 ×1,5. Ausfahrten mit weniger als 3 bestätigten Teilnehmern zählen 0,5 pro km. Höchstens 150 km pro Tag. |
| Ausfahrt organisiert (`ride_hosted`) | 20 + 5 pro Teilnehmer | nur wenn sie stattfand; Teilnehmerbonus bis höchstens 15 Teilnehmer (keine Anreize für Großgruppen ohne § 29 StVO) |
| Tour angelegt (`tour_created`) | 5 | nur Herde/öffentlich; höchstens 3 pro Tag |
| Andere fahren deine Tour (`tour_ridden_by_others`) | 15 | höchstens 1× pro Tour und Woche |
| Immer dieselbe Gruppe | ×0,5 auf Ausfahrt-Punkte | wenn ≥ 80 % der Teilnehmer schon in den letzten 7 Tagen gemeinsam gefahren sind – fördert neue Begegnungen |
| Tageslimit | 300 | Summe aller Punkte pro Tag |

### 5. Ränge

Abgeleitet aus den **gesamten Herdenpunkten seit Beginn**. Ränge steigen nur, sie fallen nicht (Status). Saisonale Wertungen können später *zusätzlich* kommen, ohne die Ränge anzutasten.

| Rang | ab Punkten | Bedeutung (wörtlich) |
|---|---|---|
| **Becerro** | 0 | Kalb |
| **Añojo** | 100 | Jährling |
| **Novillo** | 300 | Jungstier |
| **Toro** | 800 | Stier |
| **Toro Bravo** | 2.000 | Kampfstier |
| **Leyenda** | 5.000 | Legende |

Hinweis: „Leitstier“ bleibt die **Rolle** in einer Herde und ist kein Rang. Die Schwellen werden nach den ersten Monaten mit echten Zahlen nachjustiert (dank Neuberechnung problemlos).

**Sichtbarkeit (entschieden: für alle):** Der Rang erscheint für alle angemeldeten Nutzer am Namen – in Herden, Tränke, Forum, Teilnehmerlisten und im Profil. Punkte und Abzeichen stehen im Profil. Wer wen geworben hat, wird nicht öffentlich gezeigt (nur die Anzahl, z. B. „hat 7 Fahrer in die Herde geholt“).

### 6. Abzeichen

Zeigen, *wofür* jemand steht – in mehreren Dimensionen, damit nicht nur Vielfahrer aufsteigen. Jeweils drei Stufen (Bronze, Silber, Gold). Einmal verliehen, bleiben sie (außer bei entwerteten Ereignissen durch Missbrauch).

| Abzeichen | Stufen | Kriterium |
|---|---|---|
| **Botschafter** | 1 / 5 / 20 | geworbene Fahrer, die ihre erste Ausfahrt gefahren sind |
| **Social-Botschafter** | 1 / 5 / 20 | davon über Social Media geholt (`channel` ≠ `copy`/`unknown`) – die gewünschte Markierung |
| **Kilometerfresser** | 100 / 500 / 2.000 km | bestätigte Kilometer in Ausfahrten |
| **Gastgeber** | 1 / 10 / 50 | organisierte Ausfahrten, die stattfanden (mind. 3 Teilnehmer) |
| **Wegbereiter** | 1 / 10 / 50 | Ausfahrten anderer auf deinen Touren |
| **Herdentier** | 10 / 50 / 200 | verschiedene Personen, mit denen du gefahren bist |
| **Gründer** | einmalig | Herde gegründet, die mindestens 5 aktive Mitglieder hat |
| **Pionier** | einmalig | gehört zu den ersten 500 bestätigten Fahrern (Start-Abzeichen) |

Gespeichert in `user_badges` (`user_id`, `badge_key`, `tier`, `awarded_at`). Neue Abzeichen sind nur neuer Code – die Ereignisse dafür liegen schon vor und gelten rückwirkend.

### 7. Statistik-Cache `user_stats`

Für schnelle Anzeige: `points`, `rank_key`, `km`, `rides_attended`, `rides_hosted`, `referrals_active`, `updated_at`. Wird bei neuen Ereignissen aktualisiert und kann jederzeit aus dem Ledger neu aufgebaut werden.

## Missbrauchsschutz

- Punkte fürs Werben erst, wenn der Geworbene **wirklich mitfährt** – Fake-Konten bringen nichts.
- Ausfahrt-Punkte erst nach **bestätigter Teilnahme** mit Widerspruchsrecht der Teilnehmer.
- Kleine Gruppen zählen weniger, gleiche Gruppen in Serie werden abgewertet, Tageslimits.
- Auffälligkeiten (viele Konten von einer IP, Werber-Ketten, Ausfahrten mit immer denselben frischen Konten) erscheinen im Admin unter „Auffälligkeiten“; Admins können Ereignisse **entwerten** statt löschen. Danach Neuberechnung.
- Solange es keinen Geldwert gibt, reicht dieses Niveau. Bei echten Prämien: zusätzlich Wartezeit vor Einlösung, manuelle Prüfung, Altersgrenze 18 für Prämien prüfen.

## Recht und Datenschutz

- **Kein Geldwert** → kein Gewinnspiel- oder Steuerthema. Punkte sind nicht übertragbar und nicht auszahlbar; das kommt so in die Nutzungsbedingungen.
- **Datenschutzerklärung** ergänzen: Welche Aktivitäten fließen ein, Herkunft der Anmeldung (Werber, Kanal), Sichtbarkeit von Rang und Abzeichen für alle angemeldeten Nutzer, Rechtsgrundlage Nutzungsvertrag (Art. 6 Abs. 1 lit. b DSGVO) bzw. berechtigtes Interesse für Missbrauchsschutz (lit. f).
- Wer geworben hat, wird dem Geworbenen angezeigt („Du bist über X zu ElTouro gekommen“) – Transparenz, keine öffentliche Anzeige.
- Ranglisten (später): für alle sichtbar wie der Rang, aber mit **Opt-out** im Profil („nicht in Ranglisten zeigen“) – offen, siehe unten.
- Kein Tracking: Die Kanal-Markierung entsteht nur durch den Parameter an unseren eigenen Links, keine Drittanbieter-Pixel.
- Löschen des Kontos: Ereignisse werden gelöscht bzw. beim Werber anonymisiert (Zähler bleibt, Person nicht).

## Ausbaustufen

| Stufe | Inhalt | Sichtbar für Nutzer |
|---|---|---|
| **1 – Fundament** (als Nächstes) | `activity_events`, `referrals`, `users.invite_code`, Einladungslink `/join/<code>` im Profil, `?via=` an allen Teilen-Knöpfen, Herkunft bei Registrierung (Formular und Google) erfassen, Ereignisse für Bestätigung und Tour-Anlage schreiben, Datenschutzerklärung | Einladungslink im Profil |
| **2 – Teilnahme** | Teilnahme nach der Ausfahrt bestätigen + Widerspruch, Ereignisse `ride_attended`, `ride_hosted`, `tour_ridden_by_others`, `referral_activated` | „Teilnahme bestätigen“ bei Organisatoren |
| **3 – Punkte & Ränge** | `rewards_rules.php` v1, `points_ledger`, `user_stats`, Rang am Namen, Punkteübersicht im Profil („wofür habe ich Punkte bekommen“), Admin: neu berechnen, entwerten | Rang, Punkte |
| **4 – Abzeichen** | `user_badges`, Abzeichen im Profil, Mitteilung bei neuem Abzeichen | Abzeichen |
| **5 – Ranglisten** | pro Herde, pro Region, gesamt; Monats-/Saisonwertungen zusätzlich zum Lebenszeit-Rang | Ranglisten |
| **später** | GPS-Aufzeichnung als km-Nachweis, Foto-Abzeichen, ggf. echte Prämien (Neubewertung!) | |

## Offene Fragen

1. **Teilen-Link-Zuordnung:** Wer bekommt den Geworbenen, wenn jemand eine öffentliche Tour eines anderen teilt – der Teilende (Vorschlag) oder der Ersteller?
2. **Ranglisten:** für alle sichtbar wie der Rang, mit Opt-out (Vorschlag) – oder Opt-in?
3. **Herden-Einladungen:** zählt der Werber-Bonus für den Leitstier persönlich oder für die Herde (Herden-Rang als eigenes Thema)?
4. **Bestandsnutzer:** Bekommen Fahrer, die vor dem Start des Systems dabei waren, das Abzeichen „Pionier“ automatisch? (Vorschlag: ja.)
5. **Punktwerte v1:** die Tabelle oben ist ein Startvorschlag – nach den ersten Wochen mit echten Daten nachjustieren.
