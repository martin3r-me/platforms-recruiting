<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;

/**
 * Holt still fertiggemeldete Bewerbungen zurueck in den Ablauf.
 *
 * Anlass (22.09.2026): 13 Bewerbungen der Sammel-Stelle trugen einen
 * Abschluss-Haken aus dem August — gesetzt in der Sekunde, in der der AutoPilot
 * die damals FELDLOSE Phase als „fertig" las (calculateProgress() liefert ohne
 * Pflichtfelder 100, isPhaseComplete() macht daraus bei completion_type
 * 'fields' erledigt). Zehn von zwoelf aktiven haben bis heute **keinen
 * einzigen Feldwert**, sieben davon stehen trotzdem auf progress 100.
 *
 * Der Heil-Lauf (reconcile-applicant-positions) hat ihre Phase korrigiert, aber
 * der Cron nimmt nur Bewerbungen mit auto_pilot_completed_at IS NULL — sie
 * blieben also stehen, jetzt eben in der richtigen Phase. Dieses Kommando
 * raeumt den Haken weg, mitsamt Zustand und Zaehlern (resetAutoPilotCycle).
 *
 * Was DANACH passiert, entscheidet der Cron aus den echten Daten:
 *  - keine/unvollstaendige Felder → Erstkontakt der Phase (t001 + Formularlink)
 *  - vollstaendig               → Aufstieg in den naechsten Schritt
 * Deshalb meldet jede Zeile ihre Vorhersage, und --dry-run zeigt sie, bevor
 * irgendetwas rausgeht.
 *
 * Aufruf:
 *   php artisan recruiting:reset-auto-pilot-cycle --team-id=3 --applicant-id=2906 --applicant-id=2929 --dry-run
 *
 * @see \Platform\Recruiting\Models\RecApplicant::resetAutoPilotCycle()
 */
class ResetAutoPilotCycle extends Command
{
    protected $signature = 'recruiting:reset-auto-pilot-cycle
        {--applicant-id=* : Bewerber-ID, mehrfach angebbar}
        {--team-id= : Sicherheitsnetz — nur Bewerbungen dieses Teams}
        {--dry-run : Nur anzeigen was passieren würde, nichts schreiben}';

    protected $description = 'Nimmt den Abschluss-Haken von still fertiggemeldeten Bewerbungen, damit der Auto-Pilot sie wieder aufgreift.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = (array) $this->option('applicant-id');
        $teamId = $this->option('team-id');

        if ($ids === []) {
            $this->error('Keine --applicant-id angegeben.');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        $bericht = $this->zuruecksetzen($ids, $teamId, $dryRun, function (string $text): void {
            $this->line($text);
        });

        $this->info('');
        $this->info("Geprüft:            {$bericht['geprueft']}");
        $this->info("Haken entfernt:     {$bericht['zurueckgesetzt']}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("  davon Erstkontakt: {$bericht['erstkontakt']}");
        $this->info("  davon Aufstieg:    {$bericht['aufstieg']}");
        $this->info("Ohne Haken (nichts zu tun): {$bericht['ohneHaken']}");
        if ($bericht['fremd'] > 0) {
            $this->warn("Nicht gefunden oder fremdes Team: {$bericht['fremd']}");
        }

        return Command::SUCCESS;
    }

    /**
     * Reine Logik ohne Konsolen-I/O (Probe-Muster wie in
     * ReconcileApplicantPositions), damit sie ohne Artisan-Lebenszyklus
     * testbar ist.
     *
     * @param list<int|string> $ids
     * @return array{geprueft:int,zurueckgesetzt:int,ohneHaken:int,fremd:int,erstkontakt:int,aufstieg:int,zeilen:list<string>}
     */
    protected function zuruecksetzen(array $ids, ?string $teamId, bool $dryRun, ?callable $emit = null): array
    {
        $emit ??= function (string $text): void {};

        $geprueft = 0;
        $zurueckgesetzt = 0;
        $ohneHaken = 0;
        $fremd = 0;
        $erstkontakt = 0;
        $aufstieg = 0;
        $zeilen = [];

        foreach (array_map('intval', $ids) as $id) {
            $geprueft++;

            $applicant = RecApplicant::find($id);
            if (!$applicant || ($teamId !== null && (int) $applicant->team_id !== (int) $teamId)) {
                $fremd++;
                $zeilen[] = sprintf(' #%-5d : nicht gefunden oder fremdes Team — übersprungen', $id);
                $emit(end($zeilen));
                continue;
            }

            if ($applicant->auto_pilot_completed_at === null) {
                $ohneHaken++;
                $zeilen[] = sprintf(' #%-5d : kein Abschluss-Haken — nichts zu tun', $id);
                $emit(end($zeilen));
                continue;
            }

            // Vorhersage aus den ECHTEN Daten, nicht aus der gespeicherten
            // Fortschritts-Spalte: die traegt bei genau diesen Faellen die
            // eingefrorene 100 aus der feldlosen Phase.
            $steigtAuf = $applicant->isPhaseComplete();
            $steigtAuf ? $aufstieg++ : $erstkontakt++;

            $zurueckgesetzt++;
            $zeilen[] = sprintf(
                ' #%-5d : Haken vom %s entfernt → %s',
                $id,
                $applicant->auto_pilot_completed_at->format('d.m.Y'),
                $steigtAuf ? 'steigt beim nächsten Lauf auf' : 'bekommt den Erstkontakt der Phase',
            );
            $emit(end($zeilen));

            if ($dryRun) {
                continue;
            }

            $applicant->resetAutoPilotCycle();
            $applicant->save();

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $applicant->id,
                    'type' => 'cycle_reset',
                    'summary' => 'Abschluss-Haken entfernt — die Bewerbung war in einer feldlosen Phase still fertiggemeldet worden.',
                ]);
            } catch (\Throwable) {
                // Der Log-Eintrag darf den Reset nicht blockieren.
            }
        }

        return [
            'geprueft' => $geprueft,
            'zurueckgesetzt' => $zurueckgesetzt,
            'ohneHaken' => $ohneHaken,
            'fremd' => $fremd,
            'erstkontakt' => $erstkontakt,
            'aufstieg' => $aufstieg,
            'zeilen' => $zeilen,
        ];
    }
}
