<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\ShortTermDayBudget;

/**
 * Traegt den Startwert des Tagekontos aus dem unterschriebenen Arbeitsvertrag
 * im Mitarbeiter-Bestand nach (30.09.2026).
 *
 * WARUM: Geschrieben wird der Startwert erst seit dem 25.09.2026, und nur beim
 * Unterschreiben bzw. bei der MA-Anlage. Wer im laufenden Jahr davor
 * unterschrieben hat, traegt die §15-Erklaerung laengst in
 * rec_contracts.pre_signing_data — das Feld ist nur nie gefuellt worden. Ohne
 * diesen Lauf ginge die neue ZAS-Spalte `TageErlaubt` fuer diese Menschen leer
 * raus, und ZAS zaehlte von der vollen Grenze herunter statt vom Rest.
 *
 * NUR DAS LAUFENDE KALENDERJAHR. Das Kontingent gilt je Jahr (§8 Abs. 1 Nr. 2
 * SGB IV). Eine Erklaerung aus 2025 sagt nichts ueber 2026 — solche Vertraege
 * bleiben unberuehrt, das Feld bleibt leer, und leer heisst fuer ZAS
 * "keine Grundlage".
 *
 * NUR LEERE FELDER, genauer: nur wenn fuer DIESES Jahr noch nichts steht —
 * dieselbe Regel wie beim Unterschreiben. Ein vorhandener Startwert ist ein
 * Anfangsbestand, den ein spaeterer Lauf nicht zuruecksetzen darf, sonst
 * faengt ZAS' Konto von vorn an, obwohl zwischendurch gearbeitet wurde.
 *
 * OBSERVER-FREI, bewusst — Muster BackfillEmployerDeclaration. Geschrieben
 * wird per Query-Builder; `zas_changed_at` bleibt unberuehrt. Ein
 * Eloquent-Lauf ueber knapp 300 Menschen spuelte sonst denselben Bestand mit
 * VOLLEN Zeilen in die naechste updates.csv (Vorfall 02.09.2026). Die
 * Nachlieferung an ZAS ist ein eigener, angekuendigter Schritt.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-tagekonto --dry-run
 *   php artisan recruiting:backfill-tagekonto
 *   php artisan recruiting:backfill-tagekonto --employee=1372
 */
class BackfillShortTermDayBudget extends Command
{
    protected $signature = 'recruiting:backfill-tagekonto
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}
        {--team= : Nur MA dieses Teams (Default: alle)}
        {--employee= : Nur diesen RecEmployee (ID)}';

    protected $description = 'Traegt den Startwert des Tagekontos aus dem Arbeitsvertrag nach (observer-frei, kein Export-Marker)';

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
            $counts['seen'],
            $counts['set'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(string,string):void $out
     * @return array{seen:int, set:int, skipped:int}
     */
    public function backfill(bool $dryRun, ?int $teamId, ?int $employeeId, callable $out): array
    {
        $year = ShortTermDayBudget::yearOf();

        $query = DB::table('rec_employees as e')
            ->leftJoin('rec_employee_hr_data as hr', 'hr.rec_employee_id', '=', 'e.id')
            ->whereNotNull('e.rec_applicant_id')
            // Nur wo fuer DIESES Jahr noch nichts steht.
            ->where(function ($q) use ($year) {
                $q->whereNull('hr.short_term_days_allowed_year')
                    ->orWhere('hr.short_term_days_allowed_year', '!=', $year);
            });

        if ($teamId !== null) {
            $query->where('e.team_id', $teamId);
        }
        if ($employeeId !== null) {
            $query->where('e.id', $employeeId);
        }

        $counts = ['seen' => 0, 'set' => 0, 'skipped' => 0];
        $limits = [];

        foreach ($query->orderBy('e.id')->get(['e.id', 'e.team_id', 'e.rec_applicant_id', 'hr.id as hr_id']) as $row) {
            $counts['seen']++;

            $preSigningData = $this->declarationOfCurrentYear((int) $row->rec_applicant_id, $year);
            if ($preSigningData === null) {
                $counts['skipped']++;
                continue;
            }

            $teamKey = (int) $row->team_id;
            $limits[$teamKey] ??= (int) RecApplicantSettings::getOrCreateForTeam($teamKey)->getSetting('short_term_day_limit');

            $allowed = ShortTermDayBudget::allowedFrom($preSigningData, $limits[$teamKey]);
            if ($allowed === null) {
                $counts['skipped']++;
                continue;
            }

            $counts['set']++;
            $out('line', sprintf('MA #%d: TageErlaubt=%d (Jahr %d)', $row->id, $allowed, $year));

            if ($dryRun) {
                continue;
            }

            $this->write($row, $allowed, $year);
        }

        return $counts;
    }

    /**
     * Juengste §15-Erklaerung aus einem unterschriebenen AV-Vertrag DIESES
     * Kalenderjahres — oder null.
     *
     * Bewusst nicht ueber SignedContractDeclarations: das liest ohne
     * Jahresfilter, und eine Erklaerung aus dem Vorjahr wuerde hier ein
     * Kontingent fuer das falsche Jahr erzeugen.
     */
    private function declarationOfCurrentYear(int $applicantId, int $year): ?array
    {
        $rows = DB::table('rec_contracts as c')
            ->join('rec_contract_templates as t', 'c.rec_contract_template_id', '=', 't.id')
            ->where('c.rec_applicant_id', $applicantId)
            ->whereNotNull('c.signed_at')
            ->where('c.status', '!=', 'cancelled')
            ->where('t.code', 'like', 'AV-%')
            // Bereich statt whereYear(): nutzt den Index und ist unabhaengig
            // von der Datums-Funktion des Treibers.
            ->where('c.signed_at', '>=', $year . '-01-01 00:00:00')
            ->where('c.signed_at', '<', ($year + 1) . '-01-01 00:00:00')
            ->orderByDesc('c.signed_at')
            ->orderByDesc('c.id')
            ->limit(10)
            ->get(['c.pre_signing_data']);

        foreach ($rows as $row) {
            if ($row->pre_signing_data === null) {
                continue;
            }
            $data = json_decode((string) $row->pre_signing_data, true);
            if (is_array($data) && array_key_exists('par15_has_previous', $data)) {
                return $data;
            }
        }

        return null;
    }

    /** Query-Builder statt Eloquent — kein Export-Marker, keine Modell-Hooks. */
    private function write(object $row, int $allowed, int $year): void
    {
        $values = [
            'short_term_days_allowed'      => $allowed,
            'short_term_days_allowed_year' => $year,
            'updated_at'                   => now(),
        ];

        if ($row->hr_id !== null) {
            DB::table('rec_employee_hr_data')->where('id', $row->hr_id)->update($values);

            return;
        }

        DB::table('rec_employee_hr_data')->insert($values + [
            'uuid'            => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'rec_employee_id' => $row->id,
            // NOT NULL — ohne Team-Id bricht der Insert, und der Query-Builder
            // fuellt im Gegensatz zu Eloquent nichts nach.
            'team_id'         => $row->team_id,
            'created_at'      => now(),
        ]);
    }
}
