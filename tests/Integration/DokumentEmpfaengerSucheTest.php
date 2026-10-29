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

    /** 29.10.: ein Mensch mit drei Anstellungen ist EINE Zeile mit allen Nummern; getragen von der gebundenen. */
    public function test_eine_zeile_je_person_mit_allen_nummern(): void
    {
        $rg = $this->anstellung(['first_name' => 'Clara', 'last_name' => 'Domes', 'company' => 'RG', 'personnel_number' => 'RG14280', 'rec_person_id' => 5]);
        $ma1 = $this->anstellung(['first_name' => 'Clara', 'last_name' => 'Domes', 'company' => 'MA', 'personnel_number' => 'MA14912', 'rec_person_id' => 5]);
        $this->anstellung(['first_name' => 'Clara', 'last_name' => 'Domes', 'company' => 'MA', 'personnel_number' => 'MA18696', 'rec_person_id' => 5]);
        $this->anstellung(['first_name' => 'Clara', 'last_name' => 'Setzkorn', 'company' => 'RG', 'personnel_number' => 'RG9894']);

        $treffer = DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'clara']));
        $this->assertSame(['Clara Domes', 'Clara Setzkorn'], array_column($treffer, 'name'));
        $this->assertSame($rg->id, $treffer[0]['id'], 'Traeger: kleinste gebundene Anstellung');
        $this->assertSame('RG14280 · MA14912 · MA18696', $treffer[0]['nummern']);
        $this->assertSame('RG9894', $treffer[1]['nummern']);

        // Firma MA trifft die Person ueber eine ihrer Anstellungen — mit allen Nummern, eine Zeile, getragen von MA.
        $ma = DokumentEmpfaengerSuche::finde(3, $this->filter(['firma' => 'MA']));
        $this->assertCount(1, $ma);
        $this->assertSame($ma1->id, $ma[0]['id']);
        $this->assertSame('RG14280 · MA14912 · MA18696', $ma[0]['nummern']);

        // Die Auswahl, egal ueber welche Anstellung angehakt, ist dieselbe eine Zeile.
        $auswahl = DokumentEmpfaengerSuche::zeilenFuerIds(3, [$ma1->id, $rg->id]);
        $this->assertCount(1, $auswahl);
        $this->assertSame($rg->id, $auswahl[0]['id']);
    }

    /** Ohne Filter traegt die aktive gebundene Anstellung, auch wenn eine inaktive die kleinere id hat. */
    public function test_traeger_ist_unabhaengig_vom_filter(): void
    {
        $alt = $this->anstellung(['first_name' => 'Eva', 'last_name' => 'Alt', 'company' => 'RG', 'personnel_number' => 'RG1', 'rec_person_id' => 6, 'is_active' => false]);
        $neu = $this->anstellung(['first_name' => 'Eva', 'last_name' => 'Alt', 'company' => 'MA', 'personnel_number' => 'MA2', 'rec_person_id' => 6]);

        $treffer = DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'Eva']));
        $this->assertSame($neu->id, $treffer[0]['id'], 'aktive gebundene vor inaktiver');
        $this->assertSame('MA2', $treffer[0]['nummern'], 'aktiv-Filter: nur aktive Nummern');
        $this->assertSame($alt->id, DokumentEmpfaengerSuche::zeilenFuerIds(3, [$alt->id])[0]['id'], 'die gewaehlte Anstellung bleibt');
        $this->assertSame('RG1 · MA2', DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'Eva', 'aktiv' => 'all']))[0]['nummern']);
    }

    /** Sebastian 29.10.: als MA eingebucht → die MA-Anstellung traegt (Unterschrift gehoert zu dieser Anstellung). */
    public function test_filter_bestimmt_die_tragende_anstellung(): void
    {
        $rg = $this->anstellung(['first_name' => 'Mia', 'last_name' => 'Zwei', 'company' => 'RG', 'personnel_number' => 'RG7', 'rec_person_id' => 9]);
        $ma = $this->anstellung(['first_name' => 'Mia', 'last_name' => 'Zwei', 'company' => 'MA', 'personnel_number' => 'MA143424', 'rec_person_id' => 9]);
        $eventId = DB::table('rec_dispo_events')->insertGetId(['uuid' => 'ev-m', 'einsatz_ref' => 'E-M', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rec_dispo_assignments')->insert(['uuid' => 'as-m', 'ds_ref' => 'DS-M', 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $ma->id, 'pnr_raw' => 'MA143424', 'datum' => '2026-12-31', 'status_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $ev = DokumentEmpfaengerSuche::finde(3, $this->filter(['event_id' => $eventId]));
        $this->assertSame($ma->id, $ev[0]['id'], 'eingebuchte Anstellung traegt');
        $this->assertSame('MA143424', $ev[0]['traeger_nummer']);
        $this->assertSame('RG7 · MA143424', $ev[0]['nummern']);

        $this->assertSame($ma->id, DokumentEmpfaengerSuche::finde(3, $this->filter(['firma' => 'MA']))[0]['id']);
        $this->assertSame($rg->id, DokumentEmpfaengerSuche::finde(3, $this->filter())[0]['id'], 'ohne Filter: Standardregel');

        // Die Auswahl behaelt die gewaehlte Anstellung.
        $this->assertSame($ma->id, DokumentEmpfaengerSuche::zeilenFuerIds(3, [$ma->id])[0]['id']);
        $this->assertSame('MA143424', DokumentEmpfaengerSuche::zeilenFuerIds(3, [$ma->id])[0]['traeger_nummer']);
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
