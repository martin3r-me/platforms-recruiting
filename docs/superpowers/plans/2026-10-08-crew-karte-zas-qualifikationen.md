# Crew-Karte zeigt die ZAS-Qualifikationen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Mitarbeiterkarte der Veranstaltungsseite zeigt die aus ZAS gelieferten Taetigkeiten statt des leeren, handgepflegten Qualifikationsfelds — gedeckelt auf 10 Chips, mit Stand-Angabe, ohne zweites Fenster.

**Architecture:** `DispoEmployeeGateway::profileCards()` liest `dispo_taetigkeiten` statt `qualifications` und reicht den Zeitstempel mit; die Uebersetzung ueber die Auswahlliste faellt weg, weil dort Klarnamen stehen. `crewCard()` vereinigt ueber die Identitaetsgruppe statt „erster nicht leerer". Das Markup deckelt die Chips und klappt den Rest per Alpine **in der bestehenden Karte** auf. `qualifications()` bleibt unangetastet — es speist den Info-Versand-Filter.

**Tech Stack:** Laravel 11, Livewire 3, Blade, Alpine (bereits in der Karte), Tailwind v4 (`dvh` verfuegbar), PHPUnit 11 (handgebauter Container + Capsule, SQLite in-memory, kein testbench).

**Spec:** `docs/superpowers/specs/2026-10-08-crew-karte-zas-qualifikationen-design.md`

## Global Constraints

- **Testlauf:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (meingedeck liegt **neben** `platform`, drei Ebenen hoch). Das Modul hat kein eigenes `vendor/`.
- **Keine `--order-by=random`-Laeufe.** Begruendung steht in `phpunit.xml`.
- **Integrationstests** bauen Container und Capsule von Hand auf. Jede Tabelle, die ein Test anfasst, muss in dessen `runMigrations()` stehen — fehlt sie, wertet SQLite doppelt gequotete Bezeichner als **String-Literal** und der Test ist gruen, ohne zu pruefen.
- **`php -l` prueft keine Blades.** Fuer Blade-Aenderungen `php tools/blade-check.php <datei>` nutzen.
- **An Wortzeichen geklebte Blade-Direktiven kompilieren nicht** (`da@if`, `Uhr@endif` bleiben literal stehen, der falsche Zweig rendert lautlos). Immer Blockform, Werte vorberechnen.
- **`DispoEmployeeGateway::qualifications()` wird NICHT angefasst.** Sie speist den Filter „Qualifikation (aus der MA-Akte)" im Info-Versand. Nur `profileCards()` wird umgestellt.
- Oberflaechentexte deutsch MIT Umlauten, Code-Kommentare deutsch OHNE Umlaute.
- Keine Dateien ausserhalb von `platforms-recruiting`.

## Review Focus

1. **Ein Mitarbeiter mit 87 Taetigkeiten** (das gemessene Maximum) darf das Bottom-Sheet am Handy nicht zur Wand machen. → Deckelung, Test in Task 1.
2. **Eine Person mit RG- UND MA-Datensatz** muss die Taetigkeiten BEIDER Datensaetze sehen, nicht nur die des ersten nicht leeren. → Test in Task 2.
3. **Ein Mitarbeiter ohne jede Zuordnung** (266 aktive Neuzugaenge) darf keinen Fehler und keinen irrefuehrenden Text bekommen. → Test in Task 1 und Task 2.
4. **Der Info-Versand-Filter darf sich nicht veraendern.** `qualifications()` und `infoQualOptions()` bleiben auf dem alten Feld. → Test in Task 1.
5. **Ein Katalogname mit Sonderzeichen** (`1. FC Köln / Service`, `Barista / Kaffee` sind real) muss unveraendert durchkommen — keine Lookup-Uebersetzung mehr, die ihn schluckt. → Test in Task 1.

---

### Task 1: Gateway liefert die ZAS-Taetigkeiten

