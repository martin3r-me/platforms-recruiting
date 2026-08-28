# Ort-Warteliste: Eintrag bleibt nach Benachrichtigung offen und pausiert den Auto-Pilot dauerhaft

**Gefunden:** 28.08.2026 (Analyse „Ohne Termin“ MGL). 16 von 60 P2-Bewerbern betroffen, 15 davon seit Juli/August still.

## Ist-Verhalten

1. Bewerber drückt auf der Buchungsseite „Benachrichtige mich, wenn es Termine in <Ort> gibt“ → Ort-Eintrag
   (`rec_interview_waitlists`, `rec_interview_id = NULL`).
2. Nächster passender Termin → **genau eine** WhatsApp (`NotifyWaitlistForInterview`, Claim auf `notified_at`,
   „Nur-1x-Regel“). Danach überspringt jeder weitere Termin die Zeile (`whereNull('notified_at')`).
3. Der Eintrag bleibt **offen** (`fulfilled_at`/`cancelled_at` NULL), bis der Bewerber bucht oder storniert.
4. Ein offener Eintrag **pausiert den Auto-Pilot** (`ProcessAutoPilotApplicants.php:170`): keine Erinnerung mehr.

Ergebnis: Wer nach der einen WA nicht bucht, ist in beiden Systemen stumm — keine Wartelisten-WA, keine
Erinnerung, für immer. Neue Termine (z. B. 09.09./07.10. MGL, angelegt 28.08.) erreichen ihn nicht.

## Soll-Verhalten (Vorschlag)

- **Verfall statt Dauerpause:** Ein Ort-Eintrag, der N Tage (Vorschlag 7) nach `notified_at` nicht zu einer Buchung
  geführt hat, wird automatisch geschlossen (`cancelled_at`, `cancelled_by = 'system'`, Log `waitlist_expired`).
  Der Auto-Pilot läuft danach mit seinen normalen Erinnerungen weiter.
- **Keine** zweite Wartelisten-WA — bewusst kein „dauerhaft benachrichtigen“ (Spam-Risiko, Kundenentscheid 28.08.).
- Optional sichtbar machen: Badge „Warteliste verfallen am …“ in Bewerber-Show.

## Übergang

- Die 16 MGL-Fälle werden von der Kampagne „Neue Termine“ (Spec folgt) mit erledigt: Anschreiben + Eintrag schließen.
- Verfall-Job kann als Scheduler-Command (`recruiting:expire-waitlist`, täglich) laufen; idempotent.

## Offen

- N (7 Tage?) mit Kunde abstimmen.
- Verhalten für Termin-Abos (`armed`, Dauerabo) unverändert lassen — die haben eine eigene Re-Arm-Logik.
