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
 * Der Job traegt zwar die ausloesende Datei-ID, verarbeitet aber immer ALLE
 * anstehenden Echt-Dateien in ID-Reihenfolge, die neuer sind als die letzte
 * verarbeitete. So bleibt die Reihenfolge auch dann richtig, wenn der Job
 * einer juengeren Datei zuerst an die Reihe kommt (eine aeltere Lieferung
 * wuerde sonst Einbuchungen der neueren als verschwunden markieren). Alte
 * Liegengebliebene unterhalb der letzten verarbeiteten Datei fasst er nicht
 * an — die holt bei Bedarf recruiting:dispo-reprocess <id> nach.
 *
 * Nie zwei Importe gleichzeitig (WithoutOverlapping): der zweite Job wartet
 * in kurzen Schleifen, bis der erste fertig ist, und findet dann meist nichts
 * mehr vor.
 */
class ProcessZasDispoInboundFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const SPERRE = 'zas-dispo-inbound';

    /** Eine volle Lieferung braucht gut zwei Minuten; Luft fuer mehrere hintereinander. */
    public int $timeout = 1800;

    /** Warteschleife hinter einem laufenden Import: alle 15 s, bis zu 30 Minuten. */
    public int $tries = 120;

    public int $maxExceptions = 2;

    public function __construct(public int $fileId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping(self::SPERRE))->releaseAfter(15)->expireAfter($this->timeout + 60)];
    }

    public function handle(ZasDispoWebexportImporter $importer): void
    {
        $gesehen = [];
        while (($file = self::naechste()) !== null) {
            if (isset($gesehen[$file->id])) {
                // Importer konnte processed_at nicht setzen (loggt er selbst) — nicht ewig drehen.
                Log::error('ZAS dispo inbound: Datei bleibt unverarbeitet, Lauf abgebrochen', ['file_id' => $file->id]);
                return;
            }
            $gesehen[$file->id] = true;

            $z = $importer->import($file);
            Log::info('ZAS dispo inbound verarbeitet', [
                'file_id'     => $file->id,
                'ausgeloest'  => $this->fileId,
                'blocks'      => (bool) ($z['blocks_found'] ?? false),
                'events'      => ($z['events_created'] ?? 0) + ($z['events_updated'] ?? 0),
                'assignments' => ($z['assignments_created'] ?? 0) + ($z['assignments_updated'] ?? 0),
                'missing'     => $z['missing_marked'] ?? 0,
                'errors'      => count($z['errors'] ?? []),
            ]);
        }
    }

    /** Aelteste anstehende Echt-Datei, die neuer ist als die letzte verarbeitete. */
    public static function naechste(): ?RecZasDispoInboundFile
    {
        return RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->whereNull('processed_at')
            ->where('id', '>', self::letzteVerarbeitete())
            ->orderBy('id')
            ->first();
    }

    public static function letzteVerarbeitete(): int
    {
        return (int) RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->whereNotNull('processed_at')
            ->max('id');
    }
}
