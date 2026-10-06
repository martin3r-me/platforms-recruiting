# Vertragsversand vormerken — Design

Stand: 06.10.2026 · Anlass: Fall 4282 (Soufiane Mousslim) · Vorgänger: Versand-Riegel (35e09234)

## Ausgangslage

Clara oder die Schulungsleitung versenden nach der Schulung Verträge und Portal-Link
gesammelt aus der Nachbereitung (oder einzeln vom HR-Schreibtisch). Die Reihenfolge ist:

1. Verträge werden erzeugt und als versendet markiert (`SendContractsService`).
2. Das schließt die Vertragsphase ab (`completion_type = contract_sent`), der Phasen-Hook
   `creates_employee_on_completion` legt den Mitarbeiter an.
3. Erst danach geht der Portal-Link raus (`ContractDispatchService`, braucht `RecEmployee`).

Wer zum Versandzeitpunkt noch in Phase 3 „Onboarding (Bestätigung)" steht, hat die
Daten nicht vollständig (Adresse, Ausweis, Nationalität …). Heute passiert dann:

- Der Versand-Riegel lässt ihn durch, weil eine **spätere** Phase den Mitarbeiter anlegt.
- Verträge gehen raus, aber es entsteht kein Mitarbeiter und **kein Portal-Link**.
- Füllt er später aus, wird der Mitarbeiter angelegt — der Portal-Link geht dabei
  **nicht** automatisch raus (bewusst, `CreateEmployeeFromApplicantService`).
- Ein zweiter Versand wird übersprungen, weil schon Verträge raus sind.

Ergebnis: Er bekommt das Portal nie, wenn es niemand von Hand nachschickt.

Zweite Lücke: Nicht-EU-Bewerber kommen auf den HR-Schreibtisch, wenn die Buchung auf
`attended` springt (`RecInterviewBookingComplianceObserver`). Wer erst **nach** der
Schulung im Onboarding „kein EU-Bürger" angibt, wird nie geroutet.

## Ziel

Möglichst alles automatisch, aber für die Schulungsleitung jederzeit nachvollziehbar:

- Versendet wird nur, wer **vollständig** ist (Mitarbeiter existiert oder Person steht in
  der Vertragsphase).
- Wer unvollständig ist, wird **vorgemerkt**: Er bekommt die Onboarding-Nachricht mit dem
  Formular-Link, und sobald er fertig ist, gehen Verträge und Portal-Link **automatisch**
  mit den beim Vormerken eingetragenen Angaben raus.
- Pro Teilnehmer ist immer genau ein Versand-Zustand sichtbar, jeder Schritt steht im
  Verlauf.

## Nicht-Ziele

- Keine neue Meta-Vorlage. Die Erinnerung ist die Onboarding-Nachricht der aktuellen Phase.
- Kein automatischer Versand ohne vorherigen Klick. Eine Vormerkung entsteht nur durch
  einen bewussten Versand-Versuch (Nachbereitung oder HR-Schreibtisch).
- Keine Änderung an Direkteinstellung und manuellen Einzel-Aktionen der Bewerberseite
  (dort greift weiter der Riegel).

## Entscheidungen (User, 06.10.2026)

1. Variante B: automatischer Versand nach Vervollständigung (statt erneuter Klick).
2. Liegt der gespeicherte Vertragsbeginn beim Auslösen in der **Vergangenheit**, bleibt die
   Vormerkung stehen mit Hinweis „Vertragsbeginn liegt in der Vergangenheit — bitte
   anpassen". Kein rückwirkender Vertrag ohne Mensch.
3. Erinnerung = `auto_pilot_wa_initial_template_id` der aktuellen Phase (Phase 3), über
   den bestehenden `ApplicantTemplateSender`.
4. Nicht-EU nach Vervollständigung → HR-Schreibtisch; Freigabe dort löst den vorgemerkten
   Versand automatisch aus.

## Design

### 1. Riegel verschärfen

`RecApplicant::mitarbeiterAnlageSperrgrund()` bekommt eine zweite Stufe, die nur für
den **Versand** gilt (Hinweise in Listen nutzen beide):

- **Gesperrt** (wie heute): keine Phase, Phase einer fremden Stelle, Stelle ohne
  Anlage-Phase, Direkteinstellung.
