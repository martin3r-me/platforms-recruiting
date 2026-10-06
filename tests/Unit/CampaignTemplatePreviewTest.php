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

    /**
     * Review 06.10.: die Karte darf keinen Text zeigen, den der Sender fuer
     * jede Person ablehnt — dieselben zwei Guards wie NewDatesCampaignSender.
     */
    public function testFremdeVariablenUndFehlenderLinkButtonWerdenGewarnt(): void
    {
        $fremd = CampaignTemplatePreview::render([
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, am {{termin}} …', 'example' => ['body_text_named_params' => [['param_name' => 'name', 'example' => 'Max'], ['param_name' => 'termin', 'example' => 'Montag']]]],
            self::BUTTON,
        ]);
        $this->assertNotNull($fremd['warnung']);
        $this->assertStringContainsString('termin', $fremd['warnung']);

        $ohneLink = CampaignTemplatePreview::render([['type' => 'BODY', 'text' => 'Hallo {{name}}'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Website', 'url' => 'https://rheingedeck.de/fest']]]]);
        $this->assertNotNull($ohneLink['warnung']);
        $this->assertStringContainsString('Link-Button', $ohneLink['warnung']);

        $quickReply = CampaignTemplatePreview::render([['type' => 'BODY', 'text' => 'Hallo {{name}}'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ja'], ['type' => 'URL', 'text' => 'Termine', 'url' => 'https://x.de/{{1}}']]]]);
        $this->assertNotNull($quickReply['warnung'], 'Link muss an ERSTER Stelle stehen');

        $gut = CampaignTemplatePreview::render([['type' => 'BODY', 'text' => 'Hallo {{vorname}}'], self::BUTTON]);
        $this->assertNull($gut['warnung']);
    }

    public function testJsonStringUndUnbrauchbaresSindHarmlos(): void
    {
        $p = CampaignTemplatePreview::render(json_encode([['type' => 'BODY', 'text' => 'Hi {{name}}']]));
        $this->assertSame('Hi Anna', $p['text']);

        $leer = ['text' => null, 'buttons' => [], 'warnung' => null];
        $this->assertSame($leer, CampaignTemplatePreview::render(null));
        $this->assertSame($leer, CampaignTemplatePreview::render('kein json'));
        $this->assertSame($leer, CampaignTemplatePreview::render([]));
    }
}
