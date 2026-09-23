<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Livewire\Employees\Show;
use ReflectionClass;

/**
 * VERDRAHTUNG der Staatsangehoerigkeits-Pflicht im MA-Portal.
 *
 * Quelltext-Test wie PortalCertificateWiringTest, aus demselben Grund: die
 * Livewire-Komponente ist hier nicht instanziierbar. Gemessen wird bewusst
 * schmal — dass der Guard in saveAll() zwischen Ersthelfer-Pruefung und dem
 * Schreiben der Felder steht, und dass die HR-Akte ihn NICHT hat.
 *
 * Der zweite Punkt ist die eigentliche Entscheidung: HR darf nicht an einem
 * Feld haengenbleiben, das der Mitarbeiter liefern muss — sonst waeren
 * Bestandsakten ohne Wert fuer HR unspeicherbar (dieselbe Regel wie beim
 * Ersthelfer-Schein).
 */
class PortalNationalityRequiredWiringTest extends TestCase
{
    public function testPortalPrueftDieStaatsangehoerigkeitVorDemSchreiben(): void
    {
        $src = file_get_contents((new ReflectionClass(EmployeePortal::class))->getFileName());

        $guard    = strpos($src, 'NationalityRequiredGuard::error(');
        $ersthelf = strpos($src, 'FirstAiderDateGuard::error(');
        $write    = strpos($src, '$allowed = $employee->editableFieldsFlat();');

        $this->assertNotFalse($guard, 'Portal ruft den NationalityRequiredGuard nicht auf');
        $this->assertNotFalse($ersthelf);
        $this->assertNotFalse($write);
        $this->assertGreaterThan($ersthelf, $guard, 'Guard muss nach der Ersthelfer-Pruefung stehen');
        $this->assertLessThan($write, $guard, 'Guard muss VOR dem Schreiben der Felder stehen');
    }

    public function testHrAkteBleibtOhneDiesePflicht(): void
    {
        $src = file_get_contents((new ReflectionClass(Show::class))->getFileName());

        $this->assertStringNotContainsString('NationalityRequiredGuard', $src);
    }
}