**Files:**
- Modify: `src/Services/Zas/Dispo/DispoEmployeeGateway.php` — Methode `profileCards()` (Lookup-Block ca. Zeile 126-130, Quals-Block ca. Zeile 166-170, Rueckgabe ca. Zeile 185-192)
- Test: `tests/Integration/DispoCrewKarteQualifikationenTest.php` (neu)

**Interfaces:**
- Consumes: `rec_employee_hr_data.dispo_taetigkeiten` (JSON, Klarnamen) und `dispo_taetigkeiten_synced_at`.
- Produces: `profileCards()` liefert je id zusaetzlich `qualifications_synced_at` als **rohen, sortierbaren** `?string` im Format `Y-m-d H:i:s`; `qualifications` enthaelt kuenftig die ZAS-Namen unveraendert. **Die Formatierung fuer die Anzeige passiert erst in Task 2** — ein auf `d.m. H:i` gekuerzter Wert sortiert sich als String nicht chronologisch (`09.11.` < `10.10.`), und Task 2 muss ueber die Gruppe den juengsten Stand bestimmen.
  ```php
  array<int, array{
      name:string, personnel_number:string, ratings:array<string,int>,
      qualifications:list<string>, qualifications_synced_at:?string,
      selfie_url:?string, selfie_full_url:?string
  }>
  ```

**Achtung:** `qualifications()` (die Methode darueber) bleibt **unveraendert**. Sie liest weiter `hrData->qualifications` und uebersetzt weiter ueber die Auswahlliste `qualifikation`. Wer beide anfasst, veraendert ungefragt den Info-Versand.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

Datei `tests/Integration/DispoCrewKarteQualifikationenTest.php`. Container- und Capsule-Aufbau analog `tests/Integration/ZasDispoTaetigkeitSyncTest.php`; Migrationsliste: `rec_employees` (+ zas-Marker + personnel_number), `rec_employee_hr_data` (+ linen_package + dispo_taetigkeiten) und die core-Lookup-Tabellen. **Keine ContextFile-Tabellen noetig**, solange die Testmitarbeiter kein `selfie_file_id` tragen — `profileCards()` kurzschliesst dann bei `$fileIds === []`.

```php
    private function employee(string $pnr, array $hr = []): RecEmployee
    {
        $e = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
        $row = $e->ensureHrData();
        if ($hr !== []) {
            Capsule::table('rec_employee_hr_data')->where('id', $row->id)->update($hr);
        }

        return $e->fresh();
    }

    public function test_card_shows_the_zas_taetigkeiten_not_the_old_field(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten'   => json_encode(['Servicekräfte', 'Logistiker'], JSON_UNESCAPED_UNICODE),
            'qualifications'       => json_encode(['ALTWERT'], JSON_UNESCAPED_UNICODE),
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame(['Servicekräfte', 'Logistiker'], $card['qualifications']);
        $this->assertNotContains('ALTWERT', $card['qualifications'],
            'Das handgepflegte Feld gehoert nicht mehr auf die Karte.');
    }

    public function test_names_pass_through_without_lookup_translation(): void
    {
        // Reale Katalognamen aus Lieferung #258 — Schraegstriche, Punkte,
        // Umlaute. Frueher lief das durch core_lookup_values; jetzt nicht mehr.
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten' => json_encode(['1. FC Köln / Service', 'Barista / Kaffee'], JSON_UNESCAPED_UNICODE),
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame(['1. FC Köln / Service', 'Barista / Kaffee'], $card['qualifications']);
    }

    public function test_card_carries_the_sync_timestamp(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten'           => json_encode(['Servicekräfte'], JSON_UNESCAPED_UNICODE),
            'dispo_taetigkeiten_synced_at' => '2026-10-08 14:12:00',
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame('2026-10-08 14:12:00', $card['qualifications_synced_at'],
            'Roh und sortierbar — die Anzeigeform macht erst crewCard().');
    }

    public function test_employee_without_assignment_yields_empty_list_and_null_timestamp(): void
    {
        $e = $this->employee('RG1');

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame([], $card['qualifications'],
            '266 aktive Neuzugaenge haben noch nichts — das darf nicht knallen.');
        $this->assertNull($card['qualifications_synced_at']);
    }

    public function test_eightyseven_taetigkeiten_come_through_uncapped_in_the_gateway(): void
    {
        $viele = [];
        for ($i = 1; $i <= 87; $i++) {
            $viele[] = 'Taetigkeit ' . $i;
        }
        $e = $this->employee('RG1', ['dispo_taetigkeiten' => json_encode($viele, JSON_UNESCAPED_UNICODE)]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertCount(87, $card['qualifications'],
            'Die Deckelung gehoert in die Anzeige, nicht in die Datenschicht — der Aufklapper braucht alle.');
    }

    public function test_the_info_versand_source_is_untouched(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten' => json_encode(['Servicekräfte'], JSON_UNESCAPED_UNICODE),
            'qualifications'     => json_encode(['ALTWERT'], JSON_UNESCAPED_UNICODE),
        ]);

        $data = (new DispoEmployeeGateway())->qualifications([$e->id]);

        $this->assertSame(['ALTWERT'], $data['byEmployee'][$e->id],
            'qualifications() speist den Filter im Info-Versand und bleibt auf dem alten Feld.');
    }
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoCrewKarteQualifikationenTest`
Expected: FAIL — `Undefined array key "qualifications_synced_at"` bzw. `ALTWERT` steht noch in `qualifications`.

