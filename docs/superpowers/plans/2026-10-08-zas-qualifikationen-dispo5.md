# ZAS-Qualifikationen aus dem Dispo-Webexport — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Taetigkeits-Zuordnungen aus den Bloecken `{Dispo4}`/`{Dispo5}` des ZAS-Dispo-Webexports landen bei jeder Lieferung automatisch in `rec_employee_hr_data.dispo_taetigkeiten`, und die MA-Akte zeigt sie in einem Fenster als nicht klickbare Haken gegen den vollen ZAS-Katalog.

**Architecture:** Der `ZasDispoBlockSplitter` mappt die beiden Bloecke neu als bekannt. Eine neue pure Klasse `DispoQualifikationExtractor` macht daraus Katalog (Code → Name) und Zuordnung (PNr → Namensliste). Der vorhandene `ZasDispoTaetigkeitSync` bekommt einen Stapel-Eingang, der die Auswahlliste **einmal** pflegt statt pro Mitarbeiter. Der `ZasDispoWebexportImporter` verdrahtet beides **nach** seiner Haupttransaktion in eigenem try/catch, damit ein Fehler bei Sekundaerdaten nie Einbuchungen zurueckrollt. Der tote Pfad im MA-Importer wird entfernt.

**Tech Stack:** Laravel 11, Livewire 3, Blade, Tailwind v4, PHPUnit 11 (handgebauter Container + Capsule, SQLite in-memory, kein testbench).

**Spec:** `docs/superpowers/specs/2026-10-08-zas-qualifikationen-dispo5-design.md`

## Global Constraints

- **Testlauf:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (meingedeck liegt **neben** `platform`, drei Ebenen hoch). Das Modul hat kein eigenes `vendor/`.
- **Keine `--order-by=random`-Laeufe.** Begruendung steht im Kommentar in `phpunit.xml`.
- **Integrationstests** bauen Container und Capsule von Hand auf. Jede Tabelle, die ein Test anfasst, muss in dessen `runMigrations()` stehen — fehlt sie, wertet SQLite doppelt gequotete Bezeichner als **String-Literal** und der Test ist gruen, ohne zu pruefen.
- **`dispo_taetigkeiten` darf nie den ZAS-Export-Marker ausloesen.** Immer per `DB::table(...)->update(...)`, nie per Eloquent-`save()`. Sonst schicken wir ZAS seine eigenen Daten als Aenderung zurueck (Vorfall 02.09.).
- **Abgleich ueber den Namen**, nie ueber die Taetigkeits-ID. Begruendung: Spec, Entscheidung 1.
- **Deutsche Oberflaechentexte**, Kommentare im Code deutsch ohne Umlaute (Hausstil der bestehenden Dateien).
- **`php -l` prueft keine Blades.** Fuer Blade-Aenderungen `tools/blade-check.php` nutzen.
- **An Wortzeichen geklebte Blade-Direktiven kompilieren nicht** (`da@if`, `Uhr@endif`). Immer Blockform, Werte vorberechnen.

## Review Focus

1. **Lieferung ohne `{Dispo5}`** (nur `{Dispo}`/`{Dispo2}`, der Normalfall bei Teillieferungen) darf bestehende Zuordnungen **nicht leeren** — sonst verlieren 1.409 Mitarbeiter ihre Qualifikationen, sobald ZAS einmal ohne den Block liefert. → Test in Task 4.
2. **Platzhalter-PNr `RG14` trifft einen echten Mitarbeiter** (MA 126) und traegt in `{Dispo5}` vier Zeilen, darunter 782 Einsaetze. Ohne Filter bekommt ein realer Mensch fremde Qualifikationen. → Test in Task 2.
3. **Taetigkeits-ID ohne Katalogeintrag** (`RG`, 30 von 7.611 Zeilen) darf nicht als Name `RG` in der Auswahlliste landen. → Test in Task 2.
4. **Zwei IDs, ein Name** (`Logistiker` = RG13+RG271) darf nicht zu zwei gleichen Eintraegen beim selben Mitarbeiter fuehren. → Test in Task 2.
5. **Dry-run schreibt nichts**, zaehlt aber dieselben Zahlen wie der Live-Lauf — sonst ist die Vorschau wertlos. → Test in Task 4.

---

### Task 1: Splitter kennt `{Dispo4}` und `{Dispo5}`

**Files:**
- Modify: `src/Services/Zas/Dispo/ZasDispoBlockSplitter.php:17-29`
- Test: `tests/Unit/Dispo/ZasDispoBlockSplitterTest.php:49-53`

**Interfaces:**
- Consumes: nichts.
- Produces: `$split['known']['Dispo4']` als `list<array{nr:string, name:string, code:string, col_3:string}>` und `$split['known']['Dispo5']` als `list<array{pnr:string, taetigkeit_id:string, anzahl:string, col_3:string}>`. Die vierte Zelle ist in den echten Dateien immer leer und landet durch `zip()` unter `col_3`.

**Achtung:** Beide Bloecke wandern damit aus `$split['unknown']` heraus. Die Sichtungsseite (`src/Livewire/Dispo/Show.php:80`) zeigt nur `unknown` — sie zeigt die beiden Bloecke danach also nicht mehr roh an. Das ist gewollt.

- [ ] **Step 1: Bestehenden Test umschreiben, sodass er rot wird**

In `tests/Unit/Dispo/ZasDispoBlockSplitterTest.php` die Methode `test_unknown_blocks_kept_raw()` ersetzen durch:

```php
    public function test_dispo4_is_mapped_to_assoc_rows(): void
    {
        $result = $this->splitter->split($this->fixture());

        $this->assertArrayNotHasKey('Dispo4', $result['unknown'], 'Dispo4 ist jetzt ein bekannter Block.');
        $this->assertCount(1, $result['known']['Dispo4']);
        $row = $result['known']['Dispo4'][0];
        $this->assertSame('1', $row['nr']);
        $this->assertSame('Küchenchef', $row['name']);
        $this->assertSame('RG1', $row['code']);
        $this->assertSame('', $row['col_3'], 'Die vierte Zelle der echten Dateien ist leer.');
    }

    public function test_dispo5_is_mapped_to_assoc_rows(): void
    {
        $content = "{Dispo5}\r\nRG1464;RG8;17;\r\nRG14;RG8;782;\r\n";

        $result = $this->splitter->split($content);

        $this->assertArrayNotHasKey('Dispo5', $result['unknown']);
        $this->assertCount(2, $result['known']['Dispo5']);
        $this->assertSame('RG1464', $result['known']['Dispo5'][0]['pnr']);
        $this->assertSame('RG8', $result['known']['Dispo5'][0]['taetigkeit_id']);
        $this->assertSame('17', $result['known']['Dispo5'][0]['anzahl']);
    }
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasDispoBlockSplitterTest`
Expected: FAIL — `Failed asserting that an array has the key 'Dispo4'` (in `known`), weil der Block noch in `unknown` liegt.

- [ ] **Step 3: Die beiden Bloecke in COLUMNS eintragen**

In `src/Services/Zas/Dispo/ZasDispoBlockSplitter.php` hinter den `Dispo2`-Eintrag in `COLUMNS` ergaenzen:

