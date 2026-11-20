<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Mitarbeiter;

/**
 * Filter zum Anhaken (Sebastian 20.11.2026): Gesellschaft RG/MA und Status GO/MA als Kästchen.
 * Beide angehakt = „beide“; nichts angehakt geht nicht (Standard greift); gebastelte Werte fliegen raus.
 */
final class MitarbeiterStatistikHakenTest extends TestCase
{
    private function rufe(Mitarbeiter $c, string $methode): string
    {
        $m = new \ReflectionMethod($c, $methode);

        return $m->invoke($c);
    }

    public function test_haken_werden_zum_rechner_wert(): void
    {
        $c = new Mitarbeiter();

        $this->assertSame('RG', $this->rufe($c, 'gesellschaftSicher'), 'Standard nur RG');
        $this->assertSame('beide', $this->rufe($c, 'statusSicher'), 'Standard GO und MA');

        $c->gesellschaften = ['MA', 'RG'];
        $c->stati = ['GO'];
        $this->assertSame('beide', $this->rufe($c, 'gesellschaftSicher'));
        $this->assertSame('GO', $this->rufe($c, 'statusSicher'));

        $c->gesellschaften = ['MA'];
        $c->stati = ['MA'];
        $this->assertSame('MA', $this->rufe($c, 'gesellschaftSicher'));
        $this->assertSame('MA', $this->rufe($c, 'statusSicher'));
    }

    public function test_leer_oder_gebastelt_faellt_auf_den_standard(): void
    {
        $c = new Mitarbeiter();

        $c->gesellschaften = [];
        $c->stati = [];
        $this->assertSame('RG', $this->rufe($c, 'gesellschaftSicher'));
        $this->assertSame('beide', $this->rufe($c, 'statusSicher'));

        $c->gesellschaften = 'RG';
        $c->stati = [['x'], 'INAKTIV', 'beide'];
        $this->assertSame('RG', $this->rufe($c, 'gesellschaftSicher'), 'kein Array');
        $this->assertSame('beide', $this->rufe($c, 'statusSicher'), 'nur unerlaubte Werte');

        $c->gesellschaften = [];
        $c->updatedGesellschaften();
        $this->assertSame(['RG'], $c->gesellschaften, 'letzter Haken bleibt stehen');
    }
}
