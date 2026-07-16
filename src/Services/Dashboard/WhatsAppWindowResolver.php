<?php

namespace Platform\Recruiting\Services\Dashboard;

/**
 * Entscheidet pro Bewerber, ob das 24h-WhatsApp-Fenster offen ist.
 * Semantik identisch zu CommsWhatsAppThread::isWindowOpen():
 * last_inbound_at !== null && last_inbound_at > now - 24h (strikt).
 * Pure: Timestamp-Map rein, Bool-Map raus — keine DB, kein Laravel.
 */
class WhatsAppWindowResolver
{
    /**
     * @param array<int|string, string|null> $lastInboundByApplicantId MAX(last_inbound_at) je Bewerber
     * @return array<int, bool> applicant_id => Fenster offen
     */
    public static function windowMap(array $lastInboundByApplicantId, \DateTimeImmutable $now): array
    {
        $cutoff = $now->sub(new \DateInterval('PT24H'));

        $map = [];
        foreach ($lastInboundByApplicantId as $id => $lastInbound) {
            $map[(int) $id] = $lastInbound !== null
                && new \DateTimeImmutable($lastInbound) > $cutoff;
        }

        return $map;
    }
}
