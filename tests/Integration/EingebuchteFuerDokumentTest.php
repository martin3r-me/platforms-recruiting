<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\EingebuchteFuerDokument;

final class EingebuchteFuerDokumentTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_nur_auftrag_ohne_missing_mit_mitarbeiter_entdoppelt(): void
    {
        $eventId = DB::table('rec_dispo_events')->insertGetId(['uuid' => 'ev-1', 'einsatz_ref' => 'E-1', 'created_at' => now(), 'updated_at' => now()]);
        $zeile = fn (string $ds, ?int $emp, int $status, ?string $missing) => [
            'uuid' => 'as-' . $ds, 'ds_ref' => $ds, 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $emp,
            'pnr_raw' => 'RG1', 'datum' => '2026-12-31', 'status_id' => $status, 'missing_since' => $missing,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('rec_dispo_assignments')->insert([
            $zeile('1', 10, 1, null),
            $zeile('2', 10, 1, null),            // derselbe Mensch, zweiter Tag
            $zeile('3', 11, 0, null),            // Angebot
            $zeile('4', 12, 1, '2026-10-01'),    // verschwunden
            $zeile('5', null, 1, null),          // unbesetzter Platz
            $zeile('6', 9, 1, null),
        ]);

        $this->assertSame([9, 10], EingebuchteFuerDokument::ids($eventId));
        $this->assertSame([], EingebuchteFuerDokument::ids(999));
    }
}
