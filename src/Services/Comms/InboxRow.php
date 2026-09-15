<?php

namespace Platform\Recruiting\Services\Comms;

/** Eine Zeile der neuen Kommunikations-Liste. Reines DTO. */
final class InboxRow
{
    public function __construct(
        public readonly int $threadId,
        /** 'applicant' | 'employee' | 'unassigned' */
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?string $url,
        /** Name, ersatzweise die Telefonnummer. */
        public readonly string $title,
        public readonly ?string $firstName,
        public readonly ?string $preview,
        public readonly ?string $phone,
        public readonly ?int $ownerUserId,
        public readonly bool $isUnread,
        public readonly ConversationEscalation $escalation,
        /** Etikett fuer fremde Kontexte, z.B. 'hcm_onboarding'. */
        public readonly ?string $contextLabel = null,
        /** Anzahl weiterer Threads derselben Person (0 = keiner). */
        public readonly int $siblingCount = 0,
    ) {}
}
