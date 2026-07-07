<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\Flynk\FlynkPostingReconciler;

class FlynkReconcile extends Command
{
    protected $signature = 'recruiting:flynk-reconcile';

    protected $description = 'Synchronisiert veröffentlichte Ausschreibungen als Tasks nach FLYNK.';

    public function handle(FlynkPostingReconciler $reconciler): int
    {
        if (!config('recruiting.flynk.enabled') || !config('recruiting.flynk.token')) {
            $this->info('FLYNK-Sync deaktiviert (enabled=false oder Token fehlt).');
            return Command::SUCCESS;
        }

        $s = $reconciler->run();
        $this->info(sprintf(
            'FLYNK-Sync: sent=%d retried=%d stale_deleted=%d failed=%d permanent=%d skipped=%d',
            $s['sent'], $s['retried'], $s['stale_deleted'], $s['failed'], $s['permanent'], $s['skipped']
        ));

        return Command::SUCCESS;
    }
}
