<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Platzierungs-Vertrag, KEIN Verhaltenstest (Muster AutoPilotCooldownWiringTest).
 * Das Verhalten steht in GeplanteVertragsdatenTest. Hier: die Nachbereitung
 * speichert bei jeder Eingabe und laedt vor jeder Aktion nach. Die Livewire-
 * Komponente laesst sich in dieser Suite nicht booten.
 */
final class GeplanteVertragsdatenWiringTest extends TestCase
{
    private function methode(string $name): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/InterviewBookings/Index.php');
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "Methode {$name} fehlt");
        $ende = strpos($src, "\n    }\n", $start);

        return substr($src, $start, $ende - $start);
    }

    public function test_eingabe_wird_sofort_gespeichert(): void
    {
        $this->assertStringContainsString('GeplanteVertragsdaten::speichern', $this->methode('setContractDate'));
        $this->assertStringContainsString('GeplanteVertragsdaten::vormerkungNachziehen', $this->methode('setContractDate'));
    }

    /**
     * Review 08.10.: hydrate() laeuft VOR der Aktion. Liest es $this->bookings,
     * ist die gecachte Liste danach veraltet (Suche, Filter, Buchen, Loeschen,
     * Zuschlag hinken hinterher). Deshalb eigene Abfrage.
     */
    public function test_nachladen_fasst_die_gecachte_liste_nicht_an(): void
    {
        $code = $this->methode('geplanteVertragsdatenUebernehmen') . $this->methode('hydrate');

        $this->assertStringNotContainsString('$this->bookings', $code);
        $this->assertStringContainsString('GeplanteVertragsdaten::fuerTermin', $code);
    }

    public function test_vor_jeder_aktion_und_beim_laden_wird_nachgeladen(): void
    {
        $this->assertStringContainsString('geplanteVertragsdatenUebernehmen', $this->methode('hydrate'));
        $this->assertStringContainsString('geplanteVertragsdatenUebernehmen', $this->methode('mount'));
        $this->assertStringContainsString('GeplanteVertragsdaten::uebernehmen', $this->methode('geplanteVertragsdatenUebernehmen'));
    }
}
