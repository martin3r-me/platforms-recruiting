<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Lesbare Labels der Dispo-Vorlagen: Einstellungen zuerst, sonst Namens-
 * Heuristik. Nur Anzeige.
 */
final class DispoTemplateLabels
{
    /**
     * Lesbare Labels der Dispo-Vorlagen: zuerst die in den Einstellungen gewaehlten
     * Templates (Name -> Rolle), sonst Heuristik ueber den Template-Namen. Nur Anzeige.
     *
     * @return array<string, string> template_name => Label
     */
    public static function forTeam(int $teamId): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }
        if ($teamId === 0) {
            return [];
        }
        $settings = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($teamId);
        $roles = [
            'dispo_confirmation_template_id'  => 'Bestätigungsanfrage',
            'dispo_escalation_template_1_id'  => 'Erinnerung',
            'dispo_escalation_template_2_id'  => 'Letzte Erinnerung',
            'dispo_alarm_template_id'         => 'Dispo-Alarm',
        ];
        $byId = [];
        foreach ($roles as $key => $label) {
            $id = (int) ($settings->getSetting($key) ?: 0);
            if ($id > 0) {
                $byId[$id] = $label;
            }
        }
        if ($byId === []) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->whereIn('id', array_keys($byId))
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($t) => [(string) $t->name => $byId[(int) $t->id]])
            ->all();
    }

    /** Label fuer einen Template-Namen (Einstellungen zuerst, dann Namens-Heuristik, sonst der Name). */
    public static function label(string $templateName, array $labels): string
    {
        if (isset($labels[$templateName])) {
            return $labels[$templateName];
        }
        // Feste Chat-Vorlagen (config, Kunde 01.09.) — unabhaengig von den Settings-Rollen.
        foreach (DispoChatTemplateSender::options() as $option) {
            if ($option['template'] === $templateName) {
                return $option['label'];
            }
        }
        $n = mb_strtolower($templateName);
        if (str_contains($n, 'reminder2') || str_contains($n, 'final')) {
            return 'Letzte Erinnerung';
        }
        if (str_contains($n, 'reminder') || str_contains($n, 'erinner')) {
            return 'Erinnerung';
        }
        if (str_contains($n, 'alarm')) {
            return 'Dispo-Alarm';
        }
        if (str_contains($n, 'bestaetig') || str_contains($n, 'confirm')) {
            return 'Bestätigungsanfrage';
        }

        return $templateName;
    }

    /** "Template: xyz" in der Vorschau -> "Bestätigungsanfrage gesendet". */
    public static function humanPreview(string $preview, array $labels): string
    {
        if (preg_match('/^Template:\s*(\S+)/u', $preview, $m) === 1) {
            return self::label($m[1], $labels) . ' gesendet';
        }

        return $preview;
    }
}
