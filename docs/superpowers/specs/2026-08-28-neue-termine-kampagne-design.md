# Kampagne „Neue Termine“ aus der Statistik — Design

Stand: 28.08.2026 · Status: Entwurf zur Freigabe · Modul: platforms-recruiting

## 1. Ziel

HR sieht in der Statistik (`/recruiting/statistik`) pro Filiale die Kachel **„Ohne Termin“** (MGL: 156 Personen).
Werden neue Schulungstermine angelegt, soll HR diese Personen **mit einem Klick** per WhatsApp darüber informieren —
mit dem für ihre Phase richtigen Template und Link — und der Auto-Pilot soll danach **normal weiterlaufen**, statt
im erschöpften Status (`review_needed`) zu verharren.

## 2. Befunde, auf denen das Design steht

Analyse 28.08. (Prod, MGL „Ohne Termin“, 158 nachgebildet vs. 156 in der Statistik — Differenz = Filter „nur online“):

| Phase | Ohne Termin | Zustand | WA-erreichbar |
|---|---|---|---|
| P1 Bewerbung | 57 | Formular nie ausgefüllt (43 bei 42 % = nur Auto-Extraktion). Nie gebucht. | 36 (21 ohne Telefon) |
| P2 Schulung buchen | 60 | Daten komplett, nur Termin fehlt. 57 nie gebucht, 3 storniert. 16 mit offenem Ort-Wartelisten-Eintrag. | 60 |
| P3 Onboarding | 20 | Hatten gebucht; Buchung storniert (65 % selbst, Rest HR). Onboarding-Daten fehlen. | 20 |
| P4 Verträge | 21 | Daten komplett; Buchung storniert (73 % selbst, Rest HR). | 21 |

Ursachen, warum niemand von ihnen mehr Nachrichten bekommt:

1. **Auto-Pilot-Kadenz MGL: 12 h Intervall, 2 Erinnerungen.** Nach ~36 h ohne Reaktion → `review_needed` → aus der
   Auto-Pilot-Query raus (`ProcessAutoPilotApplicants.php:633`), dauerhaft.
2. **`review_needed` überlebt den Phasen-Aufstieg.** `checkAutoPilotCompletion()` setzt beim Auto-Advance Zähler und
   Timer zurück, den Status nicht (`RecApplicant.php:551–556`). Wer spät reagiert und aufsteigt, bekommt den
   Erstkontakt der neuen Phase (z. B. Buchungslink) **nie**. Einzige Ausnahme im Code: `returnToBookingPhase()`
   setzt den Status aus genau diesem Grund zurück (`RecApplicant.php:867` + Kommentar).
3. **Ort-Warteliste feuert einmal und pausiert danach den Auto-Pilot dauerhaft** (`NotifyWaitlistForInterview`
   `whereNull('notified_at')`; `ProcessAutoPilotApplicants.php:170`). 16 P2-Leute seit Juli/August still.

Technische Grundlage: Der Buchungslink `/recruiting/interviews/{token}` und der Formularlink `/form/{token}` nutzen
**denselben** Personen-Token (`getOrCreatePublicFormLink()`, 32 Hex-Zeichen). Die Buchungsseite prüft keine Phase
(`Public/InterviewBooking::mount`); ab Phase 3 gilt der Bewerber als festgelegt und sieht die Termine seiner Stelle
(`RecApplicant::istFestgelegt`). P3-Leute können also buchen, danach fordert der (re-armte) Auto-Pilot die
Onboarding-Daten an, `confirm_booking_on_completion` hebt die Buchung auf `registered`.

## 3. Scope

**Drin**

- Vorab-Fix: Status-Reset beim Auto-Advance (eigener Commit, vor der Kampagne).
- Statistik-Modal „Ohne Termin“: Auswahl (Checkboxen), Segment-Badges, Kampagnen-Button, Fortschritt/Ergebnis.
- Automatische Template-Wahl nach Phasenposition (Template A = Formular, Template B = Terminauswahl).
- Versand als Queue-Job, pro Person: senden → loggen → Auto-Pilot re-armen → Ort-Wartelisten-Eintrag schließen.
- Zwei Settings-Keys für die Default-Templates (Team-Ebene), pro Kampagne im Modal überschreibbar.
- Tests (Unit pur + Integration mit Capsule) gemäß Projekt-Konvention.

**Draußen** (eigene Tickets, `docs/tickets/2026-08-28-*.md`)

