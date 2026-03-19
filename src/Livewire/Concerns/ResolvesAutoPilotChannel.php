<?php

namespace Platform\Recruiting\Livewire\Concerns;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Recruiting\Models\RecApplicant;

trait ResolvesAutoPilotChannel
{
    private function resolvePreferredChannel(RecApplicant $applicant): ?CommsChannel
    {
        $teamId = auth()->user()->currentTeam->id;
        $applicant->loadMissing(['crmContactLinks.contact.phoneNumbers', 'crmContactLinks.contact.emailAddresses']);

        // 1. Check for mobile number with WhatsApp available
        $hasWhatsAppPhone = false;
        foreach ($applicant->crmContactLinks as $link) {
            foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                if ($phone->is_active && $phone->whatsapp_status !== CrmPhoneNumber::WHATSAPP_UNAVAILABLE) {
                    $hasWhatsAppPhone = true;
                    break 2;
                }
            }
        }

        if ($hasWhatsAppPhone) {
            $channel = CommsChannel::where('team_id', $teamId)
                ->where('type', 'whatsapp')->where('is_active', true)->first();
            if ($channel) {
                return $channel;
            }
        }

        // 2. Fallback: Email
        $hasEmail = false;
        foreach ($applicant->crmContactLinks as $link) {
            if ($link->contact?->emailAddresses?->where('is_active', true)->isNotEmpty()) {
                $hasEmail = true;
                break;
            }
        }

        if ($hasEmail) {
            $channel = CommsChannel::where('team_id', $teamId)
                ->where('type', 'email')->where('is_active', true)->first();
            if ($channel) {
                return $channel;
            }
        }

        return null;
    }
}
