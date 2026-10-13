<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\PersonendatenAbgleich;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * recruiting:personendaten-abgleich (Spec 2026-10-09 §6): Abweichungen je
 * Person listen, gezielt in eine Richtung angleichen.
 */
class PersonendatenAbgleichTest extends TestCase
{
    use SpiegelHarness;

    public static function setUpBeforeClass(): void
    {
        self::baueWelt(false, ['2026_08_26_000002_add_company_to_rec_employees']);
    }

    /** @return array{0: int, 1: string} */
    private function run_(array $options): array
    {
        $command = new PersonendatenAbgleich();
        $command->setLaravel(new PersonendatenAbgleichFakeLaravel());
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($options, $command->getDefinition()), $output);

        return [$code, $output->fetch()];
    }

    public function test_listet_abweichung_und_schreibt_nichts(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'city' => 'Koeln', 'personnel_number' => 'RG1']);
        $ma = $this->akte(['rec_person_id' => $p, 'city' => 'Bonn', 'personnel_number' => 'MA1']);
        [$code, $aus] = $this->run_([]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('city', $aus);
        $this->assertStringContainsString('Bonn', $aus);
        $this->assertStringContainsString('mit Abweichung: 1', $aus);
        $this->assertSame('Bonn', $this->zeile($ma)->city);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    public function test_gleiche_daten_ergeben_keine_abweichung(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'city' => 'Koeln']);
        $this->akte(['rec_person_id' => $p, 'city' => 'Koeln ']);
        [, $aus] = $this->run_([]);
        $this->assertStringContainsString('mit Abweichung: 0', $aus);
    }

    public function test_fuehrende_nullen_zaehlen_als_abweichung(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'zip' => '01067']);
        $this->akte(['rec_person_id' => $p, 'zip' => '1067']);
        [, $aus] = $this->run_([]);
        $this->assertStringContainsString('mit Abweichung: 1', $aus);
    }

    public function test_nicht_numerische_ids_werden_abgelehnt(): void
    {
        [$c1] = $this->run_(['--person' => 'abc']);
        [$c2] = $this->run_(['--person' => '1', '--nach' => 'x']);
        $this->assertSame(1, $c1);
        $this->assertSame(1, $c2);
    }

    public function test_nach_ohne_person_bricht_ab(): void
    {
        [$code] = $this->run_(['--nach' => '1']);
        $this->assertSame(1, $code);
    }

    public function test_nach_akte_ausserhalb_der_person_bricht_ab_und_schreibt_nichts(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'city' => 'Koeln']);
        $ma = $this->akte(['rec_person_id' => $p, 'city' => 'Bonn']);
        $fremd = $this->akte(['rec_person_id' => $this->person(), 'city' => 'Essen']);
        [$code] = $this->run_(['--person' => (string) $p, '--nach' => (string) $fremd]);
        $this->assertSame(1, $code);
        $this->assertSame('Bonn', $this->zeile($ma)->city);
    }

    public function test_angleichen_uebertraegt_nur_nicht_leere_werte_mit_marker(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'city' => 'Koeln', 'iban' => null]);
        $ma = $this->akte(['rec_person_id' => $p, 'city' => 'Bonn', 'iban' => 'DE01']);
        [$code, $aus] = $this->run_(['--person' => (string) $p, '--nach' => (string) $rg]);
        $this->assertSame(0, $code);
        $this->assertSame('Koeln', $this->zeile($ma)->city);
        $this->assertSame('DE01', $this->zeile($ma)->iban, 'leer in der Quelle wird nicht uebertragen');
        $this->assertNotNull($this->zeile($ma)->zas_changed_at);
        $this->assertStringContainsString('iban', $aus);
        $this->assertStringContainsString('Angeglichen: ' . $ma, $aus);
    }

    public function test_steuerklasse_je_gesellschaft_wird_nicht_gemeldet(): void
    {
        // Seit 12.10.2026 (Markus, E7) immer — ohne Schalter. Krankenkasse
        // ebenso (Generalprobe 13.10.).
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'tax_class' => '1', 'health_insurance' => 'aok']);
        $this->akte(['rec_person_id' => $p, 'tax_class' => '6', 'health_insurance' => 'knappschaft']);
        [, $aus] = $this->run_([]);
        $this->assertStringContainsString('mit Abweichung: 0', $aus);
    }


    public function test_arbeitgeberfelder_werden_nie_gemeldet(): void
    {
        // Seit 12.10.2026 (Markus, E8) immer — ohne Schalter.
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'is_main_employer' => 1, 'other_employer' => null]);
        $this->akte(['rec_person_id' => $p, 'is_main_employer' => 0, 'other_employer' => 'RG']);
        [, $aus] = $this->run_([]);
        $this->assertStringContainsString('mit Abweichung: 0', $aus);
    }

    public function test_nur_lesen_legt_keine_einstellungs_zeile_an(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'city' => 'Koeln']);
        $this->akte(['rec_person_id' => $p, 'city' => 'Bonn']);
        [, $aus] = $this->run_([]);
        $this->assertStringContainsString('mit Abweichung: 1', $aus);
        $this->assertSame(0, \Illuminate\Database\Capsule\Manager::table('rec_applicant_settings')->count());
    }

    public function test_nach_mit_felder_uebertraegt_nur_diese_felder(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'city' => 'Koeln', 'street' => 'A 1']);
        $ma = $this->akte(['rec_person_id' => $p, 'city' => 'Bonn', 'street' => 'B 2']);
        [$code] = $this->run_(['--person' => (string) $p, '--nach' => (string) $rg, '--felder' => 'city']);
        $this->assertSame(0, $code);
        $this->assertSame('Koeln', $this->zeile($ma)->city);
        $this->assertSame('B 2', $this->zeile($ma)->street, 'nicht genanntes Feld bleibt');
    }
    public function test_felder_begrenzt_die_ausgabe_und_person_key_gruppen_sind_nicht_adressierbar(): void
    {
        $this->akte(['person_key' => 'abcdef12345', 'phone' => '+491711234567', 'city' => 'Koeln', 'street' => 'A 1']);
        $this->akte(['person_key' => 'abcdef12345', 'phone' => '+491711234567', 'city' => 'Bonn', 'street' => 'B 2']);
        [, $aus] = $this->run_(['--felder' => 'city']);
        $this->assertStringContainsString('key:abcdef12', $aus);
        $this->assertStringContainsString('abweichende Felder: 1', $aus);
        $this->assertStringNotContainsString('A 1', $aus);
    }

    /**
     * Nachrunde 12.10.2026 (Bedenken 2): rein lesende HR-Liste der Verstoesse
     * gegen die Je-Gesellschaft-Regel. Schreibt nichts, setzt keinen Marker.
     */
    public function test_je_gesellschaft_listet_verstoesse_und_schreibt_nichts(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'company' => 'RG', 'personnel_number' => 'RG5', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_main_employer' => 1, 'tax_class' => '1']);
        $ma = $this->akte(['rec_person_id' => $p, 'company' => 'MA', 'personnel_number' => 'MA5', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_main_employer' => 1, 'tax_class' => '3']);
        $ok = $this->person();
        $this->akte(['rec_person_id' => $ok, 'company' => 'RG', 'is_main_employer' => 1, 'tax_class' => '1']);
        $this->akte(['rec_person_id' => $ok, 'company' => 'MA', 'is_main_employer' => 0, 'tax_class' => '6']);
        $inaktiv = $this->person();
        $this->akte(['rec_person_id' => $inaktiv, 'company' => 'RG', 'is_main_employer' => 1, 'tax_class' => '1']);
        $this->akte(['rec_person_id' => $inaktiv, 'company' => 'MA', 'is_main_employer' => 1, 'tax_class' => '1', 'is_active' => 0]);

        [$code, $aus] = $this->run_(['--je-gesellschaft' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('beide Hauptarbeitgeber', $aus);
        $this->assertStringContainsString('beide Steuerklasse ungleich 6', $aus);
        $this->assertStringContainsString('Ada Lovelace', $aus);
        $this->assertStringContainsString("#{$rg} (RG RG5): Haupt", $aus);
        $this->assertStringContainsString("#{$ma} (MA MA5): 3", $aus);
        $this->assertStringContainsString('Geprueft: 2 Personen mit mehreren aktiven Anstellungen · mit Verstoss: 1', $aus);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer, 'kein Heilen');
    }

    public function test_je_gesellschaft_geht_nicht_mit_nach(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p]);
        [$code] = $this->run_(['--je-gesellschaft' => true, '--person' => (string) $p, '--nach' => (string) $rg]);
        $this->assertSame(1, $code);
    }
}

final class PersonendatenAbgleichFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
