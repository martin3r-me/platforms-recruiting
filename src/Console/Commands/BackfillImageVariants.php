<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Jobs\GenerateImageVariantsJob;
use Platform\Core\Models\ContextFile;

/**
 * Dispatcht GenerateImageVariantsJob fuer alle Bewerber-Bilder, die
 * noch keine Variants haben.
 *
 * Hintergrund: das Variant-System dispatcht beim Upload normalerweise
 * automatisch einen Job. Wenn der Queue-Worker aber nicht lief (siehe
 * Setup-Problem auf mitarbeiter.rheingedeck.de bis Mai 2026), wurden
 * fuer einige aeltere Files (z. B. Quform-Forwards, Migrations, direkte
 * API-Uploads) keine Jobs dispatched. Dieser Command holt die Luecke
 * nach.
 *
 * Wichtig: legt nur Jobs in die Queue. Worker arbeitet sie ab.
 * Idempotent — wenn der Command erneut laeuft, findet er nur Files
 * die immer noch keine Variants haben.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-image-variants --dry-run
 *   php artisan recruiting:backfill-image-variants
 *   php artisan recruiting:backfill-image-variants --team-id=3
 *   php artisan recruiting:backfill-image-variants --limit=100
 */
class BackfillImageVariants extends Command
{
    protected $signature = 'recruiting:backfill-image-variants
        {--team-id= : Optional auf ein Team beschraenken}
        {--dry-run : Nur anzeigen wie viele Jobs dispatched wuerden}
        {--limit=0 : Maximal N Jobs pro Run (0 = alle)}';

    protected $description = 'Dispatcht GenerateImageVariantsJob fuer Bewerber-Bilder ohne Variants';

    public function handle(): int
    {
        $teamId = $this->option('team-id') ? (int) $this->option('team-id') : null;
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Nur Bilder anfassen die tatsaechlich in einem extra_field_value
        // referenziert sind (= im ZAS-Export potenziell relevant). Verwaiste
        // Files (Mail-Anhaenge, abgebrochene Uploads etc.) brauchen keine
        // Variants weil sie nie gestreamt werden.
        $query = ContextFile::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('context_type', \Platform\Recruiting\Models\RecApplicant::class)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('context_file_variants')
                    ->whereColumn('context_file_variants.context_file_id', 'context_files.id');
            })
            ->whereExists(function ($q) {
                // file_id kann gespeichert sein als:
                //   - "1262"             (Single-File-Feld, raw)
                //   - "[1262]"           (Multi-File JSON-Array, ein Element)
                //   - "[1262,3456]"      (Multi-File JSON-Array, mehrere)
                //   - "[3456,1262]"
                //   - "[3456,1262,7890]"
                // Wir matchen alle Patterns.
                $q->select(DB::raw(1))
                    ->from('core_extra_field_values as v')
                    ->where('v.fieldable_type', 'rec_applicant')
                    ->whereRaw(
                        "(v.value COLLATE utf8mb4_0900_ai_ci = CAST(context_files.id AS CHAR)
                          OR v.value LIKE CONCAT('[', context_files.id, ']') COLLATE utf8mb4_0900_ai_ci
                          OR v.value LIKE CONCAT('[', context_files.id, ',%') COLLATE utf8mb4_0900_ai_ci
                          OR v.value LIKE CONCAT('%,', context_files.id, ',%') COLLATE utf8mb4_0900_ai_ci
                          OR v.value LIKE CONCAT('%,', context_files.id, ']') COLLATE utf8mb4_0900_ai_ci)"
                    );
            });

        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = (clone $query)->count();

        $this->info(sprintf(
            '%d Bewerber-Bilder ohne Variants gefunden%s.',
            $count,
            $teamId !== null ? " (team_id={$teamId})" : ''
        ));

        if ($count === 0) {
            $this->info('Nichts zu tun.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: keine Jobs dispatched. Re-run ohne --dry-run zum Anwenden.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $query->select('id')->chunkById(200, function ($files) use (&$dispatched) {
            foreach ($files as $file) {
                GenerateImageVariantsJob::dispatch($file->id);
                $dispatched++;
            }
            $this->info("  dispatched: {$dispatched}");
        });

        $this->info(sprintf(
            'OK — %d Jobs in die Queue gelegt. Worker arbeitet sie ab (siehe Forge → Processes).',
            $dispatched
        ));

        return self::SUCCESS;
    }
}
