<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ProofMigrationPlanner;

/**
 * Der Umzug laeuft einmal ueber 1.558 Datensaetze. Was er falsch macht, steht
 * danach im Portal — deshalb ist die Entdoppelung je Person hier getestet und
 * nicht erst im Kommando.
 */
final class ProofMigrationPlannerTest extends TestCase
{
    private function zeile(int $id, ?string $key, array $spalten = []): array
    {
        return array_merge(['id' => $id, 'person_key' => $key], $spalten);
    }

    public function test_ohne_datei_kein_nachweis(): void
    {
        $plan = ProofMigrationPlanner::plan([$this->zeile(1, null)]);

        $this->assertSame([], $plan);
    }

    public function test_zieht_ausweis_mit_beiden_seiten_und_datum_um(): void
    {
        $plan = ProofMigrationPlanner::plan([$this->zeile(1, 'p-1', [
            'identity_card_front_file_id' => 111,
            'identity_card_back_file_id'  => 222,
            'identity_card_valid_until'   => '2030-01-31 00:00:00',
        ])]);

        $this->assertCount(1, $plan);
        $this->assertSame('ausweis', $plan[0]['proof_type_code']);
        $this->assertSame(111, $plan[0]['file_id']);
        $this->assertSame(222, $plan[0]['file_back_id']);
        $this->assertSame('2030-01-31', $plan[0]['valid_until'], 'Zeitanteil faellt weg');
        $this->assertSame('p-1', $plan[0]['person_key']);
    }

    public function test_rueckseite_ohne_vorderseite_wird_uebergangen(): void
    {
        $plan = ProofMigrationPlanner::plan([$this->zeile(1, null, [
            'identity_card_back_file_id' => 222,
        ])]);

        $this->assertSame([], $plan, 'eine Rueckseite allein ist ein Datenfehler, kein Nachweis');
    }

    public function test_dieselbe_person_bekommt_je_art_nur_einen_nachweis(): void
    {
        $plan = ProofMigrationPlanner::plan([
            $this->zeile(1, 'p-1', ['identity_card_front_file_id' => 111]),
            $this->zeile(2, 'p-1', ['identity_card_front_file_id' => 111]),
        ]);

        $this->assertCount(1, $plan, 'sonst saehe der Mensch seinen Ausweis im Portal doppelt');
    }

    public function test_der_datensatz_mit_gueltig_bis_gewinnt(): void
    {
        $plan = ProofMigrationPlanner::plan([
            $this->zeile(5, 'p-1', ['identity_card_front_file_id' => 111]),
            $this->zeile(2, 'p-1', ['identity_card_front_file_id' => 999, 'identity_card_valid_until' => '2030-01-31']),
        ]);

        $this->assertCount(1, $plan);
        $this->assertSame(999, $plan[0]['file_id'], 'wer mehr weiss, gewinnt — auch wenn er aelter ist');
        $this->assertSame(2, $plan[0]['rec_employee_id']);
    }

    public function test_bei_gleichem_wissen_gewinnt_der_juengere(): void
    {
        $plan = ProofMigrationPlanner::plan([
            $this->zeile(2, 'p-1', ['identity_card_front_file_id' => 111]),
            $this->zeile(7, 'p-1', ['identity_card_front_file_id' => 999]),
        ]);

        $this->assertSame(7, $plan[0]['rec_employee_id']);
    }

    public function test_verschiedene_personen_bleiben_getrennt(): void
    {
        $plan = ProofMigrationPlanner::plan([
            $this->zeile(1, 'p-1', ['identity_card_front_file_id' => 111]),
            $this->zeile(2, 'p-2', ['identity_card_front_file_id' => 222]),
        ]);

        $this->assertCount(2, $plan);
    }

    public function test_ohne_marker_zaehlt_jede_anstellung_fuer_sich(): void
    {
        $plan = ProofMigrationPlanner::plan([
            $this->zeile(1, null, ['identity_card_front_file_id' => 111]),
            $this->zeile(2, null, ['identity_card_front_file_id' => 111]),
        ]);

        $this->assertCount(2, $plan, 'ohne Marker wissen wir nicht, dass es dieselbe Person ist');
    }

    public function test_schul_und_immatrikulationsbescheinigung_teilen_sich_das_datum(): void
    {
        $plan = ProofMigrationPlanner::plan([$this->zeile(1, 'p-1', [
            'immatrikulation_file_id'        => 111,
            'schulbescheinigung_file_id'     => 222,
            'school_certificate_valid_until' => '2027-03-31',
        ])]);

        $this->assertCount(2, $plan);
        foreach ($plan as $eintrag) {
            $this->assertSame('2027-03-31', $eintrag['valid_until'],
                'beide Arten lasen bisher dieselbe Altspalte — ab jetzt hat jede ihr eigenes Datum');
        }
    }

    public function test_leere_und_kaputte_werte_werden_ignoriert(): void
    {
        $plan = ProofMigrationPlanner::plan([$this->zeile(1, 'p-1', [
            'identity_card_front_file_id' => 111,
            'identity_card_valid_until'   => '0000-00-00',
        ])]);

        $this->assertNull($plan[0]['valid_until'], 'ein unbrauchbares Datum wird nicht mitgeschleppt');
    }
}
