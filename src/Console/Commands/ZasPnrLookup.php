<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasEmployeeMatcher;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;
use Platform\Recruiting\Support\EmployeeMatchResolver;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Nachschlagewerk fuer eine ZAS-Personalnummer: kennen wir die, und wenn nicht
 * — haben wir sie je gesehen?
 *
 * ANLASS (09.09.2026): in Veranstaltungen stehen Einsaetze mit „PNr unbekannt"
 * (71 Zeilen, 32 verschiedene Nummern). Die Frage dahinter war jedes Mal
 * dieselbe und jedes Mal nur mit SSH und SQL zu beantworten: ist das ein
 * Mitarbeiter, den wir nicht haben — oder haben wir ihn und die Zuordnung ist
 * gescheitert? Das sind zwei voellig verschiedene Konsequenzen: das eine ist
 * eine Frage an ZAS, das andere ein Fehler bei uns.
 *
 * Vier Auskuenfte je Nummer:
 *  1. Mitarbeiter-Treffer ueber dieselbe Kaskade wie der Import
 *     (exakt → Kurzform → praefixlos), damit „bekannt" hier und dort dasselbe
 *     heisst.
 *  2. Geschwister-Datensaetze: gleicher Name UND gleiches Geburtsdatum, andere
 *     Nummer. Das ist der RG-/MA-Fall — ZAS bedient zwei Firmen, eine Person
 *     kann bei beiden angestellt sein und hat dann zwei Personalnummern.
 *  3. Dispo-Einsaetze auf diese Nummer, aufgeloest oder nicht.
 *  4. Mit --files: kam die Nummer je in einer gespeicherten Lieferung — und was
 *     hat der Import damals daraus gemacht? Der Bericht jedes Imports liegt als
 *     JSON in rec_zas_inbound_files.notes (created/updated/skipped/failed je
 *     Zeile, mit Grund), abgewiesene Lieferungen tragen status='rejected'.
 *     Beides wird ausgelesen: „ABGEWIESEN: <Grund>" ist die Antwort auf die
 *     Frage, ob ein Mitarbeiter mal mitkam und abgelehnt wurde.
 *     GRENZE: bei einer strukturell verschobenen Zeile steht die Nummer
 *     womoeglich nicht in der Spalte ZasPersonalNr — dann findet die Suche sie
 *     nicht. Solche Zeilen weist der Import als Ganzes ab und nennt sie in den
 *     Notizen der Lieferung ohne Personalnummer.
 *
 * --unresolved beantwortet die Praeventionsfrage fuer alle offenen Faelle auf
 * einmal: welche Einsatz-Nummern sind unaufgeloest, und ist die Nummer bei uns
 * eigentlich bekannt? Ein „bekannt, aber nicht verknuepft" waere ein echter
 * Zuordnungsfehler und kein Lieferproblem.
 *
 * Nur lesend.
 *
 * Aufruf:
 *   php artisan recruiting:zas-pnr-lookup MA17042
 *   php artisan recruiting:zas-pnr-lookup MA17042 --files
 *   php artisan recruiting:zas-pnr-lookup --unresolved
 */
class ZasPnrLookup extends Command
{
    protected $signature = 'recruiting:zas-pnr-lookup
        {pnr? : Personalnummer, z. B. MA17042 oder RG18292}
        {--files : Zusaetzlich die gespeicherten ZAS-Lieferungen durchsuchen (langsamer)}
        {--unresolved : Alle Dispo-Einsaetze mit unbekannter Personalnummer auflisten}
        {--team= : Team-Scope (Default: recruiting.zas.inbound_team_id)}';

    protected $description = 'Personalnummer nachschlagen: Mitarbeiter, Zweit-Datensatz, Einsaetze, Lieferungen (nur lesend)';

    public function __construct(
        private ZasEmployeeMatcher $matcher,
        private ZasInboundCsvParser $parser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $teamId = $this->option('team') !== null
            ? (int) $this->option('team')
            : (config('recruiting.zas.inbound_team_id') !== null ? (int) config('recruiting.zas.inbound_team_id') : null);

        if ($this->option('unresolved')) {
            return $this->unresolved($teamId);
        }

        $raw = (string) $this->argument('pnr');
        if (trim($raw) === '') {
            $this->error('Bitte eine Personalnummer angeben oder --unresolved nutzen.');

            return self::FAILURE;
        }

        return $this->lookup(trim($raw), $teamId);
    }

