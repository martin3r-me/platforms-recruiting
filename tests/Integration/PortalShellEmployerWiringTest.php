<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use ReflectionClass;

/**
 * VERDRAHTUNG der Arbeitgeber-Pflicht im NEUEN Portal -- Quelltext-Test wie
 * PortalMainEmployerWiringTest (fuer das alte Portal), aus demselben Grund:
 * die Livewire-Komponente laesst sich in dieser Suite nicht rendern (kein
 * 'view'-Binding, siehe TrainingCertificateRenderTest).
 *
 * Vier Entscheidungen werden hier festgenagelt:
 *  1. speichereArbeitgeber() ruft MainEmployerRequiredGuard::error() auf --
 *     keine zweite Regel.
 *  2. Geschrieben wird ueber DB::table(...), NICHT ueber Eloquent-Update --
 *     siehe Begruendung in PortalShellEmployerTest.
 *  3. render() liefert eine synthetische Aufgabe, solange die Antwort fehlt,
 *     UND zaehlt sie im 'offen'-Wert mit.
 *  4. Im Start-Bereich steht diese Aufgabe VOR jedem Nachweis aus der
 *     Aufgabenliste -- sie ist wichtiger, weil an ihr die Steuerklasse
 *     haengt.
 */
class PortalShellEmployerWiringTest extends TestCase
{
    private function quelle(): string
    {
        return file_get_contents((new ReflectionClass(PortalShell::class))->getFileName());
    }

    private function blade(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
    }

    public function test_speichereArbeitgeber_ruft_den_waechter_auf(): void
    {
        $src = $this->quelle();

        $this->assertStringContainsString('MainEmployerRequiredGuard::error(', $src);
    }

    public function test_geschrieben_wird_ueber_den_query_builder(): void
    {
        $src = $this->quelle();

        $methodStart = strpos($src, 'function speichereArbeitgeber(');
        $this->assertNotFalse($methodStart, 'speichereArbeitgeber() fehlt');
        $methodEnd = strpos($src, "\n    }\n", $methodStart);
        $methodBody = substr($src, $methodStart, $methodEnd - $methodStart);

        $this->assertStringContainsString("DB::table('rec_employees')", $methodBody);
        // Keine Eloquent-$employee->update([...]) fuer diese beiden Felder --
        // das waere zwar heute folgenlos (Feldliste), soll aber auch nach
        // einem spaeteren Umbau der Feldliste sicher bleiben.
        $this->assertStringNotContainsString('$employee->update(', $methodBody);
    }

    public function test_render_liefert_die_aufgabe_und_zaehlt_sie_im_offen_wert(): void
    {
        $src = $this->quelle();

        $renderStart = strpos($src, 'function render()');
        $this->assertNotFalse($renderStart);
        $renderBody = substr($src, $renderStart, 1200);

        $this->assertStringContainsString('arbeitgeberAufgabe', $renderBody);
        $this->assertStringContainsString('arbeitgeberOffen', $renderBody);
    }

    public function test_start_bereich_zeigt_die_arbeitgeber_aufgabe_vor_den_nachweisen(): void
    {
        $blade = $this->blade();

        $arbeitgeberPos = strpos($blade, '$arbeitgeberAufgabe');
        $foreachPos = strpos($blade, "@foreach (\$offeneAufgaben as \$aufgabe)");

        $this->assertNotFalse($arbeitgeberPos, 'Blade zeigt die Arbeitgeber-Aufgabe nicht an');
        $this->assertNotFalse($foreachPos);
        $this->assertLessThan($foreachPos, $arbeitgeberPos, 'Die Arbeitgeber-Aufgabe muss VOR der Nachweisliste stehen');
    }

    public function test_profil_bereich_hat_die_frage_und_den_speichern_knopf(): void
    {
        $blade = $this->blade();

        $this->assertStringContainsString('arbeitgeberIstHaupt', $blade);
        $this->assertStringContainsString('wire:click="speichereArbeitgeber"', $blade);
    }
}
