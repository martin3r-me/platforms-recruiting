# Umbuchen-Flow: Abbruch storniert lautlos und fällt niemandem auf

**Aufgenommen:** 14.09.2026
**Status:** offen — Fix in platforms-recruiting, nicht begonnen
**Belegfälle:** Aurora Welz (#3473, Buchung #874), Giuliana Galletto (#3426, Buchung #877)

## Was passiert

`Livewire/Public/InterviewBooking::cancelAndRebook()` storniert **alle** aktiven
Buchungen des Bewerbers und setzt ihn danach in die Terminauswahl:

```php
RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
    ->whereNotIn('status', ['cancelled'])
    ->get()
    ->each->update(['status' => 'cancelled', 'cancelled_by' => 'applicant', ...]);

$this->state = 'selection';
```

Die Absage ist also vollzogen, **bevor** ein Ersatztermin feststeht. Wer den
Flow abbricht — Seite geschlossen, kein passender Termin frei, Verbindung weg —
steht ohne Buchung da und erfährt es nicht.

Das sofortige Freigeben ist bewusst so gebaut (Kommentar im Code: Model-Updates,
damit der Warteliste-Observer den Platz mitbekommt). Der Abbrecher-Fall ist der
unbeabsichtigte Preis dafür.

## Der eigentliche Defekt: die Stille

Drei Wege stornieren eine Buchung im Namen des Bewerbers. Zwei hinterlassen eine
Spur, einer nicht:

| Weg | HR-Schreibtisch | AutoPilot-Log |
|---|---|---|
| `cancelSchulung()` — „endgültig absagen" | `applicant_cancelled_training` | `cancelled_by_applicant` |
| `ReminderResponseHandler` — Reminder mit „Nein" | `applicant_cancelled_training` | `cancelled_by_reply` |
| **`cancelAndRebook()` — Umbuchen** | **nichts** | **nichts** |

In der Datenbank sehen alle drei identisch aus (`cancelled_by = 'applicant'`).
Das System kann „hat es sich anders überlegt" nicht von „ist steckengeblieben"
unterscheiden — und niemand bei HR bekommt den zweiten Fall zu sehen.

Erschwerend: `BookingCancellationMeta::updatesFor()` räumt `cancelled_by` und
`cancelled_at` ab, sobald jemand den Status weg von `cancelled` korrigiert. Das
ist für sich richtig (ein reaktivierter Termin darf kein Storno-Datum behalten),
löscht aber genau die Belege, die man für die Nachforschung braucht. Bei
Giuliana war deshalb im Nachhinein nicht mehr feststellbar, wer storniert hat.

## Zahlen (Stand 14.09.2026, Fenster ab 01.08.2026)

- **~100** Bewerber: selbst storniert, keine aktive Buchung mehr
- davon **~61** mit Beleg einer bewussten Absage (HR-Fall oder Log)
- **39** ohne jeden Beleg → über den Umbuchen-Flow abgebrochen

Also grob sechs bis sieben pro Woche, die lautlos aus der Pipeline fallen.
Dass ein Teil davon sich schlicht umentschieden hat, ist sicher — genau das
ist der Punkt: Die beiden Fälle sind nicht unterscheidbar.

## Belegfälle

**Aurora Welz (#3473, Buchung #874)** — selbst storniert am 10.09. 21:15, kein
HR-Fall, keine Ersatzbuchung. Auf derselben Buchung steht die Notiz „Kommt 15
min später", geschrieben am 14.09. 15:28. Vier Tage nach ihrer Stornierung hat
also jemand mit ihr gerechnet; sie hielt sich für gebucht. Ihr Status steht
weiterhin auf `cancelled` — vermutlich falsch, gehört unabhängig vom Fix
korrigiert.

**Giuliana Galletto (#3426, Buchung #877)** — hat den Reminder am 14.09. 12:22
mit „Ja" bestätigt (`confirmed_at` belegt es), war danach storniert, schrieb um
15:52 dass sie sich verspätet, und wurde um 16:53 vor Ort auf `attended`
korrigiert („Stand auf abgesagt aber kam zu spät. Hatte Bescheid gesagt").
Der Storno-Beleg war durch die Korrektur bereits gelöscht — welcher Weg es war,
ist nicht mehr feststellbar. Das Verhaltensmuster ist identisch zu Aurora.

## Diagnose-Abfrage

```sql
SELECT b.rec_applicant_id,
       COUNT(*)            AS eigene_stornos,
       MAX(b.cancelled_at) AS letzter_storno
FROM rec_interview_bookings b
WHERE b.cancelled_by = 'applicant'
  AND b.cancelled_at >= '2026-08-01'
  AND b.deleted_at IS NULL
  AND NOT EXISTS (
        SELECT 1 FROM rec_interview_bookings x
        WHERE x.rec_applicant_id = b.rec_applicant_id
          AND x.status <> 'cancelled' AND x.deleted_at IS NULL)
  AND NOT EXISTS (
        SELECT 1 FROM rec_hr_desk_cases c
        WHERE c.rec_applicant_id = b.rec_applicant_id
          AND c.reason = 'applicant_cancelled_training')
  AND NOT EXISTS (
        SELECT 1 FROM rec_auto_pilot_logs l
        WHERE l.rec_applicant_id = b.rec_applicant_id
          AND l.type IN ('cancelled_by_applicant', 'cancelled_by_reply'))
GROUP BY b.rec_applicant_id
ORDER BY letzter_storno DESC;
```

Sobald Stufe 1 (unten) live ist, wird diese Abfrage überflüssig — dann trägt
der Pfad seinen eigenen Marker.

## Fix in zwei Stufen

**Stufe 1 — den Pfad protokollieren.** `cancelAndRebook()` schreibt einen
AutoPilot-Log-Eintrag mit eigenem Typ. Macht die drei Wege im Nachhinein
unterscheidbar und kostet fast nichts. Behebt den Fall nicht, aber beendet die
Blindheit — die heutige Grabung hätte damit Sekunden statt einer Stunde gedauert.

**Stufe 2 — den Abbruch bemerken.** Beim Stornieren einen Zeitstempel
„Umbuchung läuft" am Bewerber setzen. Wer nach X Stunden keine neue Buchung hat,
landet auf dem HR-Schreibtisch und bekommt optional eine WhatsApp („Du hast
gerade keinen Termin — hier buchen"). Das repariert den Fall, statt ihn nur
sichtbar zu machen.

**Bewusst nicht:** erst beim Abschluss stornieren. Wäre sauberer, dreht aber an
der Sitzplatz- und Wartelisten-Mechanik — der Platz geht absichtlich sofort an
die Warteliste. Zu viel Risiko für den Nutzen.

## Operativ, unabhängig vom Fix

Die 39 sind großenteils Bewerber, die einen Termin wollten und ihn verloren
haben. Die jüngeren lassen sich anschreiben. Und Aurora Welz' Buchungsstatus
gehört geprüft.
