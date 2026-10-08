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
