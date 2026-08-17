<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Gemeinsame Kanal-Aufloesung fuer Dispo-Features: konfiguriertes
 * Bestaetigungs-Template -> WhatsApp-Account -> CommsChannel via
 * sender_identifier (Muster DispoConfirmationSender). Jeder fehlende
 * Baustein ergibt null — wirft nie (UI zeigt dann Empty-States).
 */
class DispoChannelResolver
{
    public static function resolve(): ?\Platform\Crm\Models\CommsChannel
    {
        try {
            $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()?->currentTeam?->id);
            if ($teamId <= 0) {
                return null;
            }

            $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
            $templateId = $settings->getSetting('dispo_confirmation_template_id');
            if (!$templateId || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)
                || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
                return null;
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find((int) $templateId);
            $account = $template ? \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($template->whatsapp_account_id) : null;
            if (!$account || !$account->active) {
                return null;
            }

            return \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