```php
        // Taetigkeiten-Katalog (Mail Olaf Michel 06.10.2026). Zeilenform
        // "1;Küchenchef;RG1;" — die vierte Zelle ist immer leer. Schluessel
        // ist 'code', NICHT die letzte Spalte.
        'Dispo4' => [
            'nr', 'name', 'code',
        ],
        // Taetigkeiten je Mitarbeiter, dieselbe Mail. Zeilenform
        // "RG1464;RG8;17;" = PNr, Taetigkeits-ID, Anzahl Einsaetze.
        // Anzahl 0 kommt vor und beweist, dass dies eine gepflegte
        // Zuordnungsliste ist und keine abgeleitete Statistik.
        'Dispo5' => [
            'pnr', 'taetigkeit_id', 'anzahl',
        ],
```

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasDispoBlockSplitterTest`
Expected: PASS, alle Tests der Klasse.

- [ ] **Step 5: Gesamte Unit-Suite, damit kein anderer Test auf `unknown` baut**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS. Schlaegt ein Test fehl, der `unknown` erwartet: der Test hat recht gehabt, bis diese Aenderung ihn ueberholt hat — anpassen, nicht umgehen.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Zas/Dispo/ZasDispoBlockSplitter.php tests/Unit/Dispo/ZasDispoBlockSplitterTest.php
git commit -m "feat(recruiting): Splitter mappt die ZAS-Bloecke Dispo4 und Dispo5"
```

---

### Task 2: `DispoQualifikationExtractor` — aus Rohzeilen werden Namenslisten

**Files:**
- Create: `src/Services/Zas/Dispo/DispoQualifikationExtractor.php`
- Test: `tests/Unit/Dispo/DispoQualifikationExtractorTest.php`

**Interfaces:**
- Consumes: `$split['known']['Dispo4']` und `$split['known']['Dispo5']` aus Task 1.
- Produces:
  ```php
  DispoQualifikationExtractor::extract(array $dispo4, array $dispo5): array
  // => array{
  //      katalog: array<string,string>,          // code => name, z.B. 'RG8' => 'Servicekräfte'
  //      namen: list<string>,                    // alle Katalognamen, entdoppelt, Reihenfolge wie geliefert
  //      byPnr: array<string, list<string>>,     // 'RG1464' => ['Servicekräfte','Logistiker']
  //      stats: array{katalog:int, zeilen:int, platzhalter:int, ohne_katalog:int, ohne_pnr:int}
  //    }
  ```

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

Datei `tests/Unit/Dispo/DispoQualifikationExtractorTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoQualifikationExtractor;

/**
 * Aus {Dispo4}/{Dispo5} werden Katalog und Zuordnung. Alle Zeilen hier sind
 * echte Formen aus Lieferung #254 (07.10.2026).
 */
class DispoQualifikationExtractorTest extends TestCase
{
    /** @return list<array<string,string>> */
    private function katalog(): array
    {
        return [
            ['nr' => '8',   'name' => 'Servicekräfte', 'code' => 'RG8',   'col_3' => ''],
            ['nr' => '13',  'name' => 'Logistiker',    'code' => 'RG13',  'col_3' => ''],
            // ZAS hat dieselbe Taetigkeit doppelt angelegt (Block RG269-RG272).
            ['nr' => '271', 'name' => 'Logistiker',    'code' => 'RG271', 'col_3' => ''],
            ['nr' => '4',   'name' => 'Küchenhilfe',   'code' => 'RG4',   'col_3' => ''],
        ];
    }

    /** @return list<array<string,string>> */
    private function row(string $pnr, string $id, string $anzahl): array
    {
        return ['pnr' => $pnr, 'taetigkeit_id' => $id, 'anzahl' => $anzahl, 'col_3' => ''];
    }

    public function test_katalog_is_keyed_by_code_not_by_name(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), []);

        $this->assertSame('Servicekräfte', $r['katalog']['RG8']);
        $this->assertSame('Logistiker', $r['katalog']['RG271']);
        $this->assertSame(4, $r['stats']['katalog']);
    }

    public function test_katalognamen_are_deduplicated(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), []);

        $this->assertSame(['Servicekräfte', 'Logistiker', 'Küchenhilfe'], $r['namen'],
            'RG13 und RG271 heissen beide "Logistiker" — die Auswahlliste braucht den Namen einmal.');
    }

    public function test_assignments_are_translated_to_names(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('RG1464', 'RG8', '17'),
            $this->row('RG1464', 'RG4', '0'),
        ]);

        $this->assertSame(['Servicekräfte', 'Küchenhilfe'], $r['byPnr']['RG1464']);
        $this->assertSame(2, $r['stats']['zeilen']);
    }

    public function test_zero_assignments_still_count_as_qualification(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('RG1464', 'RG4', '0'),
        ]);

        $this->assertSame(['Küchenhilfe'], $r['byPnr']['RG1464'],
            'Anzahl 0 beweist die gepflegte Zuordnung — sie darf nicht wegfallen.');
    }

    public function test_two_ids_with_the_same_name_appear_once_per_employee(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('RG1464', 'RG13', '5'),
            $this->row('RG1464', 'RG271', '2'),
        ]);

        $this->assertSame(['Logistiker'], $r['byPnr']['RG1464']);
    }

    public function test_placeholder_personnel_numbers_are_dropped(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('RG14', 'RG8', '782'),
            $this->row('RG0', 'RG8', '3'),
            $this->row('RG1464', 'RG8', '17'),
        ]);

        $this->assertArrayNotHasKey('RG14', $r['byPnr'],
            'RG14 ist ein unbesetzter Platz und trifft zugleich den echten Mitarbeiter 126.');
        $this->assertArrayNotHasKey('RG0', $r['byPnr']);
        $this->assertSame(['RG1464'], array_keys($r['byPnr']));
        $this->assertSame(2, $r['stats']['platzhalter']);
    }

    public function test_ids_without_catalogue_entry_are_dropped_and_counted(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('RG1464', 'RG', '1'),
            $this->row('RG1464', 'RG9999', '1'),
            $this->row('RG1464', 'RG8', '17'),
        ]);

        $this->assertSame(['Servicekräfte'], $r['byPnr']['RG1464'],
            'Die leere ID "RG" darf nicht als Name in der Auswahlliste landen.');
        $this->assertSame(2, $r['stats']['ohne_katalog']);
    }

    public function test_rows_without_personnel_number_are_dropped_and_counted(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('', 'RG8', '1'),
            $this->row('   ', 'RG8', '1'),
        ]);

        $this->assertSame([], $r['byPnr']);
        $this->assertSame(2, $r['stats']['ohne_pnr']);
    }

    public function test_catalogue_rows_without_code_or_name_are_ignored(): void
    {
        $r = DispoQualifikationExtractor::extract([
            ['nr' => '1', 'name' => 'Küchenchef', 'code' => '',    'col_3' => ''],
            ['nr' => '2', 'name' => '',           'code' => 'RG2', 'col_3' => ''],
            ['nr' => '8', 'name' => 'Servicekräfte', 'code' => 'RG8', 'col_3' => ''],
        ], []);

        $this->assertSame(['RG8' => 'Servicekräfte'], $r['katalog']);
        $this->assertSame(1, $r['stats']['katalog']);
    }

    public function test_empty_input_yields_empty_result(): void
    {
        $r = DispoQualifikationExtractor::extract([], []);

        $this->assertSame([], $r['katalog']);
        $this->assertSame([], $r['namen']);
        $this->assertSame([], $r['byPnr']);
        $this->assertSame(0, $r['stats']['zeilen']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoQualifikationExtractorTest`
Expected: FAIL — `Class "Platform\Recruiting\Services\Zas\Dispo\DispoQualifikationExtractor" not found`.

- [ ] **Step 3: Die Klasse schreiben**

Datei `src/Services/Zas/Dispo/DispoQualifikationExtractor.php`:

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Macht aus den ZAS-Bloecken {Dispo4} (Taetigkeiten-Katalog) und {Dispo5}
 * (Zuordnung je Mitarbeiter) zwei brauchbare Strukturen. Pure — kein DB-
 * Zugriff, kein Matching auf unsere Mitarbeiter (das macht ZasDispoMatcher).
 *
 * Der Abgleich laeuft spaeter ueber den NAMEN, nicht ueber die ID: ZAS hat
 * mehrere Taetigkeiten doppelt angelegt (Logistiker = RG13 + RG271, Block
 * RG269-RG272 und RG287-RG290, gemessen 07.10.2026). Ueber den Namen fallen
 * die Dubletten zusammen; ueber die ID muesste man jedes Waeschepaket doppelt
 * pflegen.
 */
