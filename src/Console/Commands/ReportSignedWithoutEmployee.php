<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Support\EmployeeLinkBackfillPlan;
use Platform\Recruiting\Support\EmployeeMatchResolver;

/**
 * Bericht: Bewerber mit signiertem Arbeitsvertrag, die heute kein
 * Mitarbeiter sind — und die Gegenrichtung, Mitarbeiter ohne Verknuepfung.
 *
 * Hintergrund und Match-Logik stehen in EmployeeMatchResolver: der ZAS-Inbound
 * legt zurueckgekommene Mitarbeiter ohne rec_applicant_id an, weshalb der Link
 * die Frage nicht beantworten kann. Dieser Command loest sie ueber Name
 * (CRM-Kontakt UND Extra-Felder, weil der Altbestand verstuemmelte
 * Extra-Feld-Namen hat) plus Geburtsdatum.
 *
 * Grundlage fuer den Bewerber-Prune: „signierter Arbeitsvertrag" ist ein
 * Schutzkriterium, und es muss vor dem Loeschen bekannt sein, wer davon
 * ueberhaupt Mitarbeiter geworden ist. Ein Prune nach Datum haette am
 * 08.09.2026 39 signierte Arbeitsvertraege vernichtet, 21 davon von Menschen,
 * die nachweislich beim Termin waren.
 *
 * Absichtlich nur AV-Vorlagen: von 573 signierten Vertraegen sind 143
 * Infektionsschutz-Belehrungen: „hat unterschrieben" ist ohne
 * Vorlagen-Filter die falsche Menge.
 *
 * Aufruf:
 *   php artisan recruiting:report-signed-without-employee
 *   php artisan recruiting:report-signed-without-employee --only=alle
 *   php artisan recruiting:report-signed-without-employee --csv=storage/app/av-ohne-ma.csv
 *   php artisan recruiting:report-signed-without-employee --backfill-links --dry-run
 *
 * Der Bericht selbst schreibt nichts. --backfill-links setzt
 * rec_employees.rec_applicant_id, aber nur bei Voll-Namens-Treffern MIT
 * passendem Geburtsdatum, nur wenn der MA noch keinen Link hat und nur wenn
 * kein zweiter Bewerber denselben MA beansprucht.
 */
class ReportSignedWithoutEmployee extends Command
{
    protected $signature = 'recruiting:report-signed-without-employee
        {--team= : Nur Bewerber/MA dieses Teams}
        {--template=AV- : Praefix der Vertragsvorlagen-Codes (AV- = Arbeitsvertraege)}
        {--only=offen : offen|alle|kein-ma|unverlinkt|pruefen}
        {--csv= : Ergebnis zusaetzlich als CSV in diese Datei schreiben}
        {--skip-tests : Testbewerber (rec_applicants.is_test) auslassen}
        {--link= : Von Hand bestaetigte Paare setzen — bewerber:personalnummer, komma-getrennt (z. B. 612:MA18069,746:RG17786)}
        {--backfill-links : Eindeutige Treffer als rec_applicant_id nachtragen}
        {--include-second-records : Auch Zweit-Datensaetze verknuepfen, deren Bewerber schon einen Link hat}
        {--dry-run : Mit --backfill-links: nur zeigen, was verknuepft wuerde}';

    protected $description = 'Bewerber mit signiertem Arbeitsvertrag ohne Mitarbeiter-Datensatz (+ Link-Luecken)';

    /** @var array<string,string> */
    private const VERDICT_LABELS = [
        EmployeeMatchResolver::VERDICT_LINKED => 'MA (verlinkt)',
        EmployeeMatchResolver::VERDICT_UNLINKED => 'MA, Link fehlt',
        EmployeeMatchResolver::VERDICT_CHECK => 'pruefen',
        EmployeeMatchResolver::VERDICT_NONE => 'KEIN MA',
    ];

