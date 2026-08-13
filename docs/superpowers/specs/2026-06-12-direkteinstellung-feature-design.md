# Design: Direkteinstellung (persönliche Bewerbungsverarbeitung, z. B. Serviceleiter/Teamleiter)

**Datum:** 2026-06-12
**Status:** Entwurf zur Review
**Abhängigkeit:** Setzt auf dem Matching-Konzept auf (`2026-06-11-stellen-ausschreibungen-matching-design.md`) — Umsetzung **nach** der Matching-Pipeline.

## 1. Problem / Ziel

Der Kunde will einzelne Positionen (Serviceleiter, Teamleiter, …) abseits der normalen Recruiting-Maschinerie besetzen: Bewerbungen sollen gebündelt bei einer zuständigen Person eingehen und persönlich behandelt werden — **ohne AutoPilot, ohne Template-Versand, ohne Phasen-Automatik**. Fällt die Entscheidung für einen Kandidaten, soll ihm ein Portal-Link geschickt werden können, über den er seine Daten einträgt und damit Mitarbeiter wird.

Wichtig: Der Kunde soll das **selbst einrichten** können — kein manuelles Aufsetzen durch uns pro Position.

## 2. Grundsatz-Entscheidung: Konfiguration statt Parallelwelt

Kein neues Datenmodell, kein eigener Bewerbungs-Typ. Das Feature ist ein **Setup-Wizard „Direkteinstellung"**, der ausschließlich Standard-Objekte anlegt und passend vorkonfiguriert:

- Stelle (`RecPosition`) mit AutoPilot aus + 2 Phasen
- Ausschreibung (`RecPosting`) mit eindeutigem Eingang (eigene Mail oder Referenz-Code)
- Zuständiger User als Owner

Damit funktionieren Portal, Enrichment, Inbox, MA-Anlage und alle bestehenden Ansichten ohne Sonderfälle weiter.

Verworfen: eigenes Entity „Direktbewerbung" (Doppelbau von Portal/Enrichment/MA-Anlage) sowie rein manuelle Einrichtung durch uns (nicht kunden-self-service).

## 3. Der Wizard (kundenbedienbar)

Vier Eingaben:

