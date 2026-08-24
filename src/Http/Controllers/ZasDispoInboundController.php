<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;
use Platform\Recruiting\Support\CsvEncodingNormalizer;
use Symfony\Component\Uid\UuidV7;

/**
 * Eingangs-Endpoint fuer ZAS-Dispositionsdaten: Veranstaltungen inkl. aller
 * Felder + eingebuchtes Personal (Push-Richtung, CSV oder JSON).
 *
 * Phase 1 (bewusst): nur ANNEHMEN + roh wegspeichern + Struktur Best-Effort
 * erkennen. Keine Verarbeitung, kein Matching — Sichtung unter
 * Disposition → ZAS-Eingang. Verarbeitung kommt als Phase 2, wenn klar ist
 * welche Spalten ZAS liefert. Siehe Spec 2026-08-06-zas-dispo-inbound-design.
 *
 * Auth: ZasBearerAuth (gleiches Bearer-Token wie Export + MA-Inbound).
 * ?dry_run=true → is_test, Antwort enthaelt zusaetzlich Spalten + erste Zeile.
 */
class ZasDispoInboundController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, $originalName, $mimeType);

        if ($content === null || $content === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Keine Daten empfangen. Erwartet: Multipart-Feld "file" oder CSV/JSON im Request-Body.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        $isTest = $request->boolean('dry_run');
        $uuid   = (string) UuidV7::generate();

        // Struktur-Erkennung auf der normalisierten Kopie — gespeichert wird
        // trotzdem 1:1 roh (inkl. BOM/Encoding), damit nichts verloren geht.
        $normalized = CsvEncodingNormalizer::toUtf8($content);
        $inspector  = new DispoInboundInspector();
        $format     = $inspector->detectFormat($normalized);

        $structure = ['delimiter' => null, 'columns' => [], 'row_count' => null, 'rows' => []];
        if ($format === 'csv') {
            $structure = $inspector->inspectCsv($normalized);
        }

        $extension = match ($format) {
            'csv'   => 'csv',
            'json'  => 'json',
            default => 'txt',
        };

        $disk = (string) config('recruiting.zas.inbound_disk', 'local');
        $path = 'zas-dispo-inbound/' . now()->format('Y/m/d') . '/' . $uuid . '.' . $extension;
        Storage::disk($disk)->put($path, $content);

        $record = RecZasDispoInboundFile::create([
            'uuid'              => $uuid,
            'source'            => 'zas',
            'original_filename' => $originalName,
            'disk'              => $disk,
            'stored_path'       => $path,
            'mime_type'         => $mimeType,
            'size_bytes'        => strlen($content),
            'detected_format'   => $format === 'unknown' ? null : $format,
            'delimiter'         => $structure['delimiter'],
            'header_columns'    => $structure['columns'] !== [] ? $structure['columns'] : null,
            'row_count'         => $format === 'csv' ? $structure['row_count'] : null,
            'is_test'           => $isTest,
            'parse_status'      => in_array($format, ['csv', 'json', 'blocks'], true) ? 'viewable' : 'unparseable',
            'received_ip'       => $request->ip(),
        ]);

        Log::info('ZAS dispo inbound empfangen', [
            'id'         => $record->id,
            'uuid'       => $record->uuid,
            'is_test'    => $isTest,
            'format'     => $format,
            'size_bytes' => $record->size_bytes,
            'row_count'  => $record->row_count,
        ]);

        // Verarbeitung (Step 2 der Dispo-Reihe): echte Lieferungen sofort
        // importieren. Fehler aendern die HTTP-Antwort NICHT — Rohdatei ist
        // gespeichert, Reprocess jederzeit moeglich (recruiting:dispo-reprocess).
        // Der Importer faengt bereits alles selbst (Throwable) — dieser
        // try/catch ist die zweite Absicherung, damit WIRKLICH nichts die
        // 201-Antwort gefaehrden kann (Belt-and-braces).
        $import = null;
        if (!$isTest) {
            try {
                $import = app(\Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter::class)
                    ->import($record);
            } catch (\Throwable $e) {
                Log::error('ZAS dispo import hook fehlgeschlagen', ['file_id' => $record->id, 'error' => $e->getMessage()]);
                $import = null;
            }
        }

        // Schlanke Quittung im Echtbetrieb (keine Spaltenwerte nach aussen).
        $payload = [
            'status'      => 'received',
            'id'          => $record->id,
            'uuid'        => $record->uuid,
            'is_test'     => $isTest,
            'received_at' => $record->created_at?->toIso8601String(),
            'size_bytes'  => $record->size_bytes,
            'detected'    => [
                'format'       => $format,
                'delimiter'    => $structure['delimiter'],
                'column_count' => count($structure['columns']),
                'row_count'    => $record->row_count,
            ],
        ];

        if ($import !== null) {
            $payload['import'] = [
                'blocks_found' => $import['blocks_found'],
                'events'       => $import['events_created'] + $import['events_updated'],
                'assignments'  => $import['assignments_created'] + $import['assignments_updated'],
                'matched'      => $import['matched'],
                'unmatched'    => $import['unmatched'],
            ];
        }

        // Volle Vorschau nur im Test-Modus (enthaelt echte Datenwerte).
        if ($isTest && $format === 'csv') {
            $payload['detected']['columns'] = $structure['columns'];
            $payload['first_data_row']      = $structure['rows'][0] ?? null;
        }

        return response()->json($payload, 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Holt den Inhalt aus Multipart-Upload (`file`/`csv`) oder Raw-Body.
     * Setzt $originalName / $mimeType per Referenz.
     * (Gleiche Logik wie ZasInboundController — Zusammenfuehrung beim
     * Umzug ins Staffing-Modul.)
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