    private function lookup(string $raw, ?int $teamId): int
    {
        $prefix = (string) config('recruiting.zas.company_prefix', ZasPersonnelNumber::DEFAULT_PREFIX);
        $normalized = ZasPersonnelNumber::normalize($raw, $prefix) ?? $raw;
        $shortened = ZasPersonnelNumber::shortenedForm($normalized);

        $this->newLine();
        $this->info("Personalnummer {$raw}");
        $this->line('  normalisiert   ' . $normalized . ($shortened !== null ? "   (Kurzform {$shortened})" : ''));
        $this->line('  Firma          ' . (ZasPersonnelNumber::prefixOf($normalized) ?? '— (praefixlos, gilt als ' . $prefix . ')'));

        // 1. Mitarbeiter — dieselbe Kaskade wie der Import.
        $match = $this->matcher->match(null, $normalized, $teamId, $prefix);
        $employee = $match['employee'];

        $this->newLine();
        if ($employee === null) {
            $this->warn('  KEIN Mitarbeiter mit dieser Nummer (auch nicht als Kurzform oder praefixlos).');
        } else {
            $this->line('<comment>  Mitarbeiter gefunden</comment> (Treffer via ' . $match['via'] . '):');
            $this->table(
                ['MA', 'Name', 'Geburtsdatum', 'PersNr', 'Firma', 'aktiv', 'Bewerber', 'aus ZAS-Lieferung'],
                [[
                    $employee->id,
                    trim(((string) $employee->first_name) . ' ' . ((string) $employee->last_name)),
                    $employee->birth_date?->format('Y-m-d') ?? '—',
                    $employee->personnel_number ?? '—',
                    $employee->company ?? '—',
                    $employee->is_active ? 'ja' : 'nein',
                    $employee->rec_applicant_id ? '#' . $employee->rec_applicant_id : '— nicht verknuepft',
                    $employee->rec_zas_inbound_file_id ? '#' . $employee->rec_zas_inbound_file_id : 'von uns angelegt',
                ]]
            );

            $this->siblings($employee, $teamId);
        }

        // 3. Dispo-Einsaetze.
        $this->assignments([$normalized, $shortened, $raw]);

        // 4. Lieferungen.
        if ($this->option('files')) {
            $this->files([$normalized, $shortened, $raw], $employee !== null);
        } else {
            $this->newLine();
            $this->line('  (mit <comment>--files</comment> zusaetzlich die gespeicherten ZAS-Lieferungen durchsuchen)');
        }

        return self::SUCCESS;
    }

