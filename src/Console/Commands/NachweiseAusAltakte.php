<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\AltaktenNachweise;

/**
 * Ausweis, Infektionsschutz und Krankenkassenkarte der Bestands-MA als
 * "liegt in der alten Akte vor" vermerken, wenn ZAS die Angabe geliefert hat
 * (Pilot-Feedback Markus 29.10.2026). Idempotent; ohne --ausfuehren nur zaehlen.
 *
 *   php artisan recruiting:nachweise-altakte --team-id=3
 *   php artisan recruiting:nachweise-altakte --team-id=3 --ausfuehren
 *
 * Laeuft zusaetzlich taeglich (--scheduled): ein neues AusweisBis aus ZAS
 * erneuert den Vermerk, neue Bestands-MA bekommen ihren.
 */
class NachweiseAusAltakte extends Command
{
    protected $signature = 'recruiting:nachweise-altakte
        {--team-id= : Team-ID (Pflicht, ausser --scheduled)}
        {--ausfuehren : Wirklich anlegen (sonst nur anzeigen)}
        {--scheduled : Taeglicher Lauf: Team aus recruiting.zas.inbound_team_id, schreibt; ohne Anker No-op}';

    protected $description = 'Vermerkt Ausweis/Infektionsschutz/Krankenkassenkarte aus der ZAS-Akte als vorhanden, wo im HCM keine Datei liegt.';

    public function handle(AltaktenNachweise $abgleich): int
    {
        $geplant = (bool) $this->option('scheduled');
        $teamId = (int) ($this->option('team-id') ?: ($geplant ? config('recruiting.zas.inbound_team_id') : 0));
        if ($teamId <= 0) {
            if ($geplant) {
                return Command::SUCCESS;   // ohne Team-Anker nie ueber alle Mandanten
            }
            $this->error('--team-id ist erforderlich.');
            return Command::FAILURE;
        }
        $schreiben = $geplant || (bool) $this->option('ausfuehren');
        if (!$schreiben) {
            $this->warn('Probelauf — es wird nichts geschrieben (scharf mit --ausfuehren).');
        }

        $r = $abgleich->abgleichen($teamId, $schreiben);

        $this->info(sprintf('%d Personen %s.', $r['personen'], $schreiben ? 'ergaenzt' : 'wuerden ergaenzt'));
        foreach ($r['angelegt'] as $code => $anzahl) {
            $this->line("  {$code}: {$anzahl}");
        }

        return Command::SUCCESS;
    }
}
