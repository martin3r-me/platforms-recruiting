# Design: Warteliste für Schulungstermine (Phase 2)

**Datum:** 2026-06-09
**Modul:** platforms-recruiting
**Status:** Design abgestimmt, vor Implementierung

## Problem

In **Phase 2** (`completion_type='booking'`) klickt der Bewerber den WhatsApp-Buchungslink
und landet auf der öffentlichen Buchungsseite (`InterviewBooking.php`). `availableInterviews()`
filtert nach Position/Ort, Zukunft und freien Plätzen. Liefert das nichts zurück, zeigt die
Blade-View heute nur:

> „Keine freien Termine — Bitte versuchen Sie es später erneut."

Das ist der **gesamte** Fallback. Kein Merken des Bewerbers, keine HR-Info, keine automatische
Benachrichtigung wenn später ein Termin entsteht. Der Bewerber muss von sich aus wiederkommen —
und geht damit faktisch verloren.

### Multi-Ort-Kontext (bereits vorhanden)

Der Bewerber wählt am Anfang **mehrere** `beschaftigungsort` (Wunschorte, Array im Extra-Field).
`resolvePositionIdsForApplicant()` (InterviewBooking.php:132) mappt alle Wunschorte → Stellen
(via `beschaftigungsort_lookup_value`) + primary als Fallback. Solange nicht „committed"
(Phase < 3, kein aktives Booking), sieht er Termine aus **allen** seinen Wunschorten gemischt.
Bucht er einen Termin, vollzieht `maybeSwitchPosition()` (InterviewBooking.php:256) bei
`switch_position_on_booking=true` den **Stellenwechsel** auf den gebuchten Ort.

Der relevante „kein Termin"-Zustand ist daher: **über ALLE Wunschorte hinweg gibt es keinen
freien Termin.** Entsteht später in *irgendeinem* Wunschort ein Termin, soll der Bewerber den
Link bekommen; das Buchen vollzieht dann den bestehenden Stellenwechsel.

## Abgestimmte Entscheidungen

| Thema | Entscheidung |
|---|---|
| Grundverhalten | Warteliste mit **aktivem Eintrag** (Button bestätigt Wunschorte) |
| Verteilung | An **alle** passenden Wartenden, first-come |
| Re-Benachrichtigung | **Nur 1x**, dann Ruhe bis Buchung |
| Kanal | **WhatsApp** (bestehender Mechanismus) |
| HR-Sicht | **Zähler pro Ort + Liste** der Wartenden |
| Datenmodell | Eigene Tabelle `rec_interview_waitlist` |
| Trigger | **Event-driven** Queued Job, kein Polling-Command |
| Aktivierung | **Phasen-Setting** in `completion_config` (`waitlist_enabled`) |

## Architektur

### 0. Aktivierung als Phasen-Setting

Das Feature wird **pro Phase** über `completion_config` gesteuert — konsistent mit den
bestehenden Flags (`switch_position_on_booking`, `confirm_booking_on_completion`,
`send_booking_notification_on_completion`):

- **`waitlist_enabled`** (bool) — reiner An/Aus-Schalter für die Warteliste in dieser
  Buchungs-Phase. Nur dann erscheint der „Benachrichtigt mich"-Button und nur dann werden
  Waitlist-Zeilen angelegt.

Das **WA-Template gehört NICHT ins Phasen-Config**, sondern wird wie alle anderen WA-Templates
über die bestehende Settings-Kaskade gepflegt (siehe Abschnitt 3).

### 1. Datenmodell — `rec_interview_waitlist`

Neue Tabelle + Model `RecInterviewWaitlist`:

| Feld | Zweck |
|---|---|
| `id`, `uuid`, `team_id` | Standard |
| `rec_applicant_id` | FK, der wartende Bewerber |
| `wunschorte` (JSON) | Snapshot der bestätigten `beschaftigungsort`-Werte beim Eintrag |
| `enrolled_at` | Wann eingetragen (FIFO-Reihenfolge) |
| `notified_at` (nullable) | Setzt die „nur 1x"-Regel |
| `fulfilled_at` (nullable) | Gesetzt wenn Bewerber gebucht hat |
| `cancelled_at` (nullable) | Gesetzt bei Reject/Park/Abmeldung |

Ein Bewerber hat max. **eine offene** Zeile (`fulfilled_at` & `cancelled_at` = NULL).
Unique-/Existenz-Guard analog zu `existingBooking`.

### 2. Eintrag in die Warteliste (Bewerber-Seite)

In `InterviewBooking.php` + `interview-booking.blade.php`:

- Ist `availableInterviews()` leer, die Phase hat `waitlist_enabled=true` **und** der Bewerber
  ist noch nicht eingetragen → die heutige „Keine freien Termine"-Box bekommt einen Button
  **„Benachrichtigt mich, sobald ein Termin frei wird"**. Ohne das Flag bleibt es beim heutigen
  „später erneut versuchen".
- Neue Livewire-Action `joinWaitlist()`: snapshottet die Wunschorte (gleiche Quelle wie
  `resolvePositionIdsForApplicant` — das `beschaftigungsort`-Extra-Field), legt die Zeile an,
  State → `waitlisted`.
- Revisit: ist der Bewerber bereits eingetragen, zeigt die Seite direkt den
  `waitlisted`-Bestätigungs-State statt der nackten „keine Termine"-Box.

### 3. WhatsApp-Template + Settings

