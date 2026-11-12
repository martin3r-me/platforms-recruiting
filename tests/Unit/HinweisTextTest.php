<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\HinweisText;

/** Hinweis auf der Einsatz-Seite: Links anklickbar, alles andere bleibt Text (Markus 12.11.). */
final class HinweisTextTest extends TestCase
{
    public function testLinkWirdAnklickbarUndDerRestBleibtText(): void
    {
        $html = HinweisText::html("Ablauf siehe Video: https://youtu.be/abc123?t=5\nTicket am Eingang Nord zeigen.");

        $this->assertSame(
            "Ablauf siehe Video: <a href=\"https://youtu.be/abc123?t=5\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"hint-link\">https://youtu.be/abc123?t=5</a>\nTicket am Eingang Nord zeigen.",
            $html
        );
    }

    public function testSatzzeichenAmEndeGehoertNichtZumLink(): void
    {
        $this->assertStringContainsString('href="https://messe.de/ticket"', HinweisText::html('Ticket: https://messe.de/ticket.'));
        $this->assertStringEndsWith('</a>.', HinweisText::html('Ticket: https://messe.de/ticket.'));
        $this->assertStringContainsString('href="https://de.wikipedia.org/wiki/Messe_(Begriff)"', HinweisText::html('(siehe https://de.wikipedia.org/wiki/Messe_(Begriff))'));
        $this->assertStringEndsWith('</a>)', HinweisText::html('(siehe https://de.wikipedia.org/wiki/Messe_(Begriff))'));
    }

    public function testUmlauteVorUndNachDemLinkVerschiebenNichts(): void
    {
        $this->assertSame(
            'Bitte früh da: <a href="https://messe.de/ablauf" target="_blank" rel="noopener noreferrer" class="hint-link">https://messe.de/ablauf</a> – Grüße',
            HinweisText::html('Bitte früh da: https://messe.de/ablauf – Grüße')
        );
    }

    public function testWwwBekommtHttps(): void
    {
        $this->assertStringContainsString('href="https://www.messe-duesseldorf.de"', HinweisText::html('Infos: www.messe-duesseldorf.de'));
    }

    public function testHtmlImTextWirdEscaptUndNieAusgefuehrt(): void
    {
        $html = HinweisText::html('<script>alert(1)</script> <b>fett</b> "zitat" & mehr');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp; mehr', $html);
    }

    public function testKeinJavascriptLinkUndKeinAusbruchAusDemAttribut(): void
    {
        $this->assertStringNotContainsString('<a', HinweisText::html('javascript:alert(1)'));
        $html = HinweisText::html('https://x.de/"onmouseover="alert(1)');
        $this->assertStringNotContainsString('"onmouseover', $html, 'Anfuehrungszeichen sind escapt, das Attribut bleibt zu');
        $this->assertStringContainsString('href="https://x.de/"', $html, 'escaptes &quot; am Ende gehoert nicht zur Adresse');
    }

    public function testLeerUndOhneLinkUnveraendert(): void
    {
        $this->assertSame('', HinweisText::html(null));
        $this->assertSame('', HinweisText::html('   '));
        $this->assertSame('15 Min früher da sein', HinweisText::html('15 Min früher da sein'));
        $this->assertSame('*bitte* pünktlich', HinweisText::html('*bitte* pünktlich'), 'kein Markdown');
    }
}
