<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\Flynk\FlynkPostingReconciler;
use Platform\Recruiting\Services\PostingExpiry;

class FlynkReconcile extends Command
{
    protected $signature = 'recruiting:flynk-reconcile';

    protected $description = 'Schaltet abgelaufene Ausschreibungen inaktiv und synchronisiert veröffentlichte als Tasks nach FLYNK.';

    public function handle(PostingExpiry $expiry): int
    {
        // VOR dem FLYNK-Guard: das Inaktiv-Schalten abgelaufener Ausschreibungen
        // ist eine Regel von uns und gilt auch dort, wo FLYNK aus ist. Es haengt
        // an diesem Kommando, weil es denselben 30-Minuten-Takt braucht und der
        // Abgleich direkt danach die „entfernen"-Meldung dazu schickt.
        $expired = $expiry->deactivateExpired();
        if ($expired !== []) {
            $this->info('Abgelaufen, inaktiv geschaltet: ' . implode(', ', $expired));
        }

        if (!config('recruiting.flynk.enabled') || !config('recruiting.flynk.token')) {
            $this->info('FLYNK-Sync deaktiviert (enabled=false oder Token fehlt).');
            return Command::SUCCESS;
        }

        // Erst hier aufloesen: der Abgleich braucht Token und Ablage, beides
        // soll bei abgeschaltetem FLYNK gar nicht angefasst werden.
        $s = app(FlynkPostingReconciler::class)->run();
        $this->info(sprintf(
            'FLYNK-Sync: sent=%d retried=%d stale_deleted=%d failed=%d permanent=%d skipped=%d file_rejected=%d expired_silent=%d',
            $s['sent'], $s['retried'], $s['stale_deleted'], $s['failed'], $s['permanent'], $s['skipped'], $s['file_rejected'], $s['expired_silent']
        ));

        return Command::SUCCESS;
    }
}
