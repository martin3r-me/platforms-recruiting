<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Support\ManualBookingBackfillPlanner;

/**
 * Schaltet den Phasen-Schalter allow_manual_booking fuer bestehende Stellen
 * scharf — HR darf Bewerber aus diesen Phasen manuell in Schulungstermine
 * buchen und umbuchen.
 *
 * Warum ein Command und keine Daten-Migration: die Stellen-IDs sind Live-Daten.
 * In einer Migration waeren sie fuer immer festgenagelt und in jeder anderen
 * Installation falsch. Der Command ist wiederholbar (der Planner ueberspringt
 * bereits gesetzte Phasen) und hat einen Dry-Run.
 *
 * Aufruf fuer Rheingedeck (Team 3), Phasen ab Ordnungszahl 2:
 *   php artisan recruiting:enable-manual-booking --position=8,9,10,11,16 --dry-run
 *   php artisan recruiting:enable-manual-booking --position=8,9,10,11,16
 *
 * Die Stellen sind 8 Duesseldorf allgemein, 9 Koeln allgemein, 10 Bonn
 * allgemein, 11 Moenchengladbach allgemein, 16 Duesseldorf - Messe. Alle fuenf
 * haben denselben Phasen-Schnitt: 1 Bewerbung, 2 Schulung buchen,
 * 3 Onboarding (Bestaetigung), 4 Schulung & Vertraege versenden. P2 ist die
 * Buchungs-Phase (completion_type=booking), ab dort soll HR eingreifen duerfen.
 *
 * Der Schalter ist nur die halbe Bedingung: wer bereits versendete Vertraege
 * hat, erscheint trotzdem nicht im Dialog (ManualBookingCandidates).
 *
 * --from-order vergleicht den order-WERT der Phase, nicht ihren Rang in der
 * Liste. Bei einem Schnitt 1/2/3/4 ist das dasselbe; benutzt eine Stelle ein
 * lueckenhaftes Schema (10/20/30 — nichts erzwingt Lueckenlosigkeit), waehlt
 * --from-order=2 ALLE Phasen inklusive "Bewerbung". Deshalb nennt der Dry-Run
 * jede Phase mit ihrem order-Wert; wer ihn liest, sieht es sofort.
 */
class EnableManualBookingForPhases extends Command
{
    protected $signature = 'recruiting:enable-manual-booking
        {--position= : Kommaliste von Stellen-IDs (Pflicht)}
        {--from-order=2 : Ab welchem order-WERT der Phase geschaltet wird (nicht: die wievielte Phase)}
        {--dry-run : Nur anzeigen, welche Phasen geschaltet wuerden}';

    protected $description = 'Setzt allow_manual_booking auf den Phasen der genannten Stellen (ab --from-order).';

    public function handle(): int
    {
        $positionIds = collect(explode(',', (string) $this->option('position')))
            ->map(fn ($value) => (int) trim($value))
            ->filter()
            ->unique()
            ->values();

        if ($positionIds->isEmpty()) {
            $this->error('--position ist Pflicht, z.B. --position=8,9,10,11,16');

            return Command::FAILURE;
        }

        $fromOrder = (int) $this->option('from-order');
        $dryRun = (bool) $this->option('dry-run');
        $gesamt = 0;

        foreach ($positionIds as $positionId) {
            $phases = RecPhase::where('rec_position_id', $positionId)
                ->orderBy('order')
                ->get(['id', 'name', 'order', 'is_active', 'allow_manual_booking']);

            if ($phases->isEmpty()) {
                $this->warn("Stelle {$positionId}: keine Phasen gefunden — uebersprungen.");
                continue;
            }

            $auswahl = ManualBookingBackfillPlanner::selectPhaseIds(
                $phases->map(fn ($phase) => [
                    'id'                   => (int) $phase->id,
                    'order'                => (int) $phase->order,
                    'is_active'            => (bool) $phase->is_active,
                    'allow_manual_booking' => (bool) $phase->allow_manual_booking,
                ])->all(),
                $fromOrder,
            );

            $namen = $phases->whereIn('id', $auswahl)
                ->map(fn ($phase) => "P{$phase->order} {$phase->name}")
                ->implode(', ');

            $this->line("Stelle {$positionId}: " . ($auswahl ? $namen : 'nichts zu tun'));

            if (!$dryRun && $auswahl) {
                // Bulk-Update per Query-Builder, also ohne Model-Events. Das ist
                // hier unkritisch: an RecPhase haengt nur ein deleting()-Hook
                // (RecPhaseObserver, Phasen-Transitions) und ein creating()-Hook
                // fuer die uuid — auf ein Update reagiert nichts.
                RecPhase::whereIn('id', $auswahl)->update(['allow_manual_booking' => true]);
            }

            $gesamt += count($auswahl);
        }

        $this->info($dryRun
            ? "{$gesamt} Phase(n) waeren betroffen — ohne --dry-run ausfuehren zum Schreiben."
            : "{$gesamt} Phase(n) geschaltet.");

        return Command::SUCCESS;
    }
}
