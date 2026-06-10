# Design: WhatsApp-Begrüßung nur mit Vorname

**Datum:** 2026-06-10
**Modul:** platforms-recruiting
**Status:** Abgestimmt, vor Implementierung

## Problem

WhatsApp-Templates begrüßen mit `{{name}}`/`{{1}}`/`{{vorname}}`. Befüllt wird das aktuell mit
`CrmContact->full_name` — das enthält **akademischen Titel + Anrede + Vorname + Middle Name +
Nachname** (z.B. „Herr Dr. Max Mustermann"). Gewünscht: nur der **Vorname**. Die Befüllung ist
nicht zentral, sondern über mehrere Versand-Pfade dupliziert.

## Entscheidung

- Quelle überall von `full_name` → **`first_name`** (Ansatz A, fokussiert). Fallback
  („Bewerber/in"/„Mitarbeiter/in") bleibt. `match`-/Mapping-Logik unverändert.
- Kein Refactor/Zentralisierung (heterogene Sender; `first_name` ist eine direkte Eigenschaft —
  ein gemeinsamer Helper brächte kaum Mehrwert, aber Risiko). Die Block-Duplizierung bleibt als
  optionales späteres Cleanup notiert.

## Betroffene Stellen (alle: Namens-Wert → `first_name`)

1. `src/Models/RecApplicant.php` — `sendBookingLinkWhatsApp()`: `$contactName = getContact()?->first_name ?? 'Bewerber/in'`.
2. `src/Models/RecApplicant.php` — `sendContractPortalNotification()`: `$contactName` aus `first_name` (speist `name`/`vorname`/`candidate_name`).
3. `src/Models/RecEmployee.php` — `sendPortalNotification()`: `name`/`candidate_name`/`employee_name` auf `first_name` setzen (`vorname` ist bereits `first_name`).
4. `src/Console/Commands/ProcessAutoPilotApplicants.php` — `getContactName()` gibt `contact?->first_name` zurück.
5. `src/Console/Commands/EnrichInboxApplicants.php` — `getContactName()` dito.
6. `src/Livewire/Applicant/Show.php` — `sendManualTemplate()`: `$contactName = contact?->first_name ?? ''`.
7. `src/Models/RecInterview.php` — `resolveVariableValue()`: `'candidate_name'` aus `first_name`.

## Nicht-Ziele
- Keine Template-Änderung, keine Datenänderung. Bereits versendete (statische) Nachrichten unberührt.
- `vorname`-Platzhalter wird als Nebeneffekt korrekt (zeigt dann wirklich den Vornamen).

## Verifikation (manuell im Host)
Eine WA über einen der Pfade auslösen (z.B. Buchungslink/Reminder) → Begrüßung zeigt nur den
Vornamen. Stichprobe über mehrere Pfade (Auto-Pilot, Portal).
