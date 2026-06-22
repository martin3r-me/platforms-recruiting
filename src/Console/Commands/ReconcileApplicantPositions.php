<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Backfill/Heilung: gleicht bei bestehenden Bewerbern Phase + Verantwortlichen
 * an die aktuelle PRIMÄRE Stelle an.
 *
 * Hintergrund: Vor dem reconcilePositionState()-Fix änderte das Posting-
 * Umhängen (Enrichment via applicant_postings.POST/DELETE, manuelles
 * Verknüpfen, HR-Zuweisung) nur das Pivot — rec_phase_id und
 * owned_by_user_id blieben auf der alten Stelle stehen. Folge: Bewerber mit
 * z.B. Köln-Posting aber Düsseldorf-Phase und (im schlimmsten Fall) leerem
 * Owner → unsichtbar für den Auto-Pilot.
 *
 * Dieser Command läuft einmalig über alle Altfälle und ruft pro Bewerber
 * RecApplicant::reconcilePositionState() — exakt dieselbe Logik wie der Fix.
 * Idempotent: bereits saubere Bewerber bleiben unangetastet.
 *
 * Aufruf:
 *   php artisan recruiting:reconcile-applicant-positions --dry-run
 *   php artisan recruiting:reconcile-applicant-positions
 *   php artisan recruiting:reconcile-applicant-positions --team-id=3
 *   php artisan recruiting:reconcile-applicant-positions --include-inactive
 *
 * @see \Platform\Recruiting\Models\RecApplicant::reconcilePositionState()
 * @see \Platform\Recruiting\Services\PositionReconciler
 */
class ReconcileApplicantPositions extends Command
{
    protected $signature = 'recruiting:reconcile-applicant-positions
        {--team-id= : Optional auf ein Team beschränken}
        {--dry-run : Nur anzeigen was geändert würde, nichts schreiben}
        {--include-inactive : Auch inaktive Bewerber einbeziehen (Default: nur aktive)}
        {--limit=0 : Maximale Anzahl Bewerber pro Run (0 = alle)}';

    protected $description = 'Heilt Phase + Verantwortlichen bestehender Bewerber, deren Posting auf eine andere Stelle umgehängt wurde (Desync-Backfill).';

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
        $phaseFixed = 0;
        $ownerFixed = 0;
        $unroutedFixed = 0;
        $changed = 0;
        $errors = 0;

        foreach ($query->cursor() as $applicant) {
            $checked++;

            $plan = $applicant->resolvePositionReconciliation();
            if ($plan === null) {
                continue; // keine primäre Stelle → nichts abzugleichen
            }

            $decision = $plan['decision'];
            $oldPhaseId = (int) $applicant->rec_phase_id;
            $oldOwnerId = $applicant->owned_by_user_id ? (int) $applicant->owned_by_user_id : null;

            $willChangePhase = $decision['phase_id'] !== null && $decision['phase_id'] !== $oldPhaseId;
            $willChangeOwner = $decision['owner_id'] !== null && $decision['owner_id'] !== $oldOwnerId;
            $willRoute = (bool) $applicant->is_unrouted;

            if (!$willChangePhase && !$willChangeOwner && !$willRoute) {
                continue; // bereits sauber
            }

            $parts = [];
            if ($willChangePhase) {
                $parts[] = "Phase {$oldPhaseId}→{$decision['phase_id']}";
            }
            if ($willChangeOwner) {
                $parts[] = 'Owner ' . ($oldOwnerId ?? 'leer') . "→{$decision['owner_id']}";
            }
            if ($willRoute) {
                $parts[] = 'is_unrouted→false';
            }

            $this->line(sprintf(
                ' #%-5d %-28s [%s] : %s',
                $applicant->id,
                mb_substr($this->displayName($applicant), 0, 28),
                $plan['primary_position']->title,
                implode(', ', $parts),
            ));

            if ($willChangePhase) { $phaseFixed++; }
            if ($willChangeOwner) { $ownerFixed++; }
            if ($willRoute) { $unroutedFixed++; }

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

        $this->info('');
        $this->info("Geprüft:                 {$checked}");
        $this->info("Betroffen/geheilt:       {$changed}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("  davon Phase korrigiert: {$phaseFixed}");
        $this->info("  davon Owner gesetzt:    {$ownerFixed}");
        $this->info("  davon Routing gesetzt:  {$unroutedFixed}");
        if ($errors > 0) {
            $this->warn("Fehler:                  {$errors}");
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
