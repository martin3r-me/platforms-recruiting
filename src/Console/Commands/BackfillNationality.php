<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;
use Platform\Recruiting\Support\NationalityBackfillPlanner;

/**
 * Traegt die Staatsangehoerigkeit (`nationality`) im Bestand nach — Stufe A
 * des Nation-Fixes (23.09.2026, Clara-Liste vom 28.08.).
 *
 * Regeln: NationalityBackfillPlanner. Funnel-MA holen den Wert aus dem
 * Bewerber-Feld `nationalitaet`; Import-MA ziehen ihn aus `birth_country`
 * um, wo ZAS' `Nation` bisher falsch gelandet war, und leeren das Geburtsland.
 *
 * OBSERVER-FREI, bewusst — anders als BackfillEmployeeCompany. Geschrieben
 * wird per Query-Builder, `zas_changed_at` bleibt unberuehrt. Sonst landen
 * rund 1.300 Datensaetze in der naechsten updates.csv (Vorfall 02.09.2026).
 * Die Nachlieferung korrigierter Werte an ZAS ist Stufe B: eigener Lauf,
 * angekuendigt und mit ZAS abgestimmt.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-nationality --dry-run
 *   php artisan recruiting:backfill-nationality
 *   php artisan recruiting:backfill-nationality --employee=1372
 */
class BackfillNationality extends Command
{
    protected $signature = 'recruiting:backfill-nationality
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur MA dieses Teams (Default: alle)}
        {--employee= : Nur diesen RecEmployee (ID)}';

    protected $description = 'Traegt die Staatsangehoerigkeit im MA-Bestand nach (observer-frei, kein Export-Marker)';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $team     = $this->option('team') !== null ? (int) $this->option('team') : null;
        $employee = $this->option('employee') !== null ? (int) $this->option('employee') : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] Es wird nichts geschrieben.');
        }

        $counts = $this->backfill($dryRun, $team, $employee, function (string $type, string $text): void {
            match ($type) {
                'warn'  => $this->warn($text),
                default => $this->line($text),
            };
        });

        $this->newLine();
        $this->info(sprintf(
            '%s — gesehen: %d, gesetzt: %d, uebersprungen: %d',
            $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT',
            $counts['seen'], $counts['set'], $counts['skipped'],
        ));
        if (!$dryRun && $counts['set'] > 0) {
            $this->line('Kein Export-Marker gesetzt. Nachlieferung an ZAS = Stufe B, separat und angekuendigt.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param callable(string,string):void $emit
     * @param null|callable(int):?string $applicantNationality  Bewerber-ID → Code oder null (Default: Extra-Feld `nationalitaet`)
     * @return array{seen:int, set:int, skipped:int}
     */
    public function backfill(bool $dryRun, ?int $team, ?int $employeeId, callable $emit, ?callable $applicantNationality = null): array
    {
        $applicantNationality ??= $this->defaultApplicantNationality();
        $counts = ['seen' => 0, 'set' => 0, 'skipped' => 0];

        DB::table('rec_employees')
            ->select(['id', 'first_name', 'last_name', 'rec_applicant_id', 'rec_zas_inbound_file_id', 'birth_country', 'nationality'])
            ->when($team !== null, fn ($q) => $q->where('team_id', $team))
            ->when($employeeId !== null, fn ($q) => $q->where('id', $employeeId))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($dryRun, $emit, $applicantNationality, &$counts): void {
                foreach ($rows as $row) {
                    $counts['seen']++;
                    $fromApplicant = $row->rec_applicant_id
                        ? $applicantNationality((int) $row->rec_applicant_id)
                        : null;

                    $plan = NationalityBackfillPlanner::plan([
                        'nationality'             => $row->nationality,
                        'birth_country'           => $row->birth_country,
                        'rec_zas_inbound_file_id' => $row->rec_zas_inbound_file_id,
                        'applicant_nationality'   => $fromApplicant,
                    ]);

                    $label = "#{$row->id} {$row->last_name}, {$row->first_name}";
                    if ($plan === null) {
                        $counts['skipped']++;
                        continue;
                    }

                    $counts['set']++;
                    $text = ($dryRun ? 'WUERDE ' : '') . "SETZEN  {$label}  nationality={$plan['nationality']}"
                        . (array_key_exists('birth_country', $plan) ? '  birth_country->leer (kam von ZAS)' : '');
                    $emit('line', $text);

                    if (!$dryRun) {
                        // Query-Builder: kein Observer, kein zas_changed_at.
                        DB::table('rec_employees')->where('id', $row->id)->update($plan);
                    }
                }
            });

        return $counts;
    }

    /** @return callable(int):?string */
    private function defaultApplicantNationality(): callable
    {
        $cache = [];
        return function (int $applicantId) use (&$cache): ?string {
            if (array_key_exists($applicantId, $cache)) {
                return $cache[$applicantId];
            }
            $applicant = RecApplicant::find($applicantId);
            if (!$applicant) {
                return $cache[$applicantId] = null;
            }
            $values = app(CreateEmployeeFromApplicantService::class)->collectExtraFieldValuesByName($applicant);
            $v = $values['nationalitaet'] ?? null;
            return $cache[$applicantId] = (is_string($v) && trim($v) !== '') ? trim($v) : null;
        };
    }
}
