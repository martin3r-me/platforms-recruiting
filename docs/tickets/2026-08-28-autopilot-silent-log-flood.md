# Auto-Pilot: `silent`-Logs fluten rec_auto_pilot_logs (1 Zeile / Bewerber / Minute)

**Gefunden:** 28.08.2026 beim Ziehen der Enrichment-Logs für MGL-Bewerber (Statistik „Ohne Termin").
**Betroffen:** `rec_auto_pilot_logs`, Typ `silent`. Log-IDs stehen bei ~19 Mio.

## Symptom

`recruiting:process-auto-pilot-applicants` läuft `everyMinute` (RecruitingServiceProvider.php:193). Für jeden
Bewerber, der in der Auto-Pilot-Query landet, aber NICHT angeschrieben wird, schreibt der Lauf **jede Minute**
einen `silent`-Log-Eintrag — identischer Text, identischer Bewerber:

- `ProcessAutoPilotApplicants.php:171` — „Bewerber steht auf der Warteliste — Auto-Pilot pausiert (kein Reminder).“
- `ProcessAutoPilotApplicants.php:184` — „Phase "…" ist als auto_pilot_disabled markiert — kein Template-Versand.“

Live-Beleg: Bewerber 3165, 2529, 2448, 2921 (P4) und 3202 (Warteliste) — die letzten 40 Einträge sind jeweils
40× derselbe Satz im Minutenabstand (12:50–13:37).

## Auswirkung

- Allein MGL: 42 aktive P4 + 16 Wartelisten-Pausierte ≈ 58 Bewerber × 1440/Tag ≈ **84.000 Zeilen/Tag**.
  Über alle Filialen entsprechend mehr.
- Das Enrichment-Log dieser Bewerber ist unbrauchbar (MCP `recruiting.enrichment_logs.GET` liefert nur noch Rauschen);
  echte Ereignisse (Storno, Erinnerung, Fehler) sind nicht mehr auffindbar.
- Tabellenwachstum / Index-Kosten.

## Fix-Vorschlag

1. **Dedupe statt Dauerlog:** `silent` nur schreiben, wenn der jüngste Log-Eintrag des Bewerbers nicht bereits
   derselbe `silent`-Text ist (ein Query pro Kandidat, oder Cache-Key `autopilot:silent:{applicant}:{hash}` mit TTL 24h).
   Ergebnis: ein Eintrag beim Eintritt in den stillen Zustand, keiner danach.
2. **Aufräumen:** einmalig `DELETE FROM rec_auto_pilot_logs WHERE type = 'silent'` (ggf. in Batches) — die Zeilen
   tragen keine Information, die nicht rekonstruierbar wäre.
3. Nicht: Bewerber aus der Query filtern. Der Lauf muss sie weiter sehen, weil oben in `processApplicant()` der
   Phasen-Abschluss geprüft wird (Wartelisten-Bucher, Vertrags-Rücklauf).

## Akzeptanz

- Ein P4-Bewerber erzeugt nach dem Fix pro Zustand höchstens einen `silent`-Eintrag.
- Tests: Unit-Suite (kein testbench) mit zwei aufeinanderfolgenden Läufen → genau ein Log.
- Kein `queue:restart` nötig (Command, kein Job); Bump meingedeck wie üblich.