    /**
     * Zweit-Datensatz derselben Person: gleicher Name UND gleiches
     * Geburtsdatum, andere Nummer. Beides muss stimmen — allein der Name
     * findet Namensvettern, allein das Geburtsdatum findet Geburtstagszwillinge
     * (im Bestand teilen drei Menschen den 16.10.2006).
     */
    private function siblings(RecEmployee $employee, ?int $teamId): void
    {
        if ($employee->birth_date === null) {
            $this->line('  Zweit-Datensatz: nicht pruefbar, am Mitarbeiter fehlt das Geburtsdatum.');

            return;
        }

        $candidate = [
            'id' => 0,
            'names' => [['first' => $employee->first_name, 'last' => $employee->last_name]],
            'birth_date' => $employee->birth_date->format('Y-m-d'),
        ];

        $others = RecEmployee::query()
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->whereKeyNot($employee->id)
            ->get(['id', 'first_name', 'last_name', 'birth_date', 'personnel_number', 'company', 'rec_applicant_id'])
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'personnel_number' => $e->personnel_number,
                'first_name' => $e->first_name,
                'last_name' => $e->last_name,
                'birth_date' => $e->birth_date?->format('Y-m-d'),
                'rec_applicant_id' => $e->rec_applicant_id,
            ])
            ->all();

        $hits = array_values(array_filter(
            EmployeeMatchResolver::match($candidate, $others),
            fn ($hit) => $hit['pass'] === EmployeeMatchResolver::PASS_NAME && $hit['birth_match']
        ));

        if ($hits === []) {
            $this->line('  Zweit-Datensatz: keiner (kein weiterer MA mit gleichem Namen und Geburtsdatum).');

            return;
        }

        $this->warn('  ZWEIT-DATENSATZ derselben Person — ZAS bedient zwei Firmen:');
        foreach ($hits as $hit) {
            $this->line('    ' . $hit['label']);
        }
    }

    /** @param list<?string> $forms */
    private function assignments(array $forms): void
    {
        $forms = array_values(array_unique(array_filter($forms)));

        $rows = DB::table('rec_dispo_assignments')
            ->whereIn('pnr_raw', $forms)
            ->selectRaw('pnr_raw, COUNT(*) as einsaetze')
            ->selectRaw('MIN(datum) as erster, MAX(datum) as letzter')
            ->selectRaw('SUM(CASE WHEN rec_employee_id IS NULL THEN 1 ELSE 0 END) as unaufgeloest')
            ->groupBy('pnr_raw')
            ->get();

        $this->newLine();
        if ($rows->isEmpty()) {
            $this->line('  Dispo-Einsaetze: keine auf diese Nummer.');

            return;
        }

        $this->line('<comment>  Dispo-Einsaetze:</comment>');
        $this->table(
            ['PersNr in der Zeile', 'Einsaetze', 'erster', 'letzter', 'unaufgeloest'],
            $rows->map(fn ($r) => [$r->pnr_raw, $r->einsaetze, $r->erster, $r->letzter, $r->unaufgeloest])->all()
        );
    }

    /**
     * @param list<?string> $forms
     */
    private function files(array $forms, bool $employeeKnown): void
    {
        $forms = array_values(array_unique(array_filter($forms)));
        $files = RecZasInboundFile::query()->orderBy('id')->get();
        $found = [];
        $rejected = false;

        foreach ($files as $file) {
            try {
                $content = (string) Storage::disk((string) $file->disk)->get((string) $file->stored_path);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($this->parser->parse($content)['rows'] as $row) {
                $pn = trim((string) ($row['ZasPersonalNr'] ?? ''));
                if ($pn === '' || !in_array($pn, $forms, true)) {
                    continue;
                }
                $outcome = self::outcomeFromNotes((string) $file->notes, $forms);
                if ($outcome !== null && str_starts_with($outcome, 'ABGEWIESEN')) {
                    $rejected = true;
                }
                $found[] = [
                    '#' . $file->id,
                    $file->created_at?->format('Y-m-d H:i') ?? '—',
                    $pn,
                    trim(((string) ($row['Vorname'] ?? '')) . ' ' . ((string) ($row['Name'] ?? ''))),
                    trim((string) ($row['UUID'] ?? '')) !== '' ? 'ja' : 'nein',
                    (string) ($file->status ?? ''),
                    $outcome ?? '—',
                ];
                break; // eine Zeile je Lieferung genuegt
            }
        }

        $this->newLine();
        if ($found === []) {
            $this->warn('  In KEINER gespeicherten Lieferung enthalten — ZAS hat diese Nummer uns nie geschickt.');

            return;
        }

        $this->line('<comment>  In diesen Lieferungen enthalten:</comment>');
        $this->table(
            ['Lieferung', 'eingegangen', 'PersNr', 'Name', 'UUID dabei', 'Lieferung-Status', 'Import-Ergebnis'],
            $found
        );

        if (!$employeeKnown) {
            $this->newLine();
            if ($rejected) {
                $this->error('  Geliefert und ABGEWIESEN — der Grund steht in der Spalte Import-Ergebnis.');
            } else {
                $this->error('  Geliefert, aber kein Mitarbeiter bei uns → die Zeile ist beim Import nicht angekommen.');
            }
            $this->line('  Nachlauf moeglich: php artisan recruiting:zas-inbound-reprocess <Lieferung> --dry-run');
        }
    }

    /**
     * Alle unaufgeloesten Einsatz-Nummern — und die entscheidende Zusatzspalte:
     * kennen wir die Nummer eigentlich? „bekannt" hiesse, der Mitarbeiter liegt
     * bei uns und die Zuordnung ist trotzdem gescheitert. Das waere ein Fehler
     * bei uns und nicht bei ZAS, und genau den wuerde man sonst nie entdecken.
     */
    private function unresolved(?int $teamId): int
    {
        $prefix = (string) config('recruiting.zas.company_prefix', ZasPersonnelNumber::DEFAULT_PREFIX);

        $rows = DB::table('rec_dispo_assignments')
            ->whereNull('rec_employee_id')
            ->selectRaw('pnr_raw, COUNT(*) as einsaetze')
            ->selectRaw('MIN(datum) as erster, MAX(datum) as letzter')
            ->groupBy('pnr_raw')
            ->orderByDesc('letzter')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Keine Einsaetze mit unbekannter Personalnummer.');

            return self::SUCCESS;
        }

        $today = now()->format('Y-m-d');
        $table = [];
        $mismatches = 0;

        foreach ($rows as $row) {
            $normalized = ZasPersonnelNumber::normalize($row->pnr_raw, $prefix) ?? $row->pnr_raw;
            $match = $this->matcher->match(null, $normalized, $teamId, $prefix);
            $known = $match['employee'] !== null;
            if ($known) {
                $mismatches++;
            }

            $offen = DB::table('rec_dispo_assignments')
                ->where('pnr_raw', $row->pnr_raw)
                ->whereNull('rec_employee_id')
                ->where('datum', '>=', $today)
                ->count();

            $table[] = [
                $row->pnr_raw,
                ZasPersonnelNumber::prefixOf($normalized) ?? $prefix,
                $row->einsaetze,
                $offen,
                $row->letzter,
                $known ? 'JA — MA #' . $match['employee']->id . ' (' . $match['via'] . ')' : 'nein',
            ];
        }

        $this->newLine();
        $this->line('<comment>Einsaetze mit unbekannter Personalnummer:</comment>');
        $this->table(['PersNr', 'Firma', 'Einsaetze', 'davon offen', 'letzter', 'bei uns bekannt?'], $table);
        $this->newLine();

        if ($mismatches > 0) {
            $this->error(sprintf(
                '%d Nummer(n) sind bei uns BEKANNT und trotzdem nicht verknuepft — das ist ein Zuordnungsfehler auf unserer Seite.',
                $mismatches
            ));
            $this->line('  Behebung: php artisan recruiting:dispo-reprocess (haengt unaufgeloeste Zeilen erneut an).');
        } else {
            $this->line('Alle unbekannt — das sind Mitarbeiter, die ZAS uns nie geliefert hat (Frage an ZAS, kein Fehler bei uns).');
        }

        return self::SUCCESS;
    }

    /**
     * Was hat der Import damals mit dieser Nummer gemacht?
     *
     * Der Bericht jedes Imports liegt als JSON in
     * rec_zas_inbound_files.notes — created/updated/skipped/failed, jeweils mit
     * Personalnummer und bei Fehlern mit Grund. Eine wegen Ueberlaenge komplett
     * abgewiesene Lieferung tragt stattdessen {"rejected": ...}.
     *
     * Pur und statisch, damit die Auswertung ohne Storage und Datenbank
     * pruefbar ist.
     *
     * @param  list<string> $forms Schreibweisen der gesuchten Nummer
     */
    public static function outcomeFromNotes(?string $notes, array $forms): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        $data = json_decode($notes, true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['rejected'])) {
            $reason = is_scalar($data['rejected'])
                ? (string) $data['rejected']
                : json_encode($data['rejected'], JSON_UNESCAPED_UNICODE);

            return 'ABGEWIESEN (ganze Lieferung): ' . $reason;
        }

        $labels = [
            'failed'  => 'ABGEWIESEN',
            'created' => 'angelegt',
            'updated' => 'aktualisiert',
            'skipped' => 'uebersprungen',
        ];

        foreach ($labels as $key => $label) {
            foreach (($data[$key] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $pn = isset($entry['personnel_number']) ? trim((string) $entry['personnel_number']) : '';
                if ($pn === '' || !in_array($pn, $forms, true)) {
                    continue;
                }
                $reason = isset($entry['reason']) ? (string) $entry['reason'] : null;

                return $label . ($reason !== null && $reason !== '' ? ': ' . $reason : '');
            }
        }

        return null;
    }
}
