<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Jobs\SendReservedContractsJob;

final class QueueReservedSendTrigger implements ReservedSendTrigger
{
    public function anstossen(int $applicantId, string $anlass): void
    {
        // afterCommit: der Beobachter feuert in der Transaktion des Phasenwechsels;
        // der Job soll den fertigen Stand lesen.
        SendReservedContractsJob::dispatch($applicantId, $anlass)->afterCommit();
    }
}