- [ ] **Step 3: `profileCards()` umstellen**

In `src/Services/Zas/Dispo/DispoEmployeeGateway.php`, Methode `profileCards()`:

**3a** — den Lookup-Block entfernen (er wird nur noch von `qualifications()` gebraucht, dort bleibt er):

```php
        // Lookup-Map einmal laden (value => label).
        $lookupId = \Illuminate\Support\Facades\DB::table('core_lookups')->where('name', 'qualifikation')->value('id');
        $lookupMap = $lookupId
            ? \Illuminate\Support\Facades\DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('label', 'value')->all()
            : [];
```

**3b** — den Quals-Block ersetzen. Aus

```php
            $quals = $hr?->qualifications;
            if (is_string($quals)) {
                $decoded = json_decode($quals, true);
                $quals = is_array($decoded) ? $decoded : [];
            }
            $qualLabels = array_values(array_map(fn ($v) => (string) ($lookupMap[$v] ?? $v), is_array($quals) ? $quals : []));
```

wird

```php
            // Taetigkeiten aus ZAS ({Dispo5}), NICHT das handgepflegte Feld
            // 'qualifications' — jenes ist praktisch leer und speist nur noch
            // den Filter im Info-Versand (siehe qualifications() darueber).
            // Hier stehen Klarnamen, keine Lookup-Werte: die Uebersetzung ueber
            // core_lookup_values entfaellt ersatzlos.
            $quals = $hr?->dispo_taetigkeiten;
            if (is_string($quals)) {
                $decoded = json_decode($quals, true);
                $quals = is_array($decoded) ? $decoded : [];
            }
            $qualLabels = array_values(array_map('strval', is_array($quals) ? $quals : []));
```

**3c** — die Rueckgabe um den Zeitstempel erweitern:

```php
                'qualifications'   => $qualLabels,
                // Roh und sortierbar. crewCard() muss ueber die Gruppe den
                // juengsten Stand bestimmen; 'd.m. H:i' sortiert sich als
                // String falsch (09.11. < 10.10.).
                'qualifications_synced_at' => $hr?->dispo_taetigkeiten_synced_at?->format('Y-m-d H:i:s'),
```

**3d** — den Docblock der Methode nachziehen: er nennt heute „Qualifikations-LABELS (Lookup 'qualifikation')". Richtig ist jetzt „Taetigkeiten aus ZAS (`dispo_taetigkeiten`, Klarnamen) plus deren Stand". Auch die Rueckgabe-Annotation `@return` um `qualifications_synced_at:?string` ergaenzen, mit dem Hinweis, dass der Wert roh ist.

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoCrewKarteQualifikationenTest`
Expected: PASS, 6 Tests.

- [ ] **Step 5: Mutationsprobe — faengt der Abgrenzungstest wirklich?**

`qualifications()` voruebergehend ebenfalls auf `dispo_taetigkeiten` umstellen, Test laufen lassen.
Expected: FAIL in `test_the_info_versand_source_is_untouched`. Danach zuruecksetzen und erneut Gruen pruefen.

- [ ] **Step 6: Gesamte Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Zas/Dispo/DispoEmployeeGateway.php tests/Integration/DispoCrewKarteQualifikationenTest.php
git commit -m "feat(recruiting): Crew-Karte liest die ZAS-Taetigkeiten statt des alten Feldes"
```

