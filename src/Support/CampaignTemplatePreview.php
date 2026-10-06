<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;

/**
 * Vorschau einer Kampagnen-Vorlage fuer das Senden-Modal der Statistik
 * (UX-Paket 06.10.2026): HR soll den TEXT lesen, den der Bewerber bekommt,
 * nicht den technischen Vorlagen-Namen („statistik_p1_4 (de)“).
 *
 * Rein rechnend: baut die Versand-Parameter genau so wie der Sender
 * (HoldingTemplateComponents::build mit dem Vornamen) und setzt sie mit dem
 * Chat-Renderer in die Vorlage ein. Was hier steht, ist also derselbe Text,
 * der spaeter im Chat-Verlauf erscheint — nur mit einem Beispielnamen.
 */
final class CampaignTemplatePreview
{
    public const BEISPIEL_VORNAME = 'Anna';

    /**
     * @param mixed $components Vorlagen-Definition (integrations_whatsapp_templates.components)
     * @return array{text:?string, buttons:list<string>}
     */
    public static function render(mixed $components, string $vorname = self::BEISPIEL_VORNAME): array
    {
        $list = is_string($components) ? (json_decode($components, true) ?: []) : (is_array($components) ? $components : []);
        $sent = HoldingTemplateComponents::build(array_values(array_filter($list, 'is_array')), $vorname);

        return [
            'text' => WhatsAppTemplateRenderer::render($list, $sent),
            'buttons' => WhatsAppTemplateRenderer::buttonLabels($list),
        ];
    }
}
