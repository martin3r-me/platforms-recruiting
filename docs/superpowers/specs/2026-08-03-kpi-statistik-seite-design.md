# KPI-Statistik-Seite — Design

**Datum:** 2026-08-03
**Status:** Freigegeben (Stand Runde 5)

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
Der Mechanismus ist im Code direkt sichtbar: `positionStats()` bumpt jeden Bewerber
in **jeder** seiner Positions-Zeilen (`foreach ($positions as $position) …
bumpStatRow`, `Dashboard.php:470-480`) — die Zeilensumme der „Übersicht nach
Stelle" ist konstruktionsbedingt größer als das Unique-Total. Einen Ort-Filter
hat das Dashboard nicht; „MGL" ist dort eine Positions-Zeile.

**Ziel:** Eigene Statistik-Seite, deren Herzstück eine vollständig rekonzilierte
Kohorten-Tabelle ist (jede Gesamtzahl = exakte Summe der sichtbaren Zeilen), plus
analytische Sektionen (Quellen, Trends, Termin-Effizienz, Time-to-X, Auto-Pilot,
WhatsApp-Kosten).

**Abnahmekriterium (Live-Verifikation Schritt 1):** Nach dem Bau muss mit der
fertigen Seite erklärbar sein, wie die 96 und die 153 aus der Kunden-Mail
zustande gekommen sind. Kann die Seite das nicht, fehlt ein Bucket.