- Log-Flood `silent` (jede Minute pro Bewerber).
- Verfall offener Ort-Wartelisten-Einträge nach N Tagen.
- E-Mail-Kanal für Bewerber ohne Telefon (Entscheidung 28.08.: WhatsApp-only, Badge „kein Telefon“).
- Automatischer Trigger „neuer Termin angelegt → Kampagne vorschlagen“ (später; erst manuell lernen).

## 4. Entscheidungen (28.08., mit S. Haustein)

| Thema | Entscheidung |
|---|---|
| Templates | A (P1, Formular-Button) / B (ab P2, Terminauswahl-Button). Body ohne Variablen; `{{name}}` darf später dazukommen — der Sender füllt Vorname automatisch. |
| Segment-Defaults | P1 mit Telefon ✅ · P2 ✅ · P3 ✅ · P4 ⬜ (sichtbar, abgehakt) |
| Draußen mit Badge, nicht wählbar | kein Telefon |
| Draußen mit Badge, abgehakt | HR-Schreibtisch (`is_on_hr_desk`) · in den letzten 14 Tagen bereits Kampagne · P4 |
| 16 Wartelisten-Leute | anschreiben (B) **und** Ort-Eintrag schließen |
| Re-Arm | Status → `waiting_for_applicant`, Zähler 0, `last_reminder_at = jetzt` (Kampagne = Erstkontakt des neuen Zyklus → danach 2 normale Erinnerungen, dann wieder Ruhe) |
| Status-Reset generell | nur im Auto-Advance-Zweig (Bewerber hat gehandelt), **nicht** beim manuellen HR-Advance |
| Steuerung | vollautomatisch nach Phasenposition, HR drückt einen Button |

## 5. Design

### 5.1 Vorab-Fix: Status-Reset beim Auto-Advance

`RecApplicant::checkAutoPilotCompletion()`, Zweig „Phase has a successor → advance“: zusätzlich
`$this->auto_pilot_state_id = null;` (gleiche Semantik wie der Inbound-Reset in
`HandleWhatsAppInboundForRecruiting.php:189` — `null` = „bitte wieder aufnehmen“, der nächste Lauf setzt
`waiting_for_applicant` beim Erstkontakt).

Warum kein Spam: Der Reset feuert nur, wenn die Person gerade selbst die Phase abgeschlossen hat; ausgelöst wird
exakt der Erstkontakt der neuen Phase + max. konfigurierte Erinnerungen — dieselben Nachrichten, die sie bei
schneller Reaktion bekommen hätte. `advanceToNextPhase()` (HR-manuell) bleibt unverändert.

Test (Integration): Bewerber in P1 mit `review_needed`, Felder komplett → `checkAutoPilotCompletion()` → Phase = P2
**und** `auto_pilot_state_id === null`. Gegenprobe: `advanceToNextPhase()` lässt den Status stehen.

### 5.2 Segmentregel (pure Klasse `Support/CampaignSegment`)

Eingabe pro Bewerber: aktuelle Phase (order), Phasenliste der Stelle, aktive Buchung?, stornierte Buchungen mit
`cancelled_by`, Telefon vorhanden?, `is_on_hr_desk`, letzte Kampagne (`campaign_sent`-Log).

Buchungsschritt der Stelle (`bookingOrder`): kleinste `order` einer aktiven Phase mit `completion_type = 'booking'`;
gibt es keine (Legacy-Stellen), die Phase mit `completion_config.send_booking_notification_on_completion = true`
+ 1, sonst letzte Phase + 1.

| Position | Template | Default | Badge |
|---|---|---|---|
| `order < bookingOrder` | **A** | ✅ | „Bewerbung unvollständig“ |
| `order == bookingOrder` | **B** | ✅ | — |
| `order == bookingOrder + 1` | **B** | ✅ | „Platz freigegeben / storniert am … (Bewerber/HR)“ |
| `order >= bookingOrder + 2` | **B** | ⬜ | „Termin selbst storniert am …“ / „HR-Storno am …“ |

Überlagernd (in dieser Reihenfolge):
- kein Telefon → **nicht wählbar**, Badge „kein Telefon“
- `is_on_hr_desk` → ⬜, Badge „HR-Schreibtisch“
- `campaign_sent` in den letzten 14 Tagen → ⬜, Badge „angeschrieben am …“
- offener Ort-Wartelisten-Eintrag → Badge „Warteliste seit … (benachrichtigt am …)“, Default unverändert

Die Regel ist positions-agnostisch (keine MGL-Phasen-IDs), damit sie für Düsseldorf/Köln/Bonn identisch greift.
Test: Unit pur, Tabellen-Test über alle Zeilen oben inkl. Legacy-Stelle ohne Buchungsphase.