- **Noch nicht bereit** (neu): Mitarbeiter fehlt UND die aktuelle Phase ist nicht die
  Anlage-Phase (`creates_employee_on_completion`). Grund enthält die fehlenden
  Pflichtfelder der aktuellen Phase, z. B. „Onboarding unvollständig: Straße,
  Ausweis-Foto Vorderseite, Nationalität".

Neue Methode `RecApplicant::versandBereitschaft(): VersandBereitschaft` (Wertobjekt mit
`status` = `bereit` | `unvollstaendig` | `gesperrt` und `grund`, `fehlendeFelder`).
`mitarbeiterAnlageSperrgrund()` bleibt für `gesperrt` erhalten.

`SendContractsService::send` wirft bei `unvollstaendig` wie bei `gesperrt` (harter Riegel,
solange noch kein Vertrag raus ist). Damit kann kein Weg mehr Verträge ohne
Portal-Fähigkeit verschicken.

### 2. Vormerkung

Neue Tabelle `rec_contract_send_reservations`:

| Spalte | Zweck |
|---|---|
| `id`, `team_id`, `rec_applicant_id` | Bezug |
| `rec_interview_booking_id` (nullable) | aus welcher Schulung vorgemerkt |
| `vertragsbeginn`, `vertragsende` (date, nullable) | die beim Klick eingetragenen Werte |
| `source` | `nachbereitung` \| `hr_desk` |
| `reserved_by_user_id`, `reserved_at` | wer, wann |
| `last_reminder_at` | letzte Erinnerung (Drossel: max. 1 pro 24 h) |
| `last_attempt_at`, `last_attempt_result` | letzter automatischer Versuch + Grund, falls gescheitert |
| `completed_at`, `cancelled_at`, `cancel_reason` | Ende |

