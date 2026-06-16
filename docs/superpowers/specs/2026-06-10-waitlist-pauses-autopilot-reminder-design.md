# Design: Warteliste pausiert Auto-Pilot-Reminder

**Datum:** 2026-06-10
**Modul:** platforms-recruiting
**Status:** Abgestimmt, vor Implementierung

## Problem

Bewerber in Phase 2 „Schulung buchen" (`completion_type = 'booking'`), die sich auf die
**Warteliste** eingetragen haben (weil kein Termin frei war), bekommen **weiterhin** den
Auto-Pilot-Reminder „bitte Termin auswählen". Grund: `ProcessAutoPilotApplicants` bricht nur bei
`isPhaseComplete()` ab — ein Warteliste-Eintrag ist **kein** Booking, die Buchungs-Phase bleibt
also „offen", und die Reminder-Schleife (bis `auto_pilot_max_reminders`) läuft weiter. Die
Reminder-Logik kennt die Warteliste nicht.

## Entscheidung

Der Auto-Pilot **pausiert**, solange der Bewerber einen **offenen** Warteliste-Eintrag hat — kein
Erstkontakt/Reminder für die Buchungs-Phase. Gegenpart zum bestehenden `isPhaseComplete()`- und
`auto_pilot_disabled`-Skip.

## Architektur (ein Guard)

Datei: `src/Console/Commands/ProcessAutoPilotApplicants.php`, in der Pro-Bewerber-Verarbeitung
direkt nach dem `isPhaseComplete()`-Block (≈ Z.158):

```php
if (RecInterviewWaitlist::where('rec_applicant_id', $applicant->id)->open()->exists()) {
    // log 'silent' + return → kein Versand
}
```

- Nutzt den bestehenden `open()`-Scope (weder gebucht noch storniert).
- Deckt Erstkontakt **und** Reminder ab (Guard sitzt vor beiden).
- Sobald ein Termin frei wird → Warteliste-Benachrichtigung → Bewerber bucht → Buchungs-Phase
  `isPhaseComplete()` greift (oben) → Flow läuft normal weiter.
- Wird der Warteliste-Eintrag storniert (Reject/Park/Abmeldung/HR), ist er nicht mehr `open()` →
  Auto-Pilot nimmt den Bewerber wieder auf (gewollt).

## Nicht betroffen
- **Interview-Reminder** (`recruiting:send-interview-reminders`, „X Std. vor dem Termin") — nur für
  gebuchte Interviews; Wartende haben kein Booking → irrelevant.
- **Enrichment-Erstkontakt** (`EnrichInboxApplicants`) — läuft beim Eingang, vor jeder
  Warteliste-Eintragung.

## Verifikation (manuell im Host)
- Bewerber in Phase 2 auf Warteliste → `recruiting:process-auto-pilot-applicants` (bzw. der
  Minuten-Scheduler) sendet **keinen** Reminder mehr; Log zeigt „Auf Warteliste — Auto-Pilot
  pausiert".
- Bewerber **ohne** Warteliste in Phase 2 → Reminder wie bisher.
- Nach Buchung/Storno der Warteliste → Auto-Pilot verhält sich wieder normal.
