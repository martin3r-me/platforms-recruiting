<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\CohortAssigner;

class CohortAssignerTest extends TestCase
{
    private function applicant(int $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id, 'is_test' => false, 'applied_at' => '2026-07-01',
            'duplicate' => false, 'unrouted' => false, 'import' => false,
            'parked' => false, 'rejected' => false, 'hr_desk' => false,
            'phase_position_id' => null, 'phase_name' => null, 'phase_order' => null,
            'enrichment_status' => 'enriched', 'contract_sent' => false,
            'contract_signed' => false, 'applied_to_signed_days' => null,
        ];
    }

    private function booking(int $id, array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => $id, 'interview_id' => 10, 'status' => 'booked',
            'seat_released' => false, 'starts_at' => '2026-08-10 09:00:00', 'deleted' => false,
        ];
    }

    public function test_praezedenz_kette_erster_treffer_gewinnt(): void
    {
        $result = (new CohortAssigner())->assign([
            $this->applicant(1, ['is_test' => true, 'duplicate' => true]),   // raus
            $this->applicant(2, ['applied_at' => null, 'duplicate' => true]), // Stufe 2 vor 3
            $this->applicant(3, ['duplicate' => true, 'unrouted' => true]),   // Stufe 3 vor 4
            $this->applicant(4, ['unrouted' => true, 'import' => true]),      // Stufe 4 vor 5
            $this->applicant(5, ['import' => true]),                          // Stufe 5 vor 6 (mit Buchung!)
            $this->applicant(6, ['parked' => true]),                          // Stufe 6 vor 7 (mit Buchung!)
            $this->applicant(7, ['parked' => true]),                          // Stufe 7
            $this->applicant(8),                                              // Stufe 8
        ], [
            5 => [$this->booking(50)],
            6 => [$this->booking(60)],
        ], [], null, null);

        $typeById = [];
        foreach ($result['rows'] as $row) {
            foreach ($row['ids'] as $id) {
                $typeById[$id] = $row['type'];
            }
        }
        $this->assertArrayNotHasKey(1, $typeById, 'is_test ist raus');
        $this->assertSame('ohne_datum', $typeById[2]);
        $this->assertSame('dublette', $typeById[3]);
        $this->assertSame('unrouted', $typeById[4]);
        $this->assertSame('import', $typeById[5], 'Import schlaegt Buchung (Spec Stufe 5)');
        $this->assertSame('schulung', $typeById[6], 'Buchung schlaegt geparkt (Stufe 6 vor 7)');
        $this->assertSame('geparkt', $typeById[7]);
        $this->assertSame('ohne_schulung', $typeById[8]);
    }

    public function test_rekonziliation_jeder_genau_einmal(): void
    {
        $applicants = [];
        foreach (range(1, 30) as $i) {
            $applicants[] = $this->applicant($i, [
                'duplicate' => $i % 5 === 0, 'unrouted' => $i % 7 === 0,
                'import' => $i % 3 === 0, 'parked' => $i % 4 === 0,
                'applied_at' => $i % 11 === 0 ? null : '2026-07-01',
            ]);
        }
        $result = (new CohortAssigner())->assign($applicants, [2 => [$this->booking(1)]], [], null, null);

        $seen = [];
        foreach ($result['rows'] as $row) {
            foreach ($row['ids'] as $id) {
                $this->assertArrayNotHasKey($id, $seen, "Bewerber $id doppelt");
                $seen[$id] = true;
            }
        }
        $this->assertSame(count($result['total_ids']), count($seen), 'Gesamt = Summe der Zeilen');
        $this->assertCount(30, $result['total_ids']);
    }

    public function test_unbekannter_status_eigene_zeile_statt_schulung(): void
    {
        $result = (new CohortAssigner())->assign(
            [$this->applicant(1)],
            [1 => [$this->booking(11, ['status' => 'weird_value'])]],
            [], null, null
        );
        $types = array_column($result['rows'], 'type');
        $this->assertContains('unbekannter_status', $types);
        $this->assertNotContains('schulung', $types);
    }

    public function test_tie_break_neueste_buchung_spaetester_start_dann_kleinste_id(): void
    {
        $result = (new CohortAssigner())->assign(
            [$this->applicant(1)],
            [1 => [
                $this->booking(11, ['interview_id' => 100, 'starts_at' => '2026-08-01 09:00:00']),
                $this->booking(12, ['interview_id' => 200, 'starts_at' => '2026-08-20 09:00:00']),
                $this->booking(13, ['interview_id' => 300, 'starts_at' => '2026-08-20 09:00:00']),
            ]],
            [], null, null
        );
        $schulung = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'schulung'))[0];
        // spaetester starts_at gewinnt; bei Gleichstand kleinste Booking-ID → Interview 200
        $this->assertSame('schulung:200', $schulung['key']);
    }

    public function test_zeitraumfilter_mit_null_ausnahme(): void
    {
        $result = (new CohortAssigner())->assign([
            $this->applicant(1, ['applied_at' => '2026-06-01']), // vor Zeitraum → raus
            $this->applicant(2, ['applied_at' => '2026-07-15']), // drin
            $this->applicant(3, ['applied_at' => null]),          // NULL faellt NIE still raus
        ], [], [], '2026-07-01', '2026-07-31');

        $this->assertSame([2, 3], $result['total_ids']);
    }

    public function test_leerstring_datum_verhaelt_sich_wie_null(): void
    {
        // P6: Livewire liefert '' fuer geleerte Datumsfelder — darf die
        // Tabelle nicht leeren ("Von" gesetzt, "Bis" geleert).
        $a = [$this->applicant(1, ['applied_at' => '2026-07-05'])];
        $withEmpty = (new CohortAssigner())->assign($a, [], [], '2026-07-01', '');
        $withNull = (new CohortAssigner())->assign($a, [], [], '2026-07-01', null);
        $this->assertSame($withNull['total_ids'], $withEmpty['total_ids']);
        $this->assertSame([1], $withEmpty['total_ids']);
    }

    public function test_gruppen_fallbacks_und_hr_desk_marker(): void
    {
        $result = (new CohortAssigner())->assign(
            [$this->applicant(1, ['hr_desk' => true])],
            [1 => [$this->booking(11)]],
            [], null, null
        );
        $schulung = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'schulung'))[0];
        $this->assertSame([1], $schulung['hr_desk_ids'], 'HR-Desk ist Marker, kein Zeilentyp');
        $this->assertSame('ohne Ausschreibung', $schulung['group']['ort'], 'Gruppen-Fallback Fall 3');
    }

    public function test_funnel_spalten_kumulativ(): void
    {
        $result = (new CohortAssigner())->assign(
            [
                $this->applicant(1), $this->applicant(2), $this->applicant(3),
                $this->applicant(4, ['contract_signed' => true, 'contract_sent' => true, 'applied_to_signed_days' => 12]),
            ],
            [
                1 => [$this->booking(11, ['status' => 'booked'])],
                2 => [$this->booking(12, ['status' => 'confirmed'])],
                3 => [$this->booking(13, ['status' => 'no_show'])],
                4 => [$this->booking(14, ['status' => 'attended'])],
            ],
            [], null, null
        );
        $row = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'schulung'))[0];
        $this->assertSame([1, 2, 3, 4], $row['columns']['gebucht'], 'Rang>=1: alle');
        $this->assertSame([2, 3, 4], $row['columns']['bestaetigt'], 'Rang>=2 inkl. no_show');
        $this->assertSame([4], $row['columns']['teilgenommen'], 'Rang>=3 OHNE no_show');
        $this->assertSame([3], $row['columns']['no_show']);
        $this->assertSame([4], $row['columns']['unterschrieben']);
        $this->assertSame([12], $row['tth_days'], 'tth haengt an der Zeile (P5)');
    }
}
