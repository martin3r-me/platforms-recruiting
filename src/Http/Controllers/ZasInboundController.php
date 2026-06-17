<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasInboundFile;
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
 */
class ZasInboundController extends Controller
{
    public function __construct(private \Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter $importer) {}

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
        $structure = $this->inspect($content);

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

        $import = $this->importer->import($structure['rows'], $record, $isTest);

        $record->update([
            'status'       => $isTest ? 'received' : $import['status'],
            'processed_at' => $isTest ? null : now(),
            'notes'        => json_encode([
                'created'  => $import['created'],
                'skipped'  => $import['skipped'],
                'failed'   => $import['failed'],
                'warnings' => $import['warnings'],
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

    /**
     * Best-Effort-Analyse: Trennzeichen, Header-Spalten, Datenzeilen-Anzahl
     * und eine Vorschau der ersten Datenzeile (Header→Wert-Map).
     *
     * @return array{delimiter: ?string, columns: array<int, string>, row_count: int, first_data_row: ?array<string, string>}
     */
    protected function inspect(string $content): array
    {
        // UTF-8-BOM strippen (ZAS-Exporte tragen sie; Eingang vermutlich auch).
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $clean) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            return ['delimiter' => null, 'columns' => [], 'row_count' => 0, 'first_data_row' => null];
        }

        $headerLine = $lines[0];
        $delimiter  = $this->detectDelimiter($headerLine);
        $columns    = array_map('trim', str_getcsv($headerLine, $delimiter, '"', ''));

        $rowCount     = max(0, count($lines) - 1);
        $firstDataRow = null;
        if (isset($lines[1])) {
            $values       = array_map('trim', str_getcsv($lines[1], $delimiter, '"', ''));
            $firstDataRow = $this->zip($columns, $values);
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $values = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            $rows[] = $this->zip($columns, $values);
        }

        return [
            'delimiter'      => $delimiter,
            'columns'        => $columns,
            'row_count'      => $rowCount,
            'first_data_row' => $firstDataRow,
            'rows'           => $rows,
        ];
    }

    /**
     * Ermittelt das wahrscheinlichste Trennzeichen anhand der Header-Zeile.
     * ZAS-Exporte nutzen Semikolon — Eingang ist aber noch ungewiss.
     */
    protected function detectDelimiter(string $line): string
    {
        $candidates = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
        foreach (array_keys($candidates) as $char) {
            $candidates[$char] = substr_count($line, $char);
        }
        arsort($candidates);
        $best = array_key_first($candidates);
        return $candidates[$best] > 0 ? $best : ';';
    }

    /**
     * Verbindet Header-Spalten mit Werten zu einer Map. Laengenunterschiede
     * werden tolerant aufgefuellt (Vorschau-Zweck, keine strikte Validierung).
     *
     * @param array<int, string> $columns
     * @param array<int, string> $values
     * @return array<string, string>
     */
    protected function zip(array $columns, array $values): array
    {
        $out = [];
        $count = max(count($columns), count($values));
        for ($i = 0; $i < $count; $i++) {
            $key = $columns[$i] ?? ('col_' . $i);
            $out[$key] = $values[$i] ?? '';
        }
        return $out;
    }
}