---

### Task 2: Karte vereinigt ueber die Gruppe und deckelt die Anzeige

**Files:**
- Modify: `src/Livewire/Dispo/Events/Show.php` — neue statische Methode plus `crewCard()` (ca. Zeile 2264-2316)
- Modify: `resources/views/livewire/dispo/events/show.blade.php` — Qualifikationsblock (ca. Zeile 1215-1228)
- Test: `tests/Unit/Dispo/CrewKarteQualifikationenMergeTest.php` (neu, pur)
- Test: `tests/Integration/DispoCrewKarteQualifikationenTest.php` (aus Task 1 erweitern)

**Interfaces:**
- Consumes: `profileCards()` aus Task 1, inklusive des rohen `qualifications_synced_at` (`Y-m-d H:i:s`).
- Produces:
  ```php
  // Pur und statisch, damit die Regel ohne Event, Einbuchungen und
  // Identitaetsaufloesung pruefbar ist.
  public static function mergeQualifications(array $cards, int $primaryId): array
  // $cards: array<int, array{qualifications: list<string>, qualifications_synced_at: ?string, ...}>
  // => array{values: list<string>, synced_at: ?string}   // synced_at roh, Y-m-d H:i:s
  ```
  `crewCard()` liefert danach `qualifications` als Vereinigung und `qualifications_synced_at` **fertig formatiert** als `d.m. H:i` oder `null`.

**Warum eine eigene statische Methode:** `crewCard()` haengt an `$this->identity`, und das leitet sich aus `$this->event->assignments` plus `DispoIdentityResolver` ab. Eine Gruppe aus RG- und MA-Datensatz im Test herzustellen hiesse, Event, Einbuchungen und Personen-Paarung mit aufzubauen — viel Aufbau fuer eine Regel, die reines Zusammenfuehren von Arrays ist. Die Regel wird pur geprueft, die Verdrahtung separat.

- [ ] **Step 1: Den puren Test schreiben**

