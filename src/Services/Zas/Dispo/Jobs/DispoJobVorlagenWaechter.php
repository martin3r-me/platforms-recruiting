<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Support\WhatsAppTemplateBodyVariables;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/** Dieselben zwei Sende-Guards wie CampaignTemplatePreview::warnung — fuer Jobangebote. */
final class DispoJobVorlagenWaechter
{
    /**
     * Gespeicherte Vorlage einer Anfrage pruefen (jede Welle, nicht nur beim Anlegen):
     * fehlt, nicht freigegeben oder problem() → Text, sonst null.
     */
    public static function fuerVorlage(?int $templateId): ?string
    {
        $klasse = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class;
        if ($templateId === null || !class_exists($klasse)) {
            return 'Es ist keine Vorlage gewählt.';
        }
        $t = $klasse::query()->find($templateId);
        if ($t === null) {
            return 'Die Vorlage gibt es nicht mehr.';
        }
        if ($t->status !== 'APPROVED') {
            return 'Die Vorlage „' . $t->name . '“ ist bei Meta nicht (mehr) freigegeben.';
        }

        return self::problem($t->components);
    }

    public static function problem(mixed $components): ?string
    {
        $list = is_string($components) ? (json_decode($components, true) ?: []) : (is_array($components) ? $components : []);
        $list = array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
        if ($list === []) {
            return 'Die Vorlage ist leer oder nicht lesbar.';
        }
        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($list, 0)) {
            return 'Die Vorlage hat keinen Link-Button mit Platzhalter an erster Stelle — ohne ihn gibt es keinen persönlichen Link.';
        }
        $fremd = array_values(array_filter(
            WhatsAppTemplateBodyVariables::names($list),
            fn (string $n): bool => !in_array(strtolower($n), ['name', 'vorname', '1'], true)
        ));
        if ($fremd !== []) {
            return 'Die Vorlage hat Platzhalter außer dem Vornamen (' . implode(', ', $fremd) . ') — die könnten wir nicht füllen.';
        }

        return null;
    }
}
