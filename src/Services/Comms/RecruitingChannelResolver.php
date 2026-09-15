<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Kanal-Set des Recruitings: alle aktiven WhatsApp-Kanaele des in den
 * Einstellungen gewaehlten WABA-Kontos (auto_pilot_wa_account_id).
 *
 * Warum ueber den Kanal und nicht ueber den Thread-Kontext: die alte
 * Kommunikations-Uebersicht filtert auf context_model IN (rec_applicant,
 * RecEmployee) und macht damit jeden Chat unsichtbar, der (noch) an einem
 * blossen CrmContact haengt — Fall 2474, 41 Threads, 22 verlorene
 * Bewerbungen. Ueber den Kanal kann nichts verschwinden.
 *
 * Muster: DispoChannelResolver::dispoChannelIds(). Wirft nie; jeder fehlende
 * Baustein ergibt ein leeres Set, die UI zeigt dann einen Hinweis.
 *
 * @see docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md
 */
final class RecruitingChannelResolver
{
    /** @return list<int> */
    public static function channelIds(int $teamId): array
    {
        try {
            $accountId = self::accountId($teamId);
            if ($accountId === 0) {
                return [];
            }

            $account = class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)
                ? \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($accountId)
                : null;
            if (!$account || !$account->active) {
                return [];
            }

            $ids = CommsChannel::query()
                ->where('type', 'whatsapp')
                ->where('is_active', true)
                ->get()
                ->filter(function ($channel) use ($accountId) {
                    $meta = is_array($channel->meta)
                        ? $channel->meta
                        : (json_decode((string) ($channel->meta ?? '[]'), true) ?: []);

                    return (int) ($meta['integrations_whatsapp_account_id'] ?? 0) === $accountId;
                })
                ->map(fn ($channel) => (int) $channel->id)
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }

            // Rueckfall fuer Kanaele ohne Account-Marker im meta-JSON.
            $byNumber = CommsChannel::query()
                ->where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();

            return $byNumber ? [(int) $byNumber->id] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function isConfigured(int $teamId): bool
    {
        return self::channelIds($teamId) !== [];
    }

    private static function accountId(int $teamId): int
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        return (int) ($settings->getSetting('auto_pilot_wa_account_id') ?: 0);
    }
}
