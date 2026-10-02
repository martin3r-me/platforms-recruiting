<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;

/**
 * Vorhandener HR-Thread zur Nummer eines MA. Meta liefert die Nummer mal als
 * "+49…", mal als nackte wa_id "49…" — verglichen wird deshalb beides, sonst
 * legt findOrCreateForPhone() einen zweiten Thread derselben Person an.
 *
 * Dispo-Kanaele zaehlen nie als HR-Kanal — auch wenn sie (Fehlkonfiguration)
 * im Recruiting-Kanal-Set auftauchen.
 */
final class ForwardHrThreadLookup
{
    public function find(int $teamId, string $phone): ?CommsWhatsAppThread
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $channelIds = array_values(array_diff(RecruitingChannelResolver::channelIds($teamId), self::dispoChannelIds()));
        if ($digits === '' || $channelIds === []) {
            return null;
        }

        return CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $channelIds)
            ->whereIn('remote_phone_number', ['+' . $digits, $digits])
            ->orderByDesc('last_inbound_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return list<int> */
    public static function dispoChannelIds(): array
    {
        try {
            return array_map('intval', DispoChannelResolver::dispoChannelIds());
        } catch (\Throwable) {
            return [];
        }
    }
}
