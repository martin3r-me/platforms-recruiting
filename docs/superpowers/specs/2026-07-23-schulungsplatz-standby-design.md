# Schulungsplatz-Standby — Design

**Datum:** 2026-07-23
**Status:** Entwurf zur Review

## Problem

Ein Schulungsplatz wird heute von jeder nicht-stornierten Buchung belegt — auch von
Bewerbern, die nach der Buchung nie ihr Onboarding abschließen (`booked` → nie
`registered`). Es gibt keinen Verfall: der Platz bleibt blockiert, bis HR manuell
storniert. Gleichzeitig weiß das System bereits, wann ein Bewerber aufgegeben hat —
der Auto-Pilot stellt nach der letzten unbeantworteten Erinnerung auf
`max_reminders_reached` / `review_needed`. Diese Information hat heute keine
Konsequenz für den Platz.

Praxisbeispiel: Bewerberin #2388 — Schulung gebucht 15.07., Onboarding nie
ausgefüllt, `review_needed` seit 16.07., Platz weiterhin belegt.

## Kernidee

Ein Platz zählt, solange der Bewerber **aktiv dran** ist — nicht solange die Buchung
existiert. Gibt der Auto-Pilot auf und die Buchung steht noch auf `booked`, geht sie
in **Standby**: sie wird nicht storniert, zählt aber nicht mehr gegen die Kapazität.
Der frei gewordene Platz wird sofort über die bestehende Warteliste angeboten.
Kommt der Standby-Bewerber doch zurück, bekommt er seinen Platz, sofern noch frei
(Re-Claim); ist der Termin inzwischen voll, wird seine Buchung storniert und er
kehrt in die Terminauswahl zurück.

Damit bleibt `max_participants` ein hartes Limit, fleißige Bewerber verlieren nie
ihren Platz, und nur Bewerber, die zwei Erinnerungen ignoriert haben, tragen das
Risiko, neu wählen zu müssen.

## Nicht-Ziele

- Kein Overbooking-Puffer, keine weiche Kapazität (außer bewusster HR-Übersteuerung).
- Keine Änderung der Warteliste-Semantik — sie konsumiert nur die neue zentrale Zählung.
- Kein neuer Scheduler/Timer und keine neue Konfiguration: die Reminder-Kadenz des
  Auto-Piloten (Settings existieren) definiert die Frist.

## Bausteine

### 1. Datenmodell: `seat_released_at`

Neue nullable Timestamp-Spalte auf `rec_interview_bookings` (+ Cast + fillable).
Bewusst **kein neuer Status**: `status` bleibt `booked`, dadurch bleiben alle
bestehenden Status-Abfragen korrekt — insbesondere wertet die Phase
„Schulung buchen" (`completion_type: booking`, nicht-storniert = erfüllt) die
Standby-Buchung weiter als gebucht, der Bewerber bleibt in der Onboarding-Phase.

`status_label` mappt auf **„Standby"**, wenn `status === 'booked'` und
`seat_released_at !== null`.

### 2. Zentrale Platz-Zählung

Die Zählregel ist heute dupliziert (`whereNotIn('status', ['cancelled'])`).
Vollständige Enumeration (verifiziert, inkl. Blade-Views; platforms-hcm/-crm/
meingedeck fassen `rec_interview_bookings` nicht an — HCM hat ein eigenes,
unabhängiges `HcmInterviewBooking`):

**Platz-zählend → stellen auf die zentrale Regel um:**

- `Livewire/Public/InterviewBooking.php` — Kapazitäts-Guard beim Buchen (:293),
  Warteliste-Join-Guard „nur volle Termine" (:416), `withCount` für sichtbare
  Termine (konsumiert in `public/interview-booking.blade.php:174/:198`)
