# KPI-Statistik-Seite — Design

**Datum:** 2026-08-03
**Status:** Entwurf zur Review

## 1. Kontext & Ziel

Der Kunde (Personalvermittlung) braucht eine analytische Statistik-Seite. Auslöser ist
konkretes Feedback (Mail vom Kunden) zum heutigen Dashboard:

- Zahlen sind nicht nachvollziehbar („woher kommen 96 Bewerbungen für MGL? Die Summe
  der aktiven Stellen ergibt das nicht", „was sind die 153 allgemeinen Bewerber?")
- Gewünschte Struktur: Auswahl nach Filiale/Region, dann Qualifikationen, dann **eine
  Zeile pro Schulung** mit dem kompletten Durchlauf vom Bewerbereingang bis zur
  Unterschrift, Gesamt-Zeile als Addition
- Eine Ansicht statt zwei getrennter Tabellen
- „Wo hängen die restlichen Bewerber fest, wenn nur 53 in den Schulungen sind?"

Technischer Hintergrund der Verwirrung: Bewerber↔Ausschreibung ist n:m (Zeilensummen
zählen doppelt, Unique-Totals nicht), und es gibt unsichtbare Buckets (unzugeordnet,
geparkt, HR-Desk, Dubletten, Alt-Importe), die je nach Ansicht rein- oder rausfallen.

**Ziel:** Eigene Statistik-Seite, deren Herzstück eine vollständig rekonzilierte
Kohorten-Tabelle ist (jede Gesamtzahl = exakte Summe der sichtbaren Zeilen), plus
analytische Sektionen (Quellen, Trends, Termin-Effizienz, Time-to-X, Auto-Pilot,
WhatsApp-Kosten).

**Rollout:** Die Seite ist zunächst nur per URL erreichbar — **kein** Sidebar-/
Menü-Eintrag, bis intern freigegeben.

## 2. Architektur-Entscheidung

**Gewählt: Live-Queries mit pure Service-Klassen** (Ansatz A).

- Route `/statistik` → Livewire-Komponente `src/Livewire/Statistics/Index.php`
- Dünne Query-Schicht in der Komponente (Eloquent, immer `forTeam()`), Rechenlogik in
  pure Klassen unter `src/Services/Statistics/` — testbar ohne Laravel/DB
  (Modul-Testkonvention)
- Charts über ApexCharts (bereits global im Core-Layout eingebunden)
- Verworfen: vorberechnete Snapshot-Aggregate (Nightly Job) — Overkill bei den
  Datenmengen; bleibt Ausbaupfad, falls die Seite je träge wird

## 3. Seitenaufbau

Oben eine **globale Filterleiste**, wirkt auf alle Inhalte:

