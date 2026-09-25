<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use ReflectionClass;

/**
 * VERDRAHTUNG der Arbeitgeber-Pflicht im MA-Portal — Quelltext-Test wie
 * PortalNationalityRequiredWiringTest, aus demselben Grund: die
 * Livewire-Komponente laesst sich in dieser Suite nicht rendern.
 *
 * Zwei Entscheidungen werden hier festgenagelt:
 *  1. Der Guard steht VOR dem Schreiben der Felder (sonst blockt er zwar,
 *     aber unbeteiligte Felder waeren schon durch).
 *  2. Die HR-Akte hat ihn NICHT — HR darf nicht an einer Angabe
 *     haengenbleiben, die der Mitarbeiter liefern muss.
 */
class PortalMainEmployerWiringTest extends TestCase
{
    public function test_portal_prueft_den_arbeitgeber_vor_dem_schreiben(): void
    {
        $src = file_get_contents((new ReflectionClass(EmployeePortal::class))->getFileName());

        $guard    = strpos($src, 'MainEmployerRequiredGuard::error(');
        $national = strpos($src, 'NationalityRequiredGuard::error(');
        $write    = strpos($src, '$allowed = $employee->editableFieldsFlat();');

        $this->assertNotFalse($guard, 'Portal ruft den MainEmployerRequiredGuard nicht auf');
        $this->assertNotFalse($national);
        $this->assertNotFalse($write);
        $this->assertGreaterThan($national, $guard, 'Guard steht nach der Staatsangehoerigkeits-Pruefung');
        $this->assertLessThan($write, $guard, 'Guard muss VOR dem Schreiben der Felder stehen');
    }

    /**
     * Der (string)-Cast auf ein dreiwertiges Boolean ist die Falle: false
     * wuerde zu '' und saehe aus wie "unbeantwortet". Dieser Test haelt die
     * ausdrueckliche Abbildung fest, damit sie bei einem spaeteren Refactor
     * nicht wegvereinfacht wird.
     */
    public function test_rueckfall_auf_den_datensatz_bildet_false_ausdruecklich_ab(): void
    {
        $src = file_get_contents((new ReflectionClass(EmployeePortal::class))->getFileName());

        $this->assertStringContainsString(
            "\$employee->is_main_employer ? '1' : '0'",
            $src,
            'false muss zu \'0\' werden, nicht zu einem leeren String.',
        );
    }

    public function test_hr_akte_bleibt_ohne_diese_pflicht(): void
    {
        $src = file_get_contents((new ReflectionClass(Show::class))->getFileName());

        $this->assertStringNotContainsString('MainEmployerRequiredGuard', $src);
    }
}