- `Livewire/InterviewBookings/Index.php` (:248) + View
  `interview-bookings/index.blade.php:100` („X/Y Plätze belegt")
- `Livewire/Applicant/Index.php` (:550)
- `Tools/CreateInterviewBookingTool.php` (:97)
- `Jobs/NotifyWaitlistForInterview.php` (:64)
- `Services/WaitlistRearmService.php` (`rearmIfNowFull`)
- View `interview-schedule/index.blade.php:96` (Kalender-Belegung)

**Buchungs-Existenz → bleiben bewusst status-basiert (Standby zählt weiter als
aktive Buchung):**

- `RecApplicant.php:1578` `hasBookingMatching` — Phase-Completion
  `completion_type: booking`; Standby MUSS die Phase erfüllt halten
- Ein-Buchungs-Guard `Public/InterviewBooking.php:274/:395`
- Dashboard-Funnel (`Dashboard.php`), `is_rebooked`/`status_label`-Logik

Neu: eine zentrale Abfrage, z. B. `RecInterviewBooking::seatTakingQuery()` /
`RecInterview::takenSeatsCount()` mit der Regel
**belegt = nicht storniert UND `seat_released_at IS NULL`**. Die
Entscheidungslogik (zählt diese Buchung? darf released werden? darf re-claimed
werden?) liegt in einer puren Klasse (`SeatStandbyPolicy`, Muster
`FirstAiderDateGuard`), damit sie nach Test-Konvention ohne DB testbar ist.

### 3. Release-Trigger im Auto-Pilot

In `ProcessAutoPilotApplicants` an der Stelle, die `max_reminders_reached` setzt
(:231–237), zusätzlich:

- Alle Buchungen des Bewerbers mit `status = 'booked'` und
  `seat_released_at IS NULL` → `seat_released_at = now()` (per Model-Save, je Buchung).
- `RecAutoPilotLog` Typ `seat_released` („Schulungsplatz freigegeben — Bewerber
  reagiert nicht (2/2 Erinnerungen)").
- `NotifyWaitlistForInterview::dispatch($interviewId)` je betroffenem Termin —
  der Job re-validiert Kapazität selbst, Über-Dispatch ist safe (bestehende Garantie).

Kein Release für Buchungen, die bereits `registered`/`confirmed`/… sind.

**Dispatch-Timing:** Der Job bekommt `public $afterCommit = true;` (fehlt heute).
Damit ist jeder Dispatch-Pfad transaktionssicher — insbesondere der
Observer-Dispatch beim Storno innerhalb der FOR-UPDATE-Transaktion des
Re-Claims (Baustein 4/5); außerhalb von Transaktionen ändert das Flag nichts.

### 4. Re-Claim als Pre-Advance-Guard (P3 „Onboarding")

**Verifiziert:** `triggerPhaseCompletionHooks()` ist `void` und feuert ERST NACH
dem persistierten Phase-Advance (`checkAutoPilotCompletion()`: Advance + Save
:454–461, Hook danach :469; weitere Call-Sites :424 ohne Advance, :494
letzte Phase, :538 manueller Advance). Der Hook kann den Advance nicht
unterdrücken. Deshalb:

Der Re-Claim wird ein **expliziter Guard-Schritt in `checkAutoPilotCompletion()`
VOR dem Advance-Branch** (und vor dem `completed`-Branch), wenn die
abgeschlossene Phase `confirm_booking_on_completion` hat:

- Re-Claim erfolgreich → Advance/Completed läuft unverändert weiter; das
  Status-Upgrade selbst bleibt im Hook.
- Re-Claim fehlgeschlagen → Baustein 5, early return: **kein** Advance, kein
  `completed`, keine Booking-Notification.

**Config-Kopplung (verifiziert):** Der Flag heißt tatsächlich
`confirm_booking_on_completion`, Kopplung in `RecApplicant.php:585`
(`($config['confirm_booking_on_completion'] ?? false) === true` →
Upgrade `booked → registered`). Live sitzt er auf der Onboarding-Phase
(z. B. Phase 42 „Onboarding (Bestätigung)", Position 11, `completion_config`).
Der Guard keyt auf die Config der *abgeschlossenen* Phase — abweichende
Konfigurationen anderer Positionen werden automatisch respektiert.

**Verhalten je Call-Site des Hooks:**

| Call-Site | Erreicht der Guard? | Verhalten bei Standby + Termin voll |
|---|---|---|
| :469 Auto-Advance | ja (Guard sitzt davor in `checkAutoPilotCompletion`) | Rücksprung (Baustein 5) |
| :494 letzte Phase | ja (gleiche Methode) | Rücksprung (Baustein 5) |
| :424 Auto-Pilot AUS | ja (Guard läuft auch im Nicht-Auto-Pilot-Zweig) | HR-Fall: Log `reclaim_failed`, Buchung bleibt Standby, kein Rücksprung (keine Auto-Pilot-Kommunikation möglich) |
| :538 `advanceToNextPhase()` (manueller HR-Advance, Caller: `Dashboard.php:865`) | **nein** — separate Methode | **HR-Übersteuerung:** Upgrade ohne Kapazitätsblock, `seat_released_at` wird gelöscht, Log `seat_reclaimed_override` (konsistent zur manuellen `registered`-Setzung; bewusstes Überbuchen) |

**Invariante — Durchsetzung auf Model-Ebene, nicht je Call-Site:** Es darf nie
eine Buchung mit Status ≠ `booked` und gesetztem `seat_released_at` geben
(sonst Phantom-Sitz: zählt nicht, ist aber registriert/bestätigt). Verifizierte
Schreibstellen, die Status aus `booked` heraus bzw. auf `registered`+ setzen:

- `RecApplicant.php:588` — P3-Hook (bedient ALLE vier Hook-Call-Sites;
  `advanceToNextPhase()` :538 hat KEIN eigenes Update, es läuft über :585)
- `Tools/UpdateInterviewBookingTool.php` — beliebiger Status (HR/MCP)
- `Livewire/InterviewBookings/Index.php:302ff` — HR-Status-Dropdown
- `Services/ReminderResponseHandler.php:91` — „Ja"-Antwort: `booked|registered
  → confirmed`
- `Tools/CreateInterviewBookingTool.php:106` — `updateOrCreate`, kann auf einer
  bestehenden (auch Standby-)Zeile landen
- `Livewire/Public/InterviewBooking.php:312` — `updateOrCreate` mit explizitem
  Cancelled-Felder-Reset (dort kommt `seat_released_at = null` in die
  Reset-Liste)
- `Livewire/Applicant/Index.php:577` — reines `create()` hinter
  Existenz-Guard, immer frische Zeile → unkritisch

Statt alle Stellen einzeln zu patchen, erzwingt ein **`saving`-Guard im Model**
die Invariante zentral: `status !== 'booked'` → `seat_released_at = null`.
Damit ist jeder Model-Save-Pfad automatisch korrekt; der einzige
Query-Builder-Bypass (:588, Bulk-Update) wird ohnehin auf Model-Saves
umgestellt (Baustein 4). Die Kapazitäts-*Prüfung* bleibt allein Sache des
Pre-Advance-Guards — HR-/Tool-/Reply-Pfade konsumieren den Platz bewusst ohne
Block (Übersteuerungs-Semantik), aber nie als unsichtbaren Phantom-Sitz.

Der Hook (:585) stellt außerdem von Bulk-`->update()` auf Laden + per-Model-Save
um — heute feuern beim Upgrade `booked → registered` keine Model-Events, der
Warteliste-Observer (Re-Arm bei „wieder voll") sieht es nicht (Fix unabhängig
vom Standby sinnvoll; Modul-Konvention laut ComplianceObserver ist ohnehin
„Status nur über Model-Saves").

**Locking:** Transaktion mit `SELECT … FOR UPDATE` auf der
**`rec_interviews`-Zeile** — nicht auf Buchungszeilen (Row-Locks verhindern
keine Phantom-Inserts neuer Buchungen). Dieselbe Termin-Zeilen-Sperre kommt in
alle vier Buchungs-Erzeugungspfade: Public-Guard (:293), HR-Verwaltung
(`InterviewBookings/Index`), `CreateInterviewBookingTool` und
Schulungs-Buchung (`Applicant/Index`) — heute serialisiert sich dort NICHTS
(count + updateOrCreate ohne Transaktion), das bestehende Doppelbuchungs-Race
wird damit gleich mit gefixt. **Verifiziert:** Es gibt keinen fünften Pfad —
`NotifyWaitlistForInterview` benachrichtigt nur (WhatsApp mit Link auf die
Public-Buchungsseite), gebucht wird immer über einen der vier Guards.
Prüfmatrix je Buchung im Re-Claim:

1. `seat_released_at IS NULL` (normaler Fall): Upgrade auf `registered` wie bisher.
2. Standby + Termin hat freien Platz **und liegt in der Zukunft**: `seat_released_at
   = null`, Status → `registered`, Log `seat_reclaimed`. (Model-Save → Observer
   re-armt Termin-Abos, falls dadurch wieder voll.)
3. Standby + Termin voll **oder vergangen** → **fehlgeschlagener Re-Claim**, siehe 5.

### 5. Fehlgeschlagener Re-Claim → zurück zur Terminwahl

- Buchung stornieren (`status = cancelled`, `cancelled_by = 'system'`,
  `cancelled_at = now()`); der bestehende Observer benachrichtigt daraufhin ggf.
  die Warteliste (no-op, da der Termin voll ist — schadet nicht).
- Bewerber **explizit** zurück in die Phase mit `completion_type = booking`
  („Schulung buchen") setzen — via neuem Helper `returnToBookingPhase()`, der
  die Advance-Reset-Semantik (:454–461) spiegelt **plus zwei Felder, die der
  Forward-Advance nicht braucht, dieser Pfad aber zwingend**:
  - `rec_phase_id` (Ziel: Booking-Phase)
  - `auto_pilot_reminder_count = 0`
  - `auto_pilot_last_reminder_at = null`
  - `auto_pilot_completed_at = null` (Teil der Advance-Semantik, im ersten
    Helper-Entwurf vergessen)
  - **`auto_pilot_state_id = null`** — KRITISCH: der Bewerber steht in diesem
    Moment auf `review_needed`, und die Auto-Pilot-Query schließt
    `review_needed` aus (:556–560). Ohne State-Reset wäre er nach dem
    Rücksprung für den Auto-Piloten unsichtbar und bekäme nie das
    Termin-Template.
  - `progress` neu berechnen, `clearExtraFieldDefinitionsCache()`, Log

  **Verifiziert:** Rückwärts-Phasensetzung existiert bereits ohne Guards
  (UpdateApplicantTool, Position-Remaps), aber KEIN bestehender Pfad resettet
  dabei die Auto-Pilot-Felder — der Helper ist deshalb Pflicht, kein
  Nice-to-have.
- Log `reclaim_failed` („Termin inzwischen voll — Bewerber zurück zur Terminwahl").
- Kein neues WhatsApp-Template nötig: die Terminauswahl-Kommunikation existiert.
- Kein Advance Richtung P4: ergibt sich strukturell, weil der Re-Claim als
  Pre-Advance-Guard läuft (Baustein 4) und im Fehlerfall vor dem Advance-Branch
  early-returnt.

Bewerber-Erlebnis danach: Buchungsseite zeigt den Live-Zustand — freie Termine
buchbar, volle Termine mit vorhandenem Termin-Abo-Button („Warteliste abonnieren"),
sonst Ort-Warteliste. Onboarding-Daten bleiben gespeichert: nach neuer Buchung ist
die Buchen-Phase sofort komplett, die Onboarding-Phase mit 100 % Feldern sofort
komplett, der Hook stellt direkt auf `registered` (Platz wurde beim Buchen geprüft).

### 6. Flankierende Fixes

- **Ablehnen/Parken storniert Buchung:** `RecInterviewWaitlistObserver` (Applicant-
  saved-Hook, :84–100) storniert heute nur Warteliste-Einträge. Zusätzlich aktive
  Buchungen stornieren (`cancelled_by = 'system'`) — Observer gibt den Platz dann
  automatisch an die Warteliste.
- **HR-Übersteuerung:** Setzt HR eine Standby-Buchung manuell auf `registered`
  (Buchungsverwaltung, `UpdateInterviewBookingTool`), wird `seat_released_at`
  mitgelöscht — ohne Kapazitätsblock (HR darf bewusst überbuchen), aber mit Log.
- **Backfill/Heal-Command (Pflicht):** Die Auto-Pilot-Query schließt
  `review_needed`-Bewerber aus (`ProcessAutoPilotApplicants.php:556–560`) —
  Alt-Fälle (z. B. #2388) durchlaufen den Release-Trigger nie. Idempotenter
  Command `recruiting:release-stale-seats`: Auto-Pilot an + State
  `review_needed` + Buchung `booked` + `seat_released_at IS NULL` + Termin in
  der Zukunft → Release + Notify-Dispatch. Einmal nach Deploy ausführen;
  wiederholbar als Heal (deckt auch per MCP-Tool direkt gesetzte States ab, die
  den Trigger umgehen würden). **Filter-Äquivalenz (verifiziert):** Es gibt nur
  drei State-Codes (`waiting_for_applicant`, `completed`, `review_needed`);
  `max_reminders_reached` ist ein Log-Typ, KEIN eigener State — der
  `review_needed`-Filter trifft dieselbe Menge wie der Live-Trigger. Bewerber,
  deren State per Inbound auf `null` zurückgesetzt wurde, laufen beim nächsten
  Tick erneut in den Max-Branch (Live-Trigger greift). Bewusste Auslassung:
  Bewerber mit **Auto-Pilot AUS** und alter `booked`-Buchung werden NICHT
  automatisch released — Auto-Pilot-aus heißt, HR führt den Fall manuell.

### 7. UI

- Buchungsverwaltung + Bewerberakte: Label „Standby" (Baustein 1).
- Terminübersicht/HR-Kalender: Belegung aus zentraler Zählung, Standby separat
  ausgewiesen, z. B. **„8/10 (+2 Standby)"** — HR sieht, wer noch reinrutschen könnte.
- Public-Formular-Abschluss: im Fall „fehlgeschlagener Re-Claim" statt der
  Bestätigungsbox einen Hinweis „Dein Termin ist leider voll geworden — bitte wähle
  einen neuen" mit Link zur Terminauswahl (`renderPublicFormCompletionExtras`-Pfad).

## KPI-Definitionen (für spätere Dashboard-Kachel)

Alle Standby-Events landen strukturiert in `rec_auto_pilot_logs`
(`details`: `booking_id`, `interview_id`, plus `source`/`mode`/`status`).
Jedes `seat_released`-Event trägt ein `source` (`auto_pilot` = Live-Trigger,
`heal` = Backfill/Heal-Command) — Quoten filtern nie über Abwesenheit.

- **Standby-Quote** = `seat_released` (source=`auto_pilot`) ÷ Buchungen im Zeitraum
- **Rückhol-Quote** = (`seat_reclaimed` + `seat_reclaimed_override`) ÷ `seat_released`
- **Verlust-Quote** = `reclaim_failed` (mode=`returned`) ÷ `seat_released`
- **HR-offen** = `reclaim_failed` (mode=`hr_case`) — eigener Eimer, zählt NICHT als Verlust
- **Aufräum-Storno** = `reclaim_failed` (mode=`sibling_cancelled`) — eigener dritter
  Eimer: Multi-Standby-Teilerfolg, der Bewerber hat seinen Platz an einem anderen
  Termin zurückgeholt, das stornierte Duplikat ist Aufräumen → zählt NICHT als
  Verlust, NICHT als HR-offen
- **WARNUNG für die Kachel:** `reclaim_failed` hat DREI mode-Werte
  (`returned` | `hr_case` | `sibling_cancelled`). Niemals `reclaim_failed` ohne
  mode-Filter zählen — das mischt echte Verluste mit HR-offenen Fällen und
  Aufräum-Stornos und macht die Verlust-Quote falsch.
- Pro Termin/Standort: über `details->interview_id` → Join auf Termin/Position

**Hinweis:** Die Log-Schreibungen sind Best-Effort (`try/catch` — ein Log-Fehler
darf nie einen Save blockieren). Die Quoten sind damit Näherungswerte fürs
Steuern, kein Audit-Trail.

## Randfälle

- **Warteliste-Pause:** Abonniert der Rückkehrer ein Termin-Abo, pausiert der
  Auto-Pilot (bestehende Logik :166) — kein „bitte Termin wählen"-Spam.
- **Ein-Buchungs-Guard:** Die Standby-Buchung ist nicht storniert und blockiert
  daher weiterhin Neubuchungen des Bewerbers — korrekt; erst der fehlgeschlagene
  Re-Claim storniert und macht den Weg frei.
- **Multi-Standby (erreichbar!):** Der Ein-Buchungs-Guard gilt nur für
  Public/HR-Buchung/Bulk; `CreateInterviewBookingTool` prüft Duplikate nur pro
  Termin, und HR/Tool-Status-Updates können stornierte Buchungen zurück auf
  `booked` heben → mehrere aktive und damit mehrere Standby-Buchungen sind
  möglich. Re-Claim-Teilerfolg: mindestens ein Erfolg → Guard-Ergebnis OK,
  gescheiterte Geschwister-Buchungen werden (in beiden Auto-Pilot-Modi)
  storniert und mit `mode='sibling_cancelled'` geloggt; kein Rücksprung.
- **Später Reminder-Klick:** Reagiert der Bewerber nach Release doch (füllt Form aus),
  läuft er ganz normal in den Re-Claim (Baustein 4) — kein Sonderfall.
- **Inbound-Antwort nach Reminder 2 (bewusst akzeptiert):** Die Inbound-Listener
  setzen `auto_pilot_state_id = null` ohne Zähler-Reset — der Bewerber läuft beim
  nächsten Tick erneut in den Max-Branch. Der Release-Trigger ist deshalb
  idempotent (`seat_released_at IS NULL`-Guard). Randfall: wer nach der letzten
  Erinnerung nur *schreibt* (statt auszufüllen), verliert den Platz trotzdem an
  den Standby — akzeptiert für V1, weil reversibel (Re-Claim beim Ausfüllen,
  solange frei) und geloggt.
- **Termin-Reminder (t05) an Standby — Bypass, wird geschlossen:**
  `SendInterviewReminders` schickt die Teilnahme-Bestätigung heute an ALLE
  nicht-stornierten Buchungen (:45–49) — künftig also auch an Standby. Antwortet
  der mit „Ja", würde `ReminderResponseHandler:91` ihn ohne Kapazitätsprüfung
  auf `confirmed` heben. Zwei Ergänzungen: (1) `SendInterviewReminders` schließt
  Standby-Buchungen aus (`seat_released_at IS NULL`-Filter) — wer keinen
  garantierten Platz hat, bekommt keine Teilnahme-Bestätigungsfrage; (2)
  `ReminderResponseHandler` überspringt Standby-Buchungen (Alt-Fall: „Ja" auf
  einen VOR dem Release verschickten Reminder) — der Weg zurück führt
  ausschließlich über das Onboarding-Formular und damit über den
  kapazitätsgeprüften Re-Claim.
- **Observer-Verträglichkeit (verifiziert):** Ein reiner `seat_released_at`-Save
  triggert keinen der drei Booking-Observer (Waitlist/Compliance: nur bei
  Status-Änderung; ZAS-Export: nur bei `rec_interview_id`-Änderung). Der
  per-Model-Save `booked → registered` triggert ausschließlich das gewollte
  `rearmIfNowFull`. Booking-Notifications hängen an Phase-Config bzw. manuellem
  HR-Klick, nicht an Booking-Saves.
- **Dashboards/Funnel:** zählen weiter nach `status` — Standby bleibt `booked`,
  keine Änderung der Funnel-Semantik.

## Tests (pure PHPUnit, ohne DB)

- `SeatStandbyPolicy`: zählt/zählt nicht (Statusmatrix × seat_released_at),
  Release-Entscheidung (nur `booked`, nur ohne bestehendes Release),
  Re-Claim-Entscheidung (frei/voll/vergangen).
- Rücksprung-Übergang: Reset-Werte der Auto-Pilot-Felder als pure Funktion.
- Bestehende Zähl-Konsumenten: Regressionstests der umgestellten Stellen, soweit
  pure testbar geschnitten.

## Offene Punkte

- Optional (kein Blocker): Text der letzten Erinnerung verschärfen
  („… sonst geben wir deinen Schulungsplatz frei") — Template-Update mit
  Meta-Approval, unabhängig deploybar.