### 5.3 Statistik-Modal

`Livewire/Statistics/Index.php` + `index.blade.php` (Drill-Modal ab Zeile 762):

- Neue `#[Locked]`-Property `drillScope` (aus `$spec['type']`); der Kampagnen-Bereich rendert **nur** für
  `ohne_schulung` (Kachel „Ohne Termin“ und die gleichnamigen Tabellenzeilen).
- `drillApplicants` lädt zusätzlich `phase`, `position.phases`, `interviewBookings`, `crmContactLinks.contact.phoneNumbers`,
  `waitlistEntries` (open, ortBased) und den jüngsten `campaign_sent`-Log — in gebündelten Queries, nicht pro Zeile
  (Query-Budget ist auf dieser Seite Abnahmekriterium).
- Zeile: Checkbox · Name (Link) · Phase · Badges · Bewerbungsdatum. Kopf: „alle / keine“, Zähler „N von M gewählt“.
- Fußbereich: zwei Selects „Template Bewerbung vervollständigen (A)“ / „Template Terminauswahl (B)“ (approved
  Templates, vorbelegt aus Settings) · Button **„Kampagne an N Personen senden“** · nach Klick Fortschrittszeile
  (`wire:poll.3s`): „x / N gesendet · y Fehler · z ohne Telefon“ und Abschluss-Zusammenfassung.
- Auswahl-Property `campaignSelection` (array<int,bool>) ist **nicht** locked (Client bindet sie), wird aber
  serverseitig gegen `drillIds` geschnitten — nur IDs aus der Kohorte werden je versendet.

### 5.4 Versand-Job `Jobs/SendNewDatesCampaign`

- Dispatch mit: `teamId`, `userId`, `applicantIds` (bereits gefiltert), `templateAId`, `templateBId`, `campaignUuid`.
- Fortschritt in Cache `recruiting:campaign:{uuid}` (`total`, `sent`, `failed`, `skipped`, `done`), TTL 24 h.
- Pro Bewerber, in dieser Reihenfolge, jeder Schritt einzeln geschützt:
  1. **Re-Check** (Stand hat sich seit dem Öffnen des Modals ändern können): aktiv? keine aktive Buchung? Telefon?
     Sonst `skipped` + Grund.
  2. **Segment** neu bestimmen → Template A oder B.
  3. **Senden** über den bestehenden Versand-Kern. Refactor: aus `RecApplicant::sendBookingLinkWhatsApp()` wird die
     Template-Auflösung (Settings-Key → Template) herausgezogen; neuer Kern
     `sendWhatsAppTemplateWithPublicToken(IntegrationsWhatsAppTemplate $template, string $logType, string $logSummary, string $contextPurpose, array $bodyValues = [])`.
     `sendBookingLinkWhatsApp()` delegiert unverändert dorthin. Der Kern setzt `{{1}}` im URL-Button auf den
     Personen-Token und füllt Body-Parameter (`{{1}}`/`{{name}}`/`{{vorname}}` → Vorname) — Template ohne
     Body-Variablen bleibt möglich.
  4. **Log** `RecAutoPilotLog` Typ `campaign_sent` (≤ 30 Zeichen, Spalte `varchar(30)`), `details`:
     `{campaign: uuid, template: name, segment: 'A'|'B', phase_id, sent_by: userId}`.
  5. **Re-Arm** `RecApplicant::rearmAutoPilot(string $reason)`: `auto_pilot_state_id = waiting_for_applicant`,
     `auto_pilot_reminder_count = 0`, `auto_pilot_last_reminder_at = now()`, Log `autopilot_rearmed`.
     Nur wenn `auto_pilot = true`; Direkteinstellungen bleiben unberührt.
  6. **Warteliste**: offene Ort-Einträge des Bewerbers `cancelled_at = now()`, Log `waitlist_replaced`
     („durch Kampagne abgelöst“). Termin-Abos (`rec_interview_id` gesetzt) werden **nicht** angefasst.
- Fehler beim Senden → `failed`, Log Typ `error` mit Meta-Fehlertext; **kein** Re-Arm, **kein** Wartelisten-Schließen
  (die Person wurde nicht erreicht, ihr Zustand bleibt wie er war).
- Buchhaltungsfehler nach erfolgreichem Versand (Log/Thread) dürfen den Versand nicht als Fehler melden — Muster aus
  `sendBookingLinkWhatsApp()` (Kommentar „Ab hier ist die WhatsApp RAUS“).
- Kein Meta-`failed`-Webhook-Tracking in Runde 1 (Altbestand-Thema, siehe Memory MA-Portal).