**Erwartungsabgleich zur Kunden-Mail:** Eine Dimension „Filiale/Region" existiert
im Datenmodell **nicht** — es gibt `rec_positions.location` (UI: „Standort") und
`rec_positions.department` (UI: „Abteilung"), beides optionale Freitexte. Die vom
Kunden gewünschte zweistufige Auswahl Filiale→Region bietet V1 daher nicht; die
oberste Ebene ist der Standort. Das wird dem Kunden so kommuniziert, nicht
verschwiegen.

**Rollout:** Die Seite ist zunächst nur per URL erreichbar — **kein** Sidebar-/
Menü-Eintrag, bis intern freigegeben. Achtung: kein Menü-Eintrag ist keine
Zugriffskontrolle — die Route liegt in `ModuleRouter::group('recruiting')` und
erbt damit `['web', 'detect.module.guard', 'auth:{guard}',
'check.module.permission']` (siehe Abschnitt 8, Deploy).

## 2. Architektur-Entscheidung

**Gewählt: Live-Queries mit pure Service-Klassen** (Ansatz A).

- Route `/statistik` → Livewire-Komponente `src/Livewire/Statistics/Index.php`
- Dünne Query-Schicht in der Komponente (Eloquent, immer `forTeam()`), Rechenlogik in
  pure Klassen unter `src/Services/Statistics/` — testbar ohne Laravel/DB
  (Modul-Testkonvention)
- **Die Präzedenz-Kette und jede Zuordnungsentscheidung wird komplett in der pure
  Klasse `CohortAssigner` getroffen, nicht in SQL** — sonst liegt die
  Rekonziliations-Invariante im untestbaren Teil. SQL holt Rohdaten, PHP entscheidet.
- `CohortAssigner` liefert **pro Zelle eine ID-Menge**. Die angezeigte Zahl ist
  `count()` dieser Menge, das Drill-down-Modal lädt exakt diese IDs. Nie zwei
  getrennte Queries für Zahl und Liste — die können divergieren.
- **`CohortViewModel`** (Architektur-Ergänzung aus der Ausführung, Task 11):
  pure Klasse für die ANZEIGE-Logik — Gruppierung/Sortierung des Anzeige-Baums
  Ort → Tätigkeit → Zeilen und die Auflösung der Drill-Mengen (`resolveIds`,
  `encodeScope`/`decodeScope`). Nötig, weil der Test-Bootstrap keinen
  Composer-Autoloader lädt und `Livewire\Component` nicht auflösbar ist — die
  Komponente selbst ist nicht unit-testbar; der riskante Teil wurde deshalb
  herausgeschnitten (Modul-Konvention). Sie erzeugt, verwirft und verschiebt
  KEINE Zeilen — Kette und Zuordnung bleiben allein Sache des Assigners. Ihre
  `TYPE_ORDER` ist bewusst eine Anzeige-Reihenfolge (Erfolgspfad zuerst),
  NICHT die Präzedenz-Kette.
- Analyse-Sektionen laden **lazy** (`wire:init` bzw. lazy Child-Components), die
  Kohorten-Tabelle zuerst. Query-Budget pro Render ist Abnahmekriterium (Messung
  wie beim Dashboard-Performance-Refactor).
- Charts über ApexCharts (bereits global im Core-Layout eingebunden)
- Verworfen: vorberechnete Snapshot-Aggregate (Nightly Job) — Overkill bei den
  Datenmengen; bleibt Ausbaupfad, falls die Seite je träge wird

## 3. Seitenaufbau

Oben eine **globale Filterleiste**, wirkt auf alle Inhalte:

- Zeitraum (Presets wie im Dashboard, Basis `rec_applicants.applied_at`)
- **Ort = `rec_positions.location`** (UI-Label „Standort", Freitext). Diese
  Entscheidung ist final: der Veranstaltungsort (`rec_interviews.location`,
  befüllt aus `rec_event_locations` als full_address-Freitext-Snapshot) ist
  **keine Filter- und keine Gruppierungsdimension**, sondern nur eine
  Info-Spalte an der Terminzeile. Begründung: kein FK, Freitext-Snapshot,
  Adresskorrekturen wirken nicht rückwirkend, `rec_event_locations` kennt nur
  die heute aktiven Optionen. Keine DISTINCT-Liste ändert das.
- Tätigkeit (`rec_postings.activity`, Freitext 60 Zeichen)
- Ausschreibung (`rec_postings`)
- Quellplattform (`rec_source_platforms`)

Darunter drei Ebenen:

1. **KPI-Kacheln** (eine Zeile): Bewerbungen im Zeitraum, davon in Schulung
   gebucht, Unterschriften, Conversion gesamt, Time-to-Hire-Median — jeweils mit
   Δ zum Vorzeitraum. **Die Kacheln haben keine eigenen Queries** — sie lesen
   aus dem Kohorten-Ergebnis (Abschnitt 4); eine Kachel kann der Tabelle nie
   widersprechen.
2. **Kohorten-Tabelle** (Abschnitt 4)
3. **Analyse-Sektionen** (Abschnitt 6), aufklappbar, lazy

Wird `location` an einer Stelle nicht gepflegt, greift der Gruppen-Fallback
„ohne Ort" (Abschnitt 4, Gruppen-Fallbacks — kein stilles Wegfiltern).

## 4. Kohorten-Tabelle (Herzstück)

**Grundprinzip:** Bewerber-basiert und vollständig. **Grundmenge = alle
Bewerbungen des Teams.** Der Zeitraum ist ein Filter auf `applied_at` — mit
expliziter Ausnahme: Datensätze mit `applied_at IS NULL` fallen nie still aus
dem Zeitraumfilter, sie erscheinen immer als eigene Zeile (Stufe 2 der
Präzedenz-Kette). **Jeder Bewerber landet in genau einer Zeile.** Die
Gesamt-Zeile ist die exakte Summe der Zeilen darüber.

**Zähleinheit ist ein `rec_applicants`-Record.** Spaltenköpfe sagen durchgehend
„Bewerbungen", nicht „Bewerber" — dieselbe Person kann als Dublette mehrfach
vorkommen; die Dubletten-Zeile trägt die Erklärung dafür.

**Präzedenz-Kette der Zeilentypen** (jeder Bewerber wird gegen diese Kette
geprüft, der erste Treffer gewinnt; implementiert ausschließlich in
`CohortAssigner`):

1. `is_test` → raus (einziger stiller Filter)
2. `applied_at IS NULL` → Zeile „ohne Bewerbungsdatum"
3. Dublette (`duplicate_of_applicant_id`) → Dubletten-Zeile
4. Unrouted (`is_unrouted`) → Unzugeordnet-Zeile
5. Alt-Import (`import_source IS NOT NULL`) → Import-Zeile. **Entscheidung:
   Import schlägt Buchung** — Imports waren bereits Mitarbeiter und
   durchlaufen den Funnel nicht (Docblock `withoutImports`); eine Buchung an
   einem Import ist Bestandsdaten-Rauschen und darf nie als Funnel-Erfolg
   zählen
6. Kohorten-zugeordnete Buchung vorhanden → **bekannter** Status:
   Schulungszeile; **unbekannter** Status: Zeile „unbekannter
   Buchungsstatus". Bei mehreren Kohorten-zugeordneten Buchungen gewinnt die
   neueste — Tie-Break (Senior-Rule-Muster wie im Dedup-Guard): spätester
   `starts_at` des Termins, bei Gleichstand kleinste Booking-ID. Storno mit
   späterer Neubuchung = umgebucht, zählt einmal beim neuen Termin
7. Ausgeschieden: abgesagt (`rejected_at`) / geparkt (`is_parked`) — getrennt
   ausgewiesen. **Beide Flags gleichzeitig: abgesagt schlägt geparkt** —
   `rejected` ist der endgültige Zustand, Parken der weiche (Ruling aus der
   Ausführung, Task 10; im Code als Kommentar, per Test abgedeckt)
8. „Noch ohne Schulung", aufklappbar nach aktueller Phase — beantwortet „wo
   hängen die restlichen fest?"

**HR-Schreibtisch ist KEIN Zeilentyp in dieser Kette**, sondern eine
Zusatzspalte/Markierung an Schritt 4–6: nach der Nicht-EU-nach-Schulung-Logik
ist „HR-Desk MIT aktiver Buchung" der Normalfall, nicht der Randfall — ein
HR-Desk-Bewerber mit Buchung gehört in seine Schulungszeile, mit sichtbarem
HR-Desk-Marker.

Alle acht Stufen sind Teil der Gesamt-Addition (Gesamt je Ort-Gruppe + Gesamt
unten = reine Addition). Die Seite nutzt **weder** `routed()` **noch**
`withoutImports()` als stillen Filter — beide Buckets sind Stufen der Kette.
„Unbekannter Buchungsstatus" existiert, weil `status` ein freier String ohne
DB-Constraint ist (das Create-Tool validiert nicht) — unbekannte Werte werden
sichtbar gezeigt, nie verschluckt.

**Funnel-Spalten der Schulungszeilen — Rang-Modell, alle Spalten kumulativ:**

| Rang | Status | Spalte |
|---|---|---|
| 1 | `booked` / `registered` | Gebucht |
| 2 | `confirmed` (und `no_show` — s. u.) | Bestätigt |
| 3 | `attended` | Teilgenommen |

- Funnel-Spalte = „hat Rang ≥ N erreicht". `no_show` erfüllt Rang 2, **nicht**
  Rang 3 — er ist ein Abzweig nach der Bestätigung, keine Stufe.
- **Standby** und **Teilgenommen/No-Show als Ergebnis-Aufschlüsselung** sind
  KEINE Funnel-Schritte — sie stehen optisch abgesetzt hinter der Funnel-Kette.
- Danach: Vertrag verschickt → Unterschrieben → Conversion-% (Vertrag:
  `rec_contracts.sent_at`/`signed_at`, nur non-cancelled).
- Fußnote an „Bestätigt": `registered` zählt bewusst nicht als bestätigt —
  der Status ist mehrdeutig (Tool-Default ohne Engagement vs. Upgrade durch
  Phasen-Abschluss, `confirm_booking_on_completion` schreibt `'registered'`);
  Phasen-Abschluss-Upgrades fehlen in der Spalte systematisch, bis Auftrag ③
  entschieden ist.
- **Der Funnel ist ein Snapshot, keine Historie.** Alle Ränge leiten aus dem
  aktuellen `status` ab; wer bestätigt hat und dann storniert, verschwindet
  rückwirkend aus „Bestätigt". Innerhalb einer Zeile bleibt die Kette monoton,
  aber zwischen zwei Aufrufen kann „Bestätigt" sinken. Ohne
  `confirmed_at`/`attended_at` (bewusst nicht im Scope) unvermeidbar —
  Definitions-Tooltip weist darauf hin.

**Status-Gruppierung: keine zweite Wahrheit.** Quelle ist `SeatStandbyPolicy`:

- **Kohorten-zugeordnet** = Status ist **bekannt** UND `NOT IN
  SeatStandbyPolicy::SEAT_FREEING_STATUSES` UND `deleted_at IS NULL`. Die
  reine Negativ-Formulierung würde unbekannte Statuswerte still in die
  Schulungszeilen spülen — genau das soll die „unbekannter
  Buchungsstatus"-Zeile verhindern. „Bekannt" kommt aus der Status-Konstante
  von Auftrag ②; **bis ② existiert, gilt als offene Verzweigung** die heute
  dokumentierte Werteliste (`booked, registered, confirmed, attended,
  cancelled, no_show` aus den zwei `$validStatuses`-Duplikaten) als bekannt.
  Was weder bekannt noch platzfreigebend ist → Extra-Zeile
- **Kapazität**: `CohortAssigner` dockt an `SeatStandbyPolicy::countsAsSeat()`
  an — pure statische Methode, testbar ohne DB, garantiert dieselbe Regel wie
  UI und Buchungslogik (`scopeSeatTaking`)
- **Standby**: bestimmt über `SeatStandbyPolicy::statusLabel(...) !== null`
  (Ruling Task 10 — keine duplizierte booked+released-Bedingung). Invariante:
  der Marker existiert nur auf `'booked'`, saving-Guard im Model erzwingt das
- **Kapazität „Kohorte"** (Blade): `count(ids) − count(standby)` — exakt, weil
  (1) standby ⊆ ids (gleiche Gewinner-Buchung), (2) der Gewinner nie
  `cancelled` ist, (3) `seat_released_at` nur auf `'booked'` existiert; erst
  aus (3) folgt `countsAsSeat ⇔ !standby` (Ruling Task-11-Fix, Herleitung als
  Blade-Kommentar an der Rechnung)
- Kontaktiert: `enrichment_status IS NOT NULL AND != 'no_contact'` (wie Dashboard)
- `rec_interview_bookings.is_active` wird **ignoriert** — tote Spalte (default
  true, nirgends gesetzt, nirgends gelesen)

**Kapazitätsspalte hat eine ANDERE Grundmenge als der Rest der Zeile**
(termin-global vs. kohorten-gefiltert): „Gebucht: 3" neben „belegt: 18/20" läse
sich als Widerspruch. Darum **zwei beschriftete Spalten**: „Kohorte" (gefilterte
Zahlen) und „Termin gesamt" (belegt/max über alle Buchungen des Termins).
Zusätzlich: **belegt kann `max_participants` überschreiten** — der manuelle
HR-Advance konsumiert Standby-Plätze bewusst an der Kapazität vorbei (geloggt
als `seat_reclaimed_override`). Nicht klammern, freie Plätze nicht negativ
rechnen, Auslastung darf über 100 % anzeigen.

**Drill-down:** Jede Zahl ist anklickbar → Modal mit den Personen dahinter
(geladen über exakt die ID-Menge der Zelle, siehe Abschnitt 2).
**Mechanik (Ruling Task 11):** Die Zelle übergibt ein base64-Token, das nur
eine MENGENBESCHREIBUNG trägt (scope/ort/taetigkeit/type/key als JSON) — nie
IDs. Grund: Ort-/Phasennamen sind Freitexte mit möglichen Anführungszeichen
und würden als nackte `wire:click`-Argumente den Ausdruck zerlegen. Die
Auflösung läuft serverseitig immer gegen die FRISCH berechneten,
team-gescopten Assigner-Zeilen; ein manipuliertes Token kann nichts sehen,
was die aktuelle Kohorte nicht enthält. Unbekannte scope-/column-Werte
lösen fail-closed zu einer leeren Menge auf, nie zur Gesamtkohorte.
**Bewusster Tradeoff:** die frische Neuberechnung ist die Grundlage des
Sicherheitsarguments — dafür löst jeder Zellklick die volle Kohorten-Query
aus (Livewire-Request-Zyklus; das Computed cached nur innerhalb eines
Requests). Beim Query-Budget-Abnahmekriterium einkalkulieren, nicht als
Überraschung im Live-Check. `drillApplicants()` scoped zusätzlich
`forTeam` — Pflicht, nicht Redundanz: `drillIds` ist eine public
Livewire-Property und clientseitig manipulierbar.

Hinweis: das heutige Dashboard filtert `is_test` **nicht** (`statsApplicantPool()`
hat keinen solchen Filter) — Test-Bewerber verfälschen dort die Kundenzahlen.
Die Statistik-Seite filtert `is_test` als einzigen stillen Filter und
dokumentiert die Abweichung.

**Zwei Ketten, zwei Zuständigkeiten:** Die **Präzedenz-Kette entscheidet den
ZEILENTYP**, die **Zuordnungsregel entscheidet die GRUPPE** (Ort → Tätigkeit),
in der die Zeile erscheint. Ein Bewerber mit Buchung, aber ohne Pivot-Zeile,
landet in seiner Schulungszeile — innerhalb der Gruppe „ohne Ausschreibung".

**Zuordnungsregel Bewerber → Gruppe — fünf Fälle:**

1. Es gibt eine Pivot-Zeile, deren Posting zur Position von `rec_phase_id`
   gehört → diese Zuordnung bestimmt die Gruppe (Phase ist der abgeleitete
   Ist-Zustand). Bei mehreren passenden Pivots: kleinste `rec_posting_id`
   (deterministisch, Ruling Task 10)
2. Pivot-Zeilen vorhanden, aber keine passt zur Phase-Position → kleinste
   `rec_posting_id` zählt, Bewerber wird als „Zuordnung uneindeutig"
   gekennzeichnet — **transportiert als `uneindeutig_ids` pro Zeile im
   Assigner-Ergebnis** (analog `hr_desk_ids`), UI zeigt Marker-Badge,
   anklickbar (Ruling Task 10: Spec schlägt den Plan-Kommentar „macht die
   UI" — die UI kann das nicht ohne Logik-Duplikation)
3. Keine Pivot-Zeile → Gruppen-Fallback „ohne Ausschreibung"
4. GESTRICHEN — als Gruppe unerreichbar: „keine Pivot-Zeile" fängt Fall 3
   bereits ab, und bei `rec_phase_id IS NULL` mit Pivot-Zeilen fällt der
   Bewerber auf Fall 2 durch (kleinste `rec_posting_id`). „Ohne Phase"
   existiert als **Unterzeile von Stufe 8** der Präzedenz-Kette, nicht als
   Gruppe.
5. Dangling `rec_phase_id` → kann nicht auftreten (FK
   `constrained('rec_phases')->nullOnDelete()`, Migration `2026_04_12_000002`);
   kollabiert DB-garantiert zu Phase-NULL → Fall 2 bzw. 3. „Nie gesetzt" vs.
   „genullt" ist erst ab Einführung des Transition-Logs unterscheidbar.

**Alle Gruppen-Fallbacks an einer Stelle:** „ohne Ausschreibung" (Fall 3) und
„ohne Ort" (Position ohne gepflegtes `location`) — sie sind Gruppen, keine
Zeilentypen, und stehen in der Tabelle als ehrliche Gruppen-Header (kein
stilles Wegfiltern).

Bei aktivem Ausschreibungs-Filter zählt die gefilterte Zuordnung. Hinten bewusst
erweiterbar: Dispo-Spalten („gearbeitete Termine") kommen später als zusätzliche
Spalten, ohne Umbau.

## 5. Datenbasis: Phasen-Transition-Log

Heute existiert keine strukturierte Phasen-Historie (nur Freitext in
`rec_auto_pilot_logs.summary`; allein `phase_returned` hat IDs in `details`).
Verweildauer- und Staustellen-KPIs brauchen sie.

**Neue Tabelle `rec_phase_transitions`:**

- `team_id`, `rec_applicant_id`, `rec_position_id`
- `from_phase_id` / `to_phase_id` (nullable FK) **plus** `from_phase_name` /
  `to_phase_name` als Text-Snapshot — Phasen werden pro Stelle geklont,
  umbenannt und gelöscht; die Auswertung darf davon nicht abhängen
- **FK-Löschverhalten (verbindlich):** `from_phase_id`, `to_phase_id` und
  `rec_position_id` sind alle **`nullOnDelete`** — bei `cascadeOnDelete` würde
  eine Phasen- oder Stellenlöschung die Transition-Zeilen selbst löschen, die
  Historie verschwände genau in dem Moment, für den der Text-Snapshot gebaut
  wurde. `rec_applicant_id` bleibt `cascadeOnDelete` (die Historie eines
  gelöschten Bewerbers ist wertlos; Team-Löschung räumt so konsistent ab).
  Bewusste Konsequenz: die `phase_deleted`-Transition wird geschrieben,
  WÄHREND die Phase gelöscht wird — ihr `from_phase_id` wird von derselben
  Kaskade unmittelbar danach genullt. Das ist in Ordnung (der Name-Snapshot
  bleibt), aber Tests dürfen für diesen Fall **nicht** die ID erwarten.
- `trigger`: `auto_advance` | `manual` | `returned` | `position_switch` |
  `fix` (FixApplicantPhase — Korrektur, kein Phasenwechsel: wird aus ALLEN
  Verweildauer-Medianen ausgeschlossen) | `phase_deleted` (RecPhase-Löschung,
  s. u.) | `unknown` (Default, wenn der Observer den Auslöser nicht kennt)
- `source`: `live` | `backfill`
- `source_log_id` (nullable FK auf `rec_auto_pilot_logs`, **UNIQUE**) — der
  Idempotenz-Schlüssel des Backfills
- `occurred_at`; Indizes `(team_id, occurred_at)`, `(rec_applicant_id, occurred_at)`

**Schreibmechanik: Observer auf `created` UND `updated`.** `created` ist
Pflicht: die Initial-Phase wird bei der Anlage gesetzt
(`IncomingApplicationService`) — das ist Spalte 1 des Funnels; ein reiner
updated-Observer verpasst sie. Der Observer schreibt bei gesetztem/geändertem
`rec_phase_id` die Transition (from = alter Wert bzw. NULL bei created).
`rec_phase_id` wird an elf Stellen geschrieben — Einzel-Hooks wären lückenhaft;
der Observer deckt **alle Eloquent-Pfade** ab. Das ist NICHT dasselbe wie „alle
Pfade" — es gibt genau **zwei bekannte Ausnahmen**:

1. **`FixApplicantPhase`** schreibt per Query-Builder
   (`DB::table(...)->update()`, keine Model-Events) → der Command bekommt einen
   expliziten Transition-Insert mit `trigger='fix'`.
2. **Phasen-Wegfall auf DB-Ebene**: der FK ist `nullOnDelete` — die Nullung
   passiert ohne Eloquent-Event, der RecApplicant-Observer sieht sie nie; das
   Log zeigt den Bewerber sonst für immer in Phase X, offene Intervalle werden
   unsichtbar beliebig groß. **Prinzip: Model-Events feuern nicht bei
   DB-Kaskaden. Jede Kaskade, die auf `rec_phase_id` durchschlägt, braucht
   einen eigenen Observer an ihrem AUSGANGSPUNKT.** Konkret zwei Observer:
   - **`RecPhase::deleting`** — fängt nur direkt über Eloquent gelöschte
     Einzel-Phasen; schreibt die Transition (`to = NULL`,
     `trigger='phase_deleted'`) für alle betroffenen Bewerber, bevor die DB
     nullt.
   - **`RecPosition::deleting`** — Pflicht, denn die Kaskade
     `rec_phases.rec_position_id → cascadeOnDelete` läuft auf DB-Ebene: MySQL
     entfernt die Phasen-Zeilen, es gibt keine RecPhase-Instanz und kein
     deleting-Event. Der Position-Observer schreibt die Transitions für alle
     Bewerber aller Phasen dieser Stelle, bevor irgendetwas kaskadiert.
   - Dritter Pfad geprüft — **Team-Löschung**: `rec_applicants.team_id` ist
     ebenfalls `cascadeOnDelete`; Bewerber und (via `rec_applicant_id`-FK)
     ihre Transitions verschwinden mit dem Team konsistent. Kein Orphan, kein
     Observer nötig.
   - Defensive Leseregel zusätzlich: „Intervall endet ohne Nachfolger und
     Phase existiert nicht mehr" → Intervall verwerfen statt hochrechnen.

Defensiv: try/catch um jeden Insert; ein Log-Fehler wird geloggt, bricht aber
nie den Phasenwechsel ab.

**Backfill-Command** `recruiting:backfill-phase-transitions --team= [--dry-run]`:

- Parst `phase_advanced`-Summaries. Drei Formate: `Phase "X" abgeschlossen —
  weiter zu "Y".` (from+to), `Manuell weiter zu Phase "Y".` (**nur to**),
  `phase_returned` liefert IDs strukturiert in `details`
- **from wird NICHT abgeleitet und NICHT geschrieben.** Bei „Manuell weiter"
  steht `from_phase_id/name = NULL`. Die Ableitung passiert beim LESEN aus dem
  Vorgänger-Eintrag; weichen geschriebener und abgeleiteter Wert ab, ist das
  eine erkennbare Lücke und das Intervall fliegt aus dem Median. Abgeleitete
  Werte in der Log-Tabelle würden Lücken hinterher unauffindbar machen.
- Namens-Match nur gegen die Phasen der Stellen des jeweiligen Bewerbers
  (Join über Pivot), nicht gegen alle `rec_phases`
- **Nicht-Treffer werden nicht weggeworfen**: geparster Name ohne ID-Match
  landet als `to_phase_name` mit `to_phase_id = NULL`
- Idempotent über `source_log_id` UNIQUE (kein Duplikat bei Mehrfachlauf)
- **Live-Cutoff (Ruling Final-Review):** `source_log_id` dedupliziert nur
  backfill-gegen-backfill — Live-Transitions haben dort NULL. Der Command
  überspringt deshalb Logs mit `created_at >= min(occurred_at der
  live-Transitions des Teams)` (Fallback `now()`; eigener Zähler
  `skipped_live_window`). Warum das dicht ist: Live-Transition und Log
  entstehen synchron im selben Request, die Transition VOR dem Log — jedes
  Ereignis mit Live-Zeile hat also `log.created_at > cutoff` und wird
  übersprungen; Ereignisse aus dem Migrate-Fenster (Observer-Insert schlug
  fehl, Tabelle fehlte noch) haben keine Live-Zeile, liegen unter dem Cutoff
  und werden normal nachgezogen. Tolerierte Grenzen (dokumentiert): der
  Cutoff ist global pro Team — ein partieller Observer-Ausfall NACH dem
  Cutoff erzeugt Under-Counting (nie Doppelzählung); und
  `applicantPhaseIdsByName()` toleriert Namenskollisionen über mehrere
  beworbene Stellen (Phasen sind pro Stelle geklont, das Log gibt die
  Position nicht her — Auswertung keyt ohnehin auf order/name)
- FK-Sicherheit: `phase_returned`-Detail-IDs werden nur übernommen, wenn sie
  noch auflösbar sind (gelöschte Phase → NULL, Name-Snapshot bleibt)

**Stellenübergreifender Funnel-Schlüssel ist `order`** (folgt der
Code-Präzedenz `PhaseMatcher::sameOrderOrFirst`); bei Namens-Divergenz je order
zusätzlich `name` mit „Sonstige"-Zeile. Achtung: `unique(rec_position_id, order)`
garantiert Eindeutigkeit, **nicht Lückenlosigkeit** — nie 1..MAX annehmen.

Zeitbasierte Phasen-KPIs zeigen einen Hinweis „Datenbasis ab «ältester
Transition»", solange der Backfill Lücken hat.

## 6. Analyse-Sektionen

Je Sektion eine pure Rechen-Klasse unter `src/Services/Statistics/`, alle lazy
geladen:

1. **Phasen-Funnel & Abbruch** — Erreichungsquote pro Phase (Balken-Funnel,
   Schlüssel `order`), Abbruchverteilung (letzte Phase der Geparkten/
   Abgesagten), Verweildauer-Median pro Phase (aus Transitions; `trigger='fix'`
   ausgeschlossen)
2. **Quellen-Analyse** — pro Quellplattform: Bewerbungen, davon Dubletten,
   gebucht, unterschrieben, Conversion; Attributions-Split über `matched_via`
3. **Zeitverlauf** — Liniendiagramm Bewerbungseingänge pro Woche (ApexCharts,
   **ISO-Woche mit Montag-Start**), umschaltbar gestapelt nach Quelle oder
   Tätigkeit. **Fußnote im UI:** Daten vor dem 2026-05-01 sind unzuverlässig —
   bis dahin konnte das Enrichment-LLM `applied_at` überschreiben (Schutz:
   Commit 837e13c am 2026-04-30, final aktiv mit 60231d4 am 2026-05-01)
4. **Termin-Effizienz** — No-Show-Quote, Storno-Quote nach Urheber
   (`cancelled_by`: Bewerber/HR/System), Termin-Auslastung (seatTaking vs.
   `max_participants`, kann > 100 % sein), Standby-Quote, Warteliste
   (eingetragen → erfüllt), **`seat_reclaimed_override`-Zähler** (bewusst an
   der Kapazität vorbei konsumierte Plätze)
5. **Time-to-X** — Mediane aus der Kohorte: Eingang→Buchung, Eingang→Schulung,
   Schulung→Unterschrift, Eingang→Unterschrift. **Time-to-Hire zieht NICHT vom
   Dashboard mit um** — die Dashboard-Version rechnet ohne `is_active`-Filter
   (zählt Geparkte und HR-Desk mit) und damit auf einer anderen Population als
   der Stats-Pool; hier wird aus der Kohorten-Grundmenge neu abgeleitet.
   **Time-to-Booking nutzt die ERSTE Buchung**, nicht die neueste.
6. **Auto-Pilot** — Durchlaufquote ohne manuellen Eingriff, Reminder-
   Wirksamkeit (Bestätigung nach 1./2./3. Reminder), „nie erreicht"-Quote
7. **WhatsApp-Kosten** — `WhatsAppCostReportService`, Signatur kann nur
   `(teamId, from, to, typeFilter)`: Gesamtzahlen, reagiert nur auf den
   Zeitraum-Filter (kein Bewerber-Bezug im Join)

**UI-Fußnoten (verbindlich):**

- **Right-Censoring** *(gehört zu TEIL 1 — Spalte der Kohorten-Tabelle, die
  Ablage unter §6 ist eine Spec-Eigenheit, kein Scope-Signal)*: jede
  Kohorten-Zeile bekommt eine Spalte „noch offen: N"; Conversion-% wird
  ausgegraut, solange die Kohorte jünger ist als der Median-Durchlauf (sonst
  sieht eine junge Schulung wie eine schlechte aus). Definitionen (Ruling
  Task 13, Anker-Korrektur Review-Runde 2): „offen" = Zeilen-IDs ohne
  Unterschrift und ohne No-Show — **nur für laufende Zeilentypen** (Schulung,
  noch ohne Schulung); ausgeschlossene Buckets (Dublette, Import, Unrouted,
  ohne Datum, geparkt, abgesagt, unbekannter Status) zeigen „–", sie sind
  keine laufenden Kohorten. Kohorten-Alter = Tage seit der **jüngsten**
  Bewerbung der Zeile (max applied_at — konservativ: eine einzelne alte
  Bewerbung darf eine überwiegend frische Zeile nicht „reif" färben;
  falsch-grau ist harmlos und vom Tooltip erklärt, falsch-farbig ist die
  Kundenmail). Schwelle = Time-to-Hire-Median der aktuellen Gesamtsicht,
  strikt „jünger als"; ohne Median (keine Unterschriften) bleibt Conversion
  grau (fail-safe)
- **Definitions-Tooltip pro Spalte** *(ebenfalls TEIL 1)* — speziell
  „Kontaktiert": das ist ein Anreicherungs-Proxy (`enrichment_status`), kein
  Kontaktnachweis
- Terminart: `interview_type_id` ist **nullable** → „ohne Terminart"-Zeile;
  Gruppierung über `code` (stabil), `name` nur Anzeige
- Vorzeitraum-Regel bei laufenden Presets („letzte 30 Tage" vergleicht gegen
  die 30 Tage davor, nicht gegen den Kalendermonat) — einmal definieren, in
  allen Δ-Anzeigen identisch

## 7. Tests

Modul-Konvention: reines PHPUnit ohne Laravel/DB
(`meingedeck/vendor/bin/phpunit -c phpunit.xml`).

- **Rekonziliations-Invariante** (wichtigster Test, lebt in `CohortAssigner`):
  für beliebige Eingabemengen gilt Gesamt = Summe aller Zeilen, jeder Bewerber
  genau einmal — auch bei Umbuchung, Mehrfach-Ausschreibung, Dublette,
  fehlendem Ort, unbekanntem Status, HR-Desk-mit-Buchung
- Präzedenz-Kette: jede Stufe gegen jede Kombination der Flags
- Funnel-Mathe (Rang-Kumulation, no_show=Rang 2, Quoten, Δ Vorzeitraum,
  Right-Censoring-Schwelle)
- Backfill-Parser (drei Summary-Formate, from=NULL bei „Manuell weiter",
  Nicht-Treffer als name-only)
- Verweildauer aus Transition-Sequenzen (inkl. Rückstufung, `fix`
  ausgeschlossen, verworfene Intervalle bei `phase_deleted` ohne Nachfolger)
- **Grep-Invariante als Test**: kein `rec_phase_id` in einem `->update([...])`
  außerhalb von `FixApplicantPhase` (nagelt fest, dass der Observer-Ansatz
  vollständig bleibt)
- Livewire-Komponente bleibt dünn; Query-Budget pro Render als Abnahmekriterium

## 8. Deploy & Zugriff

- Route in `ModuleRouter::group('recruiting')` → Middleware-Stack
  `['web', 'detect.module.guard', 'auth:{guard}', 'check.module.permission']`
- **Deploy: EIN Push ist ok** — die Seite ist nicht öffentlich verlinkt und
  `php artisan migrate` läuft im Forge-Deploy-Script; das Zwei-Push-Ritual
  schützt öffentliche Dauerverkehrs-Seiten und ist hier nicht nötig.
  Bekanntes, akzeptiertes Fenster: zwischen Symlink-Switch und `migrate`
  schreibt der Observer auf eine noch nicht existierende Tabelle — der
  try/catch fängt das, es fehlen lediglich die ersten Transitions.
  Nach dem Deploy: **`queue:restart` UNMITTELBAR nach dem Symlink-Switch**,
  nicht irgendwann davor oder danach. Begründung (Ausführungs-Befund):
  Auto-Pilot-Advances laufen im Scheduler (Cron = frischer Prozess pro Tick,
  zieht neuen Code von selbst) — aber `MatchApplicantToPostingJob` ist
  queued und setzt via `assignPosting()` die Initial-Phase. Alte Worker
  schreiben dafür keine Live-Transition UND es existiert kein
  phase_advanced-Log — diese Initial-Transitions sind nicht backfillbar,
  das Fenster schrumpft nur über die Ops-Reihenfolge auf Sekunden. Danach
  Backfill (`--dry-run` zuerst). composer.lock-Bump in meingedeck beim Push.

## 9. Nicht im Scope (bewusst)

**Getrennte Aufträge aus den Code-Review-Runden** (eigene Tickets):

- ① **Standby-Lücke bei `registered`/`confirmed`-Buchungen aufgegebener
  Bewerber** — Ursache `CreateInterviewBookingTool.php:117`: Status-Argument
  ungeprüft, Default `'registered'` umgeht das Standby-Sicherheitsnetz (das nur
  `'booked'` freigibt). Der Pfad `RecApplicant:666` ist dagegen bewusst und
  korrekt (`guardSeatReclaim` sichert die Kapazität).
- ② **Status-Ordnung als Konstante** + Umstellung der **vier**
  Interpretationsstellen: `RecInterviewWaitlistObserver:105`,
  `ReminderResponseHandler:59`, `RecApplicant:918`
  (`renderPublicFormCompletionExtras` behandelt registered|confirmed|attended
  als gleichwertig) und Dashboard `bumpStatRow` (zählt strikt `confirmed`) —
  plus die zwei `$validStatuses`-Duplikate (`InterviewBookings/Index:312`,
  `UpdateInterviewBookingTool:74`).
- ③ **`confirm_booking_on_completion` schreibt `'registered'` statt
  `'confirmed'`** — Flag-Name und geschriebener Status widersprechen sich;
  `registered` bedeutet zwei verschiedene Dinge.
- ④ **`applied_at`-Schreibbarkeit inkonsistent** — `BulkUpdateApplicantsTool`
  erlaubt Updates (Whitelist :162), `UpdateApplicantTool` blockt bewusst
  (:52-56); vereinheitlichen.

Weitere bewusste Ausschlüsse:

- **Warteliste/Standby-Verhalten** („Nachrücken bei TN-Erhöhung") — separater
  Prüfauftrag, kein Dashboard-Thema
- **Dispo-Spalten** (gearbeitete Termine) — Datenbasis existiert noch nicht;
  Layout ist vorbereitet
- **Frühfluktuation** — kein Kündigungsgrund/-datum modelliert; Phase 2
- **Anzeigenkosten pro Kanal** — nirgends modelliert; Phase 2
- **Vertrags-Ablehnungsstatus** — existiert nicht; Phase 2
- **Neue Timestamps an Buchungen** (`confirmed_at`, `attended_at`) — Quoten
  gehen ohne; nachrüstbar
- **Filiale/Region als Datenmodell-Dimension** — existiert nicht (siehe
  Abschnitt 1, Erwartungsabgleich)
- **Sichtbarkeit** — kein Menü-Eintrag in V1; Freischaltung ist ein bewusster
  späterer Schritt
