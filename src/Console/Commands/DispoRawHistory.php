<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Support\CsvEncodingNormalizer;

/**
 * Was hat ZAS fuer eine Einbuchung wann geliefert? (02.10.2026)
 *
 * DER GRUND: Unsere Tabelle traegt immer nur den JETZIGEN Stand — der Import
 * ueberschreibt die Zeile bei jeder Lieferung, und eine Bestaetigung loescht
 * sogar die Marker der Rebestaetigung. Wenn eine WhatsApp 13:00 nennt und die
 * Einsatz-Seite 15:00 zeigt, laesst sich die Frage "wer hat wann was geaendert"
 * aus der Datenbank nicht mehr beantworten. Die Rohdateien liegen unveraendert
 * auf dem Disk und koennen es.
 *
 * Liest NUR — keine Zeile wird angefasst, kein Import ausgeloest.
 *
 * Aufruf:
 *   php artisan recruiting:dispo-verlauf 926453
 *   php artisan recruiting:dispo-verlauf 926453 --seit=2026-10-01
 *   php artisan recruiting:dispo-verlauf --pnr=RG1464 --seit=2026-10-01
 *   php artisan recruiting:dispo-verlauf --einsatz=RG19896 --seit=2026-10-01 --alle
 */
class DispoRawHistory extends Command
{
    protected $signature = 'recruiting:dispo-verlauf
        {dsRef? : DS-ID der Einbuchung (Spalte ds_ref)}
        {--pnr= : stattdessen/zusaetzlich nach Personalnummer suchen}
        {--einsatz= : stattdessen/zusaetzlich nach Einsatz-ID suchen}
        {--seit= : nur Lieferungen ab diesem Datum (Y-m-d), Default: letzte 7 Tage}
        {--alle : jede Lieferung zeigen, nicht nur die mit Aenderung}';

    protected $description = 'Zeigt aus den ZAS-Rohdateien, was fuer eine Einbuchung wann geliefert wurde (nur lesend)';

    public function handle(ZasDispoBlockSplitter $splitter): int
    {
        $dsRef   = trim((string) $this->argument('dsRef'));
        $pnr     = trim((string) $this->option('pnr'));
        $einsatz = trim((string) $this->option('einsatz'));

        if ($dsRef === '' && $pnr === '' && $einsatz === '') {
            $this->error('Bitte eine DS-ID, --pnr oder --einsatz angeben.');

            return self::FAILURE;
        }

        $seit = trim((string) $this->option('seit')) !== ''
            ? (string) $this->option('seit')
            : now()->subDays(7)->toDateString();

        $files = RecZasDispoInboundFile::query()
            ->whereDate('created_at', '>=', $seit)
            ->orderBy('id')
            ->get(['id', 'created_at', 'disk', 'stored_path']);

        if ($files->isEmpty()) {
            $this->warn("Keine Lieferungen seit {$seit}.");

            return self::SUCCESS;
        }

        $this->line(sprintf('%d Lieferungen seit %s — suche %s', $files->count(), $seit, $this->kriterium($dsRef, $pnr, $einsatz)));
        $this->newLine();

        $rows = [];
        $letzte = null;
        $treffer = 0;

        foreach ($files as $file) {
            try {
                $raw = (string) Storage::disk((string) $file->disk)->get((string) $file->stored_path);
            } catch (\Throwable $e) {
                $this->warn("#{$file->id}: Rohdatei nicht lesbar ({$file->stored_path})");
                continue;
            }

            $dispo = $splitter->split(CsvEncodingNormalizer::toUtf8($raw))['known']['Dispo'] ?? [];
            $gefunden = array_values(array_filter($dispo, fn ($r) => self::passt($r, $dsRef, $pnr, $einsatz)));

            if ($gefunden === []) {
                // Die Abwesenheit ist selbst ein Befund: so wird eine Zeile
                // "verschwunden" markiert.
                $rows[] = [$file->id, $file->created_at?->format('d.m. H:i'), '—', '—', '—', '—', '—', 'nicht in der Lieferung'];
                $letzte = null;
                continue;
            }

            $treffer++;
            foreach ($gefunden as $r) {
                $fingerprint = implode('|', [$r['datum'] ?? '', $r['von'] ?? '', $r['bis'] ?? '', $r['pnr'] ?? '', $r['taetigkeit'] ?? '', $r['status_id'] ?? '']);
                $geaendert = $letzte !== null && $letzte !== $fingerprint;

                if ($this->option('alle') || $letzte === null || $geaendert) {
                    $rows[] = [
                        $file->id,
                        $file->created_at?->format('d.m. H:i'),
                        $r['ds_id'] ?? '',
                        $r['datum'] ?? '',
                        ($r['von'] ?? '') . '–' . ($r['bis'] ?? ''),
                        $r['pnr'] ?? '',
                        $r['taetigkeit'] ?? '',
                        $geaendert ? '<<< GEAENDERT' : '',
                    ];
                }
                $letzte = $fingerprint;
            }
        }

        $this->table(['Datei', 'Eingang', 'DS-ID', 'Datum', 'Zeit', 'PNr', 'Taetigkeit', 'Hinweis'], $rows);
        $this->newLine();
        $this->info(sprintf(
            'In %d von %d Lieferungen enthalten.%s',
            $treffer,
            $files->count(),
            $this->option('alle') ? '' : ' Ohne --alle werden nur Erst- und Aenderungsstaende gezeigt.',
        ));

        return self::SUCCESS;
    }

    /**
     * Trifft eine Rohzeile die Suche? Oeffentlich und statisch, damit die
     * Vergleichsregeln (exakt, getrimmt, UND-verknuepft) pruefbar sind — ein
     * zu lockerer Vergleich wuerde fremde Zeilen in den Verlauf mischen.
     *
     * @param array<string,string> $row
     */
    public static function passt(array $row, string $dsRef, string $pnr, string $einsatz): bool
    {
        if ($dsRef !== '' && trim((string) ($row['ds_id'] ?? '')) !== $dsRef) {
            return false;
        }
        if ($pnr !== '' && trim((string) ($row['pnr'] ?? '')) !== $pnr) {
            return false;
        }
        if ($einsatz !== '' && trim((string) ($row['einsatz_id'] ?? '')) !== $einsatz) {
            return false;
        }

        return true;
    }

    private function kriterium(string $dsRef, string $pnr, string $einsatz): string
    {
        $teile = [];
        if ($dsRef !== '') {
            $teile[] = "DS-ID {$dsRef}";
        }
        if ($pnr !== '') {
            $teile[] = "PNr {$pnr}";
        }
        if ($einsatz !== '') {
            $teile[] = "Einsatz {$einsatz}";
        }

        return implode(' + ', $teile);
    }
}
