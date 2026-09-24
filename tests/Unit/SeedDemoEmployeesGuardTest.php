<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\SeedDemoEmployees;

/**
 * Der Wirt-Wächter des Testdaten-Kommandos. Er ist die einzige Bremse
 * zwischen „erfundene Menschen anlegen" und dem echten Bestand — und es gibt
 * bewusst keinen Schalter, der ihn uebergeht.
 */
class SeedDemoEmployeesGuardTest extends TestCase
{
    public function test_produktion_ist_gesperrt(): void
    {
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://mitarbeiter.rheingedeck.de'));
    }

    public function test_auch_jede_andere_rheingedeck_adresse_ist_gesperrt(): void
    {
        // Falls jemand spaeter eine zweite Produktionsadresse aufsetzt, soll
        // sie nicht versehentlich durchrutschen.
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://Portal.RheinGedeck.de/recruiting'));
    }

    public function test_demo_darf(): void
    {
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de'));
    }

    public function test_lokal_darf(): void
    {
        $this->assertFalse(SeedDemoEmployees::istProduktion('http://localhost:8000'));
    }

    public function test_leere_adresse_gilt_als_produktion(): void
    {
        // Im Zweifel nein. Eine fehlende APP_URL darf nicht die Tuer oeffnen.
        $this->assertTrue(SeedDemoEmployees::istProduktion(''));
        $this->assertTrue(SeedDemoEmployees::istProduktion(null));
        $this->assertTrue(SeedDemoEmployees::istProduktion('kein-gueltiger-wert'));
    }
}
