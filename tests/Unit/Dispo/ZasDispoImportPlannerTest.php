<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;

class ZasDispoImportPlannerTest extends TestCase
{
    private ZasDispoImportPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ZasDispoImportPlanner();
    }

    /** @return array{0: list<array<string,string>>, 1: list<array<string,string>>} */
    private function rowsFromFixture(): array
    {
        $splitter = new ZasDispoBlockSplitter();
        $result = $splitter->split(
            "{Dispo}\r\n"
            . "19.05.2026;BHG<br/>Halle 33;RG14;RG19077;1;RG13450;830363;10:30;21:15;2;0;Servicekräfte;;27,49;\r\n"
            . "12.04.2026;FC;RG77;RG19063;1;;830999;13:00;17:30;1;0;Supervisor;;30,00;\r\n"
            . "{Dispo2}\r\n"
            . "12.04.2026;Rhein-Energie-Stadion<br/>Aachener Straße 999;RG19063;Supervisor 13:00-17:30;1;116340;1.FC Köln vs. Bremen;CGN;400;RG26;13:00;17:30;Köln;1. FC Köln GmbH & Co. KGaA ;;2;Supervisor;_;51;\r\n"
            . "13.04.2026;Rhein-Energie-Stadion<br/>Aachener Straße 999;RG19063;Abbau 09:00-12:00;2;116999;;CGN;400;RG27;09:00;12:00;Köln;;Schwarze Hose, weißes Hemd;2;Abbau;_;51;\r\n"
        );

        return [$result['known']['Dispo'], $result['known']['Dispo2']];
    }

    public function test_builds_event_from_dispo2_grouped_by_einsatz_id(): void
    {
        [$dispo, $dispo2] = $this->rowsFromFixture();
        $plan = $this->planner->plan($dispo, $dispo2, [], '2026-04-01');

        $event = $plan['events']['RG19063'];
        $this->assertSame('1.FC Köln vs. Bremen', $event['name']); // erster nicht-leerer Wert
        $this->assertSame("Rhein-Energie-Stadion\nAachener Straße 999", $event['venue_text']);
        $this->assertSame('Köln', $event['ort']);
        $this->assertSame('1. FC Köln GmbH & Co. KGaA', $event['einsatzfirma']);
        $this->assertSame('2026-04-12', $event['starts_on']);
        $this->assertSame('2026-04-13', $event['ends_on']);
        $this->assertSame('CGN', $event['source_meta']['filiale']);
        $this->assertSame('CGN', $event['filiale']);
        $this->assertSame('Schwarze Hose, weißes Hemd', $event['dresscode']); // erster nicht-leerer Wert (Zeile 12.04. war leer)
    }

    public function test_filiale_and_dresscode_are_null_when_source_field_empty(): void
    {
        $dispo2 = [[
            'datum' => '12.04.2026', 'text' => '', 'einsatz_id' => 'RG1',
            'taetigkeit_von_bis' => '', 'anzahl' => '', 'dispoposten_id' => '1',
            'projektbezeichnung' => '', 'filiale' => '', 'filial_nr' => '',
            'taetigk_id' => '', 'von' => '', 'bis' => '', 'ort' => '', 'einsatzfirma' => '',
            'mitarbeiter_info' => '', 'status_id' => '', 'taetigkeit' => '', 'interne_bem' => '', 'id_firma' => '',
        ]];
        $plan = $this->planner->plan([], $dispo2, [], '2026-04-01');

        $this->assertNull($plan['events']['RG1']['filiale']);
        $this->assertNull($plan['events']['RG1']['dresscode']);
    }

    public function test_builds_assignments_with_source_meta(): void
    {
        [$dispo, $dispo2] = $this->rowsFromFixture();
        $plan = $this->planner->plan($dispo, $dispo2, [], '2026-04-01');

        $a = $plan['assignments']['830363'];
        $this->assertSame('RG19077', $a['einsatz_ref']);
        $this->assertSame('RG14', $a['pnr_raw']);
        $this->assertSame('2026-05-19', $a['datum']);
        $this->assertSame('10:30', $a['von']);
        $this->assertSame('21:15', $a['bis']);
        $this->assertSame(2, $a['status_id']);
        $this->assertSame('Servicekräfte', $a['taetigkeit']);
        $this->assertSame(27.49, $a['source_meta']['verrechnungssatz']);
        $this->assertSame('RG13450', $a['source_meta']['tlp_nr']);
    }

    public function test_placeholder_event_for_assignment_without_dispo2(): void
    {
        [$dispo, $dispo2] = $this->rowsFromFixture();
        $plan = $this->planner->plan($dispo, $dispo2, [], '2026-04-01');

        // RG19077 kommt nur im {Dispo}-Block vor
        $this->assertArrayHasKey('RG19077', $plan['events']);
        $this->assertNull($plan['events']['RG19077']['name']);
        $this->assertSame(1, $plan['stats']['placeholder_events']);
        $this->assertTrue($plan['events']['RG19077']['is_placeholder']);
        $this->assertFalse($plan['events']['RG19063']['is_placeholder']);
    }

    public function test_missing_detection_only_for_delivered_gap(): void
    {
        [$dispo, $dispo2] = $this->rowsFromFixture();
        // 111 existiert bei uns (zukuenftig), fehlt in der Lieferung -> missing.
        // 830363 ist in der Lieferung enthalten -> nicht missing.
        $plan = $this->planner->plan($dispo, $dispo2, ['111', '830363'], '2026-04-01');
        $this->assertSame(['111'], $plan['missing_ds_refs']);
    }

    public function test_rows_without_key_are_skipped_and_counted(): void
    {
        $noDsId = [['datum' => '19.05.2026', 'einsatzfirma_kurz' => '', 'pnr' => 'RG1', 'einsatz_id' => 'RG9', 'ze' => '', 'tlp_nr' => '', 'ds_id' => '', 'von' => '', 'bis' => '', 'status_id' => '1', 'essengeld' => '', 'taetigkeit' => '', 'tlp_nr2' => '', 'verrechnungssatz' => '']];
        $noEinsatz = [['datum' => '19.05.2026', 'text' => 'x', 'einsatz_id' => '', 'taetigkeit_von_bis' => '', 'anzahl' => '', 'dispoposten_id' => '1', 'projektbezeichnung' => 'X', 'filiale' => '', 'filial_nr' => '', 'taetigk_id' => '', 'von' => '', 'bis' => '', 'ort' => '', 'einsatzfirma' => '', 'mitarbeiter_info' => '', 'status_id' => '', 'taetigkeit' => '', 'interne_bem' => '', 'id_firma' => '']];

        $plan = $this->planner->plan($noDsId, $noEinsatz, [], '2026-04-01');

        $this->assertSame([], $plan['assignments']);
        $this->assertSame([], $plan['events']);
        $this->assertSame(1, $plan['stats']['skipped_rows_without_ds_id']);
        $this->assertSame(1, $plan['stats']['skipped_rows_without_einsatz_id']);
    }

    public function test_second_delivery_overwrites_with_current_values(): void
    {
        // Gleiche Einsatz-ID, neuer Name -> Plan enthaelt den aktuellen Wert
        $dispo2 = [[
            'datum' => '12.04.2026', 'text' => 'Neuer Text', 'einsatz_id' => 'RG19063',
            'taetigkeit_von_bis' => '', 'anzahl' => '', 'dispoposten_id' => '1',
            'projektbezeichnung' => 'Neuer Name', 'filiale' => '', 'filial_nr' => '',
            'taetigk_id' => '', 'von' => '', 'bis' => '', 'ort' => '', 'einsatzfirma' => '',
            'mitarbeiter_info' => '', 'status_id' => '', 'taetigkeit' => '', 'interne_bem' => '', 'id_firma' => '',
        ]];
        $plan = $this->planner->plan([], $dispo2, [], '2026-04-01');
        $this->assertSame('Neuer Name', $plan['events']['RG19063']['name']);
    }
}
