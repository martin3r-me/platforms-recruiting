<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\ContractSigning;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Support\EmployerDeclaration;
use ReflectionMethod;

/**
 * Kundenentscheidung Markus 12.10.2026 (E8) am Schreibweg „Erklaerung beim
 * Vertrag unterschreiben": Hauptarbeitgeber=ja an der MA-Anstellung setzt
 * die RG-Anstellung derselben Person auf Nebenarbeitgeber — ueber denselben
 * Beobachter wie Portal und HR-Akte (AnstellungsRegeln).
 */
final class AnstellungsRegelnVertragTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        RecEmployeeExportObserver::register();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    public function test_erklaerung_haupt_an_ma_setzt_rg_auf_neben(): void
    {
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4711', 'is_main_employer' => true]);
        $ma = $this->anstellung(['company' => 'MA', 'personnel_number' => 'MA4711']);
        $this->personVerbinden($rg, $ma);
        DB::table('rec_employees')->update(['zas_changed_at' => null]);
        $v = $this->vertragAn($ma->fresh(), $this->vorlage('AV-MA-LOG'));

        $signing = new ContractSigning();
        $m = new ReflectionMethod($signing, 'applyEmployerDeclaration');
        $m->setAccessible(true);
        $m->invoke($signing, $v, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::KEY_OTHER => null]);

        $this->assertTrue($ma->fresh()->is_main_employer);
        $this->assertFalse($rg->fresh()->is_main_employer);
        $this->assertNotNull($rg->fresh()->zas_changed_at, 'Schwester-Aenderung geht mit Marker an ZAS');
    }

    /**
     * HR-Akte (Employees/Show::saveAll): dieselbe Regel, und HR sieht im
     * Hinweis, was an der anderen Anstellung mitgeaendert wurde.
     */
    public function test_hr_akte_speichern_greift_und_zeigt_den_hinweis(): void
    {
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4712', 'tax_class' => '6']);
        $ma = $this->anstellung(['company' => 'MA', 'personnel_number' => 'MA4712', 'tax_class' => '1', 'is_main_employer' => true]);
        $this->personVerbinden($rg, $ma);

        $show = new \Platform\Recruiting\Livewire\Employees\Show();
        $show->employeeId = $rg->id;
        $show->fieldValues = ['tax_class' => '1', 'is_main_employer' => '1'];
        $show->saveAll();

        $this->assertSame('6', (string) $ma->fresh()->tax_class);
        $this->assertFalse($ma->fresh()->is_main_employer);
        $this->assertSame('Aenderungen gespeichert. Anstellung MA wurde auf Steuerklasse 6 / Nebenarbeitgeber gesetzt.', $show->flash);
    }

    /** Nachrunde 12.10. (Bedenken 6): das HR-Label nennt die Gesellschaft der Akte. */
    public function test_hr_akte_label_ist_gesellschaftsneutral(): void
    {
        $ma = $this->anstellung(['company' => 'MA', 'personnel_number' => 'MA4713']);
        $show = new \Platform\Recruiting\Livewire\Employees\Show();
        $show->employeeId = $ma->id;

        $this->assertSame(
            'Hauptarbeitgeber ist diese Gesellschaft (MA Dienstleistung für die Gastronomie UG)',
            $show->fieldGroups()['Arbeitgeber']['is_main_employer']['label'],
        );
    }

    /** Nachrunde 12.10. (Bedenken 6): die Erklaerung beim Unterschreiben nennt die Gesellschaft des Vertrags. */
    public function test_vertragsunterschrift_nennt_die_gesellschaft_des_vertrags(): void
    {
        $ma = $this->anstellung(['company' => 'MA', 'personnel_number' => 'MA4714']);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG', 'MA'));

        $signing = new ContractSigning();
        $signing->mount($v->getOrCreatePublicFormLink()->token);

        $this->assertSame('MA Dienstleistung für die Gastronomie UG', $signing->arbeitgeberName);

        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/contract-signing.blade.php');
        $this->assertStringNotContainsString("'RheinGedeck ist dein Hauptarbeitgeber", $blade);
        $this->assertStringContainsString('e($arbeitgeberName)', $blade);
    }
}
