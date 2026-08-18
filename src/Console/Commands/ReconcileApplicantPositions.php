<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Backfill/Heilung für Bewerber, deren Posting umgeschlüsselt wurde, ohne dass
 * Phase + Verantwortlicher mitgezogen wurden (historischer Bruch vor dem
 * reconcilePositionState()-Fix).
 *
 * Ruft pro Bewerber RecApplicant::reconcilePositionState() — identische Logik
 * wie der Live-Fix:
 *  - **Einzel-Posting** mit Phase auf falscher Stelle → Phase + Feldwerte +
 *    Verantwortlicher werden sauber an die primäre Stelle gezogen.
 *  - **Mehrfach-Posting** → nur Verantwortlicher (Phase mehrdeutig, löst sich
 *    bei der Buchung); separat zur manuellen Sichtung gelistet.
 *
 * Idempotent. Erst mit --dry-run prüfen.
 *
 * Aufruf:
 *   php artisan recruiting:reconcile-applicant-positions --dry-run --team-id=3
 *   php artisan recruiting:reconcile-applicant-positions --team-id=3
 *
 * @see \Platform\Recruiting\Models\RecApplicant::reconcilePositionState()
 */
class ReconcileApplicantPositions extends Command
{
    protected $signature = 'recruiting:reconcile-applicant-positions
        {--team-id= : Optional auf ein Team beschränken}
        {--dry-run : Nur anzeigen was geändert würde, nichts schreiben}
        {--include-inactive : Auch inaktive Bewerber einbeziehen (Default: nur aktive)}
        {--limit=0 : Maximale Anzahl Bewerber pro Run (0 = alle)}';

    protected $description = 'Zieht Phase + Feldwerte + Verantwortlichen bei umgeschlüsselten Einzel-Posting-Bewerbern sauber nach; Mehrfach-Posting nur Owner + Liste.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');
        $limit = max(0, (int) $this->option('limit'));

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        $query = RecApplicant::query()
            ->whereHas('postings')
            ->with(['postings.position', 'phase', 'team']);

        if (!$this->option('include-inactive')) {
            $query->where('is_active', true);
        }
        if ($teamId) {
            $query->where('team_id', (int) $teamId);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $phaseAligned = 0;
        $ownerFilled = 0;
        $changed = 0;
        $errors = 0;
        $festgelegtSkipped = 0;
        $multiPosting = [];

        foreach ($query->cursor() as $applicant) {
            $checked++;

            // Dieselbe Regel wie RecApplicant::reconcilePositionState(): die Stelle
            // folgt einer korrigierten Anzeige nur, solange die Person sich nicht
            // festgelegt hat (aktive Buchung oder Phase ≥ 3). Dieses Kommando ruft
            // reconcilePositionState() nicht selbst auf (siehe Klassendoc), darum
            // steht der Gate-Aufruf hier separat — und zwar VOR primaryPosition(),
            // sonst liest der Rest des Loop-Durchlaufs den alten Stand.
            $ausAnzeige = $applicant->postings
                ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
                ->first()
                ?->rec_position_id;

            if ($ausAnzeige !== null && (int) $ausAnzeige !== (int) $applicant->rec_position_id) {
                if ($applicant->istFestgelegt()) {
                    $festgelegtSkipped++;
                } elseif (!$dryRun) {
                    $applicant->rec_position_id = (int) $ausAnzeige;
                    $applicant->save();
                }
            }

            $primaryPosition = $applicant->primaryPosition();
            if (!$primaryPosition) {
                continue;
            }

            // Mehrfach-Posting: nicht automatisch (Phase mehrdeutig). Separat listen.
            if ($applicant->postings->count() > 1) {
                // Owner trotzdem auffüllen, falls leer — das ist eindeutig.
                $ownerWasEmpty = !$applicant->owned_by_user_id;
                if (!$dryRun) {
                    try { $applicant->reconcilePositionState(); } catch (\Throwable $e) { $errors++; }
                }
                if ($ownerWasEmpty) { $ownerFilled++; }

                $titles = $applicant->postings->map(fn ($p) => $p->position?->title ?? '?')->unique()->implode(', ');
                $multiPosting[] = sprintf(' #%-5d %-26s : %s%s', $applicant->id, mb_substr($this->displayName($applicant), 0, 26), $titles, $ownerWasEmpty ? '  [Owner gefüllt]' : '');
                continue;
            }

            // Einzel-Posting: was würde sich ändern?
            $phaseElsewhere = $applicant->phase === null
                || (int) $applicant->phase->rec_position_id !== (int) $primaryPosition->id;
            $ownerEmpty = !$applicant->owned_by_user_id;

            if (!$phaseElsewhere && !$ownerEmpty && !$applicant->is_unrouted) {
                continue; // bereits sauber
            }

            $parts = [];
            if ($phaseElsewhere) { $parts[] = "Phase → Stelle \"{$primaryPosition->title}\" (+ Feldwerte)"; $phaseAligned++; }
            if ($ownerEmpty)     { $parts[] = 'Owner gefüllt'; $ownerFilled++; }
            if ($applicant->is_unrouted) { $parts[] = 'is_unrouted→false'; }

            $this->line(sprintf(
                ' #%-5d %-26s : %s',
                $applicant->id,
                mb_substr($this->displayName($applicant), 0, 26),
                implode(', ', $parts),
            ));

            if (!$dryRun) {
                try {
                    $applicant->reconcilePositionState();
                    $changed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error(" Fehler bei #{$applicant->id}: {$e->getMessage()}");
                }
            } else {
                $changed++;
            }
        }

        if (!empty($multiPosting)) {
            $this->warn('');
            $this->warn('MEHRFACH-POSTING — Phase NICHT angefasst (manuell prüfen ob ein Standort falsch):');
            foreach ($multiPosting as $line) {
                $this->line($line);
            }
        }

        $this->info('');
        $this->info("Geprüft:                    {$checked}");
        $this->info("Einzel-Posting geheilt:     {$changed}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("  davon Phase+Felder:       {$phaseAligned}");
        $this->info("  davon Owner gefüllt:      {$ownerFilled}");
        $this->info('Mehrfach-Posting (manuell): ' . count($multiPosting));
        $this->info("Wegen Festlegung übersprungen: {$festgelegtSkipped}");
        if ($errors > 0) {
            $this->warn("Fehler:                     {$errors}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function displayName(RecApplicant $applicant): string
    {
        $contact = $applicant->crmContactLinks?->first()?->contact;
        return $contact?->full_name ?? "(Bewerber #{$applicant->id})";
    }
}
