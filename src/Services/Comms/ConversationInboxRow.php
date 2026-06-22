<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Eine Zeile der Kommunikations-Übersicht: ein WhatsApp-Thread mit
 * berechnetem Eskalations-Zustand. Reines DTO (kein DB/Laravel).
 */
final class ConversationInboxRow
{
    public function __construct(
        public readonly int|string $threadId,
        /** 'applicant' | 'employee' */
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        /** Deep-Link in den Verlauf (Bewerber-Detail mit Chat bzw. MA-Detail). */
        public readonly ?string $url,
        public readonly string $contactName,
        public readonly ?string $preview,
        public readonly ?string $phone,
        public readonly ?int $ownerUserId,
        public readonly bool $isUnread,
        public readonly ConversationEscalation $escalation,
    ) {}
}
