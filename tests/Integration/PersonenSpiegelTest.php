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
        self::baueWelt(false, ['2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees']);
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
    // Bis 12.10.2026 mit is_main_employer — das ist seitdem kein Personenfeld mehr.
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'is_eu_citizen' => 1]);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['is_eu_citizen' => true], true, true));
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
    // Seit 12.10.2026 (Markus, E7) immer — ohne Schalter.
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

public function test_haupt_und_nebenarbeitgeber_werden_nie_gespiegelt(): void
{
    // Seit 12.10.2026 (Markus, E8) immer — ohne Schalter.
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
    $this->assertSame('1', (string) $this->zeile($ma)->tax_class, 'seit 12.10.2026 nie gespiegelt, auch ohne Einstellung');
    $this->assertSame('Neu', $this->zeile($ma)->city);
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

public function test_krankenkasse_wird_nie_gespiegelt(): void
{
    // Generalprobe 13.10.2026: MA-Minijob -> Knappschaft, je Anstellung verschieden.
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'health_insurance' => 'aok']);
    $ma = $this->akte(['rec_person_id' => $p, 'health_insurance' => 'knappschaft']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['health_insurance' => 'aok'], true, true));
    $this->assertSame('knappschaft', $this->zeile($ma)->health_insurance);
    $this->assertNull($this->zeile($ma)->zas_changed_at);
}

    /** Spec 2026-10-22 §4: Ersthelfer gehoert dem Menschen. */
    public function test_ersthelfer_wird_gespiegelt_mit_marker(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'is_first_aider' => 1, 'first_aider_valid_until' => '2027-05-01']);
        $ma = $this->akte(['rec_person_id' => $p, 'is_first_aider' => null, 'first_aider_valid_until' => null]);

        $ids = (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['is_first_aider' => true, 'first_aider_valid_until' => '2027-05-01'], true, true);

        $this->assertSame([$ma], $ids);
        $this->assertSame(1, (int) $this->zeile($ma)->is_first_aider);
        $this->assertSame('2027-05-01', substr((string) $this->zeile($ma)->first_aider_valid_until, 0, 10));
        $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'echte Aenderung: Schwester geht an ZAS');
    }

    public function test_ersthelfer_normalisierung(): void
    {
        $this->assertSame('0', \Platform\Recruiting\Support\PersonenFelder::normalisiere('is_first_aider', false));
        $this->assertSame('1', \Platform\Recruiting\Support\PersonenFelder::normalisiere('is_first_aider', '1'));
        $this->assertNull(\Platform\Recruiting\Support\PersonenFelder::normalisiere('is_first_aider', null));
        $this->assertSame('2027-05-01', \Platform\Recruiting\Support\PersonenFelder::normalisiere('first_aider_valid_until', '2027-05-01 00:00:00'));
    }
}
