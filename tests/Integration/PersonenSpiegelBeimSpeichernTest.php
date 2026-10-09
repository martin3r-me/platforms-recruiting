<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonenSpiegel;

/** Spec 2026-10-09 §5.1: Spiegel am Speichern (Observer-Listener). */
class PersonenSpiegelBeimSpeichernTest extends TestCase
{
    use SpiegelHarness;

    public static function setUpBeforeClass(): void
    {
        self::baueWelt(true);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance(PersonenSpiegel::class);
        // Trait-Methode ueber Alias aufrufen ist nicht noetig: eigene Aufraeum-Logik unten.
        \Illuminate\Database\Capsule\Manager::schema()->dropAllTables();
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Container::getInstance()->forgetInstance(PersonenSpiegel::class);
        \Illuminate\Database\Capsule\Manager::table('rec_employees')->delete();
        \Illuminate\Database\Capsule\Manager::table('rec_persons')->delete();
        \Illuminate\Database\Capsule\Manager::table('rec_applicant_settings')->delete();
    }

    public function test_portal_speichern_landet_in_beiden_akten_je_mit_eigenem_lohn_eintrag(): void
    {
        $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['street']]);
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'street' => 'Alt RG']);
        $ma = $this->akte(['rec_person_id' => $p, 'street' => 'Alt MA']);

        RecEmployee::find($rg)->update(['street' => 'Neu 5']);

        $this->assertSame('Neu 5', $this->zeile($ma)->street);
        $this->assertNotNull($this->zeile($rg)->zas_changed_at);
        $this->assertNotNull($this->zeile($ma)->zas_changed_at);
        $this->assertCount(1, $this->lohn($rg), 'genau ein Eintrag — keine Rueckkopplung');
        $this->assertCount(1, $this->lohn($ma));
        $this->assertSame('Alt MA', $this->lohn($ma)[0]['old']);
    }

    public function test_geleertes_feld_wird_auch_beim_geschwister_geleert(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'other_employer' => 'X GmbH']);
        $ma = $this->akte(['rec_person_id' => $p, 'other_employer' => 'X GmbH']);
        RecEmployee::find($rg)->update(['is_main_employer' => true, 'other_employer' => null]);
        $this->assertNull($this->zeile($ma)->other_employer);
        $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
    }

    public function test_gesellschaftsfeld_bleibt_getrennt(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p]);
        $ma = $this->akte(['rec_person_id' => $p, 'cost_center' => 'MA-K']);
        RecEmployee::find($rg)->update(['cost_center' => 'RG-K']);
        $this->assertSame('MA-K', $this->zeile($ma)->cost_center);
        $this->assertNull($this->zeile($ma)->zas_changed_at);
    }

    public function test_fehler_im_spiegel_laesst_die_quelle_gespeichert(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p]);
        $this->akte(['rec_person_id' => $p]);

        Container::getInstance()->instance(PersonenSpiegel::class, new class extends PersonenSpiegel {
            public function geschwister(RecEmployee $quelle): array
            {
                throw new \RuntimeException('Resolver kaputt');
            }
        });

        RecEmployee::find($rg)->update(['city' => 'Neu']);

        $this->assertSame('Neu', $this->zeile($rg)->city);
    }

    public function test_erstbefuellung_fuellt_nur_leere_geschwisterfelder(): void
    {
        // Review I2: Nachtragen aus der alten Bewerbung (Feld war leer) darf
        // den aktuellen Wert der anderen Akte nicht ueberschreiben.
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'street' => null, 'city' => null]);
        $ma = $this->akte(['rec_person_id' => $p, 'street' => 'Aktuell 1', 'city' => null]);

        RecEmployee::find($rg)->update(['street' => 'Bewerbung 9', 'city' => 'Koeln']);

        $this->assertSame('Aktuell 1', $this->zeile($ma)->street, 'nicht-leerer Wert bleibt');
        $this->assertSame('Koeln', $this->zeile($ma)->city, 'leeres Geschwisterfeld wird gefuellt');
    }
}
