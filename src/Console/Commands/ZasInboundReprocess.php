<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundMarkerReparatur;

/**
 * Schickt gespeicherte ZAS-Mitarbeiter-Lieferungen (erneut) durch den Import.
 *
 * Warum es das braucht (Befund Massenimport 2026-08-25): die Rohdateien liegen
 * bei uns, aber es gab keinen Weg, sie noch einmal zu verarbeiten. Folgen:
 * keine Generalprobe ohne Tinker, kein zweiter Versuch aus eigener Kraft (ZAS
 * musste erneut senden), und kein Weg, nach einer Code-Aenderung die schon
 * gelieferten Zeilen nachzuziehen.
 *
 * Ein Nachlauf ist gefahrlos wiederholbar: die Match-Kaskade des Importers
 * erkennt bestehende MA ueber UUID oder Personalnummer und legt sie nicht
 * erneut an.
 *
 * Ohne fileId: alle noch nicht verarbeiteten Echt-Lieferungen, aelteste zuerst
 * (deckt insbesondere Lieferungen ab, die der Groessen-Waechter abgewiesen hat).
 * Mit fileId: genau diese eine, auch is_test oder bereits verarbeitet.
 *
 * Aufruf:
 *   php artisan recruiting:zas-inbound-reprocess 17 --dry-run
 *   php artisan recruiting:zas-inbound-reprocess 17
 *   php artisan recruiting:zas-inbound-reprocess --chunk=50
 *   php artisan recruiting:zas-inbound-reprocess 609 --dry-run --overwrite
 *
 * --overwrite schaltet das Ueberschreiben beim ZAS-Bestand NUR fuer diesen
 * Lauf ein (recruiting.zas.inbound_overwrite_zas_owned) — damit laesst sich
 * vor dem Einschalten per Trockenlauf sehen, welche Felder sich wie oft
 * aendern wuerden. Die Ausgabe nennt nur Feldnamen und Anzahlen.
 *
 * --marker-fehlt (Vorfall 06.11.–12.11.2026): ZAS lieferte die Datenzeilen ohne
 * den Zeilenende-Marker, der Eingang wies eine Woche lang alles ab. Der Schalter
 * ergaenzt den Marker NUR im Speicher und nur, wenn je Zeile nachweislich nichts
 * ausser dem Marker fehlt (ZasInboundMarkerReparatur); sonst wird die Datei
 * ausgelassen. Verlangt eine Datei-ID oder --von, damit nie versehentlich alles
 * laeuft. Der laufende Eingang bleibt streng.
 *   php artisan recruiting:zas-inbound-reprocess --marker-fehlt --von=5381 --bis=5556 --dry-run
 */
class ZasInboundReprocess extends Command
{
    protected $signature = 'recruiting:zas-inbound-reprocess
                            {fileId? : ID aus rec_zas_inbound_files; ohne Angabe alle unverarbeiteten}
                            {--dry-run : nur rechnen und berichten, nichts schreiben}
                            {--chunk=100 : Zeilen pro Portion}
                            {--overwrite : ZAS-Bestand ueberschreiben, nur fuer diesen Lauf (mit --dry-run zum Vorab-Pruefen)}
                            {--marker-fehlt : fehlenden Zeilenende-Marker im Speicher ergaenzen (nur mit Datei-ID oder --von)}
                            {--von= : erste Datei-ID eines Bereichs (Echt-Lieferungen, auch bereits verarbeitete)}
                            {--bis= : letzte Datei-ID des Bereichs}';

    protected $description = 'Gespeicherte ZAS-Mitarbeiter-Lieferungen (erneut) verarbeiten';

