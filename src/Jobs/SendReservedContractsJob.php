<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Services\ReservedContractSender;

/** Duenner Queue-Wrapper um ReservedContractSender (Spec Versand vormerken §4). */
class SendReservedContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(public int $applicantId, public string $anlass = '') {}

    public function handle(ReservedContractSender $sender): void
    {
        $ergebnis = $sender->versuchen($this->applicantId);
        Log::info('[SendReservedContractsJob] ' . $ergebnis, ['applicant_id' => $this->applicantId, 'anlass' => $this->anlass]);
    }
}
