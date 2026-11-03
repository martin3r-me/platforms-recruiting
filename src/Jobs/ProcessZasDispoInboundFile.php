<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/**
 * Verarbeitet den ZAS-Dispo-Eingang im Hintergrund (Spec 2026-11-04).
 *
 * Vorfall 03.11.: der Import lief innerhalb der HTTP-Anfrage und brauchte gut
 * zwei Minuten; Olafs Access-Client wartete nicht so lange und meldete
 * „Zeitlimit“, obwohl die Datei laengst gespeichert und verarbeitet war. Jetzt
 * antwortet der Endpunkt sofort, dieser Job holt die Arbeit nach.
 *
 * Der Job traegt zwar die ausloesende Datei-ID, verarbeitet aber immer die
 * anstehenden Echt-Dateien in ID-Reihenfolge, die neuer sind als die letzte
 * verarbeitete. So bleibt die Reihenfolge auch dann richtig, wenn der Job
 * einer juengeren Datei zuerst an die Reihe kommt (eine aeltere Lieferung
 * wuerde sonst Einbuchungen der neueren als verschwunden markieren). Alte
 * Liegengebliebene unterhalb der letzten verarbeiteten Datei fasst er nicht
 * an — die holt bei Bedarf recruiting:dispo-reprocess <id> nach.
 *
 * Nie zwei Importe gleichzeitig (WithoutOverlapping, geteilter Schluessel,
 * denselben nimmt der Befehl recruiting:dispo-reprocess): der zweite Job
 * wartet in kurzen Schleifen, bis der erste fertig ist, und findet dann meist
 * nichts mehr vor. Hoechstens MAX_JE_LAUF Dateien je Lauf (Review 04.11.:
 * ein Rueckstau darf nicht in den Timeout laufen), der Rest kommt in einem
 * neuen Lauf dran.
 */
class ProcessZasDispoInboundFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const SPERRE = 'zas-dispo-inbound';

    /** Dateien je Lauf: 5 x gut zwei Minuten bleibt klar unter dem Timeout. */
    public const MAX_JE_LAUF = 5;

    public int $timeout = 900;

    /** Warteschleife hinter einem laufenden Import: alle 15 s, bis zu 30 Minuten. */
    public int $tries = 120;

    public int $maxExceptions = 2;

    public function __construct(public int $fileId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping(self::SPERRE))->shared()->releaseAfter(15)->expireAfter($this->timeout + 60)];
    }

    /** Schluessel der Sperre, wie WithoutOverlapping ihn im Cache ablegt (geteilt = Praefix + Schluessel). */
    public static function sperrSchluessel(): string
    {
        return 'laravel-queue-overlap:' . self::SPERRE;
    }

    public function handle(ZasDispoWebexportImporter $importer): void
    {
        if (self::verarbeiten($importer, self::MAX_JE_LAUF, $this->fileId)) {
            // Rest in einem frischen Lauf — die Sperre dieses Laufs faellt mit ihm.
            self::dispatch($this->fileId);
        }
    }

    /**
     * Verarbeitet bis zu $max anstehende Dateien in ID-Reihenfolge.
     *
     * @param ?callable(RecZasDispoInboundFile, array): void $melden je Datei nach dem Import (Konsole)
     * @return bool true = es stehen weitere Dateien an
     */
    public static function verarbeiten(ZasDispoWebexportImporter $importer, int $max, ?int $ausloeser = null, ?callable $melden = null): bool
    {
        $gesehen = [];
        while (($file = self::naechste(array_keys($gesehen))) !== null) {
            if (count($gesehen) >= $max) {
                return true;
            }
            if (isset($gesehen[$file->id])) {
                // Darf nicht vorkommen (naechste() schliesst Gesehene aus) — zweite Bremse gegen Endlos-Lauf.
                Log::error('ZAS dispo inbound: Datei kam erneut, Lauf abgebrochen', ['file_id' => $file->id]);
                return false;
            }
            $gesehen[$file->id] = true;

            $z = $importer->import($file);
            if ($file->fresh()?->processed_at === null) {
                // Importer konnte den Abschluss nicht schreiben (loggt er selbst) —
                // kompakt nachziehen, sonst blockiert diese Datei alle folgenden.
                self::abschliessen($file, $z);
            }
            Log::info('ZAS dispo inbound verarbeitet', [
                'file_id'     => $file->id,
                'ausgeloest'  => $ausloeser,
                'blocks'      => (bool) ($z['blocks_found'] ?? false),
                'events'      => ($z['events_created'] ?? 0) + ($z['events_updated'] ?? 0),
                'assignments' => ($z['assignments_created'] ?? 0) + ($z['assignments_updated'] ?? 0),
                'missing'     => $z['missing_marked'] ?? 0,
                'errors'      => count($z['errors'] ?? []),
            ]);
            if ($melden !== null) {
                $melden($file, $z);
            }
        }

        return false;
    }

    /**
     * Aelteste anstehende Echt-Datei, die neuer ist als die letzte verarbeitete.
     *
     * @param list<int> $ausser in diesem Lauf schon angefasst (konnte nicht abgeschlossen werden)
     */
    public static function naechste(array $ausser = []): ?RecZasDispoInboundFile
    {
        return self::anstehende()
            ->when($ausser !== [], fn ($q) => $q->whereNotIn('id', $ausser))
            ->first();
    }

    /** Alle anstehenden Echt-Dateien oberhalb der letzten verarbeiteten, aelteste zuerst. */
    public static function anstehende(): \Illuminate\Database\Eloquent\Builder
    {
        return RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->whereNull('processed_at')
            ->where('id', '>', self::letzteVerarbeitete())
            ->orderBy('id');
    }

    /** Echt-Dateien ohne Abschluss unterhalb der letzten verarbeiteten — aelter als der Bestand, werden nie automatisch nachgeholt. */
    public static function liegengebliebene(): \Illuminate\Database\Eloquent\Builder
    {
        return RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->whereNull('processed_at')
            ->where('id', '<=', self::letzteVerarbeitete())
            ->orderBy('id');
    }

    public static function letzteVerarbeitete(): int
    {
        return (int) RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->whereNotNull('processed_at')
            ->max('id');
    }

    /** @param array<string,mixed> $z Zusammenfassung des Importers */
    private static function abschliessen(RecZasDispoInboundFile $file, array $z): void
    {
        $kompakt = [];
        foreach (['blocks_found', 'events_created', 'events_updated', 'assignments_created', 'assignments_updated', 'matched', 'unmatched', 'ambiguous', 'missing_marked', 'rolled_back', 'stats'] as $k) {
            if (array_key_exists($k, $z)) {
                $kompakt[$k] = $z[$k];
            }
        }
        $fehler = array_values((array) ($z['errors'] ?? []));
        $kompakt['errors'] = array_slice($fehler, 0, 20);
        $kompakt['errors_gesamt'] = count($fehler);
        $kompakt['abschluss'] = 'vom Hintergrund-Job nachgezogen, Zusammenfassung gekuerzt (Importer konnte processed_at nicht setzen)';

        try {
            $file->forceFill([
                'parse_status' => !empty($z['rolled_back']) ? 'failed' : 'processed',
                'processed_at' => now(),
                'notes'        => json_encode($kompakt, JSON_UNESCAPED_UNICODE),
            ])->save();
            Log::warning('ZAS dispo inbound: Abschluss nachgezogen', ['file_id' => $file->id, 'errors_gesamt' => count($fehler)]);
        } catch (\Throwable $e) {
            Log::error('ZAS dispo inbound: Datei laesst sich nicht abschliessen, wird uebersprungen', ['file_id' => $file->id, 'error' => $e->getMessage()]);
        }
    }
}