Datei `tests/Unit/Dispo/CrewKarteQualifikationenMergeTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Events\Show;

/**
 * Vereinigung der ZAS-Taetigkeiten ueber die Identitaetsgruppe. Dieselbe Person
 * kann einen RG- und einen MA-Datensatz haben, und ZAS liefert fuer beide
 * Qualifikationen (247 MA-Personalnummern in Lieferung #258). Mit dem frueheren
 * "erster nicht leerer" saehe man nur eine Haelfte.
 */
class CrewKarteQualifikationenMergeTest extends TestCase
{
    /** @return array<int, array{qualifications: list<string>, qualifications_synced_at: ?string}> */
    private function karten(array $spec): array
    {
        $out = [];
        foreach ($spec as $id => [$quals, $stand]) {
            $out[$id] = ['qualifications' => $quals, 'qualifications_synced_at' => $stand];
        }

        return $out;
    }

    public function test_unions_across_the_group(): void
    {
        $r = Show::mergeQualifications($this->karten([
            7 => [['Logistiker'], null],
            9 => [['Thekenmitarbeiter'], null],
        ]), 7);

        $this->assertSame(['Logistiker', 'Thekenmitarbeiter'], $r['values']);
    }

    public function test_primary_record_comes_first(): void
    {
        $r = Show::mergeQualifications($this->karten([
            9 => [['Thekenmitarbeiter'], null],
            7 => [['Logistiker'], null],
        ]), 7);

        $this->assertSame(['Logistiker', 'Thekenmitarbeiter'], $r['values'],
            'Der kanonische Datensatz steht vorn, egal in welcher Reihenfolge die Karten kommen.');
    }

    public function test_deduplicates_case_insensitively_first_spelling_wins(): void
    {
        $r = Show::mergeQualifications($this->karten([
            7 => [['Logistiker', 'Kasse'], null],
            9 => [['logistiker'], null],
        ]), 7);

        $this->assertSame(['Logistiker', 'Kasse'], $r['values']);
    }

    public function test_takes_the_newest_timestamp(): void
    {
        $r = Show::mergeQualifications($this->karten([
            7 => [['Logistiker'], '2026-10-01 09:00:00'],
            9 => [['Kasse'], '2026-10-08 14:12:00'],
        ]), 7);

        $this->assertSame('2026-10-08 14:12:00', $r['synced_at']);
    }

    public function test_newest_timestamp_works_across_month_boundaries(): void
    {
        // Mit der Anzeigeform 'd.m. H:i' waere '09.11.' kleiner als '10.10.' —
        // deshalb wird hier auf dem rohen Wert verglichen.
        $r = Show::mergeQualifications($this->karten([
            7 => [['Logistiker'], '2026-10-10 08:00:00'],
            9 => [['Kasse'], '2026-11-09 08:00:00'],
        ]), 7);

        $this->assertSame('2026-11-09 08:00:00', $r['synced_at']);
    }

    public function test_one_record_without_timestamp_does_not_win(): void
    {
        $r = Show::mergeQualifications($this->karten([
            7 => [['Logistiker'], null],
            9 => [['Kasse'], '2026-10-08 14:12:00'],
        ]), 7);

        $this->assertSame('2026-10-08 14:12:00', $r['synced_at']);
    }

    public function test_empty_group_yields_empty_result(): void
    {
        $r = Show::mergeQualifications($this->karten([7 => [[], null]]), 7);

        $this->assertSame([], $r['values']);
        $this->assertNull($r['synced_at']);
    }

    public function test_missing_primary_id_still_merges_the_rest(): void
    {
        $r = Show::mergeQualifications($this->karten([
            9 => [['Kasse'], null],
        ]), 7);

        $this->assertSame(['Kasse'], $r['values'],
            'Fehlt der kanonische Datensatz in den Karten, darf nichts verloren gehen.');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CrewKarteQualifikationenMergeTest`
Expected: FAIL — `Call to undefined method ...Show::mergeQualifications()`.

- [ ] **Step 3: Die Methode schreiben und `crewCard()` darauf umstellen**

In `src/Livewire/Dispo/Events/Show.php`, direkt vor `crewCard()`:

```php
    /**
     * Vereinigt die ZAS-Taetigkeiten ueber die Identitaetsgruppe. Dieselbe
     * Person kann einen RG- und einen MA-Datensatz haben, und ZAS liefert fuer
     * beide Qualifikationen — "erster nicht leerer" zeigte nur eine Haelfte.
     *
     * Entdoppelt schreibungsunabhaengig, erste Schreibweise gewinnt; der
     * kanonische Datensatz steht vorn. Der Zeitstempel ist der juengste der
     * Gruppe und bleibt ROH (Y-m-d H:i:s) — die Anzeigeform d.m. H:i sortiert
     * sich als String falsch (09.11. < 10.10.).
     *
     * Pur und statisch, damit die Regel ohne Event, Einbuchungen und
     * Identitaetsaufloesung pruefbar ist.
     *
     * @param array<int, array{qualifications: list<string>, qualifications_synced_at: ?string}> $cards
     * @return array{values: list<string>, synced_at: ?string}
     */
    public static function mergeQualifications(array $cards, int $primaryId): array
    {
        $reihenfolge = [];
        if (isset($cards[$primaryId])) {
            $reihenfolge[] = $cards[$primaryId];
        }
        foreach ($cards as $id => $card) {
            if ($id !== $primaryId) {
                $reihenfolge[] = $card;
            }
        }

        $values = [];
        $syncedAt = null;
        foreach ($reihenfolge as $card) {
            foreach ($card['qualifications'] as $q) {
                $values[mb_strtolower((string) $q)] ??= (string) $q;
            }
            $stand = $card['qualifications_synced_at'] ?? null;
            if ($stand !== null && ($syncedAt === null || $stand > $syncedAt)) {
                $syncedAt = $stand;
            }
        }

        return ['values' => array_values($values), 'synced_at' => $syncedAt];
    }
```

