<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\OwnerResolver;

/**
 * Backfill/Heilung: füllt bei bestehenden Bewerbern den Verantwortlichen
 * (owned_by_user_id) auf, wenn er LEER ist.
 *
 * Hintergrund: Posting-Umhängen via Enrichment (applicant_postings.POST/DELETE)
 * setzte historisch keinen Verantwortlichen → Bewerber konnten ownerlos werden.
 * Leerer Owner = unsichtbar für die Auto-Pilot-Query
 * (whereNotNull('owned_by_user_id')) → kein Template/Reminder.
 *
 * BEWUSST nur Owner: Die Phase wird NICHT angefasst. Ein Phasen-Desync (Phase
 * gehört zu einer anderen Stelle als das Posting) ist funktional folgenlos
 * (Buchung/Warteliste/Benachrichtigung/MA-Anlage hängen am Posting), und ein
 * automatischer Phasen-Umzug würde Feldwerte verwaisen lassen.
 *
 * Ruft pro betroffenem Bewerber RecApplicant::reconcilePositionState()
 * (identische Logik wie der Live-Fix). Bestehender Owner wird nie
 * überschrieben. Idempotent.
 *
 * Aufruf:
 *   php artisan recruiting:reconcile-applicant-positions --dry-run
 *   php artisan recruiting:reconcile-applicant-positions
 *   php artisan recruiting:reconcile-applicant-positions --team-id=3
 *   php artisan recruiting:reconcile-applicant-positions --include-inactive
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

    protected $description = 'Füllt leere Verantwortliche bestehender Bewerber auf (Auto-Pilot-Sichtbarkeit). Phasen bleiben unangetastet.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');
        $limit = max(0, (int) $this->option('limit'));

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        // Nur Bewerber mit Stelle UND ohne Verantwortlichen sind betroffen.
        $query = RecApplicant::query()
            ->whereNull('owned_by_user_id')
            ->whereHas('postings')
            ->with(['postings.position', 'team']);

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
        $fixed = 0;
        $unresolved = 0;
        $errors = 0;

        foreach ($query->cursor() as $applicant) {
            $checked++;

            $primaryPosition = $applicant->primaryPosition();
            if (!$primaryPosition) {
                continue;
            }

            // Würde-Owner zur Anzeige bestimmen (gleiche Kaskade wie reconcilePositionState).
            $settings = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($applicant->team_id);
            $ownerId = OwnerResolver::resolve(
                null,
                $primaryPosition->owned_by_user_id ? (int) $primaryPosition->owned_by_user_id : null,
                (int) ($settings->getSetting('default_contact_user_id') ?? 0) ?: null,
                $applicant->team?->user_id ? (int) $applicant->team->user_id : null,
            );

            if (!$ownerId) {
                $unresolved++;
                $this->line(sprintf(
                    ' #%-5d %-28s [%s] : KEIN Owner-Kandidat (Stelle/Default/Team alle leer) → manuell',
                    $applicant->id,
                    mb_substr($this->displayName($applicant), 0, 28),
                    $primaryPosition->title,
                ));
                continue;
            }

            $this->line(sprintf(
                ' #%-5d %-28s [%s] : Owner leer → %d',
                $applicant->id,
                mb_substr($this->displayName($applicant), 0, 28),
                $primaryPosition->title,
                $ownerId,
            ));

            if (!$dryRun) {
                try {
                    $applicant->reconcilePositionState();
                    $fixed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error(" Fehler bei #{$applicant->id}: {$e->getMessage()}");
                }
            } else {
                $fixed++;
            }
        }

        $this->info('');
        $this->info("Geprüft (ownerlos):        {$checked}");
        $this->info("Owner gesetzt:             {$fixed}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("Kein Kandidat (manuell):   {$unresolved}");
        if ($errors > 0) {
            $this->warn("Fehler:                    {$errors}");
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
