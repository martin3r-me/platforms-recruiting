<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FontGlyphCoverage;

class FontGlyphCoverageTest extends TestCase
{
    private function font(): string
    {
        return __DIR__ . '/../../resources/fonts/Oswald-SemiBold.ttf';
    }

    public function testLatinUndUmlauteSindAbgedeckt(): void
    {
        $this->assertSame(
            [],
            FontGlyphCoverage::missing('GÄSTEBETREUUNG UND KOMMUNIKATION – 3-Gang-Menü, 12 €', $this->font())
        );
    }

    public function testSternFehlt(): void
    {
        $this->assertSame(
            ['★'],
            FontGlyphCoverage::missing('STEHEMPFANG ★ FLYING BUFFET', $this->font())
        );
    }

    public function testJedesFehlendeZeichenNurEinmalUndInReihenfolge(): void
    {
        $this->assertSame(
            ['★', '☂'],
            FontGlyphCoverage::missing('★ A ☂ B ★', $this->font())
        );
    }

    public function testHtmlTagsWerdenNichtGepruefft(): void
    {
        // Der Vorlageninhalt ist HTML. Spitze Klammern und Attributnamen
        // stehen nie im gerenderten Text und duerfen nicht gemeldet werden.
        $this->assertSame(
            ['★'],
            FontGlyphCoverage::missing('<div class="skill">A ★ B</div>', $this->font())
        );
    }

    public function testFehlendeFontdateiBlockiertNicht(): void
    {
        $this->assertSame([], FontGlyphCoverage::missing('★', '/gibt/es/nicht.ttf'));
    }

    public function testLeererInhalt(): void
    {
        $this->assertSame([], FontGlyphCoverage::missing('', $this->font()));
    }
}