    public function __construct(
        private ZasInboundEmployeeImporter $importer,
        private ZasInboundCsvParser $parser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));
        if ($this->option('overwrite')) {
            config(['recruiting.zas.inbound_overwrite_zas_owned' => true]);
        }

        $markerFehlt = (bool) $this->option('marker-fehlt');
        $von = $this->option('von');
        $bis = $this->option('bis');
        foreach (['von' => $von, 'bis' => $bis] as $name => $wert) {
            if ($wert !== null && !ctype_digit((string) $wert)) {
                $this->error("--{$name} muss eine Datei-ID sein.");

                return self::FAILURE;
            }
        }
        if ($bis !== null && $von === null) {
            $this->error('--bis geht nur zusammen mit --von.');

            return self::FAILURE;
        }
        if ($this->argument('fileId') !== null && $von !== null) {
            $this->error('Entweder eine Datei-ID oder --von/--bis, nicht beides.');

            return self::FAILURE;
        }
        if ($markerFehlt && $this->argument('fileId') === null && $von === null) {
            $this->error('--marker-fehlt verlangt eine Datei-ID oder --von (nie „alle“).');

            return self::FAILURE;
        }

        $files = match (true) {
            $this->argument('fileId') !== null => RecZasInboundFile::query()->whereKey((int) $this->argument('fileId'))->get(),
            // Bereich: Echt-Lieferungen, auch bereits verarbeitete (z. B. die abgewiesenen des Marker-Vorfalls).
            $von !== null => RecZasInboundFile::query()
                ->where('is_test', false)
                ->where('id', '>=', (int) $von)
                ->when($bis !== null, fn ($q) => $q->where('id', '<=', (int) $bis))
                ->orderBy('id')
                ->get(),
            default => RecZasInboundFile::query()
                ->where('is_test', false)
                ->whereNull('processed_at')
                ->orderBy('id')
                ->get(),
        };

        if ($files->isEmpty()) {
            $this->info('Keine passenden Lieferungen.');

            return self::SUCCESS;
        }

        // Bilanz ueber alle Dateien — bei einem Bereich von 176 Paketen gehen einzelne Zeilen sonst unter.
        $gesamt = ['dateien' => 0, 'ausgelassen' => 0, 'rows' => 0, 'created' => 0, 'updated' => 0, 'pnr_filled' => 0,
            'skipped' => 0, 'failed' => 0, 'warnings' => 0, 'suspected' => 0, 'field_counts' => []];

        foreach ($files as $file) {
            try {
                $content = (string) Storage::disk((string) $file->disk)->get((string) $file->stored_path);
            } catch (\Throwable $e) {
                $this->error("#{$file->id}: Rohdatei nicht lesbar ({$file->stored_path}): {$e->getMessage()}");
                $gesamt['ausgelassen']++;
                continue;
            }

            $markerErgaenzt = 0;
            if ($markerFehlt) {
                $pruefung = ZasInboundMarkerReparatur::pruefen($content);
                if ($pruefung['stand'] === ZasInboundMarkerReparatur::ABGELEHNT) {
                    $this->error("#{$file->id}: ausgelassen — {$pruefung['grund']}");
                    $gesamt['ausgelassen']++;
                    continue;
                }
                // Der Schalter ist fuer die abgewiesenen Lieferungen da. Eine intakte, schon
                // verarbeitete Datei im Bereich (z. B. nach der Reparatur bei ZAS) liefe sonst
                // ein zweites Mal durch — ohne Spur im Bericht (Review 12.11.2026).
                if ($pruefung['stand'] === ZasInboundMarkerReparatur::INTAKT && $file->processed_at !== null) {
                    $this->line("#{$file->id}: Marker vorhanden und schon verarbeitet — ausgelassen.");
                    $gesamt['ausgelassen']++;
                    continue;
                }
                $content = $pruefung['content'];
                $markerErgaenzt = $pruefung['ergaenzt'];
                if ($markerErgaenzt > 0) {
                    $this->line("#{$file->id}: Zeilenende-Marker in {$markerErgaenzt} von {$pruefung['zeilen']} Zeilen ergaenzt (nur im Speicher).");
                }
            }

            // Ein Fehler in einer Datei (DB weg, Schreibfehler) bricht den Bereich nicht ab.
            try {
                $summary = $this->reprocess($file, $content, $dryRun, $chunk, $markerErgaenzt);
            } catch (\Throwable $e) {
                $this->error("#{$file->id}: abgebrochen — {$e->getMessage()}");
                $gesamt['ausgelassen']++;
                continue;
            }
            $gesamt['dateien']++;
            foreach (['rows', 'created', 'updated', 'pnr_filled', 'skipped', 'failed', 'warnings', 'suspected'] as $k) {
                $gesamt[$k] += $summary[$k];
            }
            foreach ($summary['field_counts'] as $field => $n) {
                $gesamt['field_counts'][$field] = ($gesamt['field_counts'][$field] ?? 0) + $n;
            }

            $this->line(sprintf(
                '#%d %s%s: %d Zeilen — angelegt %d, aktualisiert %d (davon PersNr nachgetragen %d),'
                . ' uebersprungen %d, fehlgeschlagen %d, Warnungen %d, Dublettenverdacht %d',
                $file->id,
                $file->original_filename ?: (string) $file->uuid,
                $dryRun ? ' [TROCKENLAUF]' : '',
                $summary['rows'],
                $summary['created'],
                $summary['updated'],
                $summary['pnr_filled'],
                $summary['skipped'],
                $summary['failed'],
                $summary['warnings'],
                $summary['suspected'],
            ));

            if ($summary['field_counts'] !== []) {
                $this->line('    Geaenderte Felder: ' . implode(', ', array_map(
                    fn ($field, $n) => "{$field} {$n}",
                    array_keys($summary['field_counts']),
                    $summary['field_counts'],
                )));
            }

            foreach ($summary['failed_details'] as $f) {
                $this->warn('    FEHLER ' . ($f['personnel_number'] ?? '-') . ': ' . $f['reason']);
            }
            // Warnungen des Mappers (ungueltiges Datum, kein Lookup-Treffer) sind der einzige Hinweis auf
            // eine still verrutschte Zeile — beim Marker-Nachlauf deshalb immer zeigen, sonst mit -v.
            if ($markerFehlt || $this->getOutput()->isVerbose()) {
                foreach ($summary['warning_details'] as $w) {
                    $this->line('    WARNUNG ' . $w);
                }
            }
            foreach ($summary['suspected_details'] as $s) {
                $treffer = array_map(
                    fn ($m) => $m['field'] . '=MA#' . $m['employee_id'] . ' (' . $m['confidence'] . ')',
                    $s['matches']
                );
                $this->warn('    VERDACHT ' . ($s['personnel_number'] ?? '-') . ' ' . $s['name']
                    . ' → ' . implode(', ', $treffer));
            }
        }

        if ($files->count() > 1 || $gesamt['ausgelassen'] > 0) {
            arsort($gesamt['field_counts']);
            $this->info(sprintf(
                'GESAMT%s: %d Dateien, %d ausgelassen — %d Zeilen, angelegt %d, aktualisiert %d (davon PersNr nachgetragen %d),'
                . ' uebersprungen %d, fehlgeschlagen %d, Warnungen %d, Dublettenverdacht %d',
                $dryRun ? ' [TROCKENLAUF]' : '',
                $gesamt['dateien'], $gesamt['ausgelassen'], $gesamt['rows'], $gesamt['created'], $gesamt['updated'],
                $gesamt['pnr_filled'], $gesamt['skipped'], $gesamt['failed'], $gesamt['warnings'], $gesamt['suspected'],
            ));
            if ($gesamt['field_counts'] !== []) {
                $this->line('    Geaenderte Felder gesamt: ' . implode(', ', array_map(
                    fn ($field, $n) => "{$field} {$n}",
                    array_keys($gesamt['field_counts']),
                    $gesamt['field_counts'],
                )));
            }
        }

        // Ausgelassene Dateien sind kein Erfolg — ein Skript drumherum soll das merken.
        return $gesamt['ausgelassen'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Der testbare Kern: Inhalt rein, Bilanz raus.
     *
     * Der Storage-Zugriff bleibt bewusst in handle() — so laesst sich das
     * Verhalten ohne Dateisystem pruefen (Muster: DispoEscalateCommand).
     *
     * @return array{rows:int, created:int, updated:int, pnr_filled:int, skipped:int,
     *               failed:int, warnings:int, suspected:int,
     *               failed_details:list<array>, suspected_details:list<array>}
     */
    public function reprocess(RecZasInboundFile $file, string $content, bool $dryRun, int $chunk, int $markerErgaenzt = 0): array
    {
        $parsed = $this->parser->parse($content);

        $summary = [
            'rows' => 0, 'created' => 0, 'updated' => 0, 'pnr_filled' => 0,
            'skipped' => 0, 'failed' => 0, 'warnings' => 0, 'suspected' => 0,
            'failed_details' => [], 'suspected_details' => [], 'warning_details' => [],
            // Feldname → wie viele Mitarbeiter; nur Namen, keine Werte.
            'field_counts' => [],
        ];

        // In Portionen, damit auch eine grosse (z.B. vom Waechter abgewiesene)
        // Lieferung durchlaeuft, ohne alles gleichzeitig im Speicher zu halten.
        foreach (array_chunk($parsed['rows'], max(1, $chunk)) as $portion) {
            $report = $this->importer->import($portion, $file, $dryRun);

            $summary['rows']      += count($portion);
            $summary['created']   += count($report['created']);
            $summary['updated']   += count($report['updated']);
            $summary['skipped']   += count($report['skipped']);
            $summary['failed']    += count($report['failed']);
            $summary['warnings']  += count($report['warnings']);
            $summary['suspected'] += count($report['suspected']);

            foreach ($report['updated'] as $u) {
                if (in_array('personnel_number', $u['changed'] ?? [], true)) {
                    $summary['pnr_filled']++;
                }
                foreach ($u['changed'] ?? [] as $field) {
                    $summary['field_counts'][$field] = ($summary['field_counts'][$field] ?? 0) + 1;
                }
            }

            $summary['failed_details']    = array_merge($summary['failed_details'], $report['failed']);
            $summary['suspected_details'] = array_merge($summary['suspected_details'], $report['suspected']);
            $summary['warning_details']   = array_merge($summary['warning_details'], $report['warnings']);
        }

        Log::info('ZAS-Inbound Reprocess', [
            'inbound_file_id' => $file->id,
            'dry_run'         => $dryRun,
            'summary'         => array_diff_key($summary, ['failed_details' => 1, 'suspected_details' => 1, 'warning_details' => 1]),
        ]);

        // Den Bericht einer bereits verarbeiteten Lieferung NICHT ueberschreiben:
        // er belegt, was damals passiert ist. Nur eine Lieferung, die noch nie
        // durchgelaufen ist (z.B. vom Groessen-Waechter abgewiesen), wird hier
        // erstmals als verarbeitet gestempelt.
        if (!$dryRun && $file->processed_at === null) {
            $file->update([
                'status'       => $summary['failed'] > 0
                    ? ($summary['created'] + $summary['updated'] + $summary['skipped'] > 0 ? 'partial' : 'failed')
                    : 'processed',
                'processed_at' => now(),
                'notes'        => json_encode([
                    'reprocessed' => true,
                    'created'     => $summary['created'],
                    'updated'     => $summary['updated'],
                    'pnr_filled'  => $summary['pnr_filled'],
                    'skipped'     => $summary['skipped'],
                    'failed'      => $summary['failed_details'],
                    'suspected'   => $summary['suspected_details'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } elseif (!$dryRun && $markerErgaenzt > 0) {
            // Marker-Nachlauf einer schon (als abgewiesen) verarbeiteten Lieferung: der Beleg von
            // damals bleibt stehen, das Ergebnis des Nachlaufs kommt DAZU — und der Status zeigt,
            // dass die Lieferung inzwischen angekommen ist. Als Liste: ein zweiter Lauf („0 angelegt,
            // alles schon da“) darf den Bericht des ersten nicht ueberschreiben (Review 12.11.2026).
            $notes = json_decode((string) $file->notes, true);
            $notes = is_array($notes) ? $notes : ['vorher' => (string) $file->notes];
            $laeufe = isset($notes['marker_nachlauf']) && is_array($notes['marker_nachlauf']) ? array_values($notes['marker_nachlauf']) : [];
            $laeufe[] = [
                'am'              => now()->toDateTimeString(),
                'marker_ergaenzt' => $markerErgaenzt,
                'created'         => $summary['created'],
                'updated'         => $summary['updated'],
                'pnr_filled'      => $summary['pnr_filled'],
                'skipped'         => $summary['skipped'],
                'failed'          => $summary['failed_details'],
                'warnings'        => $summary['warning_details'],
                'suspected'       => $summary['suspected_details'],
            ];
            $notes['marker_nachlauf'] = $laeufe;
            // Ungueltige Bytes (Windows-1252 in Namen/Fehlertexten) liessen json_encode false liefern —
            // das stuende dann anstelle des alten Berichts in der Spalte. Ersetzen statt verlieren.
            $json = json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $status = $summary['failed'] > 0
                ? ($summary['created'] + $summary['updated'] + $summary['skipped'] > 0 ? 'partial' : 'failed')
                : 'processed';
            // Erster Nachlauf setzt den Status; ein spaeterer („alles schon da“) laesst ihn stehen.
            $file->update(array_filter([
                'status' => count($laeufe) === 1 ? $status : null,
                'notes'  => $json === false ? null : $json,
            ], fn ($v) => $v !== null));
        }

        return $summary;
    }
}
