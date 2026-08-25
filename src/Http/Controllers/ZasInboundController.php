<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;
use Platform\Recruiting\Services\Zas\ZasInboundSizeGuard;
use Symfony\Component\Uid\UuidV7;

/**
 * Eingangs-Endpoint fuer von ZAS gelieferte CSV-Dateien (Push-Richtung).
 *
 * Gegenstueck zu den drei Pull-Export-Endpoints — hier schickt ZAS uns per
 * POST eine CSV (Multipart-Upload `file`, alternativ Raw-Body).
 *
 * Phase 1 (bewusst): nur ANNEHMEN + roh wegspeichern. Wir kennen die Spalten
 * noch nicht; der Endpoint parst die Struktur nur Best-Effort und spiegelt sie
 * in der JSON-Antwort zurueck, damit ZAS (und wir) sofort sehen was ankam.
 * Die eigentliche Verarbeitung/Mapping kommt als Phase 2, wenn klar ist welche
 * Spalten wirklich geliefert werden.
 *
 * Auth: ZasBearerAuth (gleiches Bearer-Token wie die Export-Endpoints).
 *
 * ?dry_run=true → Lieferung wird als Test markiert (is_test=true). Annehmen +
 *                 Speichern passiert trotzdem, damit sich die Verbindung real
 *                 durchtesten laesst.
 *
 * status-Werte: received → processed | partial | failed, sowie `rejected`,
 * wenn die Lieferung mehr Zeilen hat als erlaubt (ZasInboundSizeGuard). Auch
 * eine abgewiesene Lieferung bleibt roh gespeichert.
 */
class ZasInboundController extends Controller
{
    public function __construct(
        private \Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter $importer,
        private ZasInboundCsvParser $parser,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, $originalName, $mimeType);

        if ($content === null || $content === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Keine CSV empfangen. Erwartet: Multipart-Feld "file" oder CSV im Request-Body.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        $isTest = $request->boolean('dry_run');
        $uuid   = (string) UuidV7::generate();

        // Roh wegspeichern — 1:1, ohne Transformation (auch BOM/Encoding bleibt
        // erhalten, damit wir spaeter genau sehen was ZAS geschickt hat).
        $disk = (string) config('recruiting.zas.inbound_disk', 'local');
        $path = 'zas-inbound/' . now()->format('Y/m/d') . '/' . $uuid . '.csv';
        Storage::disk($disk)->put($path, $content);

        // Best-Effort-Strukturerkennung (darf nie den Empfang scheitern lassen).
        $structure = $this->parser->parse($content);

        $record = RecZasInboundFile::create([
            'uuid'              => $uuid,
            'original_filename' => $originalName,
            'disk'              => $disk,
            'stored_path'       => $path,
            'mime_type'         => $mimeType,
            'size_bytes'        => strlen($content),
            'delimiter'         => $structure['delimiter'],
            'header_columns'    => $structure['columns'],
            'row_count'         => $structure['row_count'],
            'is_test'           => $isTest,
            'status'            => 'received',
            'received_ip'       => $request->ip(),
        ]);

        Log::info('ZAS inbound CSV empfangen', [
            'id'           => $record->id,
            'uuid'         => $record->uuid,
            'is_test'      => $isTest,
            'size_bytes'   => $record->size_bytes,
            'row_count'    => $structure['row_count'],
            'column_count' => count($structure['columns']),
        ]);

        // Paketgroessen-Waechter: die Verarbeitung laeuft synchron im Request,
        // eine zu grosse Lieferung liefe in den Timeout. Die Rohdatei ist an
        // dieser Stelle schon gespeichert — sie ist also nicht verloren und
        // kann per recruiting:zas-inbound-reprocess portionsweise laufen.
        $maxRows   = (int) config('recruiting.zas.inbound_max_rows', ZasInboundSizeGuard::DEFAULT_MAX_ROWS);
        $rejection = ZasInboundSizeGuard::rejectionReason((int) $structure['row_count'], $maxRows);
        if ($rejection !== null) {
            $record->update([
                'status' => 'rejected',
                'notes'  => json_encode(['rejected' => $rejection], JSON_UNESCAPED_UNICODE),
            ]);

            Log::warning('ZAS inbound CSV abgewiesen (zu viele Zeilen)', [
                'id'        => $record->id,
                'row_count' => $structure['row_count'],
                'max_rows'  => $maxRows,
            ]);

            return response()->json([
                'status'   => 'rejected',
                'id'       => $record->id,
                'uuid'     => $record->uuid,
                'message'  => $rejection,
                'detected' => ['row_count' => $structure['row_count'], 'max_rows' => $maxRows],
            ], 422)->header('Cache-Control', 'no-store');
        }

        $import = $this->importer->import($structure['rows'], $record, $isTest);

        $record->update([
            'status'       => $isTest ? 'received' : $import['status'],
            'processed_at' => $isTest ? null : now(),
            'notes'        => json_encode([
                'created'   => $import['created'],
                'updated'   => $import['updated'],
                'skipped'   => $import['skipped'],
                'failed'    => $import['failed'],
                'warnings'  => $import['warnings'],
                'suspected' => $import['suspected'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Schlanke Quittung im Echtbetrieb (keine PII/Spaltenwerte nach aussen).
        $payload = [
            'status'      => 'received',
            'id'          => $record->id,
            'uuid'        => $record->uuid,
            'is_test'     => $isTest,
            'received_at' => $record->created_at?->toIso8601String(),
            'size_bytes'  => $record->size_bytes,
            'detected'    => [
                'delimiter'    => $structure['delimiter'],
                'column_count' => count($structure['columns']),
                'row_count'    => $structure['row_count'],
            ],
            'import'      => $import,
        ];

        // Volle Vorschau (Spaltennamen + erste Datenzeile) nur im Test-Modus —
        // enthaelt echte Personendaten + signierte Datei-URLs, die im Echtbetrieb
        // nicht in der HTTP-Antwort landen sollen. Rohdatei ist ohnehin gespeichert.
        if ($isTest) {
            $payload['detected']['columns'] = $structure['columns'];
            $payload['first_data_row']      = $structure['first_data_row'];
        }

        return response()->json($payload, 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Holt den CSV-Inhalt aus Multipart-Upload (`file`/`csv`) oder Raw-Body.
     * Setzt $originalName / $mimeType per Referenz.
     */
    protected function extractContent(Request $request, ?string &$originalName, ?string &$mimeType): ?string
    {
        $originalName = null;
        $mimeType     = null;

        $uploaded = $request->file('file') ?? $request->file('csv');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            $originalName = $uploaded->getClientOriginalName();
            $mimeType     = $uploaded->getClientMimeType();
            $bytes        = file_get_contents($uploaded->getRealPath());
            return $bytes === false ? null : $bytes;
        }

        $raw = $request->getContent();
        if ($raw !== '') {
            $mimeType = $request->header('Content-Type');
        }
        return $raw;
    }
}
