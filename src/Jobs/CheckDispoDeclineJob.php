<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class CheckDispoDeclineJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Doppelt zugestellter Webhook: dieselbe Nachricht nur einmal in der Schlange (spart den zweiten Modell-Aufruf). */
    public int $uniqueFor = 600;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(DispoDeclineCheckRunner $runner): void
    {
        $runner->run($this->messageId, $this->attempts() >= $this->tries);
    }
}