1. **Titel** der Stelle/Anzeige (z. B. „Serviceleiter Köln")
2. **Eingangsweg** (eine Option):
   - **Eigene Mail-Adresse:** Wunsch-Präfix (z. B. `serviceleiter@mitarbeiter.rheingedeck.de`). Der Wizard legt den Kanal über das bestehende CRM-`CreateChannelTool` an und bindet ihn als **dedizierten Kanal** an die Ausschreibung (Stufe 1 der Matching-Pipeline).
   - **Referenz-Code für die Sammelmail:** auto-generiert (z. B. `SL-K7M3`), wird als externe Referenz (`rec_posting_external_refs`, Quelle „Referenz-Code") am Posting hinterlegt. Bewerber nennt den Code im Betreff → deterministischer Stufe-1-Treffer.
3. **Zuständiger User** (Dropdown)
4. **Datenerfassungs-Felder:** Welche Daten der künftige Kollege später im Portal ausfüllen muss — vorbelegtes Standard-Set (Stammdaten, Dokumente), abwählbar/erweiterbar.

## 4. Was unter der Haube entsteht

**Stelle** — AutoPilot aus, `owned_by_user_id` = gewählter User, zwei Phasen:

| Phase | Konfiguration | Verhalten |
|---|---|---|
| 1 „Bewerbungen" | `completion_type: manual`, kein `auto_advance` | Alle Eingänge laufen hier auf und bleiben liegen. Keine automatischen Antworten, kein Template-Versand. Gespräche/Absprachen passieren persönlich außerhalb des Systems. |
| 2 „Datenerfassung" | Pflicht-Extra-Felder aus dem Wizard, `creates_employee_on_completion: true` | Wird **manuell** betreten, wenn die Entscheidung für den Kandidaten gefallen ist. Portal-Link-Versand über den bestehenden Flow (`recruiting.public.applicant-portal`, public_token). Kandidat füllt aus → bei Vollständigkeit entsteht automatisch der MA-Datensatz (bestehender Hook). |

**Ausschreibung** an der Stelle, Status `published`, mit dediziertem Kanal **oder** Referenz-Code.

**Automatik-Umfang (bewusst minimal):**

- **Basis-Enrichment läuft:** Name, Telefon, E-Mail & Co. werden wie gewohnt aus Mail/Anhängen extrahiert und vorausgefüllt — reine Datenextraktion, kein Versand.
- **Sonst nichts Automatisches:** kein AutoPilot, keine Eingangsbestätigung, keine Reminder, keine WA-Templates. (Ergibt sich von selbst: All das hängt am AutoPilot, und der ist aus.)

**Zuständigkeit:** Neue Bewerber auf diese Ausschreibung bekommen `owned_by_user_id` = gewählter User + Benachrichtigung an ihn (Mail/In-App, bestehender Notification-Weg).

## 5. UI

1. **Neuer Sidebar-Punkt „Direkteinstellungen"** in der Gruppe „Übersicht" (Muster: Eingangs-Inbox/HR-Desk, `sidebar.blade.php`), mit Badge-Zähler für neue/unbearbeitete Bewerbungen. Erscheint nur, wenn mindestens eine aktive Direkteinstellungs-Stelle im Team existiert.
2. **Ansicht:** Gruppiert nach Direkteinstellungs-Stelle, darunter die Bewerber mit Eingangsdatum und Kontaktdaten (Basis-Enrichment), Aktionen: **Portal-Link senden** und Absagen/Parken. Filter „Nur meine" für den zuständigen User. Bewusst eine simple Liste — kein Phasen-Board, keine AutoPilot-Spalten.
3. **Kein eigenes Dashboard-Widget** im ersten Wurf; Badge + Owner-Benachrichtigung reichen als Signal.
4. **Normale Listen:** Direkteinstellungs-Bewerber/-Stellen bleiben sichtbar, markiert mit Badge „Direkteinstellung". Aus Funnel-/AutoPilot-KPIs des Dashboards werden sie herausgefiltert (verzerren sonst die Massen-Stellen-Statistik).
5. Das `direct_hire`-Kennzeichen an der Stelle (Abschnitt 8) ist der Schalter für all das: Sidebar-Sichtbarkeit, Badge-Zählung, Listen-Badge, KPI-Filter.

**Team-Scope:** Das Feature läuft im bestehenden Recruiting-Team mit (aktuell RHEINGEDECK-HR) — **kein eigenes Team** für Direkteinstellungen. Begründung: Die Matching-Pipeline (Sammelmail → Referenz-Code) arbeitet pro Team; ein Extra-Team würde Config-Duplikation (Quellen, Vorlagen, Portal, MA-Anlage) erzwingen und Teams als Berechtigungs-Krücke missbrauchen. Team-Fähigkeit ist durch das durchgängige `team_id`-Scoping ohnehin gegeben. Vertraulichkeit löst die Ausbaustufe (Abschnitt 6), nicht die Team-Grenze.

## 6. Sichtbarkeit (Ausbaustufe, jetzt nicht bauen)

Erster Wurf: Zuständigkeit + Benachrichtigung; alle Recruiting-User sehen die Bewerber weiterhin normal. **Vertraulichkeit** (nur zuständiger User + Admins sehen Bewerber dieser Stelle — in Listen, Dashboard, Inbox, Suche) ist als spätere Ausbaustufe vorgesehen; der Sichtbarkeits-Check würde dann an der Stelle hängen. Das Design hält sich diese Tür offen, baut sie aber nicht.

## 7. Reihenfolge & Abhängigkeiten

1. **Zuerst Matching-Pipeline** (Spec vom 2026-06-11): dedizierter Kanal und Referenz-Code sind deren Stufe-1-Bausteine. Dieses Feature wird danach im Wesentlichen Wizard-UI + Phasen-Preset.
2. **MVP-Abkürzung falls der Kunde drängt:** Die Variante „eigene Mail-Adresse" funktioniert bereits mit der heutigen Kanal-Logik korrekt (exklusiver Kanal → ein Posting). Nur der Referenz-Code braucht zwingend die Pipeline.

## 8. Datenmodell

Keine neuen Tabellen. Genutzt wird:

- `rec_positions.auto_pilot_settings` / AutoPilot-Flags (aus)
- `rec_phases` (`completion_type`, `completion_config.creates_employee_on_completion`)
- `rec_posting_comms_channel` (dedizierter Kanal) bzw. `rec_posting_external_refs` (Referenz-Code; neue Quelle „Referenz-Code" in `rec_source_platforms`)
- `owned_by_user_id` an Stelle und Bewerber
- `public_token` / Portal-Link-Versand (bestehend)

Einzige mögliche Ergänzung: ein Kennzeichen an der Stelle (z. B. `type: direct_hire` oder ein Setting), damit UI (Wizard-Bearbeitung, spätere Vertraulichkeit) solche Stellen erkennen kann.

## 9. Edge Cases

| Fall | Verhalten |
|---|---|
| Bewerbung ohne Code auf der Sammelmail, gemeint war die Direkteinstellungs-Stelle | Läuft durch die normale Pipeline (LLM-Match kann die Ausschreibung treffen, sonst Inbox → manuell zuordnen). Kein Datenverlust. |
| Zuständiger User verlässt das Team / wird deaktiviert | Stelle bleibt; Owner muss in der Wizard-Bearbeitung umstellbar sein. Benachrichtigungen an deaktivierte User unterbleiben. |
| Mail-Präfix bereits vergeben | Wizard validiert gegen bestehende Kanäle vor Anlage. |
| Kandidat füllt Portal nur teilweise aus | Wie im normalen Flow: Phase bleibt offen, kein MA-Datensatz; zuständiger User sieht den Fortschritt (`progress`). |

## 10. Nicht-Ziele

- Keine Vertraulichkeits-/Sichtbarkeitsbeschränkung im ersten Wurf (Ausbaustufe).
- Kein AutoPilot, keine Template-/Reminder-Versendungen für diese Stellen.
- Kein eigener Bewerbungs-/Datentyp, keine Parallel-Ansichten.
- Keine Änderungen außerhalb von `platforms-recruiting` (CRM-Kanal-Anlage über bestehendes Tool, kein CRM-Codeumbau).

## 11. Test-Strategie (Umriss)

- Wizard legt das komplette Objekt-Set korrekt an (Stelle, Phasen inkl. Hook-Config, Posting, Kanal/Code, Owner).
- Eingang über dedizierte Mail bzw. Code → Bewerber landet in Phase 1 bei richtigem Owner, Benachrichtigung ausgelöst, kein automatischer Versand.
- Basis-Enrichment füllt Kontaktfelder, verschickt nichts.
- Manueller Phasenwechsel + Portal-Link → Datenerfassung → MA-Datensatz entsteht über bestehenden Hook.
