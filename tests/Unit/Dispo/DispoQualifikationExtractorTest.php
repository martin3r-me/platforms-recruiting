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
