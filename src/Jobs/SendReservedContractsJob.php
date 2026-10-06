<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Services\ContractSendReservationService;
use Platform\Recruiting\Services\ReservedContractSender;

/** Duenner Queue-Wrapper um ReservedContractSender (Spec Versand vormerken §4). */
class SendReservedContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Kein automatischer Neuversuch: die Portal-WA geht sofort an Meta raus,
     * ein Retry nach einem Abbruch koennte doppelt senden. Den naechsten
     * Versuch stoesst der naechste Ausloeser an.
     */
    public $tries = 1;

    public function __construct(public int $applicantId, public string $anlass = '') {}

    public function handle(ReservedContractSender $sender): void
    {
        $ergebnis = $sender->versuchen($this->applicantId);
        Log::info('[SendReservedContractsJob] ' . $ergebnis, ['applicant_id' => $this->applicantId, 'anlass' => $this->anlass]);
    }

    /**
     * Endgueltig gescheitert (Exception, Timeout): Grund an die offene Vormerkung.
     * claimed_at bleibt bewusst stehen — bei einem abgewuergten Worker ist offen,
     * ob die WA schon raus ist; die 15-min-Sperre im Sender faengt den
     * naechsten Ausloeser ab.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $reservation = RecContractSendReservation::query()
                ->where('rec_applicant_id', $this->applicantId)->offen()->first();
            if ($reservation) {
                app(ContractSendReservationService::class)
                    ->vermerkeVersuch($reservation, 'Versand-Job fehlgeschlagen: ' . $e->getMessage());
            }
        } catch (\Throwable) {}
    }
}
