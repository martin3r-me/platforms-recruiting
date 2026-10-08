<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Alarm ans Diensthandy der Filiale bei einer moeglichen Absage (Spec
 * 2026-10-08, Entscheidung 9) — eine Nachricht je Meldung. Muster wie der
 * 16-Uhr-Alarm in DispoEscalateCommand. Fehlt ein Baustein, entfaellt nur der
 * Alarm; die Markierung in der VA steht trotzdem.
 */
class DispoDeclineAlarm
{
    public const SETTINGS_KEY = 'dispo_decline_alarm_template_id';

    public function __construct(private DispoChannelResolver $resolver) {}

    /** @return int|null id der gesendeten Nachricht, null = kein Alarm */
    public function send(RecDispoEvent $event, string $name, string $dates): ?int
    {
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
        $templateId = $teamId > 0 ? RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting(self::SETTINGS_KEY) : null;
        $dutyPhone = RecDispoFilialeSettings::query()
            ->where('team_id', $teamId)->where('filial_nr', $event->filial_nr)
            ->value('duty_phone');
        $channel = $this->resolver->resolveForEvent($event);

        if (!$templateId || !$dutyPhone || $channel === null) {
            Log::info('[DispoDeclineAlarm] uebersprungen (kein Diensthandy/Kanal/Vorlage)', ['event_id' => $event->id]);

            return null;
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find((int) $templateId);
        if (!$template || $template->status !== 'APPROVED') {
            Log::info('[DispoDeclineAlarm] Vorlage fehlt oder ist nicht genehmigt', ['event_id' => $event->id, 'template_id' => $templateId]);

            return null;
        }

        $components = DispoDeclineAlarmComponents::build(
            $name,
            (string) ($event->name ?? $event->einsatz_ref),
            $dates,
            (int) $event->id,
            $template->components,
        );
        if ($components === null) {
            Log::warning('[DispoDeclineAlarm] Vorlage passt nicht (mehr als drei Werte oder Platzhalter im Kopf)', ['template' => $template->name]);

            return null;
        }

        try {
            $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendTemplate(
                channel:      $channel,
                to:           PhoneE164::normalize((string) $dutyPhone) ?? (string) $dutyPhone,
                templateName: $template->name,
                components:   $components,
                languageCode: $template->language ?? 'de',
            );
        } catch (\Throwable $e) {
            Log::warning('[DispoDeclineAlarm] Sende-Fehler', ['event_id' => $event->id, 'error' => $e->getMessage()]);

            return null;
        }

        return isset($message->id) ? (int) $message->id : null;
    }
}