### 5.5 Settings

`RecApplicantSettings` Defaults + `applicant-settings-modal.blade.php` (Muster: `interview_waitlist_wa_template_id`,
Zeile 509 ff.):

- `campaign_form_wa_template_id` — „WhatsApp Template — Neue Termine, Bewerbung vervollständigen (Kampagne A)“
- `campaign_booking_wa_template_id` — „WhatsApp Template — Neue Termine, Terminauswahl (Kampagne B)“

Kanal: `auto_pilot_wa_account_id` (wie Auto-Pilot). Bekannter Pitfall: die Select-Felder im Settings-Modal speichern
aktuell nicht zuverlässig (Memory „Settings-Modal: Selects speichern nicht“) — Workaround `JSON_SET` dokumentieren,
nicht in dieser Spec fixen.

### 5.6 Templates (Meta, legt der Kunde an)

Beide mit URL-Button, dynamischer Suffix `{{1}}`, Beispielwert `3f9a1c2e8b7d4f60a1b2c3d4e5f60718`:

- **A**: `https://mitarbeiter.rheingedeck.de/form/{{1}}` — Button „Angaben ergänzen“
- **B**: `https://mitarbeiter.rheingedeck.de/recruiting/interviews/{{1}}` — Button „Termine ansehen“

Bis zur Freigabe kann HR im Modal ein anderes approved Template wählen (z. B. das Wartelisten-Template als B).

## 6. Fehlerbilder und Grenzen

- HR wählt kein Template A, hat aber P1-Leute angehakt → Button gesperrt mit Hinweis („Für 36 Personen fehlt Template A“).
- Modal offen, jemand bucht zwischendurch → Re-Check im Job überspringt (`skipped: 'hat inzwischen gebucht'`).
- Doppelklick auf „Senden“ → Button nach erstem Klick disabled + Cache-Lock auf `campaignUuid`.
- Minderjährige: keine Sonderbehandlung nötig — das Jugendschutz-Gate greift weiterhin beim Phasen-Aufstieg.
- Job läuft mit altem Code, bis `queue:restart` ausgeführt wurde (Memory „Forge-Queue-Worker nach Deploy neu starten“).

## 7. Tests

Unit (pur, `tests/Unit`):
- `CampaignSegmentTest` — Tabellen-Test aller Zeilen aus 5.2 + Überlagerungen + Legacy-Stelle.
- Template-Komponenten-Builder: ohne Body-Variablen / mit `{{name}}` / URL-Button mit Token.

Integration (Capsule + SQLite, `tests/Integration`):
- Status-Reset beim Auto-Advance (5.1) + Gegenprobe HR-Advance.
- `rearmAutoPilot()` setzt State/Zähler/Timer; `auto_pilot=false` bleibt unberührt.
- Job: WhatsApp-Service-Attrappe (Erfolg / Exception) → Log, Re-Arm, Wartelisten-Schließung nur bei Erfolg;
  Termin-Abo bleibt offen; Fortschritts-Cache korrekt; Re-Check überspringt frisch Gebuchte.
- Statistik-Komponente: Kampagnen-Bereich nur bei `ohne_schulung`-Scope; Auswahl außerhalb `drillIds` wird verworfen.
- Blade: `tools/blade-check.php` auf `index.blade.php` (kein `php -l`).

Runner: `meingedeck/vendor/bin/phpunit -c phpunit.xml` (kein eigenes vendor/).

## 8. Auslieferung

1. Commit 1: Status-Reset (5.1) + Test. Kein `queue:restart` nötig.
2. Commit 2: Kampagne (5.2–5.6) + Tests. **`queue:restart` Pflicht** (neuer Job).
3. Nach Push: meingedeck `composer.lock` bumpen (Memory „Bump meingedeck nach Modul-Push“).
4. Settings setzen (beide Template-IDs), ggf. per `JSON_SET`.
5. Sichttest Prod: Modal MGL → Zählung stimmt mit Kachel überein (156) → Testversand an eine HR-Nummer über einen
   Test-Bewerber → dann Kampagne.

## 9. Offene Punkte

- Meta-Kategorie der Templates (Utility vs. Marketing) — Kundenseite.
- Wert für „14 Tage“ Wiederholungsschutz ist Konstante (`CampaignSegment::RECENT_CAMPAIGN_DAYS`), kein Setting — erst
  bei Bedarf konfigurierbar machen.
- Kunde über die 12 h / 2-Kadenz informieren: sie erzeugt den Haufen, den die Kampagne abarbeitet.
