# Plattform-Fehlerbereich: Fehler aus Hintergrundlaeufen sichtbar machen

**Gefunden:** 28.08.2026 bei der Freigabe von Dispo Runde 4 (#0 Backfill im Scheduler).

## Befund

Mehrere Hintergrundlaeufe melden Fehler nur dort, wo niemand hinschaut:

- `recruiting:zas-crm-contact-backfill` schreibt Fehler/Skips nur auf die Konsole (im Scheduler: nirgendwohin). Runde 4 ergaenzt `Log::error` + `appendOutputTo`, aber ein Log ist kein Ort, an dem HR/Dispo nachsieht.
- Meta-`failed` nach Versand (Portal-Einladung, Kampagne, Dispo-Alarm ans Diensthandy) faellt lokal als Erfolg — sichtbar erst per Webhook-Status in Einzelansichten (siehe Memory „Dispo-Alarm ans Diensthandy scheitert“, „sendPortalNotification stempelt Meta-failed als Erfolg“).
- ZAS-Import: `employee_create_failed`, Spaltenlaengen-Crash (22001) — Diagnose nur ueber Logs (Memory „MA-Anlage stirbt an zu langem Feldwert“).
- Auto-Pilot-Silent-Logs (28.08. gefixt) waren nur ueber DB-Abfragen auffindbar.

Gemeinsames Muster: **Die Plattform weiss, dass etwas schiefgelaufen ist, sagt es aber niemandem.**

## Vorschlag

Ein Modul-Bereich **„Systemmeldungen"** (Recruiting → Verwaltung), gespeist aus einer Tabelle `rec_system_events` (team_id, source, level, key, message, context json, first_seen_at, last_seen_at, count, acknowledged_at/by):

- Schreiben ueber einen kleinen Service `SystemEventRecorder::warn(source, key, message, context)` mit Dedup auf (source, key) — wiederholte gleiche Fehler zaehlen hoch statt zu fluten (Lehre aus dem Auto-Pilot-Silent-Log-Flood).
- Erste Quellen: Backfill-Fehler, Meta-`failed` (Webhook), ZAS-Import-Fehler, Eskalations-Command-Fehler, Kampagnen-Versand `failed()`.
- Anzeige: Liste mit Level-Filter, „quittieren"; Sidebar-Badge (Zaehler unquittierter Fehler) analog `DispoUnreadCounter` (wirft nie, gecacht).
- Optional spaeter: Mail-Digest pro Tag an eine konfigurierbare Adresse.

## Nicht Teil dieses Tickets

Plattform-Core-Aenderungen (ein Core-weiter Log-Kanal). Erst modul-lokal in Recruiting bauen; beim Staffing-Auszug wandert die Tabelle mit.
