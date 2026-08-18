# Die Stelle bekommt ein eigenes Feld an der Bewerbung

**Datum:** 2026-08-18
**Status:** entworfen, freigegeben durch den User (vier Entscheidungen unten)

## 1. Das Problem

Ein Bewerber gibt in Phase 1 mehrere Beschäftigungsorte an, sieht die Schulungstermine
aller dieser Orte und legt sich durch die Buchung auf einen fest. Genau so ist es
gewollt, und genau so funktioniert es heute auch — `resolvePositionIdsForApplicant`
(`src/Livewire/Public/InterviewBooking.php:217`) zeigt vor der Festlegung die Termine
aller Wunschorte und danach nur noch die der gewählten Stelle.

Kaputt ist die Auswertung. Bei der Festlegung ruft die Buchungsseite
`switchToPosition()` (`src/Models/RecApplicant.php:1876`), und das tut drei Dinge, die
die KPI-Zahlen verfälschen:

```php
$this->postings()->detach();                                               // :1882
$newPosting = $newPosition->postings()->where('is_active', true)->first();  // :1885
$this->postings()->attach($newPosting->id, ['applied_at' => now()->toDateString()]);
```

Die Verknüpfung zur Anzeige, über die sich die Person **tatsächlich beworben hat**,
wird gelöscht. Die neue wird per `first()` **ohne Sortierung** gewählt, also nimmt sie,
was die Datenbank zufällig zuerst liefert. Und `applied_at` wird auf heute gesetzt —
das Feld, nach dem `primaryPosition()` sortiert.

Wirkung auf die Statistik-Seite: Düsseldorf verliert rückwirkend eine Bewerbung, die es
bekommen hat. Eine Mönchengladbacher Anzeige gewinnt eine, die sie nie erhalten hat.
Die Spalte heißt „Bewerbungen", beantwortet aber „wer wird gerade unter dieser Anzeige
bearbeitet", und der Faktor (Bewerbungen pro Einstellung) rechnet auf einer Menge, die
zum Teil nie dort beworben war. Gemessen: 15 Wechsel, davon 11 in sechs Tagen.

### Die Ursache ist ein Zwang, kein Schlendrian

`rec_applicants` hat ein `rec_phase_id`, aber **kein `rec_position_id`**. Die Stelle hat
kein eigenes Feld — sie wird aus dem Pivot abgeleitet (`primaryPosition()`, `:1859`:
früheste Anzeige → deren Stelle). Zehn Stellen im Code lesen sie zusätzlich direkt über
`postings->first()`.

Damit muss der Wechsel den Pivot umschreiben, damit „nur noch die gewählte Stelle
buchbar" gilt — und weil eine Stelle mehrere Anzeigen hat, muss er eine davon erfinden.
Solange die Stelle kein eigenes Feld hat, gibt eines der beiden Ziele zwangsläufig nach.

## 2. Ziele

1. Ein Bewerber mit mehreren Beschäftigungsorten sieht in Phase 1 die Termine dieser
   Orte. **Bleibt wie heute.**
2. Nach der Festlegung kann er nur innerhalb des gewählten Schulungsortes umbuchen.
   **Bleibt wie heute.**
3. Die KPI-Zahlen der Statistik-Seite sind richtig: keine Bewerbung wandert zwischen
   Ausschreibungs-Zeilen, keine Anzeige bekommt eine Bewerbung, die sie nicht erhalten
   hat. **Das ist die Änderung.**
4. Die bestehende Wartelisten- und Termin-Abo-Logik funktioniert unverändert weiter und
   wird durch nichts fälschlich ausgelöst. **Abnahmekriterium, siehe §7.**

### Nicht-Ziele

- Keine Änderung an den Wunschorten (`beschaftigungsort`, mehrwertiges Extra-Feld).
- Keine Änderung an den Gates der Festlegung (`switch_position_on_booking` an der Phase,
  Phase-`order` ≤ 2, Lookup-Werte auf beiden Stellen).
- Keine Umbenennung von `primaryPosition()`. Der Name klingt nach „erste von mehreren"
  und meint künftig „die Stelle"; die Umbenennung ist ein separater, mechanischer
  Durchgang, wenn das Verhalten steht.
- Keine Tätigkeit-als-Lookup, kein Umbau der Statistik-Tabellen.