final class DispoQualifikationExtractor
{
    /**
     * Unbesetzte Plaetze der Dispo. Hartkodiert wird NUR, was nachweislich mit
     * einem echten Mitarbeiter kollidiert: 'RG14' trifft Mitarbeiter 126 und
     * traegt in {Dispo5} 782 Einsaetze auf RG8 — ohne diesen Filter bekaeme ein
     * realer Mensch fremde Qualifikationen. Andere Dummys ('RG902',
     * 'RG999999') treffen auf niemanden und laufen ohnehin als unmatched ins
     * Protokoll; sie gehoeren nicht in diese Liste.
     */
    public const PLATZHALTER_PNR = ['RG0', 'RG14'];

    /**
     * @param list<array<string,string>> $dispo4
     * @param list<array<string,string>> $dispo5
     * @return array{katalog: array<string,string>, namen: list<string>, byPnr: array<string, list<string>>, stats: array{katalog:int, zeilen:int, platzhalter:int, ohne_katalog:int, ohne_pnr:int}}
     */
    public static function extract(array $dispo4, array $dispo5): array
    {
        $katalog = [];
        $namen = [];

        foreach ($dispo4 as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $katalog[$code] = $name;
            $namen[mb_strtolower($name)] ??= $name;
        }

        $byPnr = [];
        $stats = [
            'katalog'      => count($katalog),
            'zeilen'       => 0,
            'platzhalter'  => 0,
            'ohne_katalog' => 0,
            'ohne_pnr'     => 0,
        ];

        foreach ($dispo5 as $row) {
            $stats['zeilen']++;

            $pnr = trim((string) ($row['pnr'] ?? ''));
            if ($pnr === '') {
                $stats['ohne_pnr']++;
                continue;
            }
            if (in_array($pnr, self::PLATZHALTER_PNR, true)) {
                $stats['platzhalter']++;
                continue;
            }

            $id = trim((string) ($row['taetigkeit_id'] ?? ''));
            if ($id === '' || !isset($katalog[$id])) {
                $stats['ohne_katalog']++;
                continue;
            }

            // Namen entdoppeln, erste Schreibweise gewinnt — dieselbe Regel wie
            // in ZasDispoTaetigkeitSync::parse().
            $name = $katalog[$id];
            $byPnr[$pnr][mb_strtolower($name)] ??= $name;
        }

        foreach ($byPnr as $pnr => $liste) {
            $byPnr[$pnr] = array_values($liste);
        }

        return [
            'katalog' => $katalog,
            'namen'   => array_values($namen),
            'byPnr'   => $byPnr,
            'stats'   => $stats,
        ];
    }
}
```

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoQualifikationExtractorTest`
Expected: PASS, 10 Tests.

- [ ] **Step 5: Mutationsprobe — prueft der Platzhalter-Test wirklich?**

