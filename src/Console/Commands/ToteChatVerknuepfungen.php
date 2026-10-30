<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\Comms\ToteVerknuepfung;

/**
 * Chats, die an einem geloeschten Bewerber/Mitarbeiter haengen, auf den
 * lebenden Kontext aus ihrem Verlauf umhaengen (Fall Markus 30.10.2026).
 * Ohne --ausfuehren nur zaehlen.
 *
 *   php artisan recruiting:tote-chat-verknuepfungen --team-id=3
 *   php artisan recruiting:tote-chat-verknuepfungen --team-id=3 --ausfuehren
 */
class ToteChatVerknuepfungen extends Command
{
    protected $signature = 'recruiting:tote-chat-verknuepfungen
        {--team-id= : Team-ID (Pflicht)}
        {--ausfuehren : Wirklich umhaengen (sonst nur anzeigen)}';

    protected $description = 'Haengt Chats von geloeschten Bewerbern/Mitarbeitern auf den lebenden Kontext aus ihrem Verlauf um.';

    public function handle(): int
    {
        $teamId = (int) $this->option('team-id');
        if ($teamId <= 0) {
            $this->error('--team-id ist erforderlich.');
            return Command::FAILURE;
        }
        $schreiben = (bool) $this->option('ausfuehren');
        if (!$schreiben) {
            $this->warn('Probelauf — es wird nichts geschrieben (scharf mit --ausfuehren).');
        }

        $r = ToteVerknuepfung::heilen($teamId, $schreiben);

        foreach ($r['umgehaengt'] as $u) {
            $this->line("Thread {$u['thread']}: {$u['von']} -> {$u['nach']}");
        }
        $this->info(sprintf(
            '%d Chats an Geloeschten, %d %s, %d ohne lebenden Ersatz (bleiben).',
            $r['tot'],
            count($r['umgehaengt']),
            $schreiben ? 'umgehaengt' : 'wuerden umgehaengt',
            count($r['ohne_ersatz']),
        ));
        if ($r['ohne_ersatz'] !== []) {
            $this->line('Ohne Ersatz: Thread ' . implode(', ', $r['ohne_ersatz']));
        }

        return Command::SUCCESS;
    }
}
