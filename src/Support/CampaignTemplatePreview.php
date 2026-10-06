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
     * `warnung` traegt DIESELBEN zwei Sende-Guards wie NewDatesCampaignSender::send
     * (Review 06.10.): ohne dynamischen URL-Button an Position 0 gibt es keinen
     * Link, mit Body-Variablen ausser dem Vornamen wuerde Meta-Beispieltext
     * verschickt — der Sender lehnt beides fuer JEDE Person ab. Die Vorschau darf
     * dann keinen Text zeigen, der nie rausgeht; die Karte wird zur Warnung.
     *
     * @param mixed $components Vorlagen-Definition (integrations_whatsapp_templates.components)
     * @return array{text:?string, buttons:list<string>, warnung:?string}
     */
    public static function render(mixed $components, string $vorname = self::BEISPIEL_VORNAME): array
    {
        $list = is_string($components) ? (json_decode($components, true) ?: []) : (is_array($components) ? $components : []);
        $list = array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
        $sent = HoldingTemplateComponents::build($list, $vorname);

        return [
            'text' => WhatsAppTemplateRenderer::render($list, $sent),
            'buttons' => WhatsAppTemplateRenderer::buttonLabels($list),
            'warnung' => self::warnung($list),
        ];
    }

    /** @param list<array<string, mixed>> $list */
    private static function warnung(array $list): ?string
    {
        if ($list === []) {
            return null;
        }
        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($list, 0)) {
            return 'Die Vorlage hat keinen Link-Button mit Platzhalter an erster Stelle — ohne ihn kann der Versand keinen persönlichen Link einsetzen und lehnt jede Nachricht ab.';
        }
        $fremd = array_values(array_filter(
            WhatsAppTemplateBodyVariables::names($list),
            fn (string $name): bool => !in_array(strtolower($name), ['name', 'vorname', '1'], true),
        ));
        if ($fremd !== []) {
            return 'Die Vorlage hat Platzhalter außer dem Vornamen (' . implode(', ', $fremd) . ') — die würden mit Meta-Beispieltext gefüllt, der Versand lehnt jede Nachricht ab.';
        }

        return null;
    }
}
