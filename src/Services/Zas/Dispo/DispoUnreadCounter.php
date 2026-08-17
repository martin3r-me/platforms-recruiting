<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsWhatsAppThread;

/**
 * Ungelesen-Zaehler fuer das Sidebar-Badge der Dispo-Kommunikation.
 * Wirft nie (Sidebar rendert auf JEDER Seite — ein Fehler hier wuerde
 * das ganze Modul reissen). Aufruf aus dem Disposition-Block der
 * Sidebar-Blade: dokumentierte Ausnahme der Einbahnstrassen-Regel,
 * weil die komplette Disposition-Gruppe beim Staffing-Auszug als
 * Ganzes umzieht.
 */
class DispoUnreadCounter
{
    public static function count(): int
    {
        try {
            $channel = DispoChannelResolver::resolve();
            if ($channel === null) {
                return 0;
            }

            return CommsWhatsAppThread::query()
                ->where('comms_channel_id', $channel->id)
                ->where('is_unread', true)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