`PLATZHALTER_PNR` voruebergehend auf `[]` setzen, Test laufen lassen.
Expected: FAIL in `test_placeholder_personnel_numbers_are_dropped`. Danach zuruecksetzen und erneut Gruen pruefen.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Zas/Dispo/DispoQualifikationExtractor.php tests/Unit/Dispo/DispoQualifikationExtractorTest.php
git commit -m "feat(recruiting): Extractor fuer ZAS-Qualifikationen aus Dispo4/Dispo5"
```

---

### Task 3: `ZasDispoTaetigkeitSync` bekommt einen Stapel-Eingang

**Files:**
- Modify: `src/Services/Zas/ZasDispoTaetigkeitSync.php`
- Test: `tests/Integration/ZasDispoTaetigkeitSyncTest.php`

**Interfaces:**
- Consumes: die `byPnr`- und `namen`-Struktur aus Task 2, bereits auf Mitarbeiter-IDs uebersetzt.
- Produces:
  ```php
  public function syncLabels(RecEmployee $employee, array $labels): array
  // => array{values: list<string>, created_values: int}

  public function syncMany(array $labelsByEmployeeId, array $katalogNamen): array
  // $labelsByEmployeeId: array<int, list<string>>
  // $katalogNamen:       list<string>  — ALLE Katalognamen, auch unzugewiesene
  // => array{updated:int, unchanged:int, created_values:int, missing_employees:int}
  ```

**Warum ein Stapel-Eingang:** `sync()` prueft pro Mitarbeiter die Auswahlliste. Beim MA-Import war das eine Zeile; hier waeren es 1.442 Mitarbeiter **pro Lieferung**. Die Listenpflege gehoert einmal vor die Schleife, und geschrieben wird nur, wo sich wirklich etwas geaendert hat.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

An `tests/Integration/ZasDispoTaetigkeitSyncTest.php` anhaengen (vor der schliessenden Klammer der Klasse):

```php
    public function test_sync_many_writes_only_changed_employees(): void
    {
        $a = $this->employee('RG100');
        $b = $this->employee('RG200');
        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany([$a->id => ['Servicekräfte'], $b->id => ['Logistiker']], ['Servicekräfte', 'Logistiker']);

        $r = $sync->syncMany([$a->id => ['Servicekräfte'], $b->id => ['Logistiker', 'Kasse']], ['Servicekräfte', 'Logistiker', 'Kasse']);

        $this->assertSame(1, $r['updated'], 'Nur B hat sich geaendert.');
        $this->assertSame(1, $r['unchanged']);
        $this->assertSame(['Logistiker', 'Kasse'], (array) $b->fresh()->hrData->dispo_taetigkeiten);
    }

    public function test_sync_many_ignores_order_jitter(): void
    {
        $a = $this->employee('RG100');
        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany([$a->id => ['Servicekräfte', 'Logistiker']], ['Servicekräfte', 'Logistiker']);

        $r = $sync->syncMany([$a->id => ['Logistiker', 'Servicekräfte']], ['Servicekräfte', 'Logistiker']);

        $this->assertSame(0, $r['updated'], 'Andere Reihenfolge ist keine Aenderung.');
        $this->assertSame(1, $r['unchanged']);
    }

    public function test_sync_many_refreshes_the_timestamp_even_without_a_change(): void
    {
        $a = $this->employee('RG100');
        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany([$a->id => ['Servicekräfte']], ['Servicekräfte']);
        Capsule::table('rec_employee_hr_data')
            ->where('rec_employee_id', $a->id)
            ->update(['dispo_taetigkeiten_synced_at' => '2020-01-01 00:00:00']);

        $sync->syncMany([$a->id => ['Servicekräfte']], ['Servicekräfte']);

        $this->assertNotSame(
            '2020-01-01 00:00:00',
            (string) Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $a->id)->value('dispo_taetigkeiten_synced_at'),
            'Sonst zeigt die MA-Akte einen alten Stand, obwohl ZAS den Wert heute bestaetigt hat.'
        );
    }

    public function test_sync_many_puts_the_whole_catalogue_into_the_lookup(): void
    {
        $a = $this->employee('RG100');

        $r = (new ZasDispoTaetigkeitSync())->syncMany(
            [$a->id => ['Servicekräfte']],
            ['Servicekräfte', 'Logistiker', 'Kasse']
        );

        $this->assertSame(3, $r['created_values'],
            'Auch nicht zugewiesene Taetigkeiten gehoeren in die Liste — sonst kann die MA-Akte nicht zeigen, was jemand NICHT kann.');
        $lookupId = Capsule::table('core_lookups')->where('name', 'dispo_taetigkeit')->value('id');
        $this->assertSame(3, Capsule::table('core_lookup_values')->where('lookup_id', $lookupId)->count());
    }

    public function test_sync_many_counts_unknown_employee_ids(): void
    {
        $r = (new ZasDispoTaetigkeitSync())->syncMany([999999 => ['Servicekräfte']], ['Servicekräfte']);

        $this->assertSame(1, $r['missing_employees']);
        $this->assertSame(0, $r['updated']);
    }

    public function test_sync_many_does_not_set_the_zas_export_marker(): void
    {
        $a = $this->employee('RG100');
        Capsule::table('rec_employees')->where('id', $a->id)->update(['zas_changed_at' => null]);

        (new ZasDispoTaetigkeitSync())->syncMany([$a->id => ['Servicekräfte']], ['Servicekräfte']);

        $this->assertNull(
            Capsule::table('rec_employees')->where('id', $a->id)->value('zas_changed_at'),
            'Sonst schickten wir ZAS seine eigenen Daten als Aenderung zurueck.'
        );
    }
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasDispoTaetigkeitSyncTest`
Expected: FAIL — `Call to undefined method ...::syncMany()`.

- [ ] **Step 3: `syncLabels()` herausziehen und `syncMany()` ergaenzen**

In `src/Services/Zas/ZasDispoTaetigkeitSync.php` die bestehende `sync()` ersetzen durch:

```php
    /**
     * @return array{values: list<string>, created_values: int} gespeicherte Werte + neu angelegte Listen-Eintraege
     */
    public function sync(RecEmployee $employee, ?string $rawList): array
    {
        return $this->syncLabels($employee, self::parse($rawList));
    }

    /**
     * Wie sync(), aber mit fertiger Namensliste. Der Dispo-Webexport liefert
     * keine Komma-Liste, sondern uebersetzte Katalognamen — ein Name mit Komma
     * wuerde beim Umweg ueber parse() zu zwei Phantom-Qualifikationen.
     *
     * @param list<string> $labels
     * @return array{values: list<string>, created_values: int}
     */
    public function syncLabels(RecEmployee $employee, array $labels): array
    {
        $created = 0;
        if ($labels !== []) {
            $created = $this->ensureLookupValues((int) $employee->team_id, $labels);
        }

        $hr = $employee->ensureHrData();
        DB::table('rec_employee_hr_data')
            ->where('id', $hr->id)
            ->update([
                'dispo_taetigkeiten'           => json_encode($labels, JSON_UNESCAPED_UNICODE),
                'dispo_taetigkeiten_synced_at' => now(),
            ]);

        return ['values' => $labels, 'created_values' => $created];
    }

    /**
     * Stapel-Eingang fuer den Dispo-Webexport: 1.442 Mitarbeiter pro Lieferung.
     * Die Auswahlliste wird EINMAL je Team gepflegt (nicht je Mitarbeiter), und
     * geschrieben wird nur, wo sich die Liste wirklich geaendert hat. Der
     * Zeitstempel wandert trotzdem bei allen mit — sonst zeigt die MA-Akte
     * einen alten Stand, obwohl ZAS den Wert heute bestaetigt hat.
     *
     * @param array<int, list<string>> $labelsByEmployeeId
     * @param list<string>             $katalogNamen alle Katalognamen, auch unzugewiesene
     * @return array{updated:int, unchanged:int, created_values:int, missing_employees:int}
     */
    public function syncMany(array $labelsByEmployeeId, array $katalogNamen): array
    {
        $out = ['updated' => 0, 'unchanged' => 0, 'created_values' => 0, 'missing_employees' => 0];
        if ($labelsByEmployeeId === []) {
            return $out;
        }

        $employees = RecEmployee::query()
            ->whereIn('id', array_keys($labelsByEmployeeId))
            ->with('hrData')
            ->get()
            ->keyBy('id');

        $out['missing_employees'] = count($labelsByEmployeeId) - $employees->count();

        // Auswahlliste je Team einmal pflegen: der ganze Katalog plus alles,
        // was tatsaechlich zugewiesen ist (falls ZAS eine ID zuweist, deren
        // Katalogzeile in derselben Lieferung fehlt).
        $byTeam = [];
        foreach ($employees as $employee) {
            $teamId = (int) $employee->team_id;
            $byTeam[$teamId] ??= $katalogNamen;
            foreach ($labelsByEmployeeId[$employee->id] as $label) {
                $byTeam[$teamId][] = $label;
            }
        }
        foreach ($byTeam as $teamId => $labels) {
            $out['created_values'] += $this->ensureLookupValues($teamId, array_values(array_unique($labels)));
        }

        $unveraendert = [];
        foreach ($employees as $employee) {
            $labels = $labelsByEmployeeId[$employee->id];
            $hr = $employee->ensureHrData();

            $alt = (array) ($hr->dispo_taetigkeiten ?? []);
            $a = array_map('strval', $alt);
            $b = $labels;
            sort($a, SORT_STRING);
            sort($b, SORT_STRING);

            if ($a === $b) {
                $unveraendert[] = $hr->id;
                $out['unchanged']++;
                continue;
            }

            DB::table('rec_employee_hr_data')
                ->where('id', $hr->id)
                ->update([
                    'dispo_taetigkeiten'           => json_encode($labels, JSON_UNESCAPED_UNICODE),
                    'dispo_taetigkeiten_synced_at' => now(),
                ]);
            $out['updated']++;
        }

        foreach (array_chunk($unveraendert, 500) as $chunk) {
            DB::table('rec_employee_hr_data')
                ->whereIn('id', $chunk)
                ->update(['dispo_taetigkeiten_synced_at' => now()]);
        }

        return $out;
    }
```

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasDispoTaetigkeitSyncTest`
Expected: PASS — die sechs neuen und die vier bestehenden Tests.

- [ ] **Step 5: Mutationsprobe — prueft der „nur Geaendertes"-Test wirklich?**

In `syncMany()` den Vergleich `if ($a === $b)` voruebergehend durch `if (false)` ersetzen, Test laufen lassen.
Expected: FAIL in `test_sync_many_writes_only_changed_employees` (`updated` = 2 statt 1). Danach zuruecksetzen und erneut Gruen pruefen.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Zas/ZasDispoTaetigkeitSync.php tests/Integration/ZasDispoTaetigkeitSyncTest.php
git commit -m "feat(recruiting): Stapel-Eingang syncMany fuer Dispo-Taetigkeiten"
```

---

### Task 4: Der Webexport-Importer traegt die Qualifikationen ein

**Files:**
- Modify: `src/Services/Zas/Dispo/ZasDispoWebexportImporter.php`
- Test: `tests/Integration/ZasDispoQualifikationImportTest.php` (neu)

**Interfaces:**
- Consumes: `DispoQualifikationExtractor::extract()` (Task 2), `ZasDispoTaetigkeitSync::syncMany()` (Task 3), `ZasDispoMatcher::match()` (vorhanden).
- Produces: `$summary['qualifikationen']` mit den Schluesseln `katalog`, `zeilen`, `platzhalter`, `ohne_katalog`, `ohne_pnr`, `pnr_gesamt`, `matched`, `unmatched`, `employees_updated`, `employees_unchanged`, `lookup_values_created`, `fehler`.

**Zwei Regeln, die hier nicht verhandelbar sind:**
1. Der Sync laeuft **nach** `DB::transaction()` in eigenem try/catch. Qualifikationen sind Sekundaerdaten — ein Fehler dort darf keine Einbuchungen zurueckrollen.
2. Eine Lieferung **ohne** `{Dispo5}` fasst bestehende Zuordnungen **nicht an**. Sonst verlieren 1.409 Mitarbeiter ihre Qualifikationen, sobald ZAS einmal eine Teillieferung schickt.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

Datei `tests/Integration/ZasDispoQualifikationImportTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/** Disk-Ersatz: haelt den Rohinhalt im Speicher, Storage::disk(...)->get(...). */
class FakeDispoDisk
{
    /** @var array<string,string> */
    public static array $dateien = [];

