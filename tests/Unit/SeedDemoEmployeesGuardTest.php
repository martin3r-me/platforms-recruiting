<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\SeedDemoEmployees;

/**
 * Der Waechter des Testdaten-Kommandos. Er ist die einzige Bremse zwischen
 * „erfundene Menschen anlegen" und dem echten Bestand — und es gibt bewusst
 * keinen Schalter, der ihn uebergeht.
 *
 * ZWEI RIEGEL seit dem 26.09.2026 (Schlussfix F7): der Wirt-Vergleich hing an
 * einem MARKENNAMEN. Er greift heute (die Produktion laeuft unter
 * mitarbeiter.rheingedeck.de), faellt aber lautlos aus, sobald eine
 * Produktion unter einer anderen Adresse steht. Die Umgebung zaehlt jetzt
 * mit; der Namensvergleich bleibt daneben und faengt den umgekehrten Fall
 * (Produktionsadresse mit falsch gesetztem APP_ENV).
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

    // -----------------------------------------------------------------
    // F7 -- der zweite Riegel: die Umgebung
    // -----------------------------------------------------------------

    public function test_produktions_umgebung_ist_gesperrt_auch_unter_fremder_adresse(): void
    {
        // Der Fall, den der Markenname nicht faengt: eine Produktion, die
        // nicht "rheingedeck" heisst.
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://portal.beispielkunde.de', 'production'));
        $this->assertTrue(SeedDemoEmployees::istProduktion('http://localhost:8000', 'PRODUCTION'));
    }

    public function test_der_markenname_bleibt_der_zweite_riegel(): void
    {
        // Umgekehrter Fall: Produktionsadresse, aber APP_ENV steht falsch.
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://mitarbeiter.rheingedeck.de', 'local'));
        $this->assertTrue(SeedDemoEmployees::istProduktion('https://mitarbeiter.rheingedeck.de', 'staging'));
    }

    public function test_andere_umgebungen_oeffnen_nichts_von_selbst(): void
    {
        // Die Umgebung kann nur SPERREN, nie erlauben -- und eine
        // unbekannte Umgebung (null) aendert gar nichts.
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de', 'staging'));
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de', null));
        $this->assertFalse(SeedDemoEmployees::istProduktion('https://demo.bhgdigital.de', ''));
        $this->assertTrue(SeedDemoEmployees::istProduktion('', 'local'));
    }
}
