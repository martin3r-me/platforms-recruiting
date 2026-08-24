<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsWhatsAppThread;

/**
 * Ungelesen-Zaehler fuer das Sidebar-Badge der Dispo-Kommunikation.
 * Wirft nie (Sidebar rendert auf JEDER Seite — ein Fehler hier wuerde
 * das ganze Modul reissen). Aufruf aus dem Disposition-Block der
 * Sidebar-Blade: dokumentierte Ausnahme der Einbahnstrassen-Regel,
 * weil die komplette Disposition-Gruppe beim Staffing-Auszug als
 * Ganzes umzieht. Template-Wechsel in den Einstellungen greift furs
 * Badge nach max. 5 Minuten — bewusster Trade-off fuer Seitengeschwindigkeit.
 */
class DispoUnreadCounter
{
    public static function count(): int
    {
        try {
            // Kanal-Aufloesung ist teuer (mehrere Queries) und stabil — 5 Min cachen.
            // Der Count selbst bleibt live (Badge reagiert sofort auf markRead).
            $channelIds = \Illuminate\Support\Facades\Cache::remember(
                'dispo_unread_channel_ids',
                300,
                fn () => DispoChannelResolver::dispoChannelIds()
            );
            if ($channelIds === []) {
                return 0;
            }

            return CommsWhatsAppThread::query()
                ->whereIn('comms_channel_id', $channelIds)
                ->where('is_unread', true)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