In `crewCard()` die alte Qualifikationslogik ersetzen. Die Zeile

```php
        $quals = $primary['qualifications'];
```

entfaellt, ebenso der Block in der `foreach ($cards as $card)`-Schleife

```php
            if ($quals === []) {
                $quals = $card['qualifications'];
            }
```

Stattdessen nach der Schleife:

```php
        $merged = self::mergeQualifications($cards, (int) $this->crewEmployeeId);
```

Und die Rueckgabe:

```php
            'qualifications' => $merged['values'],
            'qualifications_synced_at' => $merged['synced_at'] === null
                ? null
                : \Illuminate\Support\Carbon::parse($merged['synced_at'])->format('d.m. H:i'),
```

(Das bisherige `array_values(array_unique($quals))` entfaellt — entdoppelt wird jetzt beim Einsammeln, und zwar schreibungsunabhaengig.)

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CrewKarteQualifikationenMergeTest`
Expected: PASS, 8 Tests.

- [ ] **Step 5: Mutationsprobe — faengt der Vereinigungstest wirklich?**

In `mergeQualifications()` die innere Schleife voruebergehend so aendern, dass nur die erste nicht leere Liste gewinnt (`if ($values !== []) { continue; }` vor der inneren Schleife), Test laufen lassen.
Expected: FAIL in `test_unions_across_the_group`. Danach zuruecksetzen und erneut Gruen pruefen.

- [ ] **Step 6: Den Verdrahtungstest schreiben**

Die pure Regel ist jetzt belegt — nicht aber, dass `crewCard()` sie ueberhaupt aufruft. An `tests/Integration/DispoCrewKarteQualifikationenTest.php` anhaengen. Als Vorlage fuer den Komponentenaufbau dient `tests/Integration/DressTestCase.php`: `dispoComponent()` baut `Dispo\Events\Show` ohne Livewire-Mount und verdrahtet die `#[Computed]`-Getter von Hand (`getAttributes()->each(... boot())`); ohne das wirft jeder Zugriff eine PropertyNotFoundException. Die Helfer `event()` und `assignment()` stehen dort ebenfalls. Die Migrationsliste braucht zusaetzlich `rec_dispo_events` und `rec_dispo_assignments`, weil `crewCard()` die bestaetigten Einsaetze zaehlt.

```php
    public function test_crew_card_actually_uses_the_merge_and_formats_the_date(): void
    {
        $e = $this->employee('RG500', [
            'dispo_taetigkeiten'           => json_encode(['Logistiker', 'Kasse'], JSON_UNESCAPED_UNICODE),
            'dispo_taetigkeiten_synced_at' => '2026-10-08 14:12:00',
        ]);
        $event = $this->event([]);
        $this->assignment($event, ['rec_employee_id' => $e->id]);

        $c = $this->dispoComponent($event->id);
        $c->crewEmployeeId = $e->id;
        $karte = $c->crewCard();

        $this->assertSame(['Logistiker', 'Kasse'], $karte['qualifications']);
        $this->assertSame('08.10. 14:12', $karte['qualifications_synced_at'],
            'Die Anzeigeform entsteht erst hier, nicht im Gateway.');
    }

    public function test_crew_card_of_an_employee_without_assignment_is_empty_not_broken(): void
    {
        $e = $this->employee('RG500');
        $event = $this->event([]);
        $this->assignment($event, ['rec_employee_id' => $e->id]);

        $c = $this->dispoComponent($event->id);
        $c->crewEmployeeId = $e->id;
        $karte = $c->crewCard();

        $this->assertSame([], $karte['qualifications'],
            '266 aktive Neuzugaenge haben noch nichts — das darf nicht knallen.');
        $this->assertNull($karte['qualifications_synced_at']);
    }
```

