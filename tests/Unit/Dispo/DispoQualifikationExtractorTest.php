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

    public function test_line_count_includes_all_rows_from_dispo5(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('', 'RG8', '1'),                    // ohne_pnr
            $this->row('RG14', 'RG8', '5'),                // platzhalter
            $this->row('RG1464', 'RG9999', '1'),           // ohne_katalog
            $this->row('RG1465', 'RG8', '17'),             // accepted
        ]);

        // Alle vier Zeilen werden gezaehlt, unabhaengig vom Filterausgang.
        $this->assertSame(4, $r['stats']['zeilen']);
        // Die Filterung in Zahlen: ohne_pnr + platzhalter + ohne_katalog + accepted.
        $this->assertSame(1, $r['stats']['ohne_pnr']);
        $this->assertSame(1, $r['stats']['platzhalter']);
        $this->assertSame(1, $r['stats']['ohne_katalog']);
        $this->assertSame(['RG1465'], array_keys($r['byPnr']),
            'Nur die akzeptierte Zeile landet in byPnr.');
        $this->assertSame(1 + 1 + 1 + 1, $r['stats']['zeilen'],
            'ohne_pnr + platzhalter + ohne_katalog + 1 accepted = 4 gesamt.');
    }

    public function test_case_insensitive_deduplication_uses_canonical_spelling(): void
    {
        $r = DispoQualifikationExtractor::extract([
            ['nr' => '13',  'name' => 'Logistiker',  'code' => 'RG13',  'col_3' => ''],
            ['nr' => '271', 'name' => 'logistiker',  'code' => 'RG271', 'col_3' => ''],
            ['nr' => '8',   'name' => 'Servicekräfte', 'code' => 'RG8',  'col_3' => ''],
        ], [
            $this->row('RG1464', 'RG13', '5'),       // erste Schreibweise
            $this->row('RG1464', 'RG271', '2'),      // zweite Schreibweise
            $this->row('RG1465', 'RG271', '3'),      // nur zweite Schreibweise
        ]);

        // Namen hat die kanonische Schreibweise (erste gelieferte) genau einmal.
        $this->assertSame(['Logistiker', 'Servicekräfte'], $r['namen']);
        // RG1464 bekommt beide IDs, aber den Namen nur einmal mit kanonischer Schreibweise.
        $this->assertSame(['Logistiker'], $r['byPnr']['RG1464']);
        // RG1465 hat nur RG271, bekommt aber ebenfalls kanonische Schreibweise.
        $this->assertSame(['Logistiker'], $r['byPnr']['RG1465'],
            'Schreibweise immer kanonisch aus namen, nicht aus dem Katalog.');
    }

    public function test_placeholder_personnel_numbers_are_case_insensitive(): void
    {
        $r = DispoQualifikationExtractor::extract($this->katalog(), [
            $this->row('rg14', 'RG8', '5'),          // lowercase variant
            $this->row('RG14', 'RG8', '3'),          // original case
            $this->row('rg0', 'RG8', '2'),           // lowercase variant
            $this->row('RG1464', 'RG8', '17'),       // valid employee
        ]);

        $this->assertArrayNotHasKey('rg14', $r['byPnr'],
            'Auch lowercase rg14 ist ein Platzhalter und muss gefiltert werden.');
        $this->assertArrayNotHasKey('RG14', $r['byPnr']);
        $this->assertArrayNotHasKey('rg0', $r['byPnr']);
        $this->assertSame(['RG1464'], array_keys($r['byPnr']));
        $this->assertSame(3, $r['stats']['platzhalter'],
            'Alle drei Platzhalter-Varianten werden gezaehlt, unabhaengig von Gross-/Kleinschreibung.');
    }
}