Neues Template, **gleicher Buchungslink-Token** wie beim normalen Versand, nur anderer Text
(„Es ist ein Termin frei geworden — jetzt buchen: {Link}"). Kein neuer Versandweg — wir nutzen
`sendInterviewBookingNotification` (bzw. eine parametrisierte Variante davon, die die
Template-ID entgegennimmt).

**Template-Pflege wie gehabt** — gleiche Settings-Kaskade wie `interview_booking_wa_template_id`
(RecApplicant.php:652–658):

```
Position.auto_pilot_settings['interview_waitlist_wa_template_id']
   ↓ Fallback
RecApplicantSettings (team-weit) → getSetting('interview_waitlist_wa_template_id')
```

- Neuer Settings-Key **`interview_waitlist_wa_template_id`**, gepflegt team-weit in den
  Bewerber-Einstellungen (`RecApplicantSettings`) mit optionalem Override pro Stelle
  (`position.auto_pilot_settings`).
- WA-Account bleibt der bestehende `auto_pilot_wa_account_id` (gleiche Kaskade).
- Der Trigger-Job löst die Template-ID über Waitlist-Zeile → Bewerber → Position/Team auf.

### 4. Auto-Benachrichtigung beim neuen Termin (event-driven Trigger)

**Kein Polling-Command.** Der Auslöser ist eine diskrete, seltene HR-Handlung (Termin anlegen),
kein Zeitereignis — Polling würde 48×/Tag ins Leere greifen und bis zu 30 Min Latenz erzeugen.

Stattdessen: Geht ein `RecInterview` in einen **verfügbaren** Zustand über (neu angelegt oder
reaktiviert: `is_active=true`, status `planned`/`confirmed`, `starts_at > now`, freie Kapazität),
wird **ein** Queued Job `NotifyWaitlistForInterview` dispatcht. Er läuft auf dem bestehenden
Queue-Worker (der die WA-Sends ohnehin abarbeitet) — kein neuer Dauerprozess, idle Last = 0.

Job-Ablauf:
1. Ort des Slots auflösen: `interview.position.beschaftigungsort_lookup_value`.
2. Offene Waitlist-Zeilen finden, deren `wunschorte` diesen Ort enthält **und**
   `notified_at IS NULL`.
3. Je Treffer **atomarer** Versand-Anspruch:
   `UPDATE rec_interview_waitlist SET notified_at=now() WHERE id=? AND notified_at IS NULL`.
   Nur bei 1 affected row geht die WhatsApp-Nachricht raus (über `sendInterviewBookingNotification`),
   wobei die Template-ID über die Settings-Kaskade `interview_waitlist_wa_template_id`
   (Position → Team-Bewerber-Einstellungen) aufgelöst wird (siehe Abschnitt 3).

Damit ist die „nur 1x"-Regel auch bei parallelen Workern wasserdicht: Legt HR 5 Slots in 2 Minuten
an, werden 5 Jobs dispatcht, aber nur der erste gewinnt den atomaren Anspruch pro Bewerber.

**Einhängepunkt:** Beim Implementieren verifizieren — Model-Observer auf `RecInterview`
(`created`/`updated` mit Übergang in „verfügbar") vs. die HR-Tools, die Termine anlegen. Ziel ist
*eine* robuste Stelle, die „wird verfügbar" erkennt, ohne bei jedem Speichern doppelt zu feuern.

### 5. Lebenszyklus / Aufräumen

- **Bucht** der Bewerber (`bookInterview`) → offene Waitlist-Zeile `fulfilled_at = now()`.
- **Reject / Park / Inaktiv** → `cancelled_at = now()` am passenden Status-Übergang im `RecApplicant`.

So bleibt die Liste sauber und der HR-Zähler korrekt.

### 6. HR-Sicht — Zähler pro Ort + Liste

- Aggregat über offene Zeilen, nach Ort aus `wunschorte` aufgeschlüsselt:
  „Köln 12 · Bonn 7 · Düsseldorf 3". Ein Bewerber mit 3 Wunschorten zählt in allen dreien
  (er wartet auf alle).
- Klick auf einen Ort → Liste der wartenden Bewerber (Name, seit wann `enrolled_at`, ob schon
  benachrichtigt `notified_at`).
- Platzierung: im Recruiting-Dashboard/Phase-2-Kontext (genaue Stelle gegen die bestehende
  Dashboard-Struktur prüfen).

## Bewusste Tradeoffs / Edge-Cases

- **„Nur 1x" wörtlich:** Bekommt der Bewerber die Nachricht, ist der Termin aber first-come
  schon voll bevor er klickt, gibt es nach aktueller Regel keinen erneuten automatischen Stups.
  Er bleibt auf der Liste, muss aber selbst nochmal reinschauen. Bewusst akzeptiert; ggf. später
  lockern (z.B. Re-Notify, wenn die Verfügbarkeit erneut von 0 auf >0 springt).
- **Multi-Ort-Zählung:** Ein Wartender zählt in jedem seiner Wunschorte — Absicht, da er auf
  alle wartet.

## Testing

- **Eintrag:** leer + nicht eingetragen → Button; nach `joinWaitlist` → Zeile mit korrektem
  Wunschorte-Snapshot + `waitlisted`-State; Revisit zeigt `waitlisted`.
- **Trigger:** neuer Köln-Slot → nur Warter mit Köln im Snapshot werden benachrichtigt,
  `notified_at` gesetzt; zweiter Slot löst keine zweite Nachricht aus („nur 1x").
- **Atomarität:** zwei parallele Jobs für denselben Bewerber → genau eine Nachricht.
- **Multi-Ort:** Snapshot deckt alle Orte ab; Buchung in einem Ort vollzieht den bestehenden
  Stellenwechsel und setzt `fulfilled_at`.
- **Lifecycle:** Reject/Park setzt `cancelled_at`, fällt aus Zähler.