- Zeitraum (Presets wie im Dashboard, Basis `rec_applicants.applied_at`)
- Ort (`rec_positions.location`, UI-Label „Standort", Freitext) — Achtung: der
  **Termin-Ort** (`rec_interviews.location`, gespeist aus `rec_event_locations`
  als full_address-Freitext) ist eine getrennte Quelle; ob er als zweiter
  Filter gebraucht wird, entscheiden die DISTINCT-Werte aus Produktion
- Tätigkeit (`rec_postings.activity`, Freitext 60 Zeichen)
- Ausschreibung (`rec_postings`)
- Quellplattform (`rec_source_platforms`)

Darunter drei Ebenen:

1. **KPI-Kacheln** (eine Zeile): Bewerbungen im Zeitraum, davon in Schulung gebucht,
   Unterschriften, Conversion gesamt, Time-to-Hire-Median — jeweils mit Δ zum
   Vorzeitraum
2. **Kohorten-Tabelle** (Abschnitt 4)
3. **Analyse-Sektionen** (Abschnitt 6), aufklappbar

Wird `location` an einer Stelle nicht gepflegt, erscheint eine ehrliche
„ohne Ort"-Gruppe (kein stilles Wegfiltern).

## 4. Kohorten-Tabelle (Herzstück)

**Grundprinzip:** Bewerber-basiert und vollständig. Grundmenge = alle Bewerbungen im
gewählten Zeitraum (nach `applied_at`, mit globalen Filtern). **Jeder Bewerber landet
in genau einer Zeile.** Die Gesamt-Zeile ist die exakte Summe der Zeilen darüber.

**Struktur:** gruppiert nach Ort → Tätigkeit, darin:

| Zeilentyp | Inhalt |
|---|---|
| Eine Zeile pro Schulungstermin (Datum + Terminart) | Bewerber der Kohorte mit aktiver Buchung auf diesen Termin; bei Umbuchung zählt nur die neueste aktive Buchung |
| „Noch ohne Schulung" (aufklappbar nach Phase) | Aktive Bewerber ohne Buchung, aufgeschlüsselt nach aktueller Phase — beantwortet „wo hängen die restlichen fest?" |
| „Ausgeschieden" | Geparkt / Abgesagt (`rejected_at`) / HR-Schreibtisch, getrennt ausgewiesen |
| „Dubletten / Unzugeordnet" | `duplicate_of_applicant_id` bzw. `is_unrouted` — explizit sichtbar statt still gefiltert |
| Gesamt je Ort-Gruppe + Gesamt unten | Reine Addition |

**Spalten der Schulungszeilen** (Funnel Bewerbereingang → Unterschrift):
Eingang → Kontaktiert → Gebucht → Bestätigt → Standby → Teilgenommen / No-Show →
Vertrag verschickt → Unterschrieben → Conversion-%. Dazu Kapazität (belegt / `max_participants`).

**Status-Gruppierung: keine zweite Wahrheit erfinden.** Die Gruppierung baut auf
`SeatStandbyPolicy` auf (`SEAT_FREEING_STATUSES = ['cancelled']`, laut Docblock
„DIE zentrale Zählregel"). Die Statistik-Seite ergänzt eine Konstante (z. B.
`BookingStatusGroups`), die `SeatStandbyPolicy` referenziert statt eigene
Listen zu pflegen:

- **Kohorten-zugeordnet**: `status IN ('booked','registered','confirmed',
  'attended','no_show')` und `deleted_at IS NULL` — alles außer `cancelled`;
  `no_show` gehört zur Kohorte (war zugeordnet, ist das Negativ-Ergebnis)
- **Kapazitätsspalte (belegt)**: exakt `scopeSeatTaking()` — `status NOT IN
  SEAT_FREEING_STATUSES AND seat_released_at IS NULL`. `attended` und `no_show`
  belegen also einen Platz — konsistent mit UI und Buchungslogik, keine
  eigene Zählregel
- **Standby**: `status='booked'` + `seat_released_at` gesetzt (Invariante: der
  Marker existiert nur auf `'booked'`, saving-Guard im Model erzwingt das)
- **„Bestätigt"** = `confirmed | attended | no_show`. Fußnote: `registered` ist
  mehrdeutig (Tool-Default ohne Engagement vs. Upgrade durch Phasen-Abschluss —
  `confirm_booking_on_completion` schreibt `'registered'`, nicht `'confirmed'`)
  und zählt hier bewusst NICHT als bestätigt; Phasen-Abschluss-Upgrades fehlen
  in dieser Spalte systematisch, bis Auftrag ③ entschieden ist
- Kontaktiert: `enrichment_status IS NOT NULL AND != 'no_contact'` (wie Dashboard)
- Vertrag: `rec_contracts.sent_at` / `signed_at` (nur non-cancelled)
- `rec_interview_bookings.is_active` wird **ignoriert** — tote Spalte (default
  true, wird nirgends auf false gesetzt und nirgends gelesen)
- Hinten bewusst erweiterbar: Dispo-Spalten („gearbeitete Termine") kommen später als
  zusätzliche Spalten, ohne Umbau

**Drill-down:** Jede Zahl ist anklickbar → Modal mit den Personen dahinter (Name +
Link zur Bewerberseite).

**Einziger stiller Filter:** `is_test`. Alles andere wird als Zeile gezeigt.
(Hinweis: das heutige Dashboard filtert `is_test` **nicht** — `statsApplicantPool()`
hat keinen solchen Filter; Test-Bewerber verfälschen dort die Kundenzahlen. Die
Statistik-Seite macht es richtig und dokumentiert die Abweichung.)

**Zuordnungsregel Bewerber → Stelle/Ausschreibung — fünf Fälle:**

1. Es gibt eine Pivot-Zeile, deren Posting zur Position von `rec_phase_id`
   gehört → diese Zuordnung zählt (Phase ist der abgeleitete Ist-Zustand)
2. Pivot-Zeilen vorhanden, aber keine passt zur Phase-Position → kleinste
   `rec_posting_id` zählt, Bewerber wird als „Zuordnung uneindeutig"
   gekennzeichnet (mess- und anklickbar)
3. Keine Pivot-Zeile → Zeile „ohne Ausschreibung"
4. `rec_phase_id IS NULL` → Zeile „ohne Phase"
5. Dangling `rec_phase_id` (Phase gelöscht, Zeiger bleibt) → **kann nicht
   auftreten**: der FK ist `constrained('rec_phases')->nullOnDelete()`
   (Migration `2026_04_12_000002`); Stellen-Löschung cascadet die Phasen und
   nullt den Zeiger → Fall 5 kollabiert DB-garantiert in Fall 4. Die
   Auswertung zählt Fall 4 trotzdem getrennt nach „nie gesetzt" vs.
   „genullt" — unterscheidbar ist das nur ab Einführung des Transition-Logs

Weitere Doppelzähl-Regeln:

- Bewerber mit mehreren Buchungen: nur die neueste Kohorten-zugeordnete Buchung
  (Storno mit späterer Neubuchung = umgebucht, zählt einmal beim neuen Termin)
- Dublette schlägt alles: wer geflaggt ist, erscheint nur in der Dubletten-Zeile
- Bei aktivem Ausschreibungs-Filter zählt die gefilterte Zuordnung

## 5. Datenbasis: Phasen-Transition-Log

Heute existiert keine strukturierte Phasen-Historie (nur Freitext in
`rec_auto_pilot_logs.summary`; allein `phase_returned` hat IDs in `details`).
Verweildauer- und Staustellen-KPIs brauchen sie.

**Neue Tabelle `rec_phase_transitions`:**

- `team_id`, `rec_applicant_id`, `rec_position_id`
- `from_phase_id` / `to_phase_id` (nullable FK) **plus** `from_phase_name` /
  `to_phase_name` als Text-Snapshot — Phasen werden pro Stelle geklont, umbenannt und
  gelöscht; die Auswertung darf davon nicht abhängen
- `trigger`: `auto_advance` | `manual` | `returned` | `position_switch`
- `occurred_at`; Indizes `(team_id, occurred_at)`, `(rec_applicant_id, occurred_at)`

**Schreibmechanik: Observer, nicht Einzelstellen.** `rec_phase_id` wird an elf
Stellen geschrieben (u. a. Auto-Advance, manuelles Vorrücken, Rückstufung,
Stellen-Wechsel, Reconcile, LLM-Tool, DirectHire, zwei Commands) — Einzel-Hooks
wären lückenhaft. Stattdessen ein Observer auf `RecApplicant`, der bei
geändertem `rec_phase_id` die Transition schreibt (deckt alle Eloquent-Pfade
ab). Bekannte observer-blinde Ausnahme: `FixApplicantPhase` schreibt per
Query-Builder (`DB::table(...)->update()`, keine Model-Events) — der Command
bekommt einen expliziten Transition-Insert. Defensiv: try/catch um den Insert;
ein Log-Fehler wird geloggt, bricht aber nie den Phasenwechsel ab.

**Backfill-Command** `recruiting:backfill-phase-transitions`:

- Parst `phase_advanced`-Summaries. Drei Formate: `Phase "X" abgeschlossen —
  weiter zu "Y".` (from+to), `Manuell weiter zu Phase "Y".` (**nur to** — das
  from wird aus dem vorherigen Transition-Stand bzw. der Phase-Order
  abgeleitet), `phase_returned` liefert IDs strukturiert in `details`
- Namens-Match nur gegen die Phasen der Stellen des jeweiligen Bewerbers
  (Join über Pivot), nicht gegen alle `rec_phases`
- Idempotent (kein Duplikat bei Mehrfachlauf), `--dry-run`-Option
- Nicht matchbare Einträge werden gezählt und ausgewiesen, nicht geraten

Zeitbasierte Phasen-KPIs zeigen einen Hinweis „Datenbasis ab «ältester
Transition»", solange der Backfill Lücken hat.

## 6. Analyse-Sektionen

Je Sektion eine pure Rechen-Klasse unter `src/Services/Statistics/`:

1. **Phasen-Funnel & Abbruch** — Erreichungsquote pro Phase (Balken-Funnel),
   Abbruchverteilung (letzte Phase der Geparkten/Abgesagten), Verweildauer-Median pro
   Phase (aus Transitions)
2. **Quellen-Analyse** — pro Quellplattform: Bewerbungen, davon Dubletten, gebucht,
   unterschrieben, Conversion; Attributions-Split über `matched_via`
   (Kanal / externe Ref / LLM / Default / manuell / Vorschlag)
3. **Zeitverlauf** — Liniendiagramm Bewerbungseingänge pro Woche (ApexCharts),
   umschaltbar gestapelt nach Quelle oder Tätigkeit. **Fußnote im UI:** Daten
   vor dem 2026-05-01 sind unzuverlässig — bis dahin konnte das Enrichment-LLM
   `applied_at` mit aus dem Anschreiben extrahierten Daten überschreiben
   (Schutz: Commit 837e13c am 2026-04-30, nach Revert-Hin-und-her final aktiv
   mit 60231d4 am 2026-05-01)
4. **Termin-Effizienz** — No-Show-Quote, Storno-Quote nach Urheber (`cancelled_by`:
   Bewerber/HR/System), Termin-Auslastung (belegt vs. `max_participants`),
   Standby-Quote, Warteliste (eingetragen → erfüllt)
5. **Time-to-X** — Mediane: Eingang→Buchung (`booked_at − applied_at`),
   Eingang→Schulung, Schulung→Unterschrift, Eingang→Unterschrift (Time-to-Hire,
   Logik zieht vom Dashboard mit um)
6. **Auto-Pilot** — Durchlaufquote ohne manuellen Eingriff, Reminder-Wirksamkeit
   (Bestätigung nach 1./2./3. Reminder), „nie erreicht"-Quote (max. Reminder
   erreicht, kein Inbound)
7. **WhatsApp-Kosten** — nutzt den bestehenden `WhatsAppCostReportService`.
   Dessen Signatur kann nur `(teamId, from, to, typeFilter)` — Kosten im
   Zeitraum, automatisch vs. manuell, pro Template. **Nicht** nach
   Ort/Tätigkeit/Ausschreibung filterbar (kein Bewerber-Bezug im Join); die
   Sektion zeigt deshalb Gesamtzahlen und reagiert nur auf den Zeitraum-Filter

## 7. Tests

Modul-Konvention: reines PHPUnit ohne Laravel/DB (`meingedeck/vendor/bin/phpunit -c phpunit.xml`).

- **Rekonziliations-Invariante** (wichtigster Test): für beliebige Eingabemengen gilt
  Gesamt = Summe aller Zeilen, jeder Bewerber genau einmal — auch bei Umbuchung,
  Mehrfach-Ausschreibung, Dublette, fehlendem Ort
- Funnel-Mathe (Quoten, Conversion, Δ Vorzeitraum)
- Backfill-Parser (Summary-Formate, Nicht-Treffer-Zählung)
- Verweildauer-Berechnung aus Transition-Sequenzen (inkl. Rückstufung)
- Livewire-Komponente bleibt dünn (Queries + Übergabe an Services), analog zum
  Dashboard-Performance-Refactor

## 8. Nicht im Scope (bewusst)

**Getrennte Aufträge aus den Code-Review-Runden** (alle NICHT Teil dieser Spec,
eigene Tickets):

- ① **Standby-Lücke bei `registered`/`confirmed`-Buchungen aufgegebener
  Bewerber** — Ursache ist `CreateInterviewBookingTool.php:117`: Status-Argument
  wird ungeprüft durchgeschrieben, Default `'registered'` umgeht das
  Standby-Sicherheitsnetz (das nur `'booked'` freigibt). Der Pfad
  `RecApplicant:666` (Upgrade nach Phasen-Abschluss) ist dagegen bewusst und
  korrekt (Engagement nachgewiesen, `guardSeatReclaim` sichert die Kapazität)
- ② **Status-Ordnung als Konstante** + die drei uneinheitlichen
  `whereIn`-Stellen (`RecInterviewWaitlistObserver:105`,
  `ReminderResponseHandler:59`, `RecApplicant:918`) und die zwei
  `$validStatuses`-Duplikate (`InterviewBookings/Index:312`,
  `UpdateInterviewBookingTool:74`) darauf umstellen
- ③ **`confirm_booking_on_completion` schreibt `'registered'` statt
  `'confirmed'`** — Flag-Name und geschriebener Status widersprechen sich;
  `registered` bedeutet dadurch zwei verschiedene Dinge (Tool-Default ohne
  Engagement vs. Upgrade nach Phasenabschluss)
- ④ **`applied_at`-Schreibbarkeit inkonsistent** — `BulkUpdateApplicantsTool`
  erlaubt Updates (Whitelist :162), `UpdateApplicantTool` blockt bewusst
  (:52-56, LLM-Falschdaten-Historie); vereinheitlichen

Weitere bewusste Ausschlüsse:

- **Warteliste/Standby-Verhalten** („Nachrücken bei TN-Erhöhung, welche Zahl muss
  Clara erhöhen?") — separater Prüfauftrag, kein Dashboard-Thema
- **Dispo-Spalten** (gearbeitete Termine) — Datenbasis existiert noch nicht;
  Tabellen-Layout ist darauf vorbereitet
- **Frühfluktuation** — kein Kündigungsgrund/-datum sauber modelliert (nur
  `employment_ended_at`); Phase 2
- **Anzeigenkosten pro Kanal** — nirgends modelliert; Phase 2
- **Vertrags-Ablehnungsstatus** — existiert nicht (`cancelled` wird nirgends
  gesetzt); Phase 2
- **Neue Timestamps an Buchungen** (`confirmed_at`, `attended_at`) — Quoten gehen
  ohne; nachrüstbar, wenn „wie schnell bestätigen Leute?" gefragt wird
- **Sichtbarkeit** — kein Menü-Eintrag in V1; Freischaltung ist ein bewusster
  späterer Schritt
