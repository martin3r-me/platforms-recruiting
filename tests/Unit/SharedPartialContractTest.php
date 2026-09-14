<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Geteilte Blade-Partials rufen Methoden auf der Komponente auf, in der sie
 * gerendert werden. Dieser Vertrag ist unsichtbar: Blade prueft ihn nicht,
 * php -l prueft ihn nicht, und die Suite hat keine Komponententests. Er
 * bricht erst beim Klick — als 500er beim Nutzer.
 *
 * Genau so passiert am 14.09.2026: Das Bewertungs-Modal wurde geteilt, aber
 * lookupOptionsFor() blieb in der grossen Buchungsliste. Die Teamleiter-
 * Ansicht rendert die Tabelle einwandfrei und stirbt erst, wenn jemand
 * "Bewerten" klickt —
 * "Method TrainingReview\Show::lookupOptionsFor does not exist".
 *
 * Der Test liest die $this->-Aufrufe direkt aus den Partials und prueft sie
 * gegen jede Komponente, die sie einbindet. Wird ein Partial spaeter um einen
 * Aufruf erweitert, faellt er hier statt beim Kunden.
 */
class SharedPartialContractTest extends TestCase
{
    /** Partial => Komponenten, die es einbinden. */
    private const NUTZER = [
        'partials/evaluation-modal' => [
            \Platform\Recruiting\Livewire\InterviewBookings\Index::class,
            \Platform\Recruiting\Livewire\TrainingReview\Show::class,
        ],
        'partials/selfie' => [
            \Platform\Recruiting\Livewire\InterviewBookings\Index::class,
            \Platform\Recruiting\Livewire\TrainingReview\Show::class,
        ],
        // Die Hauptansichten der Teamleiter-Stufe gleich mit: sie leben von
        // denselben geteilten Traits und koennen auf demselben Weg brechen.
        'training-review/show' => [
            \Platform\Recruiting\Livewire\TrainingReview\Show::class,
        ],
        'training-review/index' => [
            \Platform\Recruiting\Livewire\TrainingReview\Index::class,
        ],
        // Sammelversand „ohne Einsatz" (14.09.2026): eigenes Partial, weil das
        // Statistik-Blade schon 1000 Zeilen traegt — und weil der Vertrag
        // (Auswahl, Fortschritt, Start) damit hier geprueft wird statt beim
        // Kunden.
        'statistics/no-assignment-campaign' => [
            \Platform\Recruiting\Livewire\Statistics\Index::class,
        ],
    ];

    /**
     * @dataProvider partials
     */
    public function test_jede_nutzende_komponente_erfuellt_den_vertrag(string $partial, string $komponente): void
    {
        $benoetigt = $this->erwarteteAufrufe($partial);
        $this->assertNotEmpty($benoetigt, "Keine \$this->-Aufrufe in {$partial} gefunden — Pfad falsch?");

        $reflection = new \ReflectionClass($komponente);

        foreach ($benoetigt as $name) {
            $vorhanden = $reflection->hasMethod($name) || $reflection->hasProperty($name);
            $this->assertTrue($vorhanden,
                "{$komponente} bindet {$partial} ein, hat aber kein '{$name}'. "
                . 'Das bricht erst zur Laufzeit, beim Klick des Nutzers.');
        }
    }

    public static function partials(): array
    {
        $faelle = [];
        foreach (self::NUTZER as $partial => $komponenten) {
            foreach ($komponenten as $komponente) {
                $faelle["{$partial} in " . class_basename($komponente)] = [$partial, $komponente];
            }
        }

        return $faelle;
    }

    /** @return list<string> Namen aus $this->name — Methode wie Computed-Property. */
    private function erwarteteAufrufe(string $partial): array
    {
        $pfad = dirname(__DIR__, 2) . '/resources/views/livewire/' . $partial . '.blade.php';
        $inhalt = file_get_contents($pfad);
        if ($inhalt === false) {
            $this->fail("Partial nicht lesbar: {$pfad}");
        }

        preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)/', $inhalt, $treffer);

        return array_values(array_unique($treffer[1]));
    }
}
