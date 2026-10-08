<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\DokumentEmpfaengerSuche;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

final class DokumentEmpfaengerSucheTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        // Die Auswahllisten-Tabellen gehoeren dem Core; der Harness faehrt nur die eigenen Migrationen.
        $kern = dirname((new \ReflectionClass(\Platform\Core\Models\CoreLookup::class))->getFileName(), 3);
        (require $kern . '/database/migrations/2026_02_12_000003_create_core_lookups_tables.php')->up();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function filter(array $set = []): array
    {
        return array_merge(['suche' => '', 'firma' => '', 'aktiv' => 'active', 'taetigkeit' => '', 'event_id' => null], $set);
    }

    public function test_suche_firma_und_aktiv(): void
    {
        $this->anstellung(['first_name' => 'Anna', 'last_name' => 'Zwei', 'company' => 'RG', 'personnel_number' => 'RG1']);
        $this->anstellung(['first_name' => 'Ben', 'last_name' => 'Eins', 'company' => 'MA', 'personnel_number' => 'MA2']);
        $this->anstellung(['first_name' => 'Cem', 'last_name' => 'Drei', 'company' => 'RG', 'is_active' => false]);
        $this->anstellung(['first_name' => 'Fremd', 'last_name' => 'Team', 'team_id' => 99]);

        $alle = DokumentEmpfaengerSuche::finde(3, $this->filter());
        $this->assertSame(['Eins', 'Zwei'], array_map(fn ($r) => explode(' ', $r['name'])[1], $alle), 'aktiv, nach Nachname');

        $this->assertSame(['Anna Zwei'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['firma' => 'RG'])), 'name'));
        $this->assertSame(['Cem Drei'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['aktiv' => 'inactive'])), 'name'));
        $this->assertCount(3, DokumentEmpfaengerSuche::finde(3, $this->filter(['aktiv' => 'all'])));
        $this->assertSame(['Ben Eins'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'MA2'])), 'name'));
        $this->assertSame(['Anna Zwei'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'zwei'])), 'name'));
    }

    public function test_taetigkeit_filtert_ueber_den_zas_katalog(): void
    {
        $a = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'A']);
        $b = $this->anstellung(['first_name' => 'Ben', 'last_name' => 'B']);
        DB::table('rec_employee_hr_data')->insert([
            ['uuid' => 'hr-a', 'team_id' => 3, 'rec_employee_id' => $a->id, 'dispo_taetigkeiten' => json_encode(['Küchenchef', 'Logistiker']), 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'hr-b', 'team_id' => 3, 'rec_employee_id' => $b->id, 'dispo_taetigkeiten' => json_encode(['Servicekräfte']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $lookupId = DB::table('core_lookups')->insertGetId(['team_id' => 3, 'name' => ZasDispoTaetigkeitSync::LOOKUP, 'label' => 'x', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Servicekräfte', 'Küchenchef', 'Logistiker'] as $v) {
            DB::table('core_lookup_values')->insert(['lookup_id' => $lookupId, 'value' => $v, 'label' => $v, 'order' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(['Anna A'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['taetigkeit' => 'küchenchef'])), 'name'), 'Vergleich in Kleinschreibung, Umlaut im JSON');
        $this->assertSame(['Ben B'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['taetigkeit' => 'Servicekräfte'])), 'name'));
        $this->assertSame(['Küchenchef', 'Logistiker', 'Servicekräfte'], DokumentEmpfaengerSuche::taetigkeiten(3));
    }

    public function test_veranstaltung_filtert_auf_eingebuchte(): void
    {
        $a = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'A']);
        $b = $this->anstellung(['first_name' => 'Ben', 'last_name' => 'B']);
        $eventId = DB::table('rec_dispo_events')->insertGetId(['uuid' => 'ev-1', 'einsatz_ref' => 'E-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rec_dispo_assignments')->insert([
            ['uuid' => 'as-1', 'ds_ref' => 'DS-1', 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $a->id, 'pnr_raw' => 'RG1', 'datum' => '2026-12-31', 'status_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'as-2', 'ds_ref' => 'DS-2', 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $b->id, 'pnr_raw' => 'RG2', 'datum' => '2026-12-31', 'status_id' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(['Anna A'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['event_id' => $eventId])), 'name'));
    }

    public function test_grenze_meldet_abgeschnittene_liste(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->anstellung(['first_name' => 'X', 'last_name' => $n]);
        }
        $r = DokumentEmpfaengerSuche::findeMitGrenze(3, $this->filter(), 2);
        $this->assertCount(2, $r['treffer']);
        $this->assertTrue($r['abgeschnitten']);
        $this->assertSame(['X A', 'X B'], array_column($r['treffer'], 'name'));

        $r = DokumentEmpfaengerSuche::findeMitGrenze(3, $this->filter(['suche' => 'A']), 2);
        $this->assertFalse($r['abgeschnitten']);
        $r = DokumentEmpfaengerSuche::findeMitGrenze(3, $this->filter(), 3);
        $this->assertCount(3, $r['treffer']);
        $this->assertFalse($r['abgeschnitten'], 'genau am Limit ist nicht abgeschnitten');
    }
}