    public function handle(): int
    {
        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $prefix = (string) $this->option('template');
        $only = (string) $this->option('only');

        if (!in_array($only, ['offen', 'alle', 'kein-ma', 'unverlinkt', 'pruefen'], true)) {
            $this->error("--only muss offen|alle|kein-ma|unverlinkt|pruefen sein (war: {$only}).");

            return self::FAILURE;
        }

        if ($this->option('link')) {
            return $this->linkPairs((string) $this->option('link'), (bool) $this->option('dry-run'));
        }

        $cohort = $this->loadCohort($teamId, $prefix, (bool) $this->option('skip-tests'));
        if ($cohort === []) {
            $this->warn("Keine Bewerber mit signiertem Vertrag (Vorlagen-Praefix '{$prefix}') gefunden.");

            return self::SUCCESS;
        }

        $names = $this->loadNames(array_keys($cohort));
        $employees = $this->loadEmployees($teamId);
        $employeesById = [];
        foreach ($employees as $employee) {
            $employeesById[(int) $employee['id']] = $employee;
        }

        $rows = [];
        $counts = array_fill_keys(array_keys(self::VERDICT_LABELS), 0);
        $unverifiable = 0;
        $linkable = [];

        foreach ($cohort as $applicantId => $contract) {
            $candidate = [
                'id' => $applicantId,
                'names' => $names[$applicantId]['names'] ?? [],
                'birth_date' => $names[$applicantId]['birth_date'] ?? null,
            ];

            $hits = EmployeeMatchResolver::match($candidate, $employees);
            $verdict = EmployeeMatchResolver::verdict($hits);
            $counts[$verdict]++;

            $noBirthDate = ($candidate['birth_date'] ?? null) === null;
            if ($verdict === EmployeeMatchResolver::VERDICT_NONE && $noBirthDate) {
                $unverifiable++;
            }

            // Bewerber, die schon einen verknuepften Mitarbeiter haben, werden
            // NICHT automatisch um einen zweiten erweitert. Fachlich waere das
            // oft richtig (ZAS bedient zwei Firmen, eine Person kann bei beiden
            // angestellt sein — RG- und MA-Nummer am selben Menschen), aber es
            // hat eine sichtbare Folge: ZasEmployeeFileController loest die
            // Vertragsakte ueber rec_applicant_id auf, die Akte des zweiten
            // Datensatzes zeigt danach den Vertrag der anderen Firma. Diese
            // Entscheidung gehoert einem Menschen, nicht einem Automatismus —
            // per --include-second-records oder gezielt per --link.
            if ($verdict !== EmployeeMatchResolver::VERDICT_LINKED || $this->option('include-second-records')) {
                foreach (EmployeeMatchResolver::linkableEmployeeIds($hits, $employeesById) as $employeeId) {
                    $linkable[$employeeId][] = $applicantId;
                }
            }

            $rows[] = [
                'applicant_id' => $applicantId,
                'name' => $this->displayName($candidate['names']),
                'birth_date' => $candidate['birth_date'] ?? '',
                'signed_at' => $contract['signed_at'],
                'templates' => $contract['templates'],
                'verdict' => $verdict,
                'unverifiable' => $verdict === EmployeeMatchResolver::VERDICT_NONE && $noBirthDate,
                'hits' => $hits,
            ];
        }

        $this->renderSummary($cohort, $counts, $unverifiable, $prefix);
        $this->renderTable($rows, $only);

        if ($this->option('csv')) {
            $this->writeCsv($rows, (string) $this->option('csv'));
        }

        if ($this->option('backfill-links')) {
            return $this->backfillLinks($linkable, $employeesById);
        }

        return self::SUCCESS;
    }