    public function disk(?string $name = null): self
    {
        return $this;
    }

    public function get(string $pfad): string
    {
        return self::$dateien[$pfad] ?? '';
    }
}

/** Log-Attrappe: Container::instance allein reicht nicht, die Facade cacht. */
class StummerLog
{
    public function error(string $m, array $c = []): void {}
    public function warning(string $m, array $c = []): void {}
    public function info(string $m, array $c = []): void {}
}

/**
 * Qualifikationen aus {Dispo4}/{Dispo5} (Mail Olaf Michel 06.10.2026).
 *
 * Die Bloecke kommen im selben Webexport wie die Einbuchungen. Hier wird
 * geprueft, dass sie bei jeder Lieferung in rec_employee_hr_data landen — und
 * vor allem, dass eine Lieferung OHNE sie nichts kaputt macht.
 */
class ZasDispoQualifikationImportTest extends TestCase
{
    private const TEAM = 1101;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        $container->instance('filesystem', new FakeDispoDisk());
        $container->instance('log', new StummerLog());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => 'RG']],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('filesystem');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        FakeDispoDisk::$dateien = [];
        foreach ([
            'rec_employees', 'rec_employee_hr_data', 'core_lookups', 'core_lookup_values',
            'rec_zas_dispo_inbound_files', 'rec_dispo_events', 'rec_dispo_assignments',
        ] as $t) {
            Capsule::table($t)->delete();
        }
    }

    /**
     * Jede Tabelle, die ein Test anfasst, MUSS hier stehen. Fehlt sie, wertet
     * SQLite doppelt gequotete Bezeichner als String-Literal — der Test ist
     * dann gruen, ohne irgendetwas zu pruefen.
     */
    private static function runMigrations(): void
    {
        $own  = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own,  'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own,  'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own,  'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own,  'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own,  'database/migrations/2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php'],
            [$own,  'database/migrations/2026_08_12_000003_add_processed_at_to_rec_zas_dispo_inbound_files.php'],
            [$own,  'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own,  'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    private function employee(string $pnr): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
    }

    /** Schreibt eine Rohdatei auf den Attrappen-Disk und importiert sie. */
    private function importiere(string $inhalt, bool $dryRun = false): array
    {
        $pfad = 'zas-dispo-inbound/test-' . uniqid() . '.csv';
        FakeDispoDisk::$dateien[$pfad] = $inhalt;

        $file = RecZasDispoInboundFile::create([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'source' => 'test', 'original_filename' => 'test.csv',
            'disk' => 'local', 'stored_path' => $pfad,
            'mime_type' => 'text/csv', 'size_bytes' => strlen($inhalt),
            'parse_status' => 'viewable',
        ]);

        $importer = new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
        );

        return $importer->import($file, $dryRun);
    }

    /** Eine Einbuchungszeile in der Zukunft — Form aus Michels Beispielexport. */
    private function dispoZeile(string $pnr = 'RG1464'): string
    {
        return "{Dispo}\r\n19.05.2027;BHG. BROICHCATERING GMBH;{$pnr};RG19077;1;RG13450;830363;10:30;21:15;2;0;Servicekräfte;;27,49;\r\n";
    }

    public function test_dispo5_fills_the_employee_qualifications(): void
    {
        $mitarbeiter = $this->employee('RG1464');

        $summary = $this->importiere(
            "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n13;Logistiker;RG13;\r\n"
            . "{Dispo5}\r\nRG1464;RG8;17;\r\nRG1464;RG13;0;\r\n"
        );

        $this->assertSame(['Servicekräfte', 'Logistiker'], (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten);
        $this->assertSame(1, $summary['qualifikationen']['matched']);
        $this->assertSame(1, $summary['qualifikationen']['employees_updated']);
        $this->assertSame(2, $summary['qualifikationen']['katalog']);
    }

    public function test_delivery_without_dispo5_leaves_existing_qualifications_alone(): void
    {
        $mitarbeiter = $this->employee('RG1464');
        $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n");
        $this->assertSame(['Servicekräfte'], (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten, 'Testannahme');

        $this->importiere($this->dispoZeile());

        $this->assertSame(['Servicekräfte'], (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten,
            'Eine Teillieferung ohne {Dispo5} darf 1.409 Mitarbeitern nicht die Qualifikationen leeren.');
    }

    public function test_unknown_personnel_numbers_are_counted_not_written(): void
    {
        $summary = $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG999999;RG8;1;\r\n");

        $this->assertSame(0, $summary['qualifikationen']['matched']);
        $this->assertSame(1, $summary['qualifikationen']['unmatched']);
    }

    public function test_placeholder_rg14_does_not_touch_the_real_employee(): void
    {
        $echter = $this->employee('RG14');

        $summary = $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG14;RG8;782;\r\n");

        $this->assertSame([], (array) ($echter->fresh()->hrData?->dispo_taetigkeiten ?? []),
            'RG14 ist ein unbesetzter Platz und trifft zugleich einen echten Mitarbeiter.');
        $this->assertSame(1, $summary['qualifikationen']['platzhalter']);
        $this->assertSame(0, $summary['qualifikationen']['matched']);
    }

    public function test_dry_run_counts_but_writes_nothing(): void
    {
        $mitarbeiter = $this->employee('RG1464');

        $summary = $this->importiere(
            "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n",
            dryRun: true
        );

        $this->assertSame(1, $summary['qualifikationen']['matched'], 'Die Vorschau zaehlt wie der Live-Lauf.');
        $this->assertSame([], (array) ($mitarbeiter->fresh()->hrData?->dispo_taetigkeiten ?? []));
        $this->assertSame(0, Capsule::table('core_lookup_values')->count());
    }

    public function test_a_failing_qualification_sync_does_not_roll_back_the_dispo_import(): void
    {
        $this->employee('RG1464');
        // Ohne diese Tabelle wirft die Listenpflege mitten im Sync.
        Capsule::schema()->drop('core_lookup_values');

        try {
            $summary = $this->importiere(
                $this->dispoZeile()
                . "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n"
            );

            $this->assertSame(1, Capsule::table('rec_dispo_assignments')->count(),
                'Die Einbuchung muss stehen bleiben — Qualifikationen sind Sekundaerdaten.');
            $this->assertNotSame([], $summary['qualifikationen']['fehler']);
            $this->assertArrayNotHasKey('rolled_back', $summary);
        } finally {
            self::runMigrationFor('core_lookup_values');
        }
    }

    /** Stellt eine im Test absichtlich geloeschte Tabelle wieder her. */
    private static function runMigrationFor(string $table): void
    {
        if (Capsule::schema()->hasTable($table)) {
            return;
        }
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        Capsule::schema()->drop('core_lookups');
        (require $core . '/database/migrations/2026_02_12_000003_create_core_lookups_tables.php')->up();
    }
}
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasDispoQualifikationImportTest`
Expected: FAIL — `Undefined array key "qualifikationen"`.

- [ ] **Step 3: Den Importer verdrahten**

In `src/Services/Zas/Dispo/ZasDispoWebexportImporter.php`:

**3a** — Konstruktor um den Sync erweitern:

```php
    public function __construct(
        private ZasDispoBlockSplitter $splitter,
        private ZasDispoImportPlanner $planner,
        private DispoEmployeeDirectory $directory,
        private DispoReconfirmMarker $reconfirmMarker,
        // Qualifikationen aus {Dispo4}/{Dispo5} (Mail Olaf 06.10.2026).
        private \Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync $taetigkeiten = new \Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync(),
    ) {}
```

**3b** — den Startwert in `$summary` ergaenzen, direkt hinter `'unmatched_pnrs' => [], 'ambiguous_pnrs' => [],`:

```php
            'qualifikationen' => [
                'katalog' => 0, 'zeilen' => 0, 'platzhalter' => 0,
                'ohne_katalog' => 0, 'ohne_pnr' => 0,
                'pnr_gesamt' => 0, 'matched' => 0, 'unmatched' => 0,
                'employees_updated' => 0, 'employees_unchanged' => 0,
                'lookup_values_created' => 0, 'fehler' => [],
            ],
```

**3c** — die beiden Bloecke lesen, direkt hinter `$dispo2Rows = $split['known']['Dispo2'] ?? [];`:

```php
            $dispo4Rows = $split['known']['Dispo4'] ?? [];
            $dispo5Rows = $split['known']['Dispo5'] ?? [];
```

**3d** — den Frueh-Ausstieg erweitern, damit eine Lieferung mit **nur** Qualifikationsbloecken nicht als leer durchfaellt. Aus

```php
            if ($dispoRows === [] && $dispo2Rows === []) {
```

wird

```php
            if ($dispoRows === [] && $dispo2Rows === [] && $dispo5Rows === []) {
```

**3e** — den Aufruf einsetzen. Im **dry-run-Zweig** unmittelbar vor `return $summary;`, und im Live-Lauf unmittelbar **nach** dem Ende von `DB::transaction(...)` (vor dem `$file->update([...])`, das die Notes schreibt — die Zahlen sollen mit im Protokoll stehen):

```php
            $this->syncQualifikationen($dispo4Rows, $dispo5Rows, $matcher, $dryRun, $summary);
```

**3f** — die Methode selbst, hinter `import()`:

```php
    /**
     * Qualifikationen aus {Dispo4}/{Dispo5}. Bewusst AUSSERHALB der
     * Haupttransaktion und in eigenem try/catch: das sind Sekundaerdaten, ein
     * Fehler hier darf keine Einbuchung zurueckrollen.
     *
     * Eine Lieferung ohne {Dispo5} fasst bestehende Zuordnungen NICHT an —
     * sonst verlieren bei einer Teillieferung 1.409 Mitarbeiter ihre
     * Qualifikationen.
     *
     * @param list<array<string,string>> $dispo4
     * @param list<array<string,string>> $dispo5
     * @param array<string,mixed>        $summary
     */
    private function syncQualifikationen(array $dispo4, array $dispo5, ZasDispoMatcher $matcher, bool $dryRun, array &$summary): void
    {
        if ($dispo5 === []) {
            return;
        }

        try {
            $extract = DispoQualifikationExtractor::extract($dispo4, $dispo5);
            $q = &$summary['qualifikationen'];
            $q = array_merge($q, $extract['stats']);
            $q['pnr_gesamt'] = count($extract['byPnr']);

            $byEmployeeId = [];
            foreach ($extract['byPnr'] as $pnr => $labels) {
                $employeeId = $matcher->match((string) $pnr)['employee_id'];
                if ($employeeId === null) {
                    $q['unmatched']++;
                    continue;
                }
                $q['matched']++;
                // Zwei Personalnummern koennen auf denselben Mitarbeiter
                // zeigen (gekuerzte Form). Dann gewinnt die Vereinigung.
                foreach ($labels as $label) {
                    $byEmployeeId[$employeeId][mb_strtolower($label)] ??= $label;
                }
            }
            foreach ($byEmployeeId as $id => $liste) {
                $byEmployeeId[$id] = array_values($liste);
            }

            if ($dryRun) {
                return;
            }

            $r = $this->taetigkeiten->syncMany($byEmployeeId, $extract['namen']);
            $q['employees_updated']     = $r['updated'];
            $q['employees_unchanged']   = $r['unchanged'];
            $q['lookup_values_created'] = $r['created_values'];
        } catch (\Throwable $e) {
            $summary['qualifikationen']['fehler'][] = $e->getMessage();
            Log::error('ZAS dispo import: Qualifikations-Sync fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
```

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasDispoQualifikationImportTest`
Expected: PASS, 6 Tests.

- [ ] **Step 5: Mutationsprobe — faengt der Teillieferungs-Test wirklich?**

Den Frueh-Ausstieg `if ($dispo5 === []) { return; }` voruebergehend entfernen, Test laufen lassen.
Expected: FAIL in `test_delivery_without_dispo5_leaves_existing_qualifications_alone`. Danach zuruecksetzen und erneut Gruen pruefen.

- [ ] **Step 6: Die bestehenden Dispo-Importtests laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter Dispo`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Zas/Dispo/ZasDispoWebexportImporter.php tests/Integration/ZasDispoQualifikationImportTest.php
git commit -m "feat(recruiting): Webexport traegt ZAS-Qualifikationen je Mitarbeiter ein"
```

---

### Task 5: Den toten Pfad im MA-Importer entfernen

**Files:**
- Modify: `src/Services/Zas/ZasInboundEmployeeImporter.php:44-46,118-120,133,150-151,165-166,205-207`

**Interfaces:**
- Consumes: nichts.
- Produces: nichts. Reine Entfernung.

**Begruendung:** Entscheidung des Kunden am 08.10.2026 — „er wird das nicht liefern, wir machen den import dafuer dann dicht". Bliebe der Pfad stehen, schrieben spaeter zwei Quellen auf dasselbe Feld und ueberschrieben sich je nach Reihenfolge der Lieferungen. Gemessen: `DispoTaetigkeiten` kam in **0 von 579** Lieferungen vor.

- [ ] **Step 1: Pruefen, dass kein Test den Pfad abdeckt**

Run: `grep -rn "DispoTaetigkeiten" tests/`
Expected: keine Treffer. Gibt es doch welche, sind sie Teil dieser Aufgabe und werden mit entfernt.

- [ ] **Step 2: Die sechs Stellen entfernen**

In `src/Services/Zas/ZasInboundEmployeeImporter.php`:

**2a** — Konstruktor-Eigenschaft samt Kommentar streichen:

```php
        // Dispo-Taetigkeiten aus der Spalte DispoTaetigkeiten (Kunde 15.09.) —
        // eigener Dienst, weil ZAS hier fuehrend ist und den Stand ersetzt.
        private ZasDispoTaetigkeitSync $taetigkeiten = new ZasDispoTaetigkeitSync(),
```

**2b** — die Berechnung streichen:

```php
                    // Dispo-Taetigkeiten: ZAS ist fuehrend, der Stand wird ersetzt —
                    // unabhaengig von der sonstigen "nie ueberschreiben"-Regel, weil
                    // das Feld ausschliesslich aus ZAS gepflegt wird.
                    $taetRaw = array_key_exists('DispoTaetigkeiten', $row) ? (string) $row['DispoTaetigkeiten'] : null;
                    $taetChanged = $taetRaw !== null
                        && ZasDispoTaetigkeitSync::parse($taetRaw) !== (array) ($existing->hrData?->dispo_taetigkeiten ?? []);
```

**2c** — Zeile 133: `&& !$taetChanged` aus der Bedingung entfernen. Aus

```php
                    if ($changes === [] && $overwrite['employee'] === [] && $pnrFill === null && $companyFill === null && !$taetChanged) {
```

wird

```php
                    if ($changes === [] && $overwrite['employee'] === [] && $pnrFill === null && $companyFill === null) {
```

**2d** — Zeilen 150-151 streichen:

```php
                    if ($taetChanged) {
                        $changedFields[] = 'dispo_taetigkeiten';
                    }
```

**2e** — Zeilen 165-166 streichen:

```php
                    if ($taetChanged) {
                        $this->taetigkeiten->sync($existing, $taetRaw);
                    }
```

**2f** — Zeilen 205-207 streichen:

```php
                if (array_key_exists('DispoTaetigkeiten', $row)) {
                    $this->taetigkeiten->sync($employee, (string) $row['DispoTaetigkeiten']);
                }
```

**2g** — den `use`-Eintrag fuer `ZasDispoTaetigkeitSync` entfernen, falls er nach den Streichungen unbenutzt ist. `ZasDispoTaetigkeitSync` selbst bleibt bestehen — `sync()` und `parse()` werden weiter von Task 3 und den Tests genutzt.

- [ ] **Step 3: Syntax und Suche**

Run: `php -l src/Services/Zas/ZasInboundEmployeeImporter.php && grep -rn "taetChanged\|taetRaw\|DispoTaetigkeiten" src/`
Expected: `No syntax errors` und **keine** Treffer der Suche in `src/`.

- [ ] **Step 4: Gesamte Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Kommentar in der Migration richtigstellen**

In `database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php` den Kopfkommentar ersetzen durch:

```php
/**
 * Dispo-Taetigkeiten aus ZAS. Quelle sind die Bloecke {Dispo4} (Katalog) und
 * {Dispo5} (Zuordnung je Mitarbeiter) des Dispo-Webexports — NICHT die Spalte
 * DispoTaetigkeiten im MA-Export, fuer die dieses Feld urspruenglich gebaut
 * wurde (Kunde 15.09.2026). Diese Spalte kam in 0 von 579 Lieferungen vor; der
 * Pfad wurde am 08.10.2026 entfernt, siehe
 * docs/superpowers/specs/2026-10-08-zas-qualifikationen-dispo5-design.md.
 *
 * Eigenes Feld NEBEN 'qualifications': jenes pflegen wir selbst und
 * exportieren es als Spalte Qualifikation ZU ZAS. Beides in einem Feld hiesse,
 * ZAS seine eigenen Daten als Aenderung zurueckzuschicken.
 *
 * ZAS ist fuer dieses Feld fuehrend: jede Lieferung ersetzt den Stand.
 */
```

- [ ] **Step 6: Commit**

```bash
git add src/Services/Zas/ZasInboundEmployeeImporter.php database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php
git commit -m "refactor(recruiting): MA-Importer-Pfad fuer DispoTaetigkeiten entfernt"
```

---

### Task 6: MA-Akte zeigt den ganzen Katalog mit Haken

**Files:**
- Modify: `src/Livewire/Employees/Show.php:95` (Property), `:199-211` (Computed)
- Modify: `resources/views/livewire/employees/show.blade.php:57-96`
- Test: `tests/Integration/EmployeeTaetigkeitenKatalogTest.php` (neu)

**Interfaces:**
- Consumes: `rec_employee_hr_data.dispo_taetigkeiten` (Task 4 fuellt es), Auswahlliste `dispo_taetigkeit` (Task 3 fuellt sie mit dem ganzen Katalog).
- Produces:
  ```php
  #[Computed] public function dispoTaetigkeitenKatalog(): array
  // => list<array{label:string, hat:bool}>, alphabetisch, zugewiesene zuerst
  public string $taetigkeitenSuche = '';
  ```

**Was schon da ist und bleibt:** `public bool $showTaetigkeitenModal`, das Fenster selbst, der Hinweistext „Kommt aus ZAS und wird bei jeder Lieferung aktualisiert — hier nicht bearbeitbar", die Stand-Anzeige und die Chips. Nichts davon wird neu gebaut.

**Was sich aendert:** Der aeussere Block rendert bisher nur `@if ($taet['values'] !== [])` — fuer die 266 Neuzugaenge ohne Zuordnung gibt es damit keinen Weg ins Fenster. Er rendert kuenftig immer. Im Fenster steht nicht mehr nur das Zugewiesene, sondern der ganze Katalog mit Haken, dazu ein Suchfeld (271 Eintraege sind ohne Suche nicht benutzbar).

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

Datei `tests/Integration/EmployeeTaetigkeitenKatalogTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

/**
 * Die MA-Akte zeigt den ganzen ZAS-Katalog mit Haken, damit "kann der
 * Logistik?" beantwortbar ist. Gehakt ist, was ZAS zugewiesen hat — klickbar
 * ist nichts, ZAS ist fuer dieses Feld fuehrend.
 */
class EmployeeTaetigkeitenKatalogTest extends TestCase
{
    private const TEAM = 1101;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $container->instance('config', new ConfigRepository([]));

        // auth()->user()->currentTeam->id — die einzige Framework-Abhaengigkeit
        // der Komponente. Attrappe im Container, kein Umbau der Komponente:
        // getestet werden soll der Produktionspfad. Der auth()-Helper hat
        // einen Rueckgabetyp, die Attrappe muss die Schnittstelle wirklich
        // implementieren.
        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name) {}
        });

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_employees', 'rec_employee_hr_data', 'core_lookups', 'core_lookup_values'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private static function runMigrations(): void
    {
        $own  = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own,  'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own,  'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own,  'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own,  'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    /**
     * @param list<string> $zugewiesen
     * @param list<string> $katalog
     */
    private function komponente(array $zugewiesen, array $katalog, string $suche = ''): Show
    {
        $employee = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => 'RG1464', 'portal_token' => 'tok-1464', 'is_active' => true,
        ]);
        (new ZasDispoTaetigkeitSync())->syncMany([$employee->id => $zugewiesen], $katalog);

        // Baut die Komponente OHNE Livewire-Mount (kein Testbench) und
        // verdrahtet die #[Computed]-Getter von Hand — ohne das wirft jeder
        // Zugriff auf $this->employee eine PropertyNotFoundException.
        $c = new Show();
        $c->employeeId = $employee->id;
        $c->taetigkeitenSuche = $suche;
        $c->getAttributes()->each(function ($attribute) {
            if (method_exists($attribute, 'boot')) {
                $attribute->boot();
            }
        });

        return $c;
    }

    public function test_catalogue_marks_what_the_employee_has(): void
    {
        $c = $this->komponente(['Logistiker'], ['Servicekräfte', 'Logistiker', 'Kasse']);

        $this->assertSame(
            [
                ['label' => 'Logistiker', 'hat' => true],
                ['label' => 'Kasse', 'hat' => false],
                ['label' => 'Servicekräfte', 'hat' => false],
            ],
            $c->dispoTaetigkeitenKatalog(),
            'Zugewiesene zuerst, dahinter der Rest alphabetisch.'
        );
    }

    public function test_search_filters_the_catalogue(): void
    {
        $c = $this->komponente(['Logistiker'], ['Servicekräfte', 'Logistiker', 'Kasse'], suche: 'kas');

        $this->assertSame([['label' => 'Kasse', 'hat' => false]], $c->dispoTaetigkeitenKatalog());
    }

    public function test_search_ignores_case_and_surrounding_space(): void
    {
        $c = $this->komponente([], ['Servicekräfte', 'Kasse'], suche: '  KAS ');

        $this->assertSame([['label' => 'Kasse', 'hat' => false]], $c->dispoTaetigkeitenKatalog());
    }

    public function test_employee_without_assignment_still_sees_the_catalogue(): void
    {
        $c = $this->komponente([], ['Servicekräfte', 'Logistiker']);

        $this->assertCount(2, $c->dispoTaetigkeitenKatalog(),
            'Bei 266 Neuzugaengen ohne Zuordnung waere das Fenster sonst leer und unerreichbar.');
        $this->assertSame([false, false], array_column($c->dispoTaetigkeitenKatalog(), 'hat'));
    }

    public function test_assigned_value_missing_from_the_lookup_is_still_shown(): void
    {
        // Zuweisung von Hand setzen, ohne sie in die Auswahlliste zu legen —
        // so sieht es aus, wenn die Liste der Lieferung hinterherhinkt.
        $c = $this->komponente([], ['Servicekräfte']);
        Capsule::table('rec_employee_hr_data')
            ->where('rec_employee_id', $c->employeeId)
            ->update(['dispo_taetigkeiten' => json_encode(['Sonderposten'], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame(
            [['label' => 'Sonderposten', 'hat' => true], ['label' => 'Servicekräfte', 'hat' => false]],
            $c->dispoTaetigkeitenKatalog(),
            'Was der Mitarbeiter hat, verschwindet nie — auch wenn die Liste hinterherhinkt.'
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen, Rot pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EmployeeTaetigkeitenKatalogTest`
Expected: FAIL — `Call to undefined method ...::dispoTaetigkeitenKatalog()`.

- [ ] **Step 3: Property und Computed ergaenzen**

In `src/Livewire/Employees/Show.php` neben `public bool $showTaetigkeitenModal = false;`:

```php
    public string $taetigkeitenSuche = '';
```

Hinter die bestehende `dispoTaetigkeiten()`-Methode:

```php
    /**
     * Der ganze ZAS-Katalog mit Haken — damit die Frage "kann der Logistik?"
     * beantwortbar ist und nicht nur "was macht er ueblicherweise". Die Liste
     * kommt aus der Auswahlliste `dispo_taetigkeit`, die der Webexport bei
     * jeder Lieferung mit dem vollen Katalog fuettert.
     *
     * Zugewiesenes steht vorn und verschwindet nie, auch wenn die Auswahlliste
     * den Wert (noch) nicht kennt.
     *
     * @return list<array{label:string, hat:bool}>
     */
    #[Computed]
    public function dispoTaetigkeitenKatalog(): array
    {
        $hat = [];
        foreach ((array) ($this->employee?->hrData?->dispo_taetigkeiten ?? []) as $label) {
            $hat[mb_strtolower((string) $label)] = (string) $label;
        }

        $alle = $hat;
        $lookupId = DB::table('core_lookups')
            ->where('team_id', (int) $this->employee?->team_id)
            ->where('name', ZasDispoTaetigkeitSync::LOOKUP)
            ->value('id');
        if ($lookupId !== null) {
            foreach (DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('value') as $value) {
                $alle[mb_strtolower((string) $value)] ??= (string) $value;
            }
        }

        $suche = mb_strtolower(trim($this->taetigkeitenSuche));

        $out = [];
        foreach ($alle as $key => $label) {
            if ($suche !== '' && !str_contains($key, $suche)) {
                continue;
            }
            $out[] = ['label' => $label, 'hat' => isset($hat[$key])];
        }

        usort($out, function (array $a, array $b) {
            if ($a['hat'] !== $b['hat']) {
                return $a['hat'] ? -1 : 1;
            }
            return strnatcasecmp($a['label'], $b['label']);
        });

        return $out;
    }
```

Die `use`-Zeilen fuer `Illuminate\Support\Facades\DB` und `Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync` ergaenzen, falls noch nicht vorhanden.

- [ ] **Step 4: Test laufen lassen, Gruen pruefen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EmployeeTaetigkeitenKatalogTest`
Expected: PASS, 5 Tests.

- [ ] **Step 5: Das Fenster umbauen**

In `resources/views/livewire/employees/show.blade.php` den Block ab `@php $taet = $this->dispoTaetigkeiten; @endphp` ersetzen. Die aeussere Karte rendert jetzt immer:

```blade
            @php
                $taet = $this->dispoTaetigkeiten;
                $taetAnzahl = count($taet['values']);
            @endphp
            <div class="mb-3 rounded-lg border border-gray-200 bg-white p-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Dispo-Tätigkeiten (aus ZAS)</span>
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 tabular-nums">{{ $taetAnzahl }}</span>
                    <button type="button" wire:click="$set('showTaetigkeitenModal', true)" class="text-xs font-semibold text-blue-700 hover:underline">alle anzeigen</button>
                    @if ($taet['synced_at'])
                        <span class="text-[11px] text-gray-400">Stand {{ $taet['synced_at'] }}</span>
                    @endif
                </div>
                @if ($taetAnzahl === 0)
                    <p class="mt-2 text-sm text-gray-500">Noch keine Zuordnung aus ZAS. Bei neu angelegten Mitarbeitern ist das normal — sie kommt mit der ersten Disposition.</p>
                @else
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach (array_slice($taet['values'], 0, 12) as $t)
                            <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-800">{{ $t }}</span>
                        @endforeach
                        @if ($taetAnzahl > 12)
                            <button type="button" wire:click="$set('showTaetigkeitenModal', true)" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200">+{{ $taetAnzahl - 12 }} weitere</button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($showTaetigkeitenModal)
                @php $katalog = $this->dispoTaetigkeitenKatalog; @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showTaetigkeitenModal', false)">
                    <div class="flex max-h-[85dvh] w-full max-w-lg flex-col rounded-lg bg-white">
                        <div class="border-b border-gray-100 p-5 pb-3">
                            <h2 class="text-lg font-semibold">Dispo-Tätigkeiten <span class="text-sm font-normal text-gray-400">({{ $taetAnzahl }} von {{ count($katalog) }})</span></h2>
                            <p class="mt-1 text-sm text-gray-500">Kommt aus ZAS und wird bei jeder Lieferung aktualisiert — hier nicht bearbeitbar.@if ($taet['synced_at']) Stand: {{ $taet['synced_at'] }}.@endif</p>
                            <p class="mt-1 text-sm text-gray-500">Eigene Qualifikationen vergibst du weiter unten unter „Qualifikation & Altbestand".</p>
                            <input type="text" wire:model.live.debounce.250ms="taetigkeitenSuche" placeholder="Tätigkeit suchen …" class="mt-3 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-3">
                            @forelse ($katalog as $eintrag)
                                <div class="flex items-center gap-2 border-b border-gray-50 py-1.5 last:border-0">
                                    @if ($eintrag['hat'])
                                        <span class="flex h-4 w-4 items-center justify-center rounded border border-blue-600 bg-blue-600 text-[10px] font-bold text-white">✓</span>
                                        <span class="text-sm text-gray-900">{{ $eintrag['label'] }}</span>
                                    @else
                                        <span class="h-4 w-4 rounded border border-gray-300"></span>
                                        <span class="text-sm text-gray-400">{{ $eintrag['label'] }}</span>
                                    @endif
                                </div>
                            @empty
                                <p class="py-6 text-center text-sm text-gray-400">Keine Tätigkeit gefunden.</p>
                            @endforelse
                        </div>
                        <div class="flex justify-end border-t border-gray-100 p-4">
                            <button type="button" wire:click="$set('showTaetigkeitenModal', false)" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Schließen</button>
                        </div>
                    </div>
                </div>
            @endif
```

- [ ] **Step 6: Blade pruefen**

Run: `php tools/blade-check.php resources/views/livewire/employees/show.blade.php`
Expected: keine Fehler. (`php -l` prueft Blades **nicht** — nicht darauf verlassen.)

- [ ] **Step 7: Gesamte Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Employees/Show.php resources/views/livewire/employees/show.blade.php tests/Integration/EmployeeTaetigkeitenKatalogTest.php
git commit -m "feat(recruiting): MA-Akte zeigt den ZAS-Taetigkeitskatalog mit Haken"
```

---

## Nach dem letzten Task

- [ ] **Gesamte Suite ein letztes Mal:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
- [ ] **Vorschau auf prod gegen eine echte Lieferung**, bevor gemerged wird — der Importer hat einen dry-run, und die Zahlen muessen zu den gemessenen passen (1.442 PNr, ~1.409 matched, 271 Katalogeintraege, 30 `ohne_katalog`, 4 `platzhalter`).
- [ ] **Keine Migration noetig** — Feld und Auswahlliste existieren seit 15.09.2026. Der Deploy braucht `view:clear` wegen der Blade-Aenderung, **kein** `migrate`, **kein** `queue:restart`.
