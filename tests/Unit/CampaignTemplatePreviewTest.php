<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CampaignTemplatePreview;

/**
 * UX-Paket Kampagnen-Modal (06.10.2026): die Vorschau zeigt den Text, den der
 * Bewerber liest — mit Beispielnamen, Button-Beschriftung getrennt.
 */
final class CampaignTemplatePreviewTest extends TestCase
{
    private const BUTTON = ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Termine ansehen', 'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/interviews/{{1}}']]];

    public function testNamensVariableWirdZumBeispielnamen(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, es gibt freie Termine an deinem Wunschort.', 'example' => ['body_text_named_params' => [['param_name' => 'name', 'example' => 'Max']]]],
            self::BUTTON,
        ];

        $p = CampaignTemplatePreview::render($components);

        $this->assertSame('Hallo Anna, es gibt freie Termine an deinem Wunschort.', $p['text']);
        $this->assertSame(['Termine ansehen'], $p['buttons']);
    }

    public function testPositionsVariableUndEigenerName(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Hallo {{1}}, schau mal rein.'], self::BUTTON];

        $p = CampaignTemplatePreview::render($components, 'Lea');

        $this->assertSame('Hallo Lea, schau mal rein.', $p['text']);
    }

    public function testOhneVariablenBleibtDerTextWieErIst(): void
    {
        $p = CampaignTemplatePreview::render([['type' => 'BODY', 'text' => 'Neue Termine online!'], self::BUTTON]);

        $this->assertSame('Neue Termine online!', $p['text']);
        $this->assertSame(['Termine ansehen'], $p['buttons']);
    }

    public function testKopfUndFusszeileKommenMit(): void
    {
        $p = CampaignTemplatePreview::render([
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Rheingedeck'],
            ['type' => 'BODY', 'text' => 'Hallo {{name}}.'],
            ['type' => 'FOOTER', 'text' => 'Personalabteilung'],
        ]);

        $this->assertSame("Rheingedeck\n\nHallo Anna.\n\nPersonalabteilung", $p['text']);
        $this->assertSame([], $p['buttons']);
    }

    public function testJsonStringUndUnbrauchbaresSindHarmlos(): void
    {
        $p = CampaignTemplatePreview::render(json_encode([['type' => 'BODY', 'text' => 'Hi {{name}}']]));
        $this->assertSame('Hi Anna', $p['text']);

        $this->assertSame(['text' => null, 'buttons' => []], CampaignTemplatePreview::render(null));
        $this->assertSame(['text' => null, 'buttons' => []], CampaignTemplatePreview::render('kein json'));
        $this->assertSame(['text' => null, 'buttons' => []], CampaignTemplatePreview::render([]));
    }
}
