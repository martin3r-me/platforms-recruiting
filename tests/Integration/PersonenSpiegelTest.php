<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonenSpiegel;

/**
 * PersonenSpiegel: Personenfelder auf Geschwister-Akten (Spec 2026-10-09).
 */
class PersonenSpiegelTest extends TestCase
{
    use SpiegelHarness;

    public static function setUpBeforeClass(): void
    {
        self::baueWelt(false);
    }

public function test_schreibt_abweichende_felder_auf_das_geschwister_mit_marker_und_lohn(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'street' => 'Neu 1', 'iban' => 'DE02']);
    $ma = $this->akte(['rec_person_id' => $p, 'street' => 'Alt 9', 'iban' => 'DE01']);
    $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['iban', 'street']]);

    $ids = (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'Neu 1', 'iban' => 'DE02'], true, true);

    $this->assertSame([$ma], $ids);
    $this->assertSame('Neu 1', $this->zeile($ma)->street);
    $this->assertNotNull($this->zeile($ma)->zas_changed_at);
    $this->assertNull($this->zeile($rg)->zas_changed_at, 'Quelle fasst der Spiegel nie an');
    $felder = array_column($this->lohn($ma), 'old', 'field');
    $this->assertSame(['street' => 'Alt 9', 'iban' => 'DE01'], $felder);
}

public function test_gleiche_werte_beruehren_nichts(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'street' => 'A 1']);
    $ma = $this->akte(['rec_person_id' => $p, 'street' => ' A 1 ']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'A 1'], true, true));
    $this->assertNull($this->zeile($ma)->zas_changed_at);
}

public function test_datum_mit_uhrzeit_ist_kein_unterschied(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'birth_date' => '1990-01-01']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['birth_date' => '1990-01-01 00:00:00'], true, true));
}

public function test_bool_true_und_eins_sind_gleich(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'is_main_employer' => 1]);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['is_main_employer' => true], true, true));
}

public function test_gesellschaftsfelder_werden_ignoriert(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'cost_center' => 'K1', 'personnel_number' => 'MA1']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['cost_center' => 'K9', 'personnel_number' => 'RG1'], true, true));
    $this->assertSame('K1', $this->zeile($ma)->cost_center);
}

public function test_steuerklasse_je_gesellschaft_wird_nicht_gespiegelt(): void
{
    $this->setzeEinstellung(['tax_class_per_company' => true]);
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'tax_class' => '1', 'city' => 'Alt']);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['tax_class' => '6', 'city' => 'Neu'], true, true);
    $this->assertSame('1', (string) $this->zeile($ma)->tax_class);
    $this->assertSame('Neu', $this->zeile($ma)->city);
}

public function test_einzelperson_ohne_klammer_tut_nichts(): void
{
    $rg = $this->akte(['street' => 'X']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'Y'], true, true));
}

public function test_abweichende_nummer_unter_gleichem_marker_wird_nicht_beschrieben(): void
{
    $rg = $this->akte(['person_key' => 'k1', 'phone' => '+491701111111', 'city' => 'A']);
    $gleich = $this->akte(['person_key' => 'k1', 'phone' => '01701111111', 'city' => 'B']);
    $fremd = $this->akte(['person_key' => 'k1', 'phone' => '+491709999999', 'city' => 'C']);
    $ids = (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['city' => 'A'], true, true);
    $this->assertSame([$gleich], $ids);
    $this->assertSame('C', $this->zeile($fremd)->city);
}

public function test_ohne_marker_und_ohne_lohn(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'iban' => 'DE01']);
    $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['iban']]);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['iban' => 'DE02'], false, false);
    $this->assertSame('DE02', $this->zeile($ma)->iban);
    $this->assertNull($this->zeile($ma)->zas_changed_at);
    $this->assertSame([], $this->lohn($ma));
}

public function test_schalter_nimmt_auch_haupt_und_nebenarbeitgeber_aus(): void
{
    $this->setzeEinstellung(['tax_class_per_company' => true]);
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'is_main_employer' => 1, 'other_employer' => null, 'city' => 'Alt']);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['is_main_employer' => false, 'other_employer' => 'MA GmbH', 'city' => 'Neu'], true, true);
    $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
    $this->assertNull($this->zeile($ma)->other_employer);
    $this->assertSame('Neu', $this->zeile($ma)->city);
}

public function test_spiegel_legt_keine_einstellungs_zeile_an(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'tax_class' => '1']);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['city' => 'Neu', 'tax_class' => '6'], false, false);
    $this->assertSame('6', (string) $this->zeile($ma)->tax_class, 'ohne Einstellung: Schalter aus');
    $this->assertSame(0, \Illuminate\Database\Capsule\Manager::table('rec_applicant_settings')->count());
}

public function test_nur_in_leere_felder_schreibt_nicht_ueber_vorhandene_werte(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'street' => 'Bleibt', 'city' => null]);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'Neu', 'city' => 'Neu'], true, false, nurInLeere: ['street', 'city']);
    $this->assertSame('Bleibt', $this->zeile($ma)->street);
    $this->assertSame('Neu', $this->zeile($ma)->city);
}
}