## 3. Die Entscheidungen des Users

| Frage | Entscheidung |
|---|---|
| Wo zählt die Unterschrift, wenn jemand über Düsseldorf kommt und in MGL einsteigt? | **Bei der Herkunfts-Anzeige.** Eine Personengruppe pro Zeile, Conversion und Faktor nachrechenbar. Die Einstellung in einer anderen Filiale ist eine benannte Differenz. |
| Zieht eine HR-Korrektur der Anzeige die Stelle mit? | **Nur solange nicht festgelegt.** Ab aktiver Buchung oder Phase ≥ 3 bleibt die Stelle stehen. |
| Was passiert mit den ~15 Altfällen? | **Als Wechsel markieren** (`matched_via = 'position_switch'`), nicht rekonstruieren. Die Statistik nennt sie unter „Herkunft unbekannt". |
| Sofort-Fix trotzdem zuerst? | **Ja**, als eigener kleiner Merge vor dem Paket. |

Architektur-Variante: **`primaryPosition()` bleibt die einzige Fassade** (statt zehn
Stellen, die das Feld direkt lesen, und statt eines neuen `ApplicantPlacement`-Objekts).

## 4. Datenmodell

Neu: `rec_applicants.rec_position_id`, `nullable`, Fremdschlüssel auf `rec_positions`
mit **`nullOnDelete`**. Nicht `cascadeOnDelete`: eine gelöschte Stelle darf keine
Bewerbung mitnehmen. (Dieses Modul hat gelernt, dass Model-Events bei DB-Kaskaden nicht
feuern — daher der Kaskaden-Observer für Phasen.)

Dazu am Model:

- `position()` — `belongsTo` für Eager Loading. **Nicht** verwechseln mit dem
  vorhandenen `positions()` (`:280`), das eine abgeleitete Sammlung über den Pivot
  liefert und bleibt, wie es ist.
- `istFestgelegt(): bool` — kapselt die heute an drei Stellen abgeleitete Regel:
  aktive (nicht storniert) Buchung **oder** Phase-`order` ≥ 3. Vorbild:
  `InterviewBooking.php:220-224`.

Danach bedeutet jede Datenstelle genau eine Sache:

| Frage | Ort | Beweglich |
|---|---|---|
| Welche Orte will die Person? | `beschaftigungsort` (Extra-Feld, mehrwertig) | ja — steuert die sichtbaren Termine |
| Wo wird sie bearbeitet? | **neu:** `rec_applicants.rec_position_id` | ja — Festlegung durch Buchung |
| Woher kam die Bewerbung? | Pivot `rec_applicant_posting` | **nein** |

## 5. Schreibwege

### 5.1 Bei der Anlage

Fünf Wege erzeugen Bewerbungen. Jeder setzt die Stelle aus der Anzeige, die er
verknüpft:

| Weg | Datei |
|---|---|
| Inbound (Mail, WhatsApp, öffentliches Formular) | `src/Services/IncomingApplicationService.php:114` |
| CSV-Import | `src/Services/ImportApplicantsCsvService.php:232` |
| Manuelle Anlage in der Bewerberliste | `src/Livewire/Applicant/Index.php:265` |
| MCP: einzelne Anlage | `src/Tools/CreateApplicantTool.php:113` |
| MCP: Massenanlage | `src/Tools/BulkCreateApplicantsTool.php:141` |

Wo keine Anzeige zugeordnet werden kann (Import ohne Bindung, Inbound ohne Match),
bleibt das Feld `null`. **Kein Raten, kein Default** — dieselbe Regel wie bei Bedarf und
Faktor: leer heißt nicht gepflegt. Bewerbungen ohne Stelle werden sichtbar benannt
(§8).

### 5.2 Bei der Festlegung

`switchToPosition()` schrumpft auf:

1. `rec_position_id` auf die neue Stelle setzen,
2. Phase auf dieselbe `order` der neuen Stelle mappen (unverändert),
3. Extra-Feld-Werte auf das neue Definitionen-Set umhängen (unverändert),
4. Log schreiben — **erweitert** um die alte Stelle, damit der Fall nachvollziehbar
   bleibt (heute steht nur der neue Titel im Log).

`detach()` und `attach()` fallen weg. Die Gates am Aufrufer
(`InterviewBooking.php:494-530`) bleiben unverändert.

