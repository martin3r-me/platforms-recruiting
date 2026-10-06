<?php

namespace Platform\Recruiting\Services;

/** Stoesst den automatischen Versand einer Vormerkung an (Queue im Betrieb, Attrappe im Test). */
interface ReservedSendTrigger
{
    public function anstossen(int $applicantId, string $anlass): void;
}
