<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Services\Zas\ZasInboundColumnReport;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;

/**
 * Zeigt je ZAS-Spalte, wie oft sie in den gespeicherten Lieferungen einen Wert
 * trug — und ob unser Import sie ueberhaupt liest.
 *
 * Anlass: bei den 706 aus ZAS uebernommenen Mitarbeitern sind Felder leer
 * (Dokumente, Qualifikation), und die Frage "liefert er das nicht, oder lesen
 * wir es nicht?" war nur per SSH und awk zu beantworten. Der Bericht trennt
 * genau diese beiden Faelle.
 *
 * NUR LESEND: liest rec_zas_inbound_files und die Rohdateien vom Storage,
 * schreibt nichts — kein Import, kein Statuswechsel, keine Notiz. Gefahrlos
 * beliebig oft ausfuehrbar, auch parallel zum Betrieb.
 *
 * Aufruf:
 *   php artisan recruiting:zas-inbound-columns                 letzte Echt-Lieferung
 *   php artisan recruiting:zas-inbound-columns 55              genau diese
 *   php artisan recruiting:zas-inbound-columns --all           alle zusammen
 *   php artisan recruiting:zas-inbound-columns --all --only-empty
 *   php artisan recruiting:zas-inbound-columns 55 --samples
 */
class ZasInboundColumns extends Command
{
    protected $signature = 'recruiting:zas-inbound-columns
                            {fileId? : ID aus rec_zas_inbound_files; ohne Angabe die neueste Echt-Lieferung}
                            {--all : ueber alle Echt-Lieferungen zusammen rechnen}
                            {--only-empty : nur Spalten ohne einen einzigen Wert}
                            {--samples : Beispielwerte mitzeigen — ACHTUNG, echte Personendaten in der Ausgabe}';

    protected $description = 'Fuellgrad je Spalte der gespeicherten ZAS-Mitarbeiter-Lieferungen (nur lesend)';

    /** Beispielwerte je Spalte, wenn --samples gesetzt ist. */
    private const MAX_EXAMPLES = 3;

    public function __construct(private ZasInboundColumnReport $report)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $files = $this->filesFor(
            $this->argument('fileId') !== null ? (int) $this->argument('fileId') : null,
            (bool) $this->option('all')
        );

        if ($files->isEmpty()) {
            $this->error('Keine passende Lieferung gefunden.');

            return self::FAILURE;
        }

        $contents = [];
        $rowTotal = 0;

        foreach ($files as $file) {
            try {
                $contents[] = (string) Storage::disk((string) $file->disk)->get((string) $file->stored_path);
                $rowTotal  += (int) $file->row_count;
            } catch (\Throwable $e) {
                $this->warn("#{$file->id}: Rohdatei nicht lesbar ({$file->stored_path}): {$e->getMessage()}");
            }
        }

        if ($contents === []) {
            $this->error('Keine der Rohdateien war lesbar.');

            return self::FAILURE;
        }

        $samples = (bool) $this->option('samples');
        $report  = $this->report->fromContents(
            $contents,
            ZasInboundRowMapper::knownColumns(),
            $this->expectedColumns(),
            $samples ? self::MAX_EXAMPLES : 0
        );

        if ($this->option('only-empty')) {
            $report = ZasInboundColumnReport::onlyEmpty($report);
        }

        $this->line(sprintf(
            '%d Lieferung(en) (ID %s), %d Zeilen laut Metadaten.',
            count($contents),
            $files->pluck('id')->implode(', '),
            $rowTotal
        ));

        if ($samples) {
            $this->warn('--samples: die Ausgabe enthaelt echte Personendaten. Nicht in Tickets kopieren.');
        }

        if ($report === []) {
            $this->info('Keine Spalte ohne Wert — alles was erwartet wird, kommt auch gefuellt an.');

            return self::SUCCESS;
        }

        $this->table($this->headers($samples), $this->tableRows($report, $samples));
        $this->newLine();
        $this->line('<comment>immer leer</comment> = Spalte kam mit, trug aber nie einen Wert → bei ZAS nach der Pflege fragen.');
        $this->line('<comment>fehlt</comment>      = Spalte kam in keiner Lieferung vor → Formatabsprache mit ZAS.');
        $this->line('<comment>gelesen</comment>    = unser Import uebernimmt die Spalte in ein Feld.');

        return self::SUCCESS;
    }

    /**
     * Mit fileId genau diese Lieferung (auch is_test oder schon verarbeitet).
     * Ohne: die Echt-Lieferungen — alle bei --all, sonst die neueste.
     *
     * Nimmt die Werte als Parameter statt sie selbst aus dem Input zu lesen,
     * damit die Auswahl ohne Symfony-Console pruefbar ist (Muster: Kern
     * herausgehoben, IO bleibt in handle()).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RecZasInboundFile>
     */
    public function filesFor(?int $fileId, bool $all)
    {
        if ($fileId !== null) {
            return RecZasInboundFile::query()->whereKey($fileId)->get();
        }

        $query = RecZasInboundFile::query()->where('is_test', false);

        return $all
            ? $query->orderBy('id')->get()
            : $query->orderByDesc('id')->limit(1)->get();
    }

    /**
     * Erwartet werden die Spalten, die wir selbst an ZAS exportieren (die hat
     * er also in seiner Maske) plus alles, was unser Import lesen kann. Was
     * hier steht und nie geliefert wurde, erscheint als "fehlt".
     *
     * Oeffentlich, weil hier die "fehlt"-Aussage entsteht — der Unit-Test
     * haelt fest, dass beide Quellen einfliessen.
     *
     * @return list<string>
     */
    public function expectedColumns(): array
    {
        return array_values(array_unique(array_merge(
            ZasEmployeeFieldResolver::COLUMNS,
            ZasInboundRowMapper::knownColumns(),
        )));
    }

    /** @return list<string> */
    private function headers(bool $samples): array
    {
        $headers = ['Spalte', 'gefuellt', 'von', '%', 'gelesen', 'Status'];

        return $samples ? [...$headers, 'Beispiele'] : $headers;
    }

    /**
     * Bericht → Tabellenzeilen. Oeffentlich fuer den Unit-Test (Muster: Kern
     * herausgehoben, IO bleibt in handle()).
     *
     * @param  list<array{column: string, filled: int, rows: int, ratio: float, read: bool, status: string, examples: list<string>}> $report
     * @return list<list<string>>
     */
    public function tableRows(array $report, bool $samples): array
    {
        $labels = [
            ZasInboundColumnReport::STATUS_FILLED       => 'gefuellt',
            ZasInboundColumnReport::STATUS_ALWAYS_EMPTY => 'immer leer',
            ZasInboundColumnReport::STATUS_MISSING      => 'fehlt',
        ];

        $rows = [];

        foreach ($report as $entry) {
            $row = [
                $entry['column'],
                (string) $entry['filled'],
                (string) $entry['rows'],
                $entry['rows'] === 0 ? '—' : round($entry['ratio'] * 100, 1) . '%',
                $entry['read'] ? 'ja' : '—',
                $labels[$entry['status']] ?? $entry['status'],
            ];

            if ($samples) {
                $row[] = implode(' | ', $entry['examples']);
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