### 5.3 Bei einer HR-Korrektur der Anzeige

`reconcilePositionState()` (`RecApplicant.php:1950`) zieht heute Phase und
Verantwortlichen auf die Stelle der primären Anzeige, wenn sich der Pivot geändert hat
(Enrichment-Umschlüsselung, manuelles Verknüpfen, HR-Zuweisung). Künftig setzt es
zusätzlich `rec_position_id` — **aber nur, wenn `istFestgelegt()` false ist.** Ist die
Person schon festgelegt, bleibt ihre Stelle stehen: eine Korrektur der Anzeige darf
niemanden aus der Filiale ziehen, in der er zur Schulung angemeldet ist.

Dasselbe gilt für das Heil-Command `ReconcileApplicantPositions` (`:72`).

## 6. Lesewege

`primaryPosition()` liest `rec_position_id`. Solange das Feld `null` ist, fällt es auf
den heutigen Pivot-Weg zurück — die Brücke zwischen Deploy und Backfill; bis dahin
verhält sich die Methode für Bestandsdaten exakt wie heute.

Der Fallback bleibt danach **dauerhaft** stehen, statt in einem Folgeschritt entfernt zu
werden. Grund: er ist nicht nur Übergangs-Brücke, sondern deckt die legitimen Fälle ab,
in denen das Feld leer ist — eine Bewerbung, die nach dem Backfill über einen Weg
entsteht, an dem das Setzen vergessen wurde. Ihn zu entfernen würde aus einem
vergessenen Schreibweg einen stillen Datenfehler machen; ihn zu behalten macht daraus
höchstens einen veralteten Wert. Was dagegen NICHT bleibt: `postings->first()` als
Lesweg in den zehn Aufrufern (§6) — die rufen ausschließlich die Fassade.

Die zehn Stellen, die die Stelle heute selbst aus dem Pivot raten, rufen künftig die
Fassade:

| Datei:Zeile | Wofür |
|---|---|
| `Public/InterviewBooking.php:219` | welche Termine sichtbar sind |
| `Public/InterviewBooking.php:145`, `:373`, `:464` | Fallback-Ort der Warteliste (§7) |
| `Public/InterviewBooking.php:242` | Cut-Over-Schutz gegen Legacy-Stellen („% bis %“) |
| `DirectHire/Index.php:81`, `:95`, `:272` | Gruppierung und Anzeige der Direkteinstellung |
| `Console/Commands/FixApplicantPhase.php:75` | Phasen-Reparatur |
| `Console/Commands/SyncPhases.php:68` | Phasen-Abgleich |

Die acht bestehenden `primaryPosition()`-Nutzer bleiben unverändert — darunter die
**MA-Anlage** (`CreateEmployeeFromApplicantService.php:62` setzt damit die
`rec_position_id` des Mitarbeiters) und das HR-Desk-Routing
(`HrDeskRoutingService.php:247`, nur in `approveCase()`, ausgelöst über die
synchrone Livewire-Aktion in `HrDesk/Index.php`, NICHT über den Buchungs-Observer
— der ruft `approveCase()` nie). Sie merken nur, dass der Wert exakt ist statt
abgeleitet.

## 7. Warteliste und Termin-Abo (Abnahmekriterium)

**Die Warteliste ist von der Stelle entkoppelt, und das bleibt so.** Belegt am Code:

- Ein Eintrag (`rec_interview_waitlist`) speichert seine Wunschorte als eigenen
  Schnappschuss (`wunschorte`, JSON-Array) plus optional `rec_interview_id` für das
  Termin-Abo. Er referenziert weder Stelle noch Pivot.
- Der Trigger-Pfad `NotifyWaitlistForInterview.php:100-121` liest den Ort des **frei
  gewordenen Termins** (`$interview->position?->beschaftigungsort_lookup_value`) und
  vergleicht ihn per `whereJsonContains('wunschorte', $ort)` gegen den Schnappschuss.
  Die Stelle des Bewerbers wird dort nicht gelesen.
- Die Skip-Logik „Termin-Abo gewinnt gegen Ort-Abo" arbeitet ausschließlich auf
  Einträgen.