    /**
     * Bewerber mit mindestens einem signierten Vertrag der gewaehlten
     * Vorlagen-Familie. `signature_data` muss vorhanden sein: `completed`
     * allein ist der Status, die Unterschrift ist der Beweis.
     *
     * @return array<int,array{signed_at:string,templates:string}>
     */
    protected function loadCohort(?int $teamId, string $prefix, bool $skipTests = false): array
    {
        $rows = DB::table('rec_contracts as c')
            ->join('rec_contract_templates as t', 't.id', '=', 'c.rec_contract_template_id')
            ->where('c.status', 'completed')
            ->whereNotNull('c.signature_data')
            ->where('t.code', 'like', $prefix . '%')
            ->when($teamId !== null, fn ($q) => $q->where('c.team_id', $teamId))
            ->when($skipTests, fn ($q) => $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('rec_applicants')
                    ->whereColumn('rec_applicants.id', 'c.rec_applicant_id')
                    ->where('rec_applicants.is_test', false);
            }))
            ->groupBy('c.rec_applicant_id')
            ->selectRaw('c.rec_applicant_id as applicant_id')
            ->selectRaw('MIN(c.completed_at) as signed_at')
            ->selectRaw('GROUP_CONCAT(DISTINCT t.code) as templates')
            ->get();

        $cohort = [];
        foreach ($rows as $row) {
            $cohort[(int) $row->applicant_id] = [
                'signed_at' => substr((string) $row->signed_at, 0, 10),
                'templates' => (string) $row->templates,
            ];
        }

        return $cohort;
    }

    /**
     * Namen aus BEIDEN Quellen: CRM-Kontakt und Extra-Felder. Sie weichen im
     * Altbestand voneinander ab (CRM „Dario Halabarec" vs. Extra-Feld „Dario
     * Dhalabarec", CRM „Anna Eßer" vs. Extra-Feld „Barbara Eßer"). Wer nur
     * eine Quelle nimmt, produziert falsche „kein MA"-Urteile.
     *
     * @param array<int,int> $applicantIds
     * @return array<int,array{names:array<int,array{first:?string,last:?string}>,birth_date:?string}>
     */
    protected function loadNames(array $applicantIds): array
    {
        // Alle drei Formen, die in linkable_type/fieldable_type vorkommen
        // koennen: der Morph-Alias (seit die morphMap im ServiceProvider
        // existiert), die vollqualifizierte Klasse (aeltere Zeilen) und was
        // getMorphClass() im aktuellen Boot-Zustand liefert — ohne geladenen
        // Provider ist das die Klasse, nicht der Alias.
        $morphTypes = array_values(array_unique([
            'rec_applicant',
            (new RecApplicant())->getMorphClass(),
            RecApplicant::class,
        ]));
        $result = [];
        $fields = [];

        DB::table('crm_contact_links as l')
            ->join('crm_contacts as k', 'k.id', '=', 'l.contact_id')
            ->whereIn('l.linkable_type', $morphTypes)
            ->whereIn('l.linkable_id', $applicantIds)
            ->select('l.linkable_id', 'k.first_name', 'k.last_name')
            ->get()
            ->each(function ($row) use (&$result): void {
                $result[(int) $row->linkable_id]['names'][] = [
                    'first' => $row->first_name,
                    'last' => $row->last_name,
                ];
            });

        DB::table('core_extra_field_values as v')
            ->join('core_extra_field_definitions as d', 'd.id', '=', 'v.definition_id')
            ->whereIn('v.fieldable_type', $morphTypes)
            ->whereIn('v.fieldable_id', $applicantIds)
            ->whereIn('d.name', ['vorname', 'nachname', 'geburtsdatum'])
            ->select('v.fieldable_id', 'd.name', 'v.value')
            ->get()
            ->each(function ($row) use (&$fields): void {
                $fields[(int) $row->fieldable_id][(string) $row->name] = $row->value;
            });

        foreach ($fields as $applicantId => $values) {
            if (($values['vorname'] ?? null) || ($values['nachname'] ?? null)) {
                $result[$applicantId]['names'][] = [
                    'first' => $values['vorname'] ?? null,
                    'last' => $values['nachname'] ?? null,
                ];
            }
            $result[$applicantId]['birth_date'] = $this->normalizeBirthDate($values['geburtsdatum'] ?? null);
        }

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    protected function loadEmployees(?int $teamId): array
    {
        return DB::table('rec_employees')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->get(['id', 'personnel_number', 'first_name', 'last_name', 'birth_date', 'rec_applicant_id'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function normalizeBirthDate(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, trim((string) $value));
            if ($parsed instanceof \DateTimeImmutable) {
                $year = (int) $parsed->format('Y');

                return ($year < 1900 || $year > 2100) ? null : $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /** @param array<int,array{first:?string,last:?string}> $names */
    private function displayName(array $names): string
    {
        foreach ($names as $name) {
            $joined = trim(((string) ($name['first'] ?? '')) . ' ' . ((string) ($name['last'] ?? '')));
            if ($joined !== '') {
                return $joined;
            }
        }

        return '(ohne Namen)';
    }

    /**
     * @param array<int,array{signed_at:string,templates:string}> $cohort
     * @param array<string,int> $counts
     */
    private function renderSummary(array $cohort, array $counts, int $unverifiable, string $prefix): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Kohorte: %d Bewerber mit signiertem Vertrag (Vorlagen %s*).',
            count($cohort),
            $prefix
        ));

        foreach (self::VERDICT_LABELS as $verdict => $label) {
            $this->line(sprintf('  %-16s %d', $label, $counts[$verdict] ?? 0));
        }

        if ($unverifiable > 0) {
            $this->warn(sprintf(
                '  davon %d ohne Geburtsdatum am Bewerber — „KEIN MA" ist dort unbewiesen, nicht widerlegt.',
                $unverifiable
            ));
        }
        $this->newLine();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderTable(array $rows, string $only): void
    {
        $wanted = match ($only) {
            'alle' => array_keys(self::VERDICT_LABELS),
            'kein-ma' => [EmployeeMatchResolver::VERDICT_NONE],
            'unverlinkt' => [EmployeeMatchResolver::VERDICT_UNLINKED],
            'pruefen' => [EmployeeMatchResolver::VERDICT_CHECK],
            default => [
                EmployeeMatchResolver::VERDICT_NONE,
                EmployeeMatchResolver::VERDICT_CHECK,
                EmployeeMatchResolver::VERDICT_UNLINKED,
            ],
        };

        $filtered = array_values(array_filter($rows, fn ($row) => in_array($row['verdict'], $wanted, true)));
        usort($filtered, function ($a, $b) {
            $rank = [
                EmployeeMatchResolver::VERDICT_NONE => 0,
                EmployeeMatchResolver::VERDICT_CHECK => 1,
                EmployeeMatchResolver::VERDICT_UNLINKED => 2,
                EmployeeMatchResolver::VERDICT_LINKED => 3,
            ];

            return [$rank[$a['verdict']], $a['signed_at']] <=> [$rank[$b['verdict']], $b['signed_at']];
        });

        if ($filtered === []) {
            $this->info('Keine Zeilen fuer diese Auswahl.');

            return;
        }

        $this->table(
            ['ID', 'Name', 'Geb.', 'Signiert', 'Vorlagen', 'Urteil', 'Treffer'],
            array_map(fn ($row) => [
                $row['applicant_id'],
                mb_strimwidth($row['name'], 0, 28, '…'),
                $row['birth_date'] !== '' ? $row['birth_date'] : ($row['unverifiable'] ? 'fehlt!' : ''),
                $row['signed_at'],
                mb_strimwidth($row['templates'], 0, 18, '…'),
                self::VERDICT_LABELS[$row['verdict']],
                mb_strimwidth($this->hitSummary($row['hits']), 0, 46, '…'),
            ], $filtered)
        );
    }

    /** @param array<int,array{label:string,pass:string,birth_match:bool}> $hits */
    private function hitSummary(array $hits): string
    {
        return implode(', ', array_map(
            fn ($hit) => $hit['label'] . ' [' . $hit['pass'] . ($hit['birth_match'] ? '+geb' : '') . ']',
            $hits
        ));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function writeCsv(array $rows, string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error("CSV-Verzeichnis nicht anlegbar: {$directory}");

            return;
        }

        $handle = @fopen($path, 'w');
        if ($handle === false) {
            $this->error("CSV nicht schreibbar: {$path}");

            return;
        }

        // BOM, damit Excel die Umlaute nicht zerlegt.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['bewerber_id', 'name', 'geburtsdatum', 'signiert_am', 'vorlagen', 'urteil', 'treffer'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['applicant_id'],
                $row['name'],
                $row['birth_date'],
                $row['signed_at'],
                $row['templates'],
                self::VERDICT_LABELS[$row['verdict']],
                $this->hitSummary($row['hits']),
            ], ';');
        }
        fclose($handle);

        $this->info("CSV geschrieben: {$path} (" . count($rows) . ' Zeilen)');
    }

    /**
     * @param array<int,array<int,int>> $linkable Employee-ID => Bewerber-IDs
     * @param array<int,array<string,mixed>> $employeesById
     */
    private function backfillLinks(array $claims, array $employeesById): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $plan = EmployeeLinkBackfillPlan::build($claims);
        $written = 0;
        $skipped = 0;

        foreach ($plan['ambiguous'] as $employeeId => $applicantIds) {
            $this->warn(sprintf(
                '  %s uebersprungen: von %d Bewerbern beansprucht (%s) — Handarbeit.',
                $this->employeeLabel($employeeId, $employeesById),
                count($applicantIds),
                implode(', ', $applicantIds)
            ));
            $skipped++;
        }

        if ($plan['link'] === []) {
            $this->info('Kein eindeutiger Treffer zum Nachtragen (Voll-Name + Geburtsdatum, MA ohne Link).');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Wuerde verknuepfen' : 'Verknuepfe') . ':');

        foreach ($plan['link'] as $employeeId => $applicantId) {
            $label = $this->employeeLabel($employeeId, $employeesById);
            $this->line(sprintf('  MA %s -> Bewerber #%d', $label, $applicantId));

            if ($dryRun) {
                continue;
            }

            // Direktes Update ohne Observer: ein gesetzter Link ist keine
            // fachliche Aenderung am MA und darf keinen ZAS-Export ausloesen
            // (RecEmployeeExportObserver stempelt sonst zas_changed_at).
            // whereNull ist der Wettlauf-Schutz — zwischen Bericht und
            // Schreiben kann der Link von Hand gesetzt worden sein.
            $affected = DB::table('rec_employees')
                ->where('id', $employeeId)
                ->whereNull('rec_applicant_id')
                ->update(['rec_applicant_id' => $applicantId]);

            if ($affected === 1) {
                $written++;
                Log::info('[recruiting:report-signed-without-employee] Link nachgetragen', [
                    'employee_id' => $employeeId,
                    'rec_applicant_id' => $applicantId,
                ]);
            } else {
                $skipped++;
                $this->warn(sprintf('  %s uebersprungen: Link wurde zwischenzeitlich gesetzt.', $label));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d %s, %d uebersprungen.',
            $dryRun ? 'Probelauf' : 'Fertig',
            $dryRun ? count($plan['link']) : $written,
            $dryRun ? 'wuerden verknuepft' : 'verknuepft',
            $skipped
        ));

        return self::SUCCESS;
    }

    /** @param array<int,array<string,mixed>> $employeesById */
    private function employeeLabel(int $employeeId, array $employeesById): string
    {
        $number = $employeesById[$employeeId]['personnel_number'] ?? null;

        return ($number !== null && $number !== '') ? (string) $number : '#' . $employeeId;
    }

    /**
     * Von Hand bestaetigte Paare verknuepfen.
     *
     * Die schwachen Paesse (Nachname, Teil-Name, nur Geburtsdatum) duerfen nie
     * automatisch schreiben — aber sie liefern die Kandidaten, die ein Mensch
     * dann bestaetigt. Dieser Weg fuehrt genau diese Entscheidung aus, ohne
     * dass jemand SQL auf der Produktion tippt. Jedes Paar wird trotzdem
     * geprueft: Bewerber muss existieren, Mitarbeiter muss auffindbar sein,
     * und ein bereits gesetzter Link auf einen ANDEREN Bewerber wird nicht
     * ueberschrieben — sonst haengt der Vertrag danach in der falschen Akte.
     */
    private function linkPairs(string $raw, bool $dryRun): int
    {
        $rows = [];
        $errors = 0;
        $written = 0;

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            if (!preg_match('/^(\d+)\s*:\s*(.+)$/', $pair, $m)) {
                $this->error("Ungueltiges Paar '{$pair}' — erwartet bewerber:personalnummer.");
                $errors++;
                continue;
            }

            $applicantId = (int) $m[1];
            $key = trim($m[2]);

            $applicant = DB::table('rec_applicants')->where('id', $applicantId)->first(['id']);
            if ($applicant === null) {
                $rows[] = [$applicantId, $key, '—', 'Bewerber existiert nicht'];
                $errors++;
                continue;
            }

            // Mitarbeiter per Personalnummer oder per #id ansprechbar: die
            // nummernlosen Faelle haben nur eine ID.
            $query = DB::table('rec_employees');
            $employees = str_starts_with($key, '#')
                ? $query->where('id', (int) ltrim($key, '#'))->get(['id', 'personnel_number', 'rec_applicant_id'])
                : $query->where('personnel_number', $key)->get(['id', 'personnel_number', 'rec_applicant_id']);

            if ($employees->isEmpty()) {
                $rows[] = [$applicantId, $key, '—', 'Mitarbeiter nicht gefunden'];
                $errors++;
                continue;
            }
            if ($employees->count() > 1) {
                $rows[] = [$applicantId, $key, '—', 'mehrdeutig: ' . $employees->pluck('id')->implode(', ')];
                $errors++;
                continue;
            }

            $employee = $employees->first();
            $current = $employee->rec_applicant_id !== null ? (int) $employee->rec_applicant_id : null;

            if ($current === $applicantId) {
                $rows[] = [$applicantId, $key, $employee->id, 'schon verknuepft'];
                continue;
            }
            if ($current !== null) {
                $rows[] = [$applicantId, $key, $employee->id, "haengt an Bewerber #{$current} — nicht ueberschrieben"];
                $errors++;
                continue;
            }

            if ($dryRun) {
                $rows[] = [$applicantId, $key, $employee->id, 'wuerde verknuepft'];
                continue;
            }

            $affected = DB::table('rec_employees')
                ->where('id', $employee->id)
                ->whereNull('rec_applicant_id')
                ->update(['rec_applicant_id' => $applicantId]);

            if ($affected === 1) {
                $written++;
                Log::info('[recruiting:report-signed-without-employee] Paar von Hand verknuepft', [
                    'employee_id' => (int) $employee->id,
                    'rec_applicant_id' => $applicantId,
                ]);
                // Haengen jetzt ZWEI Anstellungen an der Bewerbung (RG + MA),
                // stempelt der gemeinsame person_key sie als eine Person —
                // der Einsatz-Abgleich der Statistik liest darueber.
                $anstellungen = DB::table('rec_employees')
                    ->where('rec_applicant_id', $applicantId)
                    ->pluck('id')->map(fn ($i) => (int) $i)->all();
                if (count($anstellungen) > 1) {
                    \Platform\Recruiting\Services\Zas\PersonPairLinker::stamp($anstellungen, null);
                    $rows[] = [$applicantId, $key, $employee->id, 'verknuepft + als Person gestempelt (' . count($anstellungen) . ' Anstellungen)'];
                    continue;
                }
                $rows[] = [$applicantId, $key, $employee->id, 'verknuepft'];
            } else {
                $rows[] = [$applicantId, $key, $employee->id, 'zwischenzeitlich gesetzt — uebersprungen'];
                $errors++;
            }
        }

        $this->table(['Bewerber', 'Schluessel', 'MA', 'Ergebnis'], $rows);
        $this->newLine();
        $this->info(sprintf(
            '%s: %d verknuepft, %d beanstandet.',
            $dryRun ? 'Probelauf' : 'Fertig',
            $written,
            $errors
        ));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
