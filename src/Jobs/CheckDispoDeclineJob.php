<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineCheckRunner;

/**
 * Absage-Erkennung fuer eine eingehende Nachricht (Spec 2026-10-08,
 * Entscheidung 3): eingereiht vom Recruiting-Listener, damit der Webhook nie
 * auf das Sprachmodell wartet. Transportfehler -> Wiederholung; beim letzten
 * Versuch wird "failed" protokolliert und nichts gemeldet.
 */
class CheckDispoDeclineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $messageId) {}

    public function handle(DispoDeclineCheckRunner $runner): void
    {
        $runner->run($this->messageId, $this->attempts() >= $this->tries);
    }
}