Ein Stellenwechsel kann dort also heute nichts auslösen und nach dem Umbau genauso
wenig. Berührt wird nur **ein Eingang**: der Fallback-Ort beim Eintragen, wenn keine
Wunschorte gepflegt sind (`WaitlistEnrollmentPlanner::resolveWunschorte($extraField,
$fallbackOrt)`, aufgerufen an `InterviewBooking.php:145`, `:373`, `:464`). Er liest
künftig die Stelle aus dem Feld statt aus `postings->first()` — derselbe Wert, andere
Quelle.

Pflicht-Regressionstests:

1. Eintragen ohne Wunschorte nach einer Festlegung ergibt denselben Ort wie heute.
2. Ein Stellenwechsel verändert **keinen** bestehenden Eintrag (kein `wunschorte`, kein
   `armed`, kein `notified_at`).
3. Ein frei werdender Platz benachrichtigt genau dieselbe Menge wie heute; die
   Skip-Logik bleibt wirksam.
4. Re-Arm verhält sich unverändert (`WaitlistRearmService`).

## 8. Statistik-Seite

Die Ausschreibungs-Zeile zählt ab dann Bewerbungen nach ihrer **Herkunft**. Das ist
keine Änderung an der Berechnung, sondern die Folge davon, dass der Pivot nicht mehr
umgeschrieben wird — `CohortAssigner` und `CohortViewModel` bleiben unangetastet.

Zwei Ergänzungen an der Seite:

1. **Herkunft unbekannt:** Zeilen, deren Verknüpfung `matched_via = 'position_switch'`
   trägt (die ~15 Altfälle, siehe §9), zählen in keiner Ausschreibungs-Zeile mit,
   sondern werden in einem eigenen Block benannt. Kein stiller Topf.
2. **In einer anderen Filiale eingestellt:** Wer über Anzeige A kam und an Stelle B
   unterschrieben hat, zählt seine Unterschrift bei A (Entscheidung §3) — der Bedarf von
   B wird davon nicht bedient. Diese Differenz wird beziffert und benannt. Größenordnung
   heute: ~15 auf ~1542 Bewerbungen, also rund 1 %.

Der Rekonziliations-Test der Seite (`StatisticsPageReconciliationTest`) wird um die
neuen Fälle erweitert: jede Bewerbung genau einmal, auch mit gewanderten und mit
stellenlosen Bewerbungen im Bestand.

## 9. Migration und Backfill

Der Backfill ist **exakt**, weil der Pivot heute *ist*, was das Feld sein wird:

```
rec_position_id := primaryPosition()   -- also die Stelle der frühesten Anzeige
```

Für die ~15 bereits gewechselten Bewerbungen ist die ursprüngliche Anzeige gelöscht und
nicht rekonstruierbar (der Log führt nur den neuen Stellentitel). Ihre vorhandene
Verknüpfung bleibt — HR arbeitet damit weiter — wird aber mit
`matched_via = 'position_switch'` markiert, damit die Statistik sie nicht als Bewerbung
dieser Anzeige zählt. Erkennbar sind die Fälle über
`rec_phase_transitions` mit `trigger = position_switch`; die alte **Stelle** ließe sich
daraus über `from_phase_id` herleiten, wird aber bewusst nicht zurückgeschrieben: die
Person ist absichtlich in der neuen Filiale angemeldet.

Kommando statt Migration für den Backfill (Konvention des Moduls, vgl.
`recruiting:backfill-employee-fields`): idempotent, füllt nur leere Spalten, mit
`--dry-run`.

## 10. Kanten, die ausdrücklich festgelegt sind

- **Stornieren öffnet die Wahl wieder.** Nach `cancelAndRebook` gilt die Person nicht
  mehr als festgelegt und sieht wieder alle Wunschorte — richtig so, sie wählt neu. Ihre
  Stelle bleibt bis zur nächsten Buchung auf der zuletzt gewählten stehen und zieht dann
  mit. Das ist gewollt und darf nicht „aufgeräumt" werden.
- **Bewerbung ohne Stelle** (`null`): möglich bei Import ohne Bindung und Inbound ohne
  Match. Sie wird in der Statistik benannt (§8) und blockiert nichts; `primaryPosition()`
  gibt `null` zurück, wie heute bei einer Bewerbung ohne Anzeige.
- **Stelle gelöscht:** `nullOnDelete` → das Feld wird leer, die Bewerbung bleibt.
- **Mehrere Anzeigen am Bewerber** (Matcher hat zwei zugeordnet): unverändert. Die
  Herkunft ist die früheste Verknüpfung, der Marker „Zuordnung unklar" der Statistik
  bleibt für diesen Fall zuständig.