Höchstens eine **offene** Vormerkung je Bewerber (offen = `completed_at` und
`cancelled_at` leer). Erneutes Vormerken aktualisiert die offene statt eine zweite
anzulegen (neue Daten gewinnen, Verlaufseintrag „Vormerkung aktualisiert").

Vorlage und Zuschlag werden **nicht** gespeichert — sie stehen an der Bewerbung und
werden beim Auslösen frisch gelesen (wie beim Klick).

### 3. Ablauf beim Klick „Versenden" (Nachbereitung, HR-Schreibtisch)

Für jeden Teilnehmer nach den bestehenden Prüfungen (Teilgenommen, Vorlage, Zuschlag,
Vertragsbeginn, Rechtsstatus):

- `bereit` → sofort senden wie heute.
- `unvollstaendig` → Vormerkung anlegen/aktualisieren, Erinnerung senden (Drossel),
  Verlauf: „Versand vorgemerkt von Clara — Onboarding unvollständig: …".
- `gesperrt` → überspringen wie heute (Riegel), keine Vormerkung.

Flash-Meldung: „12 versendet, 3 vorgemerkt (Daten fehlen — Erinnerung geschickt),
1 gesperrt: …".

### 4. Automatisches Auslösen

Ein Job `SendReservedContractsJob(applicantId)` wird **nach Commit** dispatcht, wenn:

- (a) `rec_phase_id` wechselt in eine Phase mit `creates_employee_on_completion`
  (Beobachter auf `RecApplicant`, pfadunabhängig: Autopilot, Formular, Dashboard-Button,
  Werkzeuge) und eine offene Vormerkung existiert;
- (b) ein HR-Schreibtisch-Fall `non_eu_citizen` freigegeben wird (`approveCase`) bzw. der
  Rechtsstatus als geprüft markiert wird, und eine offene Vormerkung existiert.

Der Job prüft alles frisch, in dieser Reihenfolge, und schreibt das Ergebnis an die
Vormerkung + in den Verlauf:

1. Vormerkung noch offen? Bewerber aktiv, nicht geparkt, nicht abgelehnt? Sonst abbrechen
   (`cancel_reason`).
2. Schon ein Vertrag raus (anderer Weg)? → `completed_at`, „bereits versendet".
3. Rechtsstatus ungeprüft (Nicht-EU)? → HR-Fall routen (siehe 5), Ergebnis
   „wartet: Rechtsstatus", Vormerkung bleibt offen.
4. `versandBereitschaft()` ≠ `bereit` → Ergebnis mit Grund, bleibt offen.
5. Vertragsbeginn < heute → Ergebnis „Vertragsbeginn liegt in der Vergangenheit — bitte
   anpassen", bleibt offen.
6. Zuschlag fehlt → Ergebnis mit Grund, bleibt offen.
7. `ContractDispatchService::sendForApplicant(...)` mit den gespeicherten Daten,
   `userId = reserved_by_user_id`. Erfolg → `completed_at`, Verlauf „Verträge + Portal-Link
   automatisch versendet (vorgemerkt von Clara am …)". Fehler → Ergebnis mit Text, bleibt
   offen.

Der Job ist idempotent (Zeilensperre auf der Vormerkung, Prüfung 2).

### 5. Nicht-EU nach der Schulung

Beim Auslösen (Prüfung 3) und zusätzlich, wenn bei offener Vormerkung `eu_burger` auf
„Nein" gesetzt wird: `HrDeskRoutingService::routeIfNotAlreadyOpen(…, non_eu_citizen, …,
'Versand vorgemerkt von X am … (Vertragsbeginn …) — nach Prüfung geht er automatisch
raus.')`. Die HR-Karte zeigt die Vormerkung. Freigabe → Auslöser (b).

### 6. Aufräumen

Vormerkung wird automatisch beendet (`cancelled_at` + Grund + Verlauf), wenn:
Buchung auf `cancelled`/`no_show`/`rejected_on_site` geht, Bewerber geparkt / abgelehnt /
deaktiviert wird. Manuell zurücknehmen per Zeilen-Aktion „Vormerkung zurücknehmen".

### 7. Anzeige

Nachbereitung: neue Spalte **Versand** mit genau einem Zustand je Teilnehmer:

| Zustand | Text |
|---|---|
| bereit | „Bereit" |
| vorgemerkt, Daten fehlen | „Vorgemerkt · Daten fehlen: … · erinnert 07.10. 19:12" |
| vorgemerkt, wartet | „Vorgemerkt · wartet: Rechtsstatus / Vertragsbeginn in der Vergangenheit / …" |
| versendet | „Versendet 08.10. 10:03 (automatisch, vorgemerkt von Clara)" bzw. „Versendet 07.10." |
| gesperrt | „Gesperrt: …" (Riegel) |

Dazu pro Zeile „Erinnern" (Drossel beachten) und bei Vormerkung „Zurücknehmen".
Übersicht (oberer Hinweis) zählt vorgemerkt / gesperrt. HR-Schreibtisch-Karte und
Bewerberseite (Kasten „Stelle & Phase") zeigen eine offene Vormerkung mit Stand.

### 8. Verlauf (`rec_auto_pilot_logs`)

Neue Typen: `contract_send_reserved`, `contract_send_reminder`, `contract_send_auto_sent`,
`contract_send_waiting`, `contract_send_cancelled`. Jeder mit Nutzer, Daten und Grund.

## Tests

- `versandBereitschaft`: bereit / unvollständig (mit Feldliste) / gesperrt.
- Riegel im Versanddienst wirft bei `unvollstaendig`.
- Klick-Ablauf: gemischte Gruppe → senden / vormerken / sperren, Meldung.
- Vormerkung: eine offene je Bewerber, Aktualisierung, Drossel der Erinnerung.
- Auslöser (a) über Phasenwechsel aus jedem Weg (Modell-Ebene), (b) HR-Freigabe.
- Job: jede Prüfstufe einzeln (abgebrochen, schon versendet, Rechtsstatus → HR-Fall,
  unvollständig, Vertragsbeginn vergangen, Zuschlag fehlt, Erfolg), Idempotenz.
- Aufräumen bei Absage / Parken / no_show.

## Risiken

- **Doppelter Versand:** ausgeschlossen durch Prüfung 2 + Zeilensperre + `hasAnyContractSent`.
- **Versand ohne Mensch:** nur mit vorheriger Vormerkung durch einen Menschen; Daten aus
  dem Klick; vergangener Vertragsbeginn stoppt.
- **Queue-Worker:** neuer Job → nach Deploy `queue:restart` nötig.
- **Bestehende 29 Teilnehmer in Phase 3 (Gladbach 07.10.):** werden beim Versand am
  Mittwoch vorgemerkt statt (wie heute) ohne Portal versendet — gewollt.

## Deploy

Migration (`rec_contract_send_reservations`), `queue:restart`, `view:clear`.
