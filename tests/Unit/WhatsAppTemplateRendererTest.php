<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\WhatsAppTemplateRenderer;

/**
 * Chat zeigt den echten Vorlagen-Text (Kunde 23.09.) — bisher stand dort nur
 * "Template: t_wo_bist".
 */
class WhatsAppTemplateRendererTest extends TestCase
{
    /** Dispo-Bestaetigung: fuenf Werte in Reihenfolge, dazu ein URL-Knopf. */
    public function test_setzt_positionsplatzhalter_ein(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{1}}, am {{2}} bist du bei {{3}} eingeteilt. Bitte {{4}} Minuten vorher da sein. Schicht: {{5}}.'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Einsatz ansehen']]],
        ];
        $sent = [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Tristan'],
                ['type' => 'text', 'text' => '24.09.2026'],
                ['type' => 'text', 'text' => 'Messe Düsseldorf'],
                ['type' => 'text', 'text' => '30'],
                ['type' => 'text', 'text' => '08:00–16:00'],
            ]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'abc123']]],
        ];

        $this->assertSame(
            'Hallo Tristan, am 24.09.2026 bist du bei Messe Düsseldorf eingeteilt. Bitte 30 Minuten vorher da sein. Schicht: 08:00–16:00.',
            WhatsAppTemplateRenderer::render($components, $sent)
        );
        $this->assertSame(['Einsatz ansehen'], WhatsAppTemplateRenderer::buttonLabels($components));
    }

    public function test_setzt_benannte_platzhalter_ein(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Hi {{name}}, wo bist du gerade?']];
        $sent = [['type' => 'body', 'parameters' => [
            ['type' => 'text', 'parameter_name' => 'name', 'text' => 'Vesa'],
        ]]];

        $this->assertSame('Hi Vesa, wo bist du gerade?', WhatsAppTemplateRenderer::render($components, $sent));
    }

    /** Kopf- und Fusszeile gehoeren zur gelesenen Nachricht. */
    public function test_nimmt_textkopf_und_fusszeile_mit(): void
    {
        $components = [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Einsatz {{1}}'],
            ['type' => 'BODY', 'text' => 'Bitte bestätigen.'],
            ['type' => 'FOOTER', 'text' => 'Rheingedeck'],
        ];
        $sent = [
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Messe']]],
        ];

        $this->assertSame("Einsatz Messe\n\nBitte bestätigen.\n\nRheingedeck", WhatsAppTemplateRenderer::render($components, $sent));
    }

    public function test_ignoriert_bildkopf(): void
    {
        $components = [
            ['type' => 'HEADER', 'format' => 'IMAGE', 'text' => 'nicht lesbar'],
            ['type' => 'BODY', 'text' => 'Text.'],
        ];

        $this->assertSame('Text.', WhatsAppTemplateRenderer::render($components, []));
    }

    /**
     * Altnachrichten ohne gespeicherte Werte: der Platzhalter bleibt sichtbar
     * stehen, statt dass ein Stueck Text still verschwindet.
     */
    public function test_laesst_platzhalter_ohne_wert_stehen(): void
    {
        $components = [['type' => 'BODY', 'text' => 'Hallo {{1}}, am {{2}}.']];
        $sent = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Tristan']]]];

        $this->assertSame('Hallo Tristan, am {{2}}.', WhatsAppTemplateRenderer::render($components, $sent));
    }

    public function test_versteht_json_strings(): void
    {
        $components = json_encode([['type' => 'BODY', 'text' => 'Hallo {{1}}.']]);
        $sent = json_encode([['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ida']]]]);

        $this->assertSame('Hallo Ida.', WhatsAppTemplateRenderer::render($components, $sent));
    }

    public function test_ohne_definition_kein_text(): void
    {
        $this->assertNull(WhatsAppTemplateRenderer::render(null, null));
        $this->assertNull(WhatsAppTemplateRenderer::render([['type' => 'BUTTONS', 'buttons' => []]], []));
        $this->assertSame([], WhatsAppTemplateRenderer::buttonLabels(null));
    }
}
