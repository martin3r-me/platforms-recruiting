<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;

/**
 * Darf an diesen Kontakt eine Auto-Antwort gehen? Eine Stelle fuer alle
 * automatischen Antworten (OOO-Vorlage, Themen-Frage), damit die Regel nicht
 * auseinanderlaeuft.
 *
 * Zweistufiges Block-Gate: is_blacklisted ODER Kontakt-Status BLOCKED.
 * Erst der verknuepfte Thread-Kontakt; ohne Kontakt Fallback-Lookup ueber
 * die Telefonnummer in mehreren Schreibweisen (CRM-Muster, +49/ohne +).
 */
final class KontaktSperre
{
    public static function gesperrt(CommsWhatsAppThread $thread, int $teamId, string $phone): bool
    {
        $contact = $thread->contact;
        if ($contact instanceof CrmContact) {
            return (bool) $contact->is_blacklisted
                || $contact->contactStatus?->code === 'BLOCKED';
        }

        $variants = array_unique([$phone, '+' . ltrim($phone, '+'), ltrim($phone, '+')]);

        return CrmContact::query()
            ->where('team_id', $teamId)
            ->where(function ($q) {
                $q->where('is_blacklisted', true)
                    ->orWhereHas('contactStatus', fn ($s) => $s->where('code', 'BLOCKED'));
            })
            ->whereHas('phoneNumbers', fn ($p) => $p->whereIn('international', $variants))
            ->exists();
    }
}