- [ ] **Step 7: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoCrewKarteQualifikationenTest`
Expected: PASS, 8 Tests (6 aus Task 1 plus diese 2).

Laesst sich der Komponentenaufbau nicht zum Laufen bringen: **baue keine Attrappe, die gruen bleibt, ohne zu pruefen.** Melde BLOCKED mit der konkreten Fehlermeldung.

- [ ] **Step 8: Das Markup deckeln und aufklappbar machen**

In `resources/views/livewire/dispo/events/show.blade.php` den Qualifikationsblock (ca. Zeile 1215-1228) ersetzen:

```blade
                    @php
                        $quals = $crew['qualifications'];
                        $qualsSichtbar = array_slice($quals, 0, 10);
                        $qualsRest = array_slice($quals, 10);
                    @endphp
                    <div class="mt-4" x-data="{ alleQuals: false }">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Qualifikationen</span>
                            @if ($crew['qualifications_synced_at'])
                                <span class="text-[10.5px] text-gray-400">aus ZAS · Stand {{ $crew['qualifications_synced_at'] }}</span>
                            @endif
                        </div>
                        @if ($quals !== [])
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach ($qualsSichtbar as $qual)
                                    <span class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-700">{{ $qual }}</span>
                                @endforeach
                                @if ($qualsRest !== [])
                                    @foreach ($qualsRest as $qual)
                                        <span x-cloak x-show="alleQuals" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-700">{{ $qual }}</span>
                                    @endforeach
                                    <button type="button" x-on:click="alleQuals = !alleQuals"
                                            class="rounded-lg bg-gray-100 px-2.5 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-200">
                                        <span x-show="!alleQuals">+{{ count($qualsRest) }} weitere</span>
                                        <span x-cloak x-show="alleQuals">weniger anzeigen</span>
                                    </button>
                                @endif
                            </div>
                        @else
                            <div class="mt-1 text-sm text-gray-400">Noch keine aus ZAS — bei neu angelegten Mitarbeitern ist das normal.</div>
                        @endif
                    </div>
```

**Warum kein zweites Fenster:** Die Karte ist bereits ein Modal (mobil Bottom-Sheet ueber `items-end`/`rounded-t-2xl`, ab `sm` zentrierter Dialog mit `max-w-lg`, dazu `max-h-[calc(100dvh-2rem)]` und ein innerer `overflow-y-auto`). Ein Fenster im Fenster hiesse zwei Schliessen-Ebenen uebereinander — am Handy eine Falle. Alpine laeuft in dieser Karte schon (`x-data="{ zoom: false }"` am aeusseren Container); das `x-data` hier ist eine eigene, verschachtelte Insel und stoert den Zoom nicht.

- [ ] **Step 9: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/dispo/events/show.blade.php`
Expected: keine Fehler. (`php -l` prueft Blades **nicht**.)

- [ ] **Step 10: Gesamte Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add src/Livewire/Dispo/Events/Show.php resources/views/livewire/dispo/events/show.blade.php tests/Unit/Dispo/CrewKarteQualifikationenMergeTest.php tests/Integration/DispoCrewKarteQualifikationenTest.php
git commit -m "feat(recruiting): Crew-Karte vereinigt ueber die Gruppe und deckelt die Chipliste"
```

---

## Nach dem letzten Task

- [ ] **Gesamte Suite:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
- [ ] **Keine Migration noetig.** Der Deploy braucht `view:clear` wegen der Blade-Aenderung, **kein** `migrate`, **kein** `queue:restart`.
- [ ] **Sichttests** (nichts davon ist automatisch abgedeckt): Karte eines Mitarbeiters mit wenigen Taetigkeiten · Karte mit mehr als zehn, „+N weitere" klappt in der Karte auf und wieder zu · Karte eines Neuzugangs ohne Zuordnung · dasselbe am Handy als Bottom-Sheet · der Foto-Zoom funktioniert weiterhin · der Filter „Wer?" im Info-Versand sieht unveraendert aus.