## 11. Tests

- **Model:** `istFestgelegt()` in allen vier Kombinationen (aktive Buchung ja/nein ×
  Phase ≥ 3 ja/nein); `primaryPosition()` liest das Feld, fällt ohne Feld auf den Pivot
  zurück.
- **Festlegung:** `switchToPosition()` setzt die Stelle, mappt die Phase — und lässt den
  Pivot **unverändert** (Mutationsprobe: wieder `detach()` einbauen muss den Test rot
  machen).
- **HR-Korrektur:** vor der Festlegung zieht die Stelle mit, nach der Festlegung nicht.
- **Buchungsseite:** vor der Festlegung Termine aller Wunschorte, danach nur die der
  gewählten Stelle — mit dem Feld als Quelle.
- **Warteliste:** die vier Regressionstests aus §7.
- **MA-Anlage:** ein Mitarbeiter aus einer festgelegten Bewerbung bekommt die Stelle der
  Festlegung.
- **Statistik:** Herkunft bleibt nach einem Wechsel unverändert; die Partition der Seite
  bleibt vollständig und disjunkt.

Testrunner (das Modul hat kein eigenes `vendor/`):
`/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`

## 12. Reihenfolge

**Stufe 0 — Sofort-Fix, eigener Merge, vor allem anderen.** `switchToPosition()` wählt
die Anzeige nicht mehr per `first()`, sondern nimmt `rec_interviews.rec_posting_id` des
gebuchten Termins; ohne Eintrag greift ein Fallback mit stabiler Sortierung, und die
Verknüpfung wird als `matched_via = 'position_switch'` markiert. Die alte Anzeige kommt
in den Log. Das stoppt die Willkür sofort und macht die Altfälle erkennbar. Wird durch
Stufe 1 später obsolet — bewusst in Kauf genommen, weil täglich etwa zwei Wechsel
stattfinden.

**Stufe 1 — das Feld.** Migration, Model (Feld, Relation, `istFestgelegt()`), Fassade,
Backfill-Kommando, die zehn Leser, `switchToPosition` entschlacken,
`reconcilePositionState` mit dem Festlegungs-Gate, Tests.

**Stufe 2 — die Seite.** Block „Herkunft unbekannt", Bezifferung „in einer anderen
Filiale eingestellt", Erweiterung des Rekonziliations-Tests.

Deploy jeder Stufe: ff auf `main`, meingedeck-Bump, `php artisan migrate`.

**Stufe 1 braucht `queue:restart`.** `MatchApplicantToPostingJob` implementiert
`ShouldQueue` und ruft in seinem `handle()` `IncomingApplicationService::assignPosting()`,
die wiederum `RecApplicant::stelleAusAnzeigeUebernehmen()` aufruft — also genau den
neuen Schreibweg der Stufe 1. Läuft ein Worker-Prozess mit altem Code weiter, setzt er
`rec_position_id` bei asynchron gematchten Bewerbungen nicht. Ohne Neustart bliebe das
unbemerkt inkonsistent. (Korrektur eines früheren Entwurfs dieses Dokuments: NICHT
`HrDeskRoutingService`/der Buchungs-Observer — der ruft `approveCase()`, die einzige
Stelle dort, die die Fassade liest, nie; `approveCase()` läuft ausschließlich synchron
über die Livewire-Aktion in `HrDesk/Index.php`, also immer mit frisch geladenem Code.)
Stufe 0 und Stufe 2 brauchen keinen Neustart: Stufe 0 ändert nur `switchToPosition()`,
das ausschließlich von der Buchungsseite (`InterviewBooking.php:530`) gerufen wird,
Stufe 2 nur Views.

## 13. Bewusst nicht enthalten

- Umbenennung `primaryPosition()` → `stelle()`: eigener mechanischer Durchgang danach.
- Tätigkeit als Lookup (verhindert Schreibweisen-Varianten): eigenes Paket.
- `RecApplicant::postings()` ohne Team-Scope samt Schreibseite: eigenes Ticket.
- `FlynkPostingReconciler.php:45` als handgeschriebene Kopie von `scopeOpen()`: eigenes
  Ticket.
